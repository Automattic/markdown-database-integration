<?php
/**
 * Write Engine — Persists backend mutations to markdown/JSON files.
 *
 * Every normalized durable mutation is also persisted to files on disk.
 * This makes markdown files the source of truth.
 *
 * Table-specific strategies:
 *   - wp_posts     → individual .md files (via WP_Markdown_Storage)
 *   - wp_options   → individual _options/*.json files
 *   - wp_users     → _tables/users.json
 *   - wp_usermeta  → _tables/usermeta.json
 *   - wp_terms     → _tables/terms.json
 *   - etc.         → _tables/{table}.json
 *
 * Ref: GitHub issue #3
 *
 * @package Markdown_Database_Integration
 * @since 0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_Canonical_Persistence {

	/** @var string The canonical Markdown post directory. */
	private $content_dir;

	/**
	 * The base directory for non-post runtime state.
	 *
	 * @var string
	 */
	private $state_dir;

	/**
	 * The markdown storage engine (for posts).
	 *
	 * @var WP_Markdown_Storage
	 */
	private $storage;

	/**
	 * Semantic backend operations for reading and indexing canonical data.
	 *
	 * @var WP_Markdown_Backend_Operations
	 */
	private $operations;

	/**
	 * Table prefix resolver.
	 *
	 * Stored as a callable instead of a baked string so callers in boot
	 * paths where `$table_prefix` is unset at construct time still get
	 * the canonical prefix at query time. See WP_Markdown_DB::
	 * boot_connection() for the deferral rationale and issue #77 for
	 * the underlying boot-order bug.
	 *
	 * @var callable
	 */
	private $prefix_resolver;

	/**
	 * Options that are ephemeral (not persisted to disk).
	 * Transients, cron data, session tokens.
	 *
	 * @var string[]
	 */
	private const EPHEMERAL_OPTION_PREFIXES = array(
		'_transient_',
		'_site_transient_',
		'_transient_timeout_',
		'_site_transient_timeout_',
	);

	/**
	 * Ephemeral option names (exact match).
	 *
	 * @var string[]
	 */
	private const EPHEMERAL_OPTION_NAMES = array(
		'cron',
		'doing_cron',
	);

	/**
	 * Tables that have been modified and need flushing at shutdown.
	 *
	 * @var array<string, bool>
	 */
	private $dirty = array();
	/** @var array<string,array<string,bool>> Partitioned table identities dirty in this request. */
	private $dirty_partition_resources = array();

	/**
	 * Post IDs whose `.md` files need rewriting at shutdown.
	 *
	 * Populated by post-row updates, postmeta writes, and term-relationship
	 * writes. Each ID gets rewritten exactly once per request regardless of
	 * how many writes touched it — a plugin doing 50 `update_post_meta()`
	 * calls in a loop produces one file write, not fifty.
	 *
	 * Keyed by post_id for uniqueness. See GitHub issue #21.
	 *
	 * @var array<int, bool>
	 * @since 0.3.0
	 */
	private $dirty_posts = array();

	/**
	 * Specific wp_options names changed in this request.
	 *
	 * Populated by persist_write() when it can cleanly parse the option_name
	 * from the query. persist_options() uses this to write only the changed
	 * options to disk (one file per option) instead of re-dumping the whole
	 * table. This eliminates whole-file races between concurrent workers
	 * since each worker only ever writes files it itself changed.
	 *
	 * Keyed by option_name for uniqueness.
	 *
	 * @var array<string, bool>
	 * @since 0.4.0
	 */
	protected $dirty_option_names = array();

	/**
	 * When true, persist_options() falls back to persisting every non-
	 * ephemeral option currently in the backend. Set when mutation parsing fails
	 * for any options write — ensures we never silently drop a change
	 * we couldn't analyze.
	 *
	 * @var bool
	 * @since 0.4.0
	 */
	private $dirty_options_all = false;

	/**
	 * Whether the shutdown flush handler has been registered.
	 *
	 * @var bool
	 */
	private $shutdown_registered = false;

	/**
	 * Whether we're currently writing (prevents recursion).
	 *
	 * @var bool
	 */
	private $writing = false;

	/**
	 * Canonical paths touched since the previous successful flush, keyed by path.
	 *
	 * Each value carries the pre-mutation hash and, when MDI serializes a
	 * replacement itself, its known post-mutation hash. Content is therefore
	 * hashed only for paths MDI actually mutates, without rereading snapshots
	 * that were just atomically replaced.
	 *
	 * @var array<string, array{before:string|null,after:string|null}>
	 */
	private $canonical_mutations = array();

	/**
	 * Next matched temp-file offset to inspect for each canonical JSON path.
	 *
	 * @var array<string, int>
	 */
	private $json_temp_cleanup_offsets = array();
	private ?WP_Markdown_Durable_Reconciliation_Coordinator $reconciliation = null;
	private string $reconciliation_fence_directory = '';

	/**
	 * Constructor.
	 *
	 * @param string                $content_dir     Content directory.
	 * @param WP_Markdown_Storage   $storage         Markdown storage for posts.
	 * @param WP_Markdown_Backend_Operations $operations Semantic persistence backend.
	 * @param callable|string       $prefix_resolver Either a callable returning the
	 *                                               current table prefix at call
	 *                                               time, or a string for the
	 *                                               legacy (always-the-same) case.
	 *                                               String is wrapped in a closure
	 *                                               internally so call sites stay
	 *                                               uniform.
	 * @param string|null           $state_dir       Runtime state directory. Defaults to content directory.
	 */
	public function __construct(
		string $content_dir,
		WP_Markdown_Storage $storage,
		WP_Markdown_Backend_Operations $operations,
		$prefix_resolver = 'wp_',
		?string $state_dir = null,
		?WP_Markdown_Durable_Reconciliation_Coordinator $reconciliation = null
	) {
		$this->content_dir     = rtrim( $content_dir, '/' );
		$this->state_dir       = rtrim( $state_dir ?? $content_dir, '/' );
		$this->storage         = $storage;
		$this->operations      = $operations;
		$this->prefix_resolver = is_callable( $prefix_resolver )
			? $prefix_resolver
			: static function () use ( $prefix_resolver ): string { return (string) $prefix_resolver; };
		$this->reconciliation = $reconciliation;
		$this->reconciliation_fence_directory = rtrim( sys_get_temp_dir(), '/' ) . '/markdown-database-integration-fences/' . hash( 'sha256', $this->content_dir . "\0" . $this->state_dir );
		if ( method_exists( $this->storage, 'set_file_mutation_observer' ) ) {
			$this->storage->set_file_mutation_observer( array( $this, 'track_canonical_mutation' ) );
		}
	}

	/**
	 * Resolve the canonical table prefix at the moment of the call.
	 *
	 * Internal accessor — callers in this class read `$this->prefix()`
	 * instead of `$this->prefix() . 'foo'`. See `$prefix_resolver` for
	 * the deferral rationale.
	 */
	private function prefix(): string {
		return ( $this->prefix_resolver )();
	}

	/**
	 * Handle a write query — persist affected data to disk.
	 *
	 * Called after a successful backend write.
	 * Markdown-type post writes are immediate (one file per post).
	 * Everything else is deferred to shutdown via dirty flags.
	 *
	 * @param string $query   The MySQL query.
	 * @param string $table   The affected table name.
	 * @param string $op_type The operation: INSERT, UPDATE, DELETE, REPLACE.
	 */
	public function persist_write( string $query, string $table, string $op_type ): void {
		$mutations = $this->operations->mutations_for_query( $query, array( 'table' => $table, 'op' => $op_type, 'type' => 'DML' ) );
		foreach ( $mutations as $mutation ) {
			$this->apply_normalized_mutation( array( 'key' => $mutation['stable_id'], 'resource' => $mutation['stable_id'], 'operation' => $mutation['operation'], 'table' => $mutation['table'], 'context' => array( 'resource_ids' => $mutation['resource_ids'], 'scope' => $mutation['scope'] ?? array() ) ) );
		}
	}

	/**
	 * Accept a normalized backend mutation without depending on its connection or result types.
	 *
	 * @param array{key:string,resource:string,operation:string,table:string,context?:array<string,mixed>} $mutation
	 */
	public function persist_mutation( array $mutation ): void {
		if ( ! empty( $mutation['context']['schema'] ) ) {
			$this->persist_schema( '', $mutation['table'], $mutation['operation'] );
			return;
		}
		$this->apply_normalized_mutation( $mutation );
	}

	/** Recover original durable WordPress-to-canonical IDs before new intent is derived. */
	public function recover_pending( int $limit = 100 ): array {
		if ( null === $this->reconciliation ) { return array(); }
		return $this->reconciliation->recover_pending(
			function ( array $record ): ?WP_Markdown_Reconciliation_Adapter {
				$binding = $record['binding'];
				if ( 'wordpress_to_canonical' !== $binding['direction'] || 'post' !== $binding['resource']['type'] ) { return null; }
				$id = (int) $binding['resource']['id'];
				$domains = array_keys( $binding['after'] );
				$observer = function () use ( $id, $domains ): array {
					$values = array( 'wordpress' => $this->current_post_receipt( $id ), 'canonical' => $this->storage_post_receipt( $id ), 'index' => $this->file_index_receipt( $id ) );
					return array_intersect_key( $values, array_flip( $domains ) );
				};
				$mutation = function () use ( $id ): void {
					$rows = $this->operations->post_rows( array( $id ) );
					if ( empty( $rows ) ) { $this->storage->delete_post( $id ); $this->operations->delete_file_index( $id ); return; }
					$path = $this->storage->write_post( (object) $rows[0] );
					if ( false === $path ) { throw new RuntimeException( 'Recovered canonical post replacement failed.' ); }
					$relative = str_starts_with( $path, $this->content_dir . '/' ) ? substr( $path, strlen( $this->content_dir ) + 1 ) : $path;
					$this->operations->upsert_file_index( $id, $relative, (int) filemtime( $path ), (int) filesize( $path ) );
				};
				return new WP_Markdown_Filesystem_Reconciliation_Adapter( $this->reconciliation_fence_directory, $observer, $mutation );
			},
			$limit
		);
	}

	/** Capture exact WordPress post identities before the owning SQL transaction mutates them. */
	public function wordpress_post_identities( array $post_ids ): array {
		$result = array();
		foreach ( $post_ids as $post_id ) { $result[ (int) $post_id ] = $this->current_post_receipt( (int) $post_id ); }
		return $result;
	}

	/** Plan and claim the cross-domain continuation while the WordPress write is still uncommitted. */
	public function prepare_post_commit( int $post_id, mixed $wordpress_before ): ?array {
		if ( null === $this->reconciliation ) { return null; }
		try {
			if ( 'auto-draft' === $this->operations->post_status( $post_id ) ) { return null; }
		} catch ( Throwable $error ) {
			// Status lookup failures retain the post for fail-safe persistence.
		}
		$wordpress_after = $this->current_post_receipt( $post_id );
		$canonical_before = $this->storage_post_receipt( $post_id );
		$index_before = $this->file_index_receipt( $post_id );
		$after = array( 'wordpress' => $wordpress_after, 'canonical' => $wordpress_after, 'index' => null === $wordpress_after ? null : array( 'post_id' => $post_id ) );
		$before = array( 'wordpress' => $wordpress_before, 'canonical' => $canonical_before, 'index' => $index_before );
		$checkpoint = array( 'wordpress' => $wordpress_after, 'canonical' => $canonical_before, 'index' => $index_before );
		$observer = fn(): array => array( 'wordpress' => $this->current_post_receipt( $post_id ), 'canonical' => $this->storage_post_receipt( $post_id ), 'index' => $this->file_index_receipt( $post_id ) );
		$mutation = function () use ( $post_id, $wordpress_after ): void {
			if ( null === $wordpress_after ) { $this->storage->delete_post( $post_id ); $this->operations->delete_file_index( $post_id ); return; }
			$rows = $this->operations->post_rows( array( $post_id ) );
			if ( empty( $rows ) ) { throw new RuntimeException( 'Prepared WordPress post is unavailable.' ); }
			$path = $this->storage->write_post( (object) $rows[0] );
			if ( false === $path ) { throw new RuntimeException( 'Prepared canonical post replacement failed.' ); }
			$relative = str_starts_with( $path, $this->content_dir . '/' ) ? substr( $path, strlen( $this->content_dir ) + 1 ) : $path;
			$this->operations->upsert_file_index( $post_id, $relative, (int) filemtime( $path ), (int) filesize( $path ) );
		};
		$adapter = new WP_Markdown_Filesystem_Reconciliation_Adapter( $this->reconciliation_fence_directory, $observer, $mutation );
		$record = $this->reconciliation->prepare( array( 'plan_id' => 'wordpress-commit:' . $post_id . ':' . hash( 'sha256', WP_Markdown_Reconciliation_Identity::encode( array( $before, $after ) ) ), 'continuation' => array( 'post_id' => $post_id ), 'canonical_root' => $this->content_dir, 'resource' => array( 'type' => 'post', 'id' => (string) $post_id ), 'kind' => null === $wordpress_after ? 'deletion' : ( null === $wordpress_before ? 'create' : 'update' ), 'direction' => 'wordpress_to_canonical', 'before' => $before, 'checkpoint' => $checkpoint, 'after' => $after ), $adapter );
		return array( 'id' => $record['id'], 'adapter' => $adapter );
	}

	public function continue_post_commit( array $prepared ): array {
		return $this->reconciliation->continue_prepared( $prepared['id'], $prepared['adapter'] );
	}

	/** @param array{operation:string,table:string,context?:array<string,mixed>} $mutation */
	private function apply_normalized_mutation( array $mutation ): void {
		if ( $this->writing ) {
			return;
		}
		$this->writing = true;

		try {
			$table        = $mutation['table'];
			$resource_ids = (array) ( $mutation['context']['resource_ids'] ?? array() );
			$op_type      = $mutation['operation'];
			$table_suffix = $this->strip_prefix( $table );
			if ( ! $this->should_persist_table( $table_suffix ) ) {
				return;
			}

			if ( $table_suffix === 'posts' ) {
				$this->persist_post_write( $resource_ids, $op_type );
			} elseif ( $table_suffix === 'postmeta' ) {
				$this->persist_postmeta_write( $resource_ids, $op_type );
			} elseif ( in_array( $table_suffix, array( 'term_relationships', 'term_taxonomy', 'terms' ), true ) ) {
				$this->persist_terms_write( $resource_ids, $op_type, $table_suffix );
			} elseif ( $table_suffix === 'options' ) {
				// Defer to shutdown. Track which specific option_name(s) were
				// touched so we can write only the changed files — per-option
				// persistence eliminates whole-file races. See issue #55.
				$this->mark_dirty( 'options' );
				$this->track_options_change( $resource_ids );
			} elseif ( null !== ( $identity_column = $this->partition_identity_column( $table_suffix ) ) ) {
				$ids = (array) ( $mutation['context']['scope']['resource_ids_by_column'][ $identity_column ] ?? array() );
				if ( in_array( $identity_column, (array) ( $mutation['context']['scope']['assigned_columns'] ?? array() ), true ) ) { $ids = array(); }
				if ( empty( $ids ) ) { $ids = array( '*' ); }
				foreach ( $ids as $id ) { $this->dirty_partition_resources[ $table_suffix ][ (string) $id ] = true; }
				$this->ensure_shutdown_registered();
			} else {
				// Defer users, usermeta, and all other tables to shutdown.
				$this->mark_dirty( $table_suffix );
			}
		} catch ( \Throwable $e ) {
			// Write failures should never break WordPress.
			error_log( 'Markdown DB write error: ' . $e->getMessage() );
		}

		$this->writing = false;
	}

	/**
	 * Mark a table as dirty (needs to be flushed at shutdown).
	 *
	 * @param string $table_suffix Table name without prefix.
	 */
	private function mark_dirty( string $table_suffix ): void {
		$this->dirty[ $table_suffix ] = true;
		$this->ensure_shutdown_registered();
	}

	/**
	 * Mark a post as needing a `.md` file rewrite at shutdown.
	 *
	 * Called whenever a post-row, postmeta, or term-relationship write
	 * affects a post. The file is actually written once in `flush_dirty()`,
	 * no matter how many marks accumulate against the same post ID during
	 * the request. This is the debounce that keeps bulk meta updates
	 * (ACF repeaters, WooCommerce product attributes, etc.) from
	 * rewriting the same file fifty times.
	 *
	 * See GitHub issue #21.
	 *
	 * @param int $post_id Post ID (ignored if ≤ 0).
	 */
	private function mark_post_dirty( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		$this->dirty_posts[ $post_id ] = true;
		$this->ensure_shutdown_registered();
	}

	/**
	 * Cancel a queued rewrite for a post that has since been deleted.
	 *
	 * Keeps flush_dirty() from attempting to re-persist a post whose
	 * source file has already been unlinked this request.
	 *
	 * @param int $post_id Post ID.
	 */
	private function unmark_post_dirty( int $post_id ): void {
		unset( $this->dirty_posts[ $post_id ] );
	}

	/**
	 * Register the shutdown flush handler (once).
	 */
	private function ensure_shutdown_registered(): void {
		if ( $this->shutdown_registered ) {
			return;
		}
		$this->shutdown_registered = true;
		register_shutdown_function( array( $this, 'flush_dirty' ) );
	}

	/**
	 * Flush all dirty tables and post rewrites to disk.
	 *
	 * Called at shutdown. Dirty posts flush first so that a post turning
	 * out to be a non-markdown type can raise the `posts_non_markdown`
	 * table flag before the table-level flush loop runs. Each post is
	 * rewritten exactly once regardless of how many dirty marks landed
	 * against it during the request — see `mark_post_dirty`.
	 *
	 * @param bool $throw_on_error Whether persistence failures should propagate to the caller.
	 * @return array{created:string[],changed:string[],deleted:string[]}
	 */
	public function flush_dirty( bool $throw_on_error = false ): array {
		if ( empty( $this->dirty ) && empty( $this->dirty_posts ) && empty( $this->dirty_partition_resources ) && empty( $this->canonical_mutations ) ) {
			return array( 'created' => array(), 'changed' => array(), 'deleted' => array() );
		}

		$this->writing = true;

		try {
			// Debounced post rewrites — each dirty post becomes exactly one
			// call to persist_single_post(). A post turning out to be a
			// non-markdown type raises the `posts_non_markdown` flag, which
			// the table-level flush below then persists.
			if ( ! empty( $this->dirty_posts ) ) {
				foreach ( array_keys( $this->dirty_posts ) as $post_id ) {
					if ( $this->persist_single_post( (int) $post_id ) ) {
						$this->dirty['posts_non_markdown'] = true;
					}
				}
				$this->dirty_posts = array();
			}

			foreach ( array_keys( $this->dirty ) as $table_suffix ) {
				if ( $table_suffix === 'options' ) {
					$this->persist_options();
				} elseif ( $table_suffix === 'posts_non_markdown' ) {
					$this->persist_non_markdown_posts();
				} elseif ( $table_suffix === 'postmeta_non_markdown' ) {
					$this->persist_table_excluding_markdown_posts( 'postmeta', 'post_id' );
				} elseif ( $table_suffix === 'term_relationships_non_markdown' ) {
					$this->persist_table_excluding_markdown_posts( 'term_relationships', 'object_id' );
				} else {
					$this->persist_table( $table_suffix );
				}
			}
			foreach ( $this->dirty_partition_resources as $table_suffix => $ids ) {
				$this->persist_partitioned_table( $table_suffix, array_keys( $ids ) );
			}
			$this->dirty               = array();
			$this->dirty_partition_resources = array();
			$this->dirty_option_names  = array();
			$this->dirty_options_all   = false;
			$changes                   = $this->canonical_changes();
			$this->canonical_mutations = array();
			if ( function_exists( 'do_action' ) ) {
				do_action( 'markdown_database_integration_flushed', $changes );
			}
			return $changes;
		} catch ( \Throwable $e ) {
			if ( $throw_on_error ) {
				throw $e;
			}
			error_log( 'Markdown DB flush error: ' . $e->getMessage() );
			return array( 'created' => array(), 'changed' => array(), 'deleted' => array() );
		} finally {
			$this->writing = false;
		}
	}

	/**
	 * @return array{created:string[],changed:string[],deleted:string[]}
	 */
	private function canonical_changes(): array {
		$created = array();
		$deleted = array();
		$changed = array();
		foreach ( $this->canonical_mutations as $path => $mutation ) {
			$before   = $mutation['before'];
			$absolute = $this->canonical_absolute_path( $path );
			if ( ! is_file( $absolute ) ) {
				if ( null !== $before ) {
					$deleted[] = $path;
				}
				continue;
			}

			$after = $mutation['after'] ?? $this->canonical_file_hash( $absolute );
			if ( null === $before ) {
				$created[] = $path;
			} elseif ( $after !== $before ) {
				$changed[] = $path;
			}
		}
		sort( $created, SORT_STRING );
		sort( $changed, SORT_STRING );
		sort( $deleted, SORT_STRING );
		return array( 'created' => $created, 'changed' => $changed, 'deleted' => $deleted );
	}

	/** @param string $absolute_path File about to be written, renamed, or deleted. */
	public function track_canonical_mutation( string $absolute_path ): void {
		$path = $this->canonical_relative_path( $absolute_path );
		if ( null === $path || array_key_exists( $path, $this->canonical_mutations ) ) {
			return;
		}

		$this->canonical_mutations[ $path ] = array(
			'before' => is_file( $absolute_path ) ? $this->canonical_file_hash( $absolute_path ) : null,
			'after'  => null,
		);
	}

	/** Record the known content identity after MDI atomically replaces a canonical file. */
	private function track_canonical_write( string $absolute_path, string $hash ): void {
		$this->track_canonical_mutation( $absolute_path );
		$path = $this->canonical_relative_path( $absolute_path );
		if ( null !== $path ) {
			$this->canonical_mutations[ $path ]['after'] = $hash;
		}
	}

	/** @return string SHA-256 identity for a canonical file. */
	protected function canonical_file_hash( string $path ): string {
		return (string) hash_file( 'sha256', $path );
	}

	private function canonical_relative_path( string $absolute_path ): ?string {
		$absolute_path = str_replace( DIRECTORY_SEPARATOR, '/', $absolute_path );
		foreach ( array( $this->content_dir, $this->state_dir ) as $root ) {
			$root = str_replace( DIRECTORY_SEPARATOR, '/', rtrim( $root, '/' ) );
			if ( str_starts_with( $absolute_path, $root . '/' ) ) {
				$path = substr( $absolute_path, strlen( $root ) + 1 );
				if ( str_ends_with( $path, '.md' ) || str_ends_with( $path, '.json' ) ) {
					return $path;
				}
			}
		}

		return null;
	}

	private function canonical_absolute_path( string $path ): string {
		$state_path = $this->state_dir . '/' . $path;
		return is_file( $state_path ) || str_starts_with( $path, '_' ) ? $state_path : $this->content_dir . '/' . $path;
	}

	/**
	 * Record option resource names from a normalized mutation.
	 */
	private function track_options_change( array $names ): void {
		foreach ( $names as $name ) {
			if ( '*' !== $name ) {
				$this->dirty_option_names[ (string) $name ] = true;
			}
		}
		if ( empty( $names ) || in_array( '*', $names, true ) ) {
			$this->dirty_options_all = true;
		}
	}

	/**
	 * Persist wp_options changes to disk as one file per option.
	 *
	 * Layout:
	 *   {state_dir}/_options/{sanitized_name}.json
	 *
	 * Each file contains a single JSON object:
	 *   { "option_id": ..., "option_name": ..., "option_value": ..., "autoload": ... }
	 *
	 * Why one-file-per-option: see issue #55. Concurrent writers touching
	 * different options write to different files and cannot clobber each
	 * other. No flock, no merge logic — the filesystem provides isolation.
	 *
	 * For each dirty option name:
	 *   - Row exists in the backend → write/overwrite the file, update index.
	 *   - Row missing in the backend → option was deleted → remove file + index row.
	 *
	 * Fallback: if $dirty_options_all is set (query parsing failed), persist
	 * every non-ephemeral option currently in the backend.
	 *
	 * Ephemerals (transients, cron locks) are filtered here — they never
	 * hit disk.
	 *
	 * @since 0.4.0 Rewritten as per-file persistence.
	 */
	private function persist_options(): void {
		$ephemeral_names = $this->get_ephemeral_option_names();
		$names = $this->dirty_options_all
			? $this->list_all_non_ephemeral_option_names( $ephemeral_names )
			: array_keys( $this->dirty_option_names );

		if ( empty( $names ) ) {
			return;
		}

		// Ensure the _options directory exists.
		$options_dir = $this->state_dir . '/_options';
		if ( ! is_dir( $options_dir ) ) {
			if ( ! @mkdir( $options_dir, 0755, true ) && ! is_dir( $options_dir ) ) {
				throw new \RuntimeException( 'Markdown DB: Failed to create _options directory.' );
			}
		}

		// Read existing backend rows for the dirty names in one operation.
		$rows_by_name = $this->fetch_options_by_names( $names );

		// Track which files we wrote/deleted so the driver can update the
		// _options_file_index in one batch.
		$index_updates = array();
		$index_deletes = array();

		foreach ( $names as $name ) {
			if ( $this->is_ephemeral_option( $name, $ephemeral_names ) ) {
				// Ephemerals never hit disk. If one was previously persisted
				// (legacy migration edge case), remove its file.
				$this->delete_option_file( $name, $index_deletes );
				continue;
			}

			if ( ! isset( $rows_by_name[ $name ] ) ) {
				// Not in the backend → was deleted in-request.
				$this->delete_option_file( $name, $index_deletes );
				continue;
			}

			$row = $rows_by_name[ $name ];
			$path = $this->write_option_file( $name, $row );
			if ( null === $path ) {
				continue;
			}

			$abs = $this->state_dir . '/' . $path;
			$index_updates[] = array(
				'option_name' => $name,
				'file_path'   => $path,
				'file_mtime'  => (int) @filemtime( $abs ),
				'file_size'   => (int) @filesize( $abs ),
				'option_id'   => (int) $row['option_id'],
				'autoload'    => (string) $row['autoload'],
			);
		}

		// Update the index table so sync_incremental() can diff per-row.
		if ( ! empty( $index_updates ) ) {
			$this->operations->upsert_options_index( $index_updates );
		}
		if ( ! empty( $index_deletes ) ) {
			$this->operations->delete_options_index( $index_deletes );
		}
	}

	/**
	 * Fetch current backend rows for the given option names, keyed by name.
	 *
	 * The backend may batch this operation for efficiency.
	 *
	 * @since 0.4.0
	 *
	 * @param string[] $names
	 * @return array<string, array{option_id:int,option_name:string,option_value:string,autoload:string}>
	 */
	private function fetch_options_by_names( array $names ): array {
		if ( empty( $names ) ) {
			return array();
		}

		try {
			$rows = $this->operations->options( $names );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'Markdown DB: Failed to read dirty options: ' . $e->getMessage(), 0, $e );
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $name => $row ) {
			$row = (array) $row;
			$out[ $name ] = $row;
		}
		return $out;
	}

	/**
	 * List every non-ephemeral option name currently in the backend.
	 *
	 * Used as a fallback when query parsing failed. O(n) but only runs
	 * in the rare fallback path.
	 *
	 * @since 0.4.0
	 *
	 * @param string[] $ephemeral_names Exact ephemeral option names for this flush.
	 * @return string[]
	 */
	private function list_all_non_ephemeral_option_names( array $ephemeral_names ): array {
		try {
			$rows = $this->operations->option_names();
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'Markdown DB: Failed to list options: ' . $e->getMessage(), 0, $e );
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$names = array();
		foreach ( $rows as $row ) {
			if ( ! $this->is_ephemeral_option( (string) $row, $ephemeral_names ) ) {
				$names[] = (string) $row;
			}
		}
		return $names;
	}

	/**
	 * Write a single option to disk atomically. Uses temp+rename so
	 * concurrent readers never see a partial file.
	 *
	 * @since 0.4.0
	 *
	 * @param string $name Option name.
	 * @param array  $row  Row data (option_id, option_name, option_value, autoload).
	 * @return string|null Relative path under state_dir on success, null on failure.
	 */
	private function write_option_file( string $name, array $row ): ?string {
		$filename = self::option_filename( $name );
		$relative = '_options/' . $filename;
		$abs      = $this->state_dir . '/' . $relative;
		$this->track_canonical_mutation( $abs );

		$payload = array(
			'option_id'    => (int) $row['option_id'],
			'option_name'  => $row['option_name'],
			'option_value' => $row['option_value'],
			'autoload'     => $row['autoload'],
		);

		$json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new \RuntimeException( 'Markdown DB: Failed to encode option "' . $name . '".' );
		}

		$tmp = $this->json_tmp_path( $abs );
		$handle = $this->open_json_temp_file( $tmp, $abs );
		try {
			$this->write_json_contents( $handle, $json, $abs );
			if ( ! fflush( $handle ) ) {
				throw new \RuntimeException( 'Markdown DB: Failed to flush option file: ' . $abs );
			}
			if ( ! @rename( $tmp, $abs ) ) {
				throw new \RuntimeException( 'Markdown DB: Failed to rename option file: ' . $abs );
			}
		} finally {
			fclose( $handle );
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
		}
		$this->track_canonical_write( $abs, hash( 'sha256', $json ) );
		$this->cleanup_json_temp_files( $abs );

		return $relative;
	}

	/**
	 * Delete an option's file on disk and record the index deletion.
	 *
	 * @since 0.4.0
	 *
	 * @param string   $name           Option name.
	 * @param string[] $index_deletes  Reference — name appended if a delete is needed.
	 */
	private function delete_option_file( string $name, array &$index_deletes ): void {
		$filename = self::option_filename( $name );
		$abs      = $this->state_dir . '/_options/' . $filename;
		if ( file_exists( $abs ) ) {
			$this->track_canonical_mutation( $abs );
			@unlink( $abs );
		}
		$index_deletes[] = $name;
	}

	/**
	 * Derive a safe, stable filename from an option_name.
	 *
	 * Option names can contain characters that are legal in MySQL but
	 * problematic on disk or in paths (slashes, control chars, non-ASCII
	 * above certain encodings, etc.). WordPress core option names are
	 * ASCII + [_.\-] but plugin/theme code is not bound by that.
	 *
	 * Strategy:
	 *   - Replace any character outside [A-Za-z0-9._-] with '_'.
	 *   - Collapse runs of '_'.
	 *   - If any replacement happened OR the name is longer than 180
	 *     bytes, append a short hash of the original name so different
	 *     names can never collide on the same file.
	 *   - Always append .json.
	 *
	 * The function is deterministic — same name always maps to same
	 * filename — so the loader can round-trip via the index table.
	 *
	 * @since 0.4.0
	 *
	 * @param string $name Option name.
	 * @return string Safe filename with .json extension.
	 */
	public static function option_filename( string $name ): string {
		$safe = preg_replace( '/[^A-Za-z0-9._\-]/', '_', $name );
		$safe = preg_replace( '/_+/', '_', $safe );
		$safe = trim( $safe, '._' );
		if ( '' === $safe ) {
			$safe = 'option';
		}

		$needs_hash = ( $safe !== $name ) || strlen( $name ) > 180;
		if ( $needs_hash ) {
			$hash = substr( md5( $name ), 0, 8 );
			// Keep the readable prefix short enough to fit with hash + ext
			// inside common filesystem limits (255 bytes).
			if ( strlen( $safe ) > 180 ) {
				$safe = substr( $safe, 0, 180 );
			}
			return $safe . '-' . $hash . '.json';
		}

		return $safe . '.json';
	}

	/**
	 * Persist a post write to markdown files.
	 *
	 * Markdown-type posts are written immediately as individual .md files.
	 * Non-markdown posts JSON is deferred to shutdown.
	 *
	 * @param string $query   The SQL query.
	 * @param string $op_type INSERT, UPDATE, DELETE, REPLACE.
	 */
	private function persist_post_write( array $ids, string $op_type ): void {
		if ( 'DELETE' === $op_type ) {
			// Deletes must fire immediately — a queued rewrite for the same
			// ID in this request would try to re-persist a vanished post.
			foreach ( $ids as $id ) {
				$this->persist_post_deletion( (int) $id );
				$this->unmark_post_dirty( $id );
			}

			// Non-markdown posts JSON also needs updating.
			$this->mark_dirty( 'posts_non_markdown' );
			return;
		}

		// For INSERT/UPDATE/REPLACE, queue the rewrite for the shutdown
		// flush. Any number of downstream writes (postmeta, terms, etc.)
		// in the same request collapses to a single file write. See #21.
		if ( 'INSERT' === $op_type || 'REPLACE' === $op_type ) {
			$id = $this->operations->insert_id();
			if ( $id && ! $this->is_auto_draft( (int) $id ) ) {
				$this->mark_post_dirty( (int) $id );
			}
		} elseif ( 'UPDATE' === $op_type ) {
			foreach ( $ids as $id ) {
				$this->mark_post_dirty( (int) $id );
			}
		}
	}

	/**
	 * Determine whether an inserted post is an ephemeral auto-draft.
	 *
	 * Status lookup failures retain the post so canonical persistence remains
	 * fail-safe. The narrow query avoids loading post content for rows that the
	 * storage layer will discard.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether the post is an auto-draft.
	 */
	private function is_auto_draft( int $post_id ): bool {
		try {
			$status = $this->operations->post_status( $post_id );
		} catch ( \Throwable $e ) {
			return false;
		}

		return 'auto-draft' === $status;
	}

	/**
	 * Persist a single post — either to markdown or to the JSON fallback.
	 *
	 * For markdown-type posts, writes post_content bytes exactly as received.
	 * Content-format conversion belongs to the caller/policy layer.
	 *
	 * @param int $post_id
	 * @return bool True if the post type is non-markdown (caller should update JSON).
	 */
	private function persist_single_post( int $post_id ): bool {
		try {
			$rows = $this->operations->post_rows( array( $post_id ) );
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return false;
		}

		$row = (object) $rows[0];
		$post_type = $row->post_type ?? 'post';

		if ( ! $this->storage->is_markdown_type( $post_type ) ) {
			return true; // Non-markdown — caller should update JSON fallback.
		}

		$file_path = $this->persist_markdown_post( $row );

		// Update the file index after writing the .md file.
		// The driver uses this index for lazy-loading content on demand.
		if ( null === $this->reconciliation && false !== $file_path && $post_id > 0 ) {
			$content_dir = $this->storage->get_content_dir();
			$relative_path = $file_path;
			if ( str_starts_with( $file_path, $content_dir . '/' ) ) {
				$relative_path = substr( $file_path, strlen( $content_dir ) + 1 );
			}
			$this->operations->upsert_file_index(
				$post_id,
				$relative_path,
				(int) filemtime( $file_path ),
				(int) filesize( $file_path )
			);
		}

		return false;
	}

	private function persist_markdown_post( object $row ): string|false {
		if ( null === $this->reconciliation ) {
			return $this->storage->write_post( $row );
		}
		$id       = (int) $row->ID;
		$before   = array( 'canonical' => $this->storage_post_receipt( $id ), 'index' => $this->file_index_receipt( $id ) );
		$after    = array( 'canonical' => $this->current_post_receipt( $id ), 'index' => array( 'post_id' => $id ) );
		$result   = false;
		$observer = fn(): array => array( 'canonical' => $this->storage_post_receipt( $id ), 'index' => $this->file_index_receipt( $id ) );
		$mutation = function () use ( $row, $id, &$result ): void {
			$result = $this->storage->write_post( $row );
			if ( false === $result ) { throw new RuntimeException( 'Canonical post replacement failed.' ); }
			$relative = str_starts_with( $result, $this->content_dir . '/' ) ? substr( $result, strlen( $this->content_dir ) + 1 ) : $result;
			$this->operations->upsert_file_index( $id, $relative, (int) filemtime( $result ), (int) filesize( $result ) );
		};
		$adapter = new WP_Markdown_Filesystem_Reconciliation_Adapter( $this->reconciliation_fence_directory, $observer, $mutation );
		$this->reconciliation->reconcile( array( 'plan_id' => 'persistence:' . $id . ':' . hash( 'sha256', WP_Markdown_Reconciliation_Identity::encode( $after ) ), 'continuation' => array( 'post_id' => $id ), 'canonical_root' => $this->content_dir, 'resource' => array( 'type' => 'post', 'id' => (string) $id ), 'kind' => null === $before['canonical'] ? 'create' : ( ( $before['canonical']['post_name'] ?? '' ) === ( $after['canonical']['post_name'] ?? '' ) ? 'update' : 'move' ), 'direction' => 'wordpress_to_canonical', 'before' => $before, 'after' => $after ), $adapter );
		return false === $result ? $this->storage->path_for_post( $id ) : $result;
	}

	private function persist_post_deletion( int $post_id ): void {
		if ( null === $this->reconciliation ) {
			$this->storage->delete_post( $post_id );
			$this->operations->delete_file_index( $post_id );
			return;
		}
		$before = array( 'canonical' => $this->storage_post_receipt( $post_id ), 'index' => $this->file_index_receipt( $post_id ) );
		$observer = fn(): array => array( 'canonical' => $this->storage_post_receipt( $post_id ), 'index' => $this->file_index_receipt( $post_id ) );
		$mutation = function () use ( $post_id ): void { $this->storage->delete_post( $post_id ); $this->operations->delete_file_index( $post_id ); };
		$adapter = new WP_Markdown_Filesystem_Reconciliation_Adapter( $this->reconciliation_fence_directory, $observer, $mutation );
		$this->reconciliation->reconcile( array( 'plan_id' => 'persistence-delete:' . $post_id . ':' . hash( 'sha256', WP_Markdown_Reconciliation_Identity::encode( $before ) ), 'continuation' => array( 'post_id' => $post_id ), 'canonical_root' => $this->content_dir, 'resource' => array( 'type' => 'post', 'id' => (string) $post_id ), 'kind' => 'deletion', 'direction' => 'wordpress_to_canonical', 'before' => $before, 'after' => array( 'canonical' => null, 'index' => null ) ), $adapter );
	}

	private function file_index_receipt( int $post_id ): ?array {
		return method_exists( $this->operations, 'file_index_receipt' ) ? $this->operations->file_index_receipt( $post_id ) : null;
	}

	private function storage_post_receipt( int $post_id ): ?array {
		$post = $this->storage->read_post( $post_id );
		return null === $post ? null : $this->post_row_receipt( $post );
	}

	private function current_post_receipt( int $post_id ): ?array {
		$rows = $this->operations->post_rows( array( $post_id ) );
		if ( empty( $rows ) ) { return null; }
		$receipt = $this->post_row_receipt( (object) $rows[0] );
		$receipt['meta']  = $this->normalize_post_meta( $this->operations->post_meta( $post_id ) );
		$receipt['terms'] = $this->normalize_post_terms( $this->operations->post_terms( $post_id ) );
		return $receipt;
	}

	private function post_row_receipt( object $post ): array {
		$row = (array) $post;
		$receipt = array(
			'ID'           => (int) ( $row['ID'] ?? 0 ),
			'post_name'    => (string) ( $row['post_name'] ?? '' ),
			'post_title'   => (string) ( $row['post_title'] ?? '' ),
			'post_content' => (string) ( $row['post_content'] ?? '' ),
			'post_status'  => (string) ( $row['post_status'] ?? '' ),
			'post_type'    => (string) ( $row['post_type'] ?? 'post' ),
			'post_parent'  => (int) ( $row['post_parent'] ?? 0 ),
		);
		if ( array_key_exists( '_frontmatter_meta', $row ) ) { $receipt['meta'] = WP_Markdown_Reconciliation_Identity::normalize( (array) $row['_frontmatter_meta'] ); }
		if ( array_key_exists( '_frontmatter_terms', $row ) ) { $receipt['terms'] = WP_Markdown_Reconciliation_Identity::normalize( (array) $row['_frontmatter_terms'] ); }
		return $receipt;
	}

	private function normalize_post_meta( array $rows ): array {
		$meta = array();
		foreach ( $rows as $row ) { $row = (array) $row; $meta[ (string) ( $row['meta_key'] ?? '' ) ][] = (string) ( $row['meta_value'] ?? '' ); }
		return WP_Markdown_Reconciliation_Identity::normalize( $meta );
	}

	private function normalize_post_terms( array $rows ): array {
		$terms = array();
		foreach ( $rows as $row ) { $row = (array) $row; $terms[ (string) ( $row['taxonomy'] ?? '' ) ][] = (string) ( $row['slug'] ?? '' ); }
		foreach ( $terms as &$slugs ) { sort( $slugs, SORT_STRING ); } unset( $slugs );
		return WP_Markdown_Reconciliation_Identity::normalize( $terms );
	}

	/**
	 * Persist a postmeta write.
	 *
	 * For meta belonging to markdown-type posts, rewrites the post's .md file
	 * (the meta is embedded in frontmatter). For non-markdown posts, dumps
	 * the remaining rows to postmeta.json.
	 *
	 * See GitHub issue #6.
	 *
	 * @param string $query   The SQL query.
	 * @param string $op_type INSERT, UPDATE, DELETE, REPLACE.
	 */
	private function persist_postmeta_write( array $resource_ids, string $op_type ): void {
		// Find which post IDs are affected.
		$post_ids = $this->operations->affected_post_ids( 'postmeta', $resource_ids, $op_type );

		// Queue each affected post for a single shutdown rewrite, so bulk
		// meta updates (ACF repeaters, Woo product attributes, 50-key
		// loops) collapse to one file write per post. See issue #21.
		foreach ( $post_ids as $post_id ) {
			$this->mark_post_dirty( (int) $post_id );
		}

		// Defer non-markdown postmeta JSON dump to shutdown.
		$this->mark_dirty( 'postmeta_non_markdown' );
	}

	/**
	 * Persist a terms-related write (term_relationships, term_taxonomy, terms).
	 *
	 * For relationships belonging to markdown-type posts, rewrites the post's
	 * .md file. For non-markdown posts, dumps to JSON.
	 *
	 * See GitHub issue #6.
	 *
	 * @param string $query        The SQL query.
	 * @param string $op_type      INSERT, UPDATE, DELETE, REPLACE.
	 * @param string $table_suffix Which terms table was written.
	 */
	private function persist_terms_write( array $resource_ids, string $op_type, string $table_suffix ): void {
		if ( $table_suffix === 'term_relationships' ) {
			// Queue affected posts for a single shutdown rewrite. Assigning
			// N categories + M tags in one request collapses to one file
			// write per post, not one per relationship. See issue #21.
			$post_ids = $this->operations->affected_post_ids( $table_suffix, $resource_ids, $op_type );
			foreach ( $post_ids as $post_id ) {
				$this->mark_post_dirty( (int) $post_id );
			}

			// Defer non-markdown term_relationships JSON dump to shutdown.
			$this->mark_dirty( 'term_relationships_non_markdown' );
		}

		// Defer terms and term_taxonomy table dumps to shutdown.
		if ( $table_suffix === 'terms' || $table_suffix === 'term_taxonomy' ) {
			$this->mark_dirty( $table_suffix );
		}
	}

	/**
	 * Persist a table to JSON, excluding rows that belong to markdown-type posts.
	 *
	 * Used for postmeta and term_relationships — those rows are embedded
	 * in the .md frontmatter instead.
	 *
	 * @param string $table_suffix  Table name without prefix.
	 * @param string $post_id_col   Column name that references the post ID.
	 */
	private function persist_table_excluding_markdown_posts( string $table_suffix, string $post_id_col ): void {
		try {
			$rows = $this->operations->table_rows( $table_suffix );
		} catch ( \Throwable $e ) {
			return;
		}

		// Build a set of markdown-type post IDs for fast lookup.
		$markdown_post_ids = $this->get_markdown_post_ids();

		$this->ensure_tables_dir();
		$this->write_json_rows(
			$this->state_dir . '/_tables/' . $table_suffix . '.json',
			( function () use ( $rows, $post_id_col, $markdown_post_ids ): iterable {
				foreach ( $rows as $row ) {
					$row = (array) $row;
					if ( ! isset( $markdown_post_ids[ (int) ( $row[ $post_id_col ] ?? 0 ) ] ) ) { yield $row; }
				}
			} )()
		);
	}

	/**
	 * Get a set of post IDs that belong to markdown-type post types.
	 *
	 * @return array<int, bool>
	 */
	private function get_markdown_post_ids(): array {
		$ids   = array();

		try {
			$rows = $this->operations->table_rows( 'posts' );
		} catch ( \Throwable $e ) {
			return $ids;
		}

		foreach ( $rows as $row ) {
			$row = (object) $row;
			$type = $row->post_type ?? 'post';
			if ( $this->storage->is_markdown_type( $type ) ) {
				$ids[ (int) $row->ID ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Persist posts that are excluded from markdown to the JSON fallback.
	 */
	private function persist_non_markdown_posts(): void {
		try {
			$rows = $this->operations->table_rows( 'posts' );
		} catch ( \Throwable $e ) {
			return;
		}

		$this->ensure_tables_dir();
		$this->write_json_rows(
			$this->state_dir . '/_tables/posts.json',
			( function () use ( $rows ): iterable {
				foreach ( $rows as $row ) {
					$row = (object) $row;
					if ( ! $this->storage->is_markdown_type( $row->post_type ?? 'post' ) ) { yield (array) $row; }
				}
			} )()
		);
	}

	/**
	 * Persist a full table to JSON.
	 *
	 * @param string $table_suffix Table name without prefix.
	 */
	private function persist_table( string $table_suffix ): void {
		if ( ! $this->should_persist_table( $table_suffix ) ) {
			return;
		}

		$policy = $this->table_persistence_policy_for( $table_suffix );
		try {
			$rows = $this->operations->table_rows( $table_suffix, is_array( $policy ) ? $policy : null );
		} catch ( \Throwable $e ) {
			error_log( "Markdown DB: Failed to read {$table_suffix} for persist: " . $e->getMessage() );
			return;
		}

		$path = $this->state_dir . '/_tables/' . $table_suffix . '.json';
		$this->ensure_tables_dir();

		if ( $this->has_persistent_table_row_filter() ) {
			$data = array();
			foreach ( $rows as $row ) { $data[] = (array) $row; }
			/**
			 * Filters rows before a JSON-backed table is written to disk.
			 *
			 * This lets site/storage config keep plugin/runtime tables compact without
			 * coupling those plugins to Markdown Database Integration.
			 *
			 * @param array       $data         Rows about to be written.
			 * @param string      $table_suffix Table name without WordPress prefix.
			 * @param string      $table        Full table name.
			 * @param array|bool|null $policy   Table persistence policy, if configured.
			 */
			$filtered = apply_filters( 'markdown_db_persistent_table_rows', $data, $table_suffix, $this->prefix() . $table_suffix, $policy );
			if ( is_array( $filtered ) ) {
				$data = array_values( $filtered );
			}
			$this->write_json( $path, $data );
			return;
		}

		$this->write_json_rows( $path, $rows );
	}

	/** Persist only affected rows for a table with a declared stable identity. */
	private function persist_partitioned_table( string $table_suffix, array $resource_ids ): void {
		$identity_column = $this->partition_identity_column( $table_suffix );
		if ( null === $identity_column ) { $this->persist_table( $table_suffix ); return; }
		$resource_ids = array_map( 'strval', $resource_ids );
		$directory = $this->state_dir . '/_tables/' . $table_suffix;
		$marker = $directory . '/.mdi-partition.json';
		$this->ensure_tables_dir();
		$lock = fopen( $this->partition_lock_path( $table_suffix ), 'c+' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) { throw new \RuntimeException( 'Markdown DB: Failed to lock table partition.' ); }
		try {
			if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) { throw new \RuntimeException( 'Markdown DB: Failed to create table partition directory.' ); }
			$policy = $this->table_persistence_policy_for( $table_suffix );
			if ( is_array( $policy ) ) { unset( $policy['resource_ids'] ); }
			$marker_data = json_decode( (string) @file_get_contents( $marker ), true );
			$active_generation = is_array( $marker_data ) ? (string) ( $marker_data['generation'] ?? '' ) : '';
			$full = '' === $active_generation || 1 !== count( $resource_ids ) || in_array( '*', $resource_ids, true ) || $this->has_persistent_table_row_filter() || isset( $policy['query'] ) || isset( $policy['limit'] );
			if ( ! $full ) { $policy = array_merge( is_array( $policy ) ? $policy : array(), array( 'partition_by' => $identity_column, 'resource_ids' => $resource_ids ) ); }
			$rows = $this->operations->table_rows( $table_suffix, is_array( $policy ) ? $policy : null );
			if ( $this->has_persistent_table_row_filter() ) {
				$data = array(); foreach ( $rows as $row ) { $data[] = (array) $row; }
				$filtered = apply_filters( 'markdown_db_persistent_table_rows', $data, $table_suffix, $this->prefix() . $table_suffix, $policy );
				$rows = is_array( $filtered ) ? array_values( $filtered ) : $data;
			}
			$generation = $full ? 'generation-' . bin2hex( random_bytes( 12 ) ) : $active_generation;
			$generation_directory = $directory . '/' . $generation;
			if ( ! is_dir( $generation_directory ) && ! mkdir( $generation_directory, 0755, true ) && ! is_dir( $generation_directory ) ) { throw new \RuntimeException( 'Markdown DB: Failed to create table partition generation.' ); }
			$seen = array();
			foreach ( $rows as $row ) {
				$data = (array) $row; $identity = isset( $data[ $identity_column ] ) ? (string) $data[ $identity_column ] : '';
				if ( '' === $identity ) { throw new \RuntimeException( 'Markdown DB: Partitioned row is missing its identity.' ); }
				$seen[ $identity ] = true;
				$this->write_json( $generation_directory . '/' . hash( 'sha256', $identity ) . '.json', array( '_mdi_partition' => array( 'version' => 1, 'identity_column' => $identity_column, 'identity' => $identity ), 'row' => $data ) );
			}
			$candidates = $full ? array() : array_combine( $resource_ids, array_map( static fn( $id ): string => $generation_directory . '/' . hash( 'sha256', (string) $id ) . '.json', $resource_ids ) );
			foreach ( $candidates as $candidate_identity => $path ) {
				$identity = (string) $candidate_identity;
				if ( ! isset( $seen[ $identity ] ) ) { $this->track_canonical_mutation( $path ); @unlink( $path ); }
			}
			if ( $full ) {
				$this->write_json( $marker, array( 'version' => 1, 'table' => $table_suffix, 'identity_column' => $identity_column, 'generation' => $generation ) );
				$this->remove_inactive_partition_generations( $directory, $generation );
			}
			@unlink( $this->state_dir . '/_tables/' . $table_suffix . '.json' );
		} finally {
			flock( $lock, LOCK_UN ); fclose( $lock );
		}
	}

	/** Whether an installed row filter requires the legacy complete-array contract. */
	private function has_persistent_table_row_filter(): bool {
		return ! function_exists( 'has_filter' ) || false !== has_filter( 'markdown_db_persistent_table_rows' );
	}

	/**
	 * Read the site-configured persistence policy for a table.
	 *
	 * Policies are keyed by unprefixed table name. Values may be `true`, `false`,
	 * or an array of site-defined options consumed by filters such as
	 * `markdown_db_persistent_table_rows`.
	 *
	 * @param string $table_suffix Table name without prefix.
	 * @return array|bool|null Configured policy, or null when unset.
	 */
	private function table_persistence_policy_for( string $table_suffix ): array|bool|null {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}

		$policy = apply_filters( 'markdown_db_table_persistence_policy', array() );
		if ( ! is_array( $policy ) || ! array_key_exists( $table_suffix, $policy ) ) {
			return null;
		}

		$table_policy = $policy[ $table_suffix ];
		return is_array( $table_policy ) || is_bool( $table_policy ) ? $table_policy : null;
	}

	private function partition_identity_column( string $table_suffix ): ?string {
		$policy = $this->table_persistence_policy_for( $table_suffix );
		$column = is_array( $policy ) ? strtolower( (string) ( $policy['partition_by'] ?? '' ) ) : '';
		return preg_match( '/^[a-z_][a-z0-9_]*$/', $column ) ? $column : null;
	}

	private function partition_lock_path( string $table_suffix ): string {
		$directory = sys_get_temp_dir() . '/markdown-database-integration-locks';
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) { throw new \RuntimeException( 'Markdown DB: Failed to create lock directory.' ); }
		return $directory . '/partition-' . hash( 'sha256', $this->state_dir . "\0" . $table_suffix ) . '.lock';
	}

	private function remove_inactive_partition_generations( string $directory, string $active_generation ): void {
		foreach ( glob( $directory . '/generation-*', GLOB_ONLYDIR ) ?: array() as $generation_directory ) {
			if ( basename( $generation_directory ) === $active_generation ) { continue; }
			foreach ( glob( $generation_directory . '/*.json' ) ?: array() as $path ) {
				$this->track_canonical_mutation( $path );
				@unlink( $path );
			}
			@rmdir( $generation_directory );
		}
	}

	/**
	 * Determine whether a table should be mirrored to disk.
	 *
	 * Existing behavior remains the default: core tables and plugin tables are
	 * persistent unless a site-level policy explicitly disables a table.
	 *
	 * @param string $table_suffix Table name without prefix.
	 * @return bool True when the table should be persisted.
	 */
	private function should_persist_table( string $table_suffix ): bool {
		$policy = $this->table_persistence_policy_for( $table_suffix );
		return false !== $policy;
	}

	/**
	 * Persist a schema change (CREATE TABLE, ALTER TABLE, DROP TABLE).
	 *
	 * For CREATE and ALTER, we snapshot the current table schema via
	 * SHOW CREATE TABLE — this gives us a single clean MySQL CREATE TABLE
	 * with all columns, indexes, and constraints as they are NOW. No more
	 * append logs of ALTER TABLE history. See issue #47.
	 *
	 * @param string $query    The DDL query.
	 * @param string $table    The affected table name.
	 * @param string $ddl_type CREATE, ALTER, or DROP.
	 */
	public function persist_schema( string $query, string $table, string $ddl_type ): void {
		if ( $this->writing ) {
			return;
		}
		$this->writing = true;

		try {
			$table_suffix = $this->strip_prefix( $table );
			if ( ! $this->should_persist_table( $table_suffix ) ) {
				return;
			}
			$schema_dir = $this->state_dir . '/_schema';

			if ( ! is_dir( $schema_dir ) ) {
				mkdir( $schema_dir, 0755, true );
			}

			$schema_path = $schema_dir . '/' . $table_suffix . '.sql';

			if ( 'DROP' === $ddl_type ) {
				// Remove schema and data files.
				@unlink( $schema_path );
				@unlink( $this->state_dir . '/_tables/' . $table_suffix . '.json' );
			} else {
				// CREATE or ALTER — snapshot the current table state.
				// SHOW CREATE TABLE returns a clean MySQL CREATE TABLE
				// with all columns, types, defaults, and indexes.
				$create_sql = $this->operations->persist_schema( $table_suffix, $ddl_type );
				if ( null !== $create_sql ) {
					file_put_contents(
						$schema_path,
						$create_sql . ";\n",
						LOCK_EX
					);
				}
			}
		} catch ( \Throwable $e ) {
			error_log( 'Markdown DB schema persist error: ' . $e->getMessage() );
		}

		$this->writing = false;
	}

	/**
	 * Check if an option name is ephemeral (should not be persisted).
	 *
	 * @param string   $name            Option name.
	 * @param string[] $ephemeral_names Exact ephemeral option names for this flush.
	 * @return bool
	 */
	private function is_ephemeral_option( string $name, array $ephemeral_names ): bool {
		if ( in_array( $name, $ephemeral_names, true ) ) {
			return true;
		}

		foreach ( self::EPHEMERAL_OPTION_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get exact option names that should not be persisted.
	 *
	 * Durable runtimes with an explicit background scheduler may remove `cron`
	 * from this list. Prefix-based transient exclusions remain unconditional.
	 *
	 * @return string[] Ephemeral option names.
	 */
	private function get_ephemeral_option_names(): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return self::EPHEMERAL_OPTION_NAMES;
		}

		/**
		 * Filters exact option names excluded from canonical persistence.
		 *
		 * @param string[] $names Exact ephemeral option names.
		 */
		$names = apply_filters( 'markdown_database_integration_ephemeral_option_names', self::EPHEMERAL_OPTION_NAMES );
		if ( ! is_array( $names ) ) {
			return self::EPHEMERAL_OPTION_NAMES;
		}

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || '' === $name ) {
				return self::EPHEMERAL_OPTION_NAMES;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Strip the table prefix from a table name.
	 *
	 * @param string $table Full table name.
	 * @return string Table name without prefix.
	 */
	private function strip_prefix( string $table ): string {
		$prefix = $this->prefix();
		if ( str_starts_with( $table, $prefix ) ) {
			return substr( $table, strlen( $prefix ) );
		}
		return $table;
	}

	/**
	 * Write a JSON file atomically.
	 *
	 * @param string $path File path.
	 * @param array  $data Data to encode.
	 */
	private function write_json( string $path, array $data ): void {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		$this->track_canonical_mutation( $path );
		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new \RuntimeException( 'Markdown DB: Failed to encode JSON file: ' . $path );
		}

		$tmp    = $this->json_tmp_path( $path );
		$handle = $this->open_json_temp_file( $tmp, $path );
		try {
			$this->write_json_contents( $handle, $json, $path );
			if ( ! fflush( $handle ) ) {
				throw new \RuntimeException( 'Markdown DB: Failed to flush JSON temp file: ' . $path );
			}
			clearstatcache( true, $tmp );
			$mtime = filemtime( $tmp );
			$size  = filesize( $tmp );
			if ( false === $mtime || false === $size ) {
				throw new \RuntimeException( 'Markdown DB: Failed to stat JSON file: ' . $path );
			}
			if ( ! @rename( $tmp, $path ) ) {
				throw new \RuntimeException( 'Markdown DB: Failed to rename JSON file: ' . $path );
			}
		} finally {
			fclose( $handle );
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
		}

		$this->track_canonical_write( $path, hash( 'sha256', $json ) );
		$this->update_json_manifest( $path, (int) $mtime, (int) $size );
		$this->cleanup_json_temp_files( $path );
	}

	/** Stream semantic backend rows to an atomic JSON snapshot. */
	private function write_json_rows( string $path, iterable $rows ): void {
		$this->track_canonical_mutation( $path );
		$tmp = $this->json_tmp_path( $path );
		$handle = $this->open_json_temp_file( $tmp, $path );
		$hash = hash_init( 'sha256' );
		try {
			$first = true;
			foreach ( $rows as $row ) {
				$json = json_encode( (array) $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( false === $json ) { throw new \RuntimeException( 'Markdown DB: Failed to encode table row: ' . $path ); }
				$this->write_json_chunk( $handle, $hash, ( $first ? "[\n" : ",\n" ) . '    ' . str_replace( "\n", "\n    ", $json ), $path );
				$first = false;
			}
			$this->write_json_chunk( $handle, $hash, $first ? '[]' : "\n]", $path );
			if ( ! fflush( $handle ) ) { throw new \RuntimeException( 'Markdown DB: Failed to flush JSON temp file: ' . $path ); }
			clearstatcache( true, $tmp ); $mtime = filemtime( $tmp ); $size = filesize( $tmp );
			if ( false === $mtime || false === $size || ! @rename( $tmp, $path ) ) { throw new \RuntimeException( 'Markdown DB: Failed to replace JSON snapshot: ' . $path ); }
			$this->track_canonical_write( $path, hash_final( $hash ) );
			$this->update_json_manifest( $path, (int) $mtime, (int) $size );
		} finally { fclose( $handle ); if ( file_exists( $tmp ) ) { @unlink( $tmp ); } }
		$this->cleanup_json_temp_files( $path );
	}

	/** @param resource $handle @param resource $hash */
	private function write_json_chunk( $handle, $hash, string $chunk, string $path ): void {
		$offset = 0;
		$length = strlen( $chunk );
		while ( $offset < $length ) {
			$written = fwrite( $handle, substr( $chunk, $offset ) );
			if ( false === $written || 0 === $written ) {
				throw new \RuntimeException( 'Markdown DB: Failed to write JSON file: ' . $path );
			}
			$offset += $written;
		}
		hash_update( $hash, $chunk );
	}

	/** @param resource $handle */
	private function write_json_contents( $handle, string $contents, string $path ): void {
		$offset = 0;
		$length = strlen( $contents );
		while ( $offset < $length ) {
			$written = fwrite( $handle, substr( $contents, $offset ) );
			if ( false === $written || 0 === $written ) {
				throw new \RuntimeException( 'Markdown DB: Failed to write JSON file: ' . $path );
			}
			$offset += $written;
		}
	}

	/**
	 * Record an atomically replaced table snapshot in the warm-sync manifest.
	 *
	 * A manifest write can only follow a successful rename. If the manifest is
	 * unavailable, retaining its previous entry is safe: the next warm sync
	 * treats the snapshot as changed instead of trusting an unrecorded write.
	 *
	 * @param string $path  Replaced JSON file path.
	 * @param int    $mtime Mtime of the successfully written temp snapshot.
	 * @param int    $size  Size of the successfully written temp snapshot.
	 */
	private function update_json_manifest( string $path, int $mtime, int $size ): void {
		$tables_dir = rtrim( $this->state_dir, '/' ) . '/_tables';
		if ( dirname( $path ) !== $tables_dir ) {
			return;
		}

		try {
			$this->operations->update_manifest( '_tables/' . basename( $path ), $mtime, $size );
		} catch ( \Throwable $e ) {
			// Isolated and non-primary callers may not have the manifest table.
		}
	}

	/**
	 * Build a unique temp path for an atomic JSON write.
	 *
	 * @param string $path Destination file path.
	 * @return string Temp file path in the same directory as the destination.
	 */
	private function json_tmp_path( string $path ): string {
		try {
			$suffix = bin2hex( random_bytes( 4 ) );
		} catch ( \Throwable $e ) {
			$suffix = substr( md5( uniqid( '', true ) ), 0, 8 );
		}

		return $path . '.tmp.' . getmypid() . '.' . $suffix;
	}

	/**
	 * Reclaim interrupted atomic JSON writes without touching live writers.
	 *
	 * A temp file is eligible only when it has MDI's exact destination-derived
	 * name, exceeds the configured age, and is not exclusively locked by a
	 * writer. The matched-candidate inspection window rotates between scans.
	 *
	 * @param string $path Canonical JSON destination.
	 */
	private function cleanup_json_temp_files( string $path ): void {
		$max_age = 300;
		$limit   = 100;
		if ( function_exists( 'apply_filters' ) ) {
			$max_age = apply_filters( 'markdown_database_integration_json_temp_cleanup_max_age', $max_age, $path );
			$limit   = apply_filters( 'markdown_database_integration_json_temp_cleanup_limit', $limit, $path );
		}
		if ( ! is_int( $max_age ) || $max_age < 0 ) {
			$max_age = 300;
		}
		if ( ! is_int( $limit ) || $limit < 1 ) {
			$limit = 100;
		}

		$pattern = '/\A' . preg_quote( basename( $path ), '/' ) . '\\.tmp\\.([1-9][0-9]*)\\.([a-f0-9]{8})\z/';
		$count   = $this->count_json_temp_candidates( dirname( $path ), $pattern );
		if ( 0 === $count ) {
			return;
		}

		$offset = $this->json_temp_cleanup_offsets[ $path ] ?? 0;
		$offset %= $count;
		$this->json_temp_cleanup_offsets[ $path ] = ( $offset + $limit ) % $count;
		$directory = dirname( $path );
		$handle    = @opendir( $directory );
		if ( false === $handle ) {
			error_log( 'Markdown DB: Failed to scan JSON temp files: ' . $directory );
			return;
		}

		$matched = 0;
		$checked = 0;
		try {
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ! preg_match( $pattern, $entry, $matches ) ) {
					continue;
				}
				if ( $checked >= $limit || ( ( $matched - $offset + $count ) % $count ) >= $limit ) {
					$matched++;
					continue;
				}
				$matched++;
				$checked++;
				$temp  = $directory . '/' . $entry;
				$temp_handle = @fopen( $temp, 'rb' );
				if ( false === $temp_handle || ! @flock( $temp_handle, LOCK_EX | LOCK_NB ) ) {
					if ( is_resource( $temp_handle ) ) {
						fclose( $temp_handle );
					}
					continue;
				}
				$stat = fstat( $temp_handle );
				if ( ! is_array( $stat ) || $stat['mtime'] > time() - $max_age ) {
					flock( $temp_handle, LOCK_UN );
					fclose( $temp_handle );
					continue;
				}
				if ( ! $this->remove_json_temp_file( $temp ) ) {
					error_log( 'Markdown DB: Failed to reclaim stale JSON temp file: ' . $temp );
				}
				flock( $temp_handle, LOCK_UN );
				fclose( $temp_handle );
			}
		} finally {
			closedir( $handle );
		}
	}

	/**
	 * Create a temp file and keep its exclusive advisory lock through rename.
	 *
	 * @return resource
	 */
	private function open_json_temp_file( string $tmp, string $path ) {
		$handle = @fopen( $tmp, 'xb' );
		if ( false === $handle || ! @flock( $handle, LOCK_EX ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
				@unlink( $tmp );
			}
			throw new \RuntimeException( 'Markdown DB: Failed to create locked JSON temp file: ' . $path );
		}
		return $handle;
	}

	/** Count strict temp-file candidates without consuming the cleanup budget. */
	private function count_json_temp_candidates( string $directory, string $pattern ): int {
		$handle = @opendir( $directory );
		if ( false === $handle ) {
			error_log( 'Markdown DB: Failed to scan JSON temp files: ' . $directory );
			return 0;
		}
		$count = 0;
		try {
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( preg_match( $pattern, $entry ) ) {
					$count++;
				}
			}
		} finally {
			closedir( $handle );
		}
		return $count;
	}

	/** @param string $path Temp file to remove. */
	protected function remove_json_temp_file( string $path ): bool {
		return @unlink( $path );
	}

	/**
	 * Ensure the _tables directory exists.
	 */
	private function ensure_tables_dir(): void {
		$dir = $this->state_dir . '/_tables';
		if ( ! is_dir( $dir ) ) {
			if ( ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				throw new \RuntimeException( 'Markdown DB: Failed to create _tables directory.' );
			}
		}
	}

}
