<?php
/**
 * Write Engine — Persists in-memory SQLite writes to markdown/JSON files.
 *
 * Every INSERT, UPDATE, DELETE, REPLACE that hits the in-memory SQLite
 * is also persisted to files on disk. This makes markdown files the
 * source of truth.
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

class WP_Markdown_Write_Engine {

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
	 * The driver for reading back data.
	 *
	 * @var WP_SQLite_Driver
	 */
	private $driver;

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
	private $dirty_option_names = array();

	/**
	 * When true, persist_options() falls back to persisting every non-
	 * ephemeral option currently in SQLite. Set when query parsing fails
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
	 * Canonical file hashes at the previous successful flush boundary.
	 *
	 * @var array<string, string>
	 */
	private $canonical_files = array();

	/**
	 * Constructor.
	 *
	 * @param string                $content_dir     Content directory.
	 * @param WP_Markdown_Storage   $storage         Markdown storage for posts.
	 * @param WP_SQLite_Driver      $driver          SQLite driver.
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
		WP_SQLite_Driver $driver,
		$prefix_resolver = 'wp_',
		?string $state_dir = null
	) {
		$this->content_dir     = rtrim( $content_dir, '/' );
		$this->state_dir       = rtrim( $state_dir ?? $content_dir, '/' );
		$this->storage         = $storage;
		$this->driver          = $driver;
		$this->prefix_resolver = is_callable( $prefix_resolver )
			? $prefix_resolver
			: static function () use ( $prefix_resolver ): string { return (string) $prefix_resolver; };
		$this->canonical_files = $this->canonical_file_hashes();
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
	 * Called by WP_Markdown_Driver after a successful write query.
	 * Markdown-type post writes are immediate (one file per post).
	 * Everything else is deferred to shutdown via dirty flags.
	 *
	 * @param string $query   The MySQL query.
	 * @param string $table   The affected table name.
	 * @param string $op_type The operation: INSERT, UPDATE, DELETE, REPLACE.
	 */
	public function persist_write( string $query, string $table, string $op_type ): void {
		if ( $this->writing ) {
			return;
		}
		$this->writing = true;

		try {
			$table_suffix = $this->strip_prefix( $table );
			if ( ! $this->should_persist_table( $table_suffix ) ) {
				return;
			}

			if ( $table_suffix === 'posts' ) {
				$this->persist_post_write( $query, $op_type );
			} elseif ( $table_suffix === 'postmeta' ) {
				$this->persist_postmeta_write( $query, $op_type );
			} elseif ( in_array( $table_suffix, array( 'term_relationships', 'term_taxonomy', 'terms' ), true ) ) {
				$this->persist_terms_write( $query, $op_type, $table_suffix );
			} elseif ( $table_suffix === 'options' ) {
				// Defer to shutdown. Track which specific option_name(s) were
				// touched so we can write only the changed files — per-option
				// persistence eliminates whole-file races. See issue #55.
				$this->mark_dirty( 'options' );
				$this->track_options_change( $query );
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
		if ( empty( $this->dirty ) && empty( $this->dirty_posts ) ) {
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
			$this->dirty               = array();
			$this->dirty_option_names  = array();
			$this->dirty_options_all   = false;
			$after                     = $this->canonical_file_hashes();
			$changes                   = $this->canonical_changes( $this->canonical_files, $after );
			$this->canonical_files     = $after;
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

	/** @return array<string, string> Canonical relative path to SHA-256 hash. */
	private function canonical_file_hashes(): array {
		$files = array();
		$roots = array_values( array_unique( array( rtrim( $this->content_dir, '/' ), rtrim( $this->state_dir, '/' ) ) ) );
		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || ( ! str_ends_with( $file->getFilename(), '.md' ) && ! str_ends_with( $file->getFilename(), '.json' ) ) ) {
					continue;
				}
				$path           = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
				$files[ $path ] = (string) hash_file( 'sha256', $file->getPathname() );
			}
		}
		ksort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * @param array<string, string> $before Previous canonical file hashes.
	 * @param array<string, string> $after  Current canonical file hashes.
	 * @return array{created:string[],changed:string[],deleted:string[]}
	 */
	private function canonical_changes( array $before, array $after ): array {
		$created = array_keys( array_diff_key( $after, $before ) );
		$deleted = array_keys( array_diff_key( $before, $after ) );
		$changed = array();
		foreach ( array_intersect_key( $after, $before ) as $path => $hash ) {
			if ( $hash !== $before[ $path ] ) {
				$changed[] = $path;
			}
		}
		sort( $created, SORT_STRING );
		sort( $changed, SORT_STRING );
		sort( $deleted, SORT_STRING );
		return array( 'created' => $created, 'changed' => $changed, 'deleted' => $deleted );
	}

	/**
	 * Extract the option_name(s) touched by an options-table query and record
	 * them in $dirty_option_names.
	 *
	 * If the query shape cannot be parsed, set $dirty_options_all so
	 * persist_options() falls back to persisting every non-ephemeral option
	 * currently in SQLite — better to over-write than silently lose a change.
	 *
	 * Supported shapes (all WordPress-generated):
	 *   INSERT INTO `...options` (`option_name`, `option_value`, `autoload`) VALUES ('name', ...)
	 *   REPLACE INTO `...options` (`option_name`, ...) VALUES ('name', ...)
	 *   INSERT ... ON DUPLICATE KEY UPDATE ... (MySQL upsert — option_name in VALUES list)
	 *   UPDATE `...options` SET ... WHERE `option_name` = 'name'
	 *   DELETE FROM `...options` WHERE `option_name` = 'name'
	 *
	 * @since 0.4.0
	 *
	 * @param string $query The SQL query string.
	 */
	private function track_options_change( string $query ): void {
		// INSERT / REPLACE: option_name appears as the first VALUES entry
		// when the column list starts with option_name. WordPress always
		// emits the column list in this order.
		if ( preg_match( '/^\s*(?:INSERT|REPLACE)\b/i', $query ) ) {
			if ( preg_match( '/VALUES\s*\(\s*\'((?:\\\\\'|[^\'])*)\'/i', $query, $m ) ) {
				$this->dirty_option_names[ $this->unslash_sql_string( $m[1] ) ] = true;
				return;
			}
			$this->dirty_options_all = true;
			return;
		}

		// UPDATE / DELETE: option_name appears in WHERE clause.
		if ( preg_match( '/^\s*(?:UPDATE|DELETE)\b/i', $query ) ) {
			if ( preg_match( '/WHERE\s+`?option_name`?\s*=\s*\'((?:\\\\\'|[^\'])*)\'/i', $query, $m ) ) {
				$this->dirty_option_names[ $this->unslash_sql_string( $m[1] ) ] = true;
				return;
			}
			$this->dirty_options_all = true;
			return;
		}

		// Unknown shape — safe fallback.
		$this->dirty_options_all = true;
	}

	/**
	 * Reverse SQL-escape sequences in an extracted string literal.
	 *
	 * @since 0.4.0
	 *
	 * @param string $raw String captured from inside single quotes.
	 * @return string
	 */
	private function unslash_sql_string( string $raw ): string {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $raw );
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
	 *   - Row exists in SQLite → write/overwrite the file, update index.
	 *   - Row missing in SQLite → option was deleted → remove file + index row.
	 *
	 * Fallback: if $dirty_options_all is set (query parsing failed), persist
	 * every non-ephemeral option currently in SQLite.
	 *
	 * Ephemerals (transients, cron locks) are filtered here — they never
	 * hit disk.
	 *
	 * @since 0.4.0 Rewritten as per-file persistence.
	 */
	private function persist_options(): void {
		$names = $this->dirty_options_all
			? $this->list_all_non_ephemeral_option_names()
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

		// Read existing SQLite rows for the dirty names in one query.
		$rows_by_name = $this->fetch_options_by_names( $names );

		// Track which files we wrote/deleted so the driver can update the
		// _options_file_index in one batch.
		$index_updates = array();
		$index_deletes = array();

		foreach ( $names as $name ) {
			if ( $this->is_ephemeral_option( $name ) ) {
				// Ephemerals never hit disk. If one was previously persisted
				// (legacy migration edge case), remove its file.
				$this->delete_option_file( $name, $index_deletes );
				continue;
			}

			if ( ! isset( $rows_by_name[ $name ] ) ) {
				// Not in SQLite → was deleted in-request.
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
		if ( $this->driver instanceof WP_Markdown_Driver ) {
			if ( ! empty( $index_updates ) ) {
				$this->driver->upsert_options_index( $index_updates );
			}
			if ( ! empty( $index_deletes ) ) {
				$this->driver->remove_from_options_index( $index_deletes );
			}
		}
	}

	/**
	 * Fetch current SQLite rows for the given option names, keyed by name.
	 *
	 * Uses a single SELECT with an IN clause for efficiency. Names are
	 * escaped inline (option names are already WP-sanitized; we quote
	 * defensively).
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

		$table   = $this->prefix() . 'options';
		$escaped = array();
		foreach ( $names as $name ) {
			$escaped[] = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $name ) . "'";
		}
		$in_list = implode( ',', $escaped );

		try {
			$rows = $this->driver->query(
				"SELECT option_id, option_name, option_value, autoload
				 FROM `{$table}` WHERE option_name IN ({$in_list})"
			);
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'Markdown DB: Failed to read dirty options: ' . $e->getMessage(), 0, $e );
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row->option_name ] = array(
				'option_id'    => (int) $row->option_id,
				'option_name'  => $row->option_name,
				'option_value' => $row->option_value,
				'autoload'     => $row->autoload,
			);
		}
		return $out;
	}

	/**
	 * List every non-ephemeral option_name currently in SQLite.
	 *
	 * Used as a fallback when query parsing failed. O(n) but only runs
	 * in the rare fallback path.
	 *
	 * @since 0.4.0
	 *
	 * @return string[]
	 */
	private function list_all_non_ephemeral_option_names(): array {
		$table = $this->prefix() . 'options';

		try {
			$rows = $this->driver->query(
				"SELECT option_name FROM `{$table}` ORDER BY option_id"
			);
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'Markdown DB: Failed to list options: ' . $e->getMessage(), 0, $e );
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$names = array();
		foreach ( $rows as $row ) {
			if ( ! $this->is_ephemeral_option( $row->option_name ) ) {
				$names[] = $row->option_name;
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

		$tmp = $abs . '.tmp.' . getmypid() . '.' . substr( md5( uniqid( '', true ) ), 0, 8 );
		if ( false === @file_put_contents( $tmp, $json ) ) {
			throw new \RuntimeException( 'Markdown DB: Failed to write option file: ' . $abs );
		}

		if ( ! @rename( $tmp, $abs ) ) {
			@unlink( $tmp );
			throw new \RuntimeException( 'Markdown DB: Failed to rename option file: ' . $abs );
		}

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
	private function persist_post_write( string $query, string $op_type ): void {
		if ( 'DELETE' === $op_type ) {
			// Deletes must fire immediately — a queued rewrite for the same
			// ID in this request would try to re-persist a vanished post.
			$ids = $this->extract_ids_from_query( $query, 'ID' );
			foreach ( $ids as $id ) {
				$this->storage->delete_post( $id );
				if ( $this->driver instanceof WP_Markdown_Driver ) {
					$this->driver->remove_from_file_index( $id );
				}
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
			$id = $this->driver->get_insert_id();
			if ( $id ) {
				$this->mark_post_dirty( (int) $id );
			}
		} elseif ( 'UPDATE' === $op_type ) {
			$ids = $this->extract_ids_from_query( $query, 'ID' );
			foreach ( $ids as $id ) {
				$this->mark_post_dirty( (int) $id );
			}
		}
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
		$table = $this->prefix() . 'posts';

		try {
			$rows = $this->driver->query(
				"SELECT * FROM `{$table}` WHERE ID = {$post_id}"
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return false;
		}

		$row = $rows[0];
		$post_type = $row->post_type ?? 'post';

		if ( ! $this->storage->is_markdown_type( $post_type ) ) {
			return true; // Non-markdown — caller should update JSON fallback.
		}

		$file_path = $this->storage->write_post( $row );

		// Update the file index after writing the .md file.
		// The driver uses this index for lazy-loading content on demand.
		if ( false !== $file_path && $post_id > 0 && $this->driver instanceof WP_Markdown_Driver ) {
			$content_dir = $this->storage->get_content_dir();
			$relative_path = $file_path;
			if ( str_starts_with( $file_path, $content_dir . '/' ) ) {
				$relative_path = substr( $file_path, strlen( $content_dir ) + 1 );
			}
			$this->driver->update_file_index(
				$post_id,
				$relative_path,
				(int) filemtime( $file_path ),
				(int) filesize( $file_path )
			);
		}

		return false;
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
	private function persist_postmeta_write( string $query, string $op_type ): void {
		// Find which post IDs are affected.
		$post_ids = $this->extract_post_ids_from_meta_query( $query, $op_type );

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
	private function persist_terms_write( string $query, string $op_type, string $table_suffix ): void {
		if ( $table_suffix === 'term_relationships' ) {
			// Queue affected posts for a single shutdown rewrite. Assigning
			// N categories + M tags in one request collapses to one file
			// write per post, not one per relationship. See issue #21.
			$post_ids = $this->extract_ids_from_query( $query, 'object_id' );
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
		$table      = $this->prefix() . $table_suffix;
		$posts_table = $this->prefix() . 'posts';

		try {
			// Get all rows from the table.
			$rows = $this->driver->query(
				"SELECT * FROM `{$table}` ORDER BY 1"
			);
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! is_array( $rows ) ) {
			return;
		}

		// Build a set of markdown-type post IDs for fast lookup.
		$markdown_post_ids = $this->get_markdown_post_ids();

		$data = array();
		foreach ( $rows as $row ) {
			$row_array = (array) $row;
			$pid = (int) ( $row_array[ $post_id_col ] ?? 0 );

			// Skip rows belonging to markdown-type posts (they're in frontmatter).
			if ( isset( $markdown_post_ids[ $pid ] ) ) {
				continue;
			}

			$data[] = $row_array;
		}

		$this->ensure_tables_dir();
		$this->write_json( $this->state_dir . '/_tables/' . $table_suffix . '.json', $data );
	}

	/**
	 * Get a set of post IDs that belong to markdown-type post types.
	 *
	 * @return array<int, bool>
	 */
	private function get_markdown_post_ids(): array {
		$table = $this->prefix() . 'posts';
		$ids   = array();

		try {
			$rows = $this->driver->query(
				"SELECT ID, post_type FROM `{$table}`"
			);
		} catch ( \Throwable $e ) {
			return $ids;
		}

		if ( ! is_array( $rows ) ) {
			return $ids;
		}

		foreach ( $rows as $row ) {
			$type = $row->post_type ?? 'post';
			if ( $this->storage->is_markdown_type( $type ) ) {
				$ids[ (int) $row->ID ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Extract post IDs from a postmeta query.
	 *
	 * @param string $query The SQL query.
	 * @return int[]
	 */
	private function extract_post_ids_from_meta_query( string $query, string $op_type = '' ): array {
		$ids = $this->extract_ids_from_query( $query, 'post_id' );

		// INSERT queries into wp_postmeta have no WHERE clause — the
		// post_id lives in the VALUES list, which is awkward to parse
		// reliably in raw SQL. Cheaper and more robust: look up the
		// freshly-inserted row by the auto-assigned meta_id.
		if ( empty( $ids ) && ( 'INSERT' === $op_type || 'REPLACE' === $op_type ) ) {
			$meta_id = (int) $this->driver->get_insert_id();
			if ( $meta_id > 0 ) {
				$meta_table = $this->prefix() . 'postmeta';
				try {
					$rows = $this->driver->query(
						"SELECT post_id FROM `{$meta_table}` WHERE meta_id = {$meta_id}"
					);
					if ( is_array( $rows ) && ! empty( $rows ) ) {
						$post_id = (int) ( $rows[0]->post_id ?? 0 );
						if ( $post_id > 0 ) {
							$ids[] = $post_id;
						}
					}
				} catch ( \Throwable $e ) {
					// No recovery — a failed lookup means this INSERT
					// won't drive a file rewrite, but the DB write itself
					// already succeeded. Next warm boot will rebuild.
				}
			}
		}

		return array_unique( array_filter( $ids ) );
	}

	/**
	 * Persist posts that are excluded from markdown to the JSON fallback.
	 */
	private function persist_non_markdown_posts(): void {
		$table = $this->prefix() . 'posts';

		try {
			$rows = $this->driver->query(
				"SELECT * FROM `{$table}` ORDER BY ID"
			);
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! is_array( $rows ) ) {
			return;
		}

		$non_markdown = array();
		foreach ( $rows as $row ) {
			$type = $row->post_type ?? 'post';
			if ( ! $this->storage->is_markdown_type( $type ) ) {
				$non_markdown[] = (array) $row;
			}
		}

		$this->ensure_tables_dir();
		$this->write_json( $this->state_dir . '/_tables/posts.json', $non_markdown );
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

		$table = $this->prefix() . $table_suffix;

		try {
			$rows = $this->driver->query(
				"SELECT * FROM `{$table}` ORDER BY 1"
			);
		} catch ( \Throwable $e ) {
			error_log( "Markdown DB: Failed to read {$table} for persist: " . $e->getMessage() );
			return;
		}

		if ( ! is_array( $rows ) ) {
			return;
		}

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = (array) $row;
		}

		$policy = $this->table_persistence_policy_for( $table_suffix );
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters rows before a JSON-backed table is written to disk.
			 *
			 * This lets site/storage config keep plugin/runtime tables compact without
			 * coupling those plugins to Markdown Database Integration.
			 *
			 * @param array       $data         Rows about to be written.
			 * @param string      $table_suffix Table name without WordPress prefix.
			 * @param string      $table        Full table name.
			 * @param array|null  $policy       Table persistence policy, if configured.
			 */
			$filtered = apply_filters( 'markdown_db_persistent_table_rows', $data, $table_suffix, $table, $policy );
			if ( is_array( $filtered ) ) {
				$data = array_values( $filtered );
			}
		}

		$this->ensure_tables_dir();
		$this->write_json( $this->state_dir . '/_tables/' . $table_suffix . '.json', $data );
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
				$create_sql = $this->get_create_table_sql( $table );
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
	 * Get the CREATE TABLE statement for a table via SHOW CREATE TABLE.
	 *
	 * The SQLite driver's MySQL translator supports this and returns
	 * clean MySQL DDL from the information schema.
	 *
	 * @param string $table The full table name.
	 * @return string|null The CREATE TABLE SQL, or null on failure.
	 */
	private function get_create_table_sql( string $table ): ?string {
		try {
			$rows = $this->driver->query(
				"SHOW CREATE TABLE `{$table}`"
			);
			if ( is_array( $rows ) && ! empty( $rows ) ) {
				$row = $rows[0];
				// The column name varies: "Create Table" or "create table".
				return $row->{'Create Table'} ?? $row->{'create table'} ?? null;
			}
		} catch ( \Throwable $e ) {
			// Table might not exist yet (race on CREATE TABLE).
			error_log( "Markdown DB: SHOW CREATE TABLE failed for {$table}: " . $e->getMessage() );
		}
		return null;
	}

	/**
	 * Check if an option name is ephemeral (should not be persisted).
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private function is_ephemeral_option( string $name ): bool {
		if ( in_array( $name, self::EPHEMERAL_OPTION_NAMES, true ) ) {
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
	 * Extract IDs from a WHERE clause.
	 *
	 * @param string $query  The SQL query.
	 * @param string $column The ID column name.
	 * @return int[]
	 */
	private function extract_ids_from_query( string $query, string $column ): array {
		$ids = array();

		if ( preg_match( '/WHERE\s+.*?`?' . preg_quote( $column, '/' ) . '`?\s*=\s*(\d+)/i', $query, $m ) ) {
			$ids[] = (int) $m[1];
		}

		if ( preg_match( '/WHERE\s+.*?`?' . preg_quote( $column, '/' ) . '`?\s+IN\s*\(([^)]+)\)/i', $query, $m ) ) {
			$in_ids = array_map( 'intval', explode( ',', $m[1] ) );
			$ids = array_merge( $ids, array_filter( $in_ids ) );
		}

		return array_unique( array_filter( $ids ) );
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

		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new \RuntimeException( 'Markdown DB: Failed to encode JSON file: ' . $path );
		}

		$tmp = $this->json_tmp_path( $path );
		if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
			throw new \RuntimeException( 'Markdown DB: Failed to write JSON file: ' . $path );
		}

		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			throw new \RuntimeException( 'Markdown DB: Failed to rename JSON file: ' . $path );
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
