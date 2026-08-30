<?php
/**
 * SQLite runtime adapter.
 *
 * Extends the canonical MySQL-on-SQLite PDO driver to persist all writes to
 * markdown/JSON files.
 * In Phase 2 ('primary' mode), the in-memory SQLite is the query engine
 * and markdown files on disk are the source of truth.
 *
 * ALL table writes (core and plugin) are persisted to disk. Tables that
 * are ephemeral (session tokens, object caches) can be excluded via
 * the MARKDOWN_DB_EPHEMERAL_TABLES constant or the
 * 'markdown_db_ephemeral_tables' filter.
 *
 * Ref: GitHub issue #17
 *
 * @package Markdown_Database_Integration
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../class-wp-markdown-backend-capabilities.php';
require_once __DIR__ . '/../class-wp-markdown-sql-classifier.php';
require_once __DIR__ . '/class-wp-markdown-sqlite-operations.php';

// Plugin and db.php drop-in updates are not atomic. Preserve the canonical PDO
// API when a previous drop-in loads this driver against the pre-rename class.
if ( ! class_exists( 'WP_MySQL_On_SQLite' ) && class_exists( 'WP_PDO_MySQL_On_SQLite' ) ) {
	if ( ! defined( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API' ) ) {
		define( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API', true );
	}
	class_alias( 'WP_PDO_MySQL_On_SQLite', 'WP_MySQL_On_SQLite' );
}

class WP_Markdown_SQLite_Runtime_Adapter extends WP_MySQL_On_SQLite {
	private const POST_MUTATION_BUSY_RETRIES = 2;

	/** @var string|null Canonical hydration scope owned by this adapter. */
	private $canonical_transaction_scope = null;

	/** Build the complete SQLite runtime from path-level boot inputs. */
	public static function create_runtime(
		string $path,
		?\PDO $pdo,
		string $database,
		WP_Markdown_Storage $storage,
		?WP_Markdown_Backend_Capabilities $capabilities = null,
		bool $bounded_warm_boot = false
	): self {
		if ( $bounded_warm_boot && null === $pdo ) {
			$uri = 'file:' . str_replace( '%2F', '/', rawurlencode( $path ) ) . '?mode=rw';
			$pdo = new \PDO( 'sqlite:' . $uri, null, null, array( \PDO::ATTR_TIMEOUT => 0 ) );
			$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
			$pdo->exec( 'PRAGMA busy_timeout = 0' );
			$pdo->exec( 'PRAGMA query_only = ON' );
			$pdo->query( 'SELECT 1 FROM sqlite_schema LIMIT 1' )->fetchColumn();
		}
		$connection = new WP_SQLite_Connection(
			array(
				'pdo'          => $pdo,
				'path'         => $path,
				'journal_mode' => $bounded_warm_boot ? null : ( defined( 'SQLITE_JOURNAL_MODE' ) ? SQLITE_JOURNAL_MODE : null ),
			)
		);
		return new self( $connection, $database, $storage, $capabilities );
	}

	public function operations( $prefix = 'wp_' ): WP_Markdown_SQLite_Operations {
		return new WP_Markdown_SQLite_Operations( $this, $prefix );
	}

	public function checkpoint(): void {
		$this->get_connection()->get_pdo()->exec( 'PRAGMA wal_checkpoint(TRUNCATE)' );
	}

	/**
	 * The markdown storage engine.
	 *
	 * @var WP_Markdown_Storage
	 */
	private $storage;

	/**
	 * The write engine for persisting changes.
	 *
	 * @var WP_Markdown_Write_Engine|null
	 */
	private $write_engine = null;

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	private $table_prefix;

	/**
	 * Whether we're in the middle of a sync (prevents recursion).
	 *
	 * @var bool
	 */
	private $syncing = false;

	/**
	 * Core WordPress table suffixes.
	 *
	 * Used to distinguish core tables (whose schemas are hardcoded in the
	 * loader) from plugin tables (whose schemas are persisted to _schema/).
	 *
	 * @var string[]
	 */
	private const CORE_TABLE_SUFFIXES = array(
		'options', 'users', 'usermeta', 'posts', 'postmeta',
		'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
		'comments', 'commentmeta', 'links',
	);

	/**
	 * Tables that should NOT be persisted to disk.
	 * Built once in the constructor from config + filter.
	 *
	 * @var array<string, bool>
	 */
	private $ephemeral_tables = array();

	/**
	 * Last materialized result for the released SQLite wpdb facade.
	 *
	 * @var mixed
	 */
	private $last_legacy_result = null;

	/** @var WP_Markdown_Backend_Capabilities */
	private $backend_capabilities;

	/**
	 * Constructor.
	 *
	 * @param WP_SQLite_Connection $connection The SQLite connection.
	 * @param string               $database   The database name.
	 * @param WP_Markdown_Storage  $storage    The markdown storage engine.
	 */
	public function __construct(
		WP_SQLite_Connection $connection,
		string $database,
		WP_Markdown_Storage $storage,
		?WP_Markdown_Backend_Capabilities $backend_capabilities = null
	) {
		parent::__construct(
			sprintf( 'mysql-on-sqlite:dbname=%s', $database ),
			null,
			null,
			array(
				'pdo'          => $connection->get_pdo(),
				'journal_mode' => $connection->get_pdo()->query( 'PRAGMA journal_mode' )->fetchColumn(),
			)
		);
		$connection->get_pdo()->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );

		$this->storage = $storage;
		$this->backend_capabilities = WP_Markdown_Backend_Resolver::resolve( $backend_capabilities );

		global $table_prefix;
		$this->table_prefix = $table_prefix ?? 'wp_';

		// Build the ephemeral tables list from config.
		$this->build_ephemeral_tables();
	}

	/**
	 * Build the set of tables that should NOT be persisted.
	 *
	 * Sources:
	 *   1. MARKDOWN_DB_EPHEMERAL_TABLES constant (comma-separated suffixes)
	 *   2. 'markdown_db_ephemeral_tables' filter (array of full table names)
	 */
	private function build_ephemeral_tables(): void {
		$ephemeral = array();

		// From constant: comma-separated table suffixes.
		if ( defined( 'MARKDOWN_DB_EPHEMERAL_TABLES' ) ) {
			$suffixes = array_filter( array_map( 'trim', explode( ',', MARKDOWN_DB_EPHEMERAL_TABLES ) ) );
			foreach ( $suffixes as $suffix ) {
				$ephemeral[ $this->table_prefix . $suffix ] = true;
			}
		}

		// From filter (if WordPress hooks are available at this point).
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'markdown_db_ephemeral_tables', array_keys( $ephemeral ) );
			$ephemeral = array();
			foreach ( $filtered as $table ) {
				$ephemeral[ $table ] = true;
			}
		}

		$this->ephemeral_tables = $ephemeral;
	}

	/**
	 * Set the write engine. Called after construction in db_connect().
	 *
	 * @param WP_Markdown_Write_Engine $engine The write engine.
	 */
	public function set_write_engine( WP_Markdown_Write_Engine $engine, bool $recover_pending = true ): void {
		$this->write_engine = $engine;
		if ( $recover_pending ) {
			$engine->recover_pending();
		}
	}

	/** End the query-only attachment phase after warm bootstrap has returned its retained index. */
	public function finish_warm_bootstrap(): void {
		$this->get_connection()->get_pdo()->exec( 'PRAGMA query_only = OFF' );
	}

	/**
	 * Flush deferred canonical writes for a caller-managed request boundary.
	 *
	 * Normal WordPress requests flush at PHP shutdown. Long-lived runtimes need
	 * an explicit boundary before their durable canonical store is published.
	 *
	 * @return array{created:string[],changed:string[],deleted:string[]}
	 */
	public function flush_canonical_writes(): array {
		$this->backend_capabilities->require( 'explicit_flush' );
		$this->backend_capabilities->require( 'changed_path_receipts' );
		if ( null !== $this->write_engine ) {
			return $this->write_engine->flush_dirty( true );
		}
		return array( 'created' => array(), 'changed' => array(), 'deleted' => array() );
	}

	/**
	 * Get the markdown storage engine.
	 *
	 * @return WP_Markdown_Storage
	 */
	public function get_storage(): WP_Markdown_Storage {
		return $this->storage;
	}

	/**
	 * Whether the file index table has been created (for lazy-loading).
	 *
	 * @var bool
	 */
	private $file_index_ready = false;

	/**
	 * In-memory cache of post_id → file_path from _markdown_file_index.
	 * Loaded once on first content resolution, then kept in memory.
	 *
	 * @var array<int, string>|null
	 */
	private $file_index_cache = null;

	/**
	 * Lazily-constructed file-grep search helper.
	 *
	 * Rewrites `post_content LIKE '%foo%'` clauses into `ID IN (...)` lists
	 * by scanning the source .md files on disk. See issue #43.
	 *
	 * @var WP_Markdown_Search|null
	 */
	private $search = null;

	/**
	 * Execute a MySQL query.
	 *
	 * All queries go through the parent SQLite driver. For write operations,
	 * we also persist to disk via the write engine.
	 *
	 * For SELECT queries on wp_posts that include post_content, the driver
	 * resolves empty content by lazy-loading from the source .md files.
	 * See: Index/Map Architecture design doc.
	 *
	 * For SELECT queries with `post_content LIKE '%foo%'` clauses, the
	 * driver rewrites those clauses into `ID IN (...)` lists by grepping
	 * the source .md files before handing the query to SQLite. Without
	 * this rewrite, WordPress search (`?s=foo`) and any `LIKE`-based
	 * content query would silently match nothing because post_content is
	 * stored as an empty string. See issue #43.
	 *
	 * @param string $query              Full MySQL query string.
	 * @param int    $fetch_mode         PDO fetch mode.
	 * @param array  ...$fetch_mode_args Additional fetch mode args.
	 *
	 * @return PDOStatement|false Query statement.
	 * @throws WP_SQLite_Driver_Exception On query failure.
	 */
	#[ReturnTypeWillChange]
	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		$op = $this->detect_operation( $query );
		if ( null !== $op && ! $this->syncing && null !== $this->write_engine ) {
			$this->require_mutation_capability( $op );
		}
		$pdo = $this->get_connection()->get_pdo();
		$post_ids = $this->prepared_post_ids( $query, $op );
		$post_mutation = null !== $op && str_ends_with( strtolower( (string) $op['table'] ), 'posts' );
		if ( $post_mutation && empty( $post_ids ) && ! in_array( $op['op'], array( 'INSERT', 'REPLACE' ), true ) ) { throw new RuntimeException( 'Post persistence requires a bounded post identity.' ); }
		if ( $post_mutation && $pdo->inTransaction() ) { throw new RuntimeException( 'Post persistence requires the runtime-owned transaction boundary.' ); }
		$wordpress_before = empty( $post_ids ) ? array() : $this->write_engine->wordpress_post_identities( $post_ids );
		$owns_transaction = $post_mutation;
		$adapter_transactions = $owns_transaction && $this->parent_owns_transaction_api();
		$attempt = 0;
		do {
			$prepared = array();
			try {
				if ( $owns_transaction ) { $adapter_transactions ? parent::beginTransaction() : $pdo->beginTransaction(); }
				$result = $this->query_cursor( $query, $fetch_mode, ...$fetch_mode_args );
				if ( empty( $post_ids ) && null !== $op && in_array( $op['op'], array( 'INSERT', 'REPLACE' ), true ) && str_ends_with( strtolower( (string) $op['table'] ), 'posts' ) ) {
					$insert_id = (int) $this->get_insert_id();
					if ( $insert_id > 0 ) { $post_ids = array( $insert_id ); $wordpress_before[ $insert_id ] = null; }
				}
				foreach ( $post_ids as $post_id ) {
					$operation = $this->write_engine->prepare_post_commit( $post_id, $wordpress_before[ $post_id ] ?? null );
					if ( null !== $operation ) { $prepared[] = $operation; }
				}
				if ( $owns_transaction ) { $adapter_transactions ? parent::commit() : $pdo->commit(); }
			} catch ( \Throwable $error ) {
				if ( $owns_transaction && ( $adapter_transactions ? parent::inTransaction() : $pdo->inTransaction() ) ) { $adapter_transactions ? parent::rollBack() : $pdo->rollBack(); }
				if ( $post_mutation && empty( $prepared ) && $attempt < self::POST_MUTATION_BUSY_RETRIES && $this->is_sqlite_contention_error( $error ) ) {
					++$attempt;
					usleep( 100000 * $attempt );
					continue;
				}
				throw $error;
			}
			break;
		} while ( true );
		foreach ( $prepared as $operation ) { $this->write_engine->continue_post_commit( $operation ); }

		if ( defined( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API' ) && MARKDOWN_DB_SQLITE_LEGACY_RESULT_API ) {
			if ( $result instanceof \PDOStatement ) {
				$result = $result->columnCount() > 0
					? $result->fetchAll( $fetch_mode, ...$fetch_mode_args )
					: $result->rowCount();
			}
			$this->last_legacy_result = $result;
		}

		// If we're already syncing or no write engine, skip.
		if ( $this->syncing || null === $this->write_engine ) {
			return $result;
		}

		// Detect the operation type and affected table.
		if ( null !== $op && empty( $prepared ) ) {
			$this->syncing = true;
			try {
				$operations = new WP_Markdown_SQLite_Operations( $this );
				foreach ( $operations->mutations_for_query( $query, $op ) as $mutation ) {
					$this->write_engine->persist_mutation(
						array(
							'key'       => $mutation['stable_id'],
							'resource'  => $mutation['stable_id'],
							'operation' => $mutation['operation'],
							'table'     => $mutation['table'],
							'context'   => array( 'resource_ids' => $mutation['resource_ids'], 'scope' => $mutation['scope'] ?? array(), 'schema' => 'schema' === $mutation['kind'] ),
						)
					);
				}
			} catch ( \Throwable $e ) {
				error_log( 'Markdown DB persist error: ' . $e->getMessage() );
			}
			$this->syncing = false;
		}

		return $result;
	}

	private function is_sqlite_contention_error( \Throwable $error ): bool {
		do {
			if ( preg_match( '/(?:database|table|schema) is locked|database is busy/i', $error->getMessage() ) ) {
				return true;
			}
			$error = $error->getPrevious();
		} while ( $error instanceof \Throwable );
		return false;
	}

	private function prepared_post_ids( string $query, ?array $operation ): array {
		if ( null === $operation || null === $this->write_engine || $this->syncing || ! str_ends_with( strtolower( (string) $operation['table'] ), 'posts' ) ) { return array(); }
		if ( ! preg_match_all( '/(?<![A-Za-z0-9_])(?:`ID`|ID)(?![A-Za-z0-9_])\s*(?:=\s*([0-9]+)|IN\s*\(([^)]*)\))/i', $query, $matches, PREG_SET_ORDER ) ) { return array(); }
		$ids = array();
		foreach ( $matches as $match ) { foreach ( explode( ',', $match[1] ?: $match[2] ) as $id ) { if ( ctype_digit( trim( $id ) ) ) { $ids[ (int) trim( $id ) ] = true; } } }
		return array_keys( $ids );
	}


	/**
	 * Execute a query and retain its PDO cursor for internal streaming consumers.
	 *
	 * Unlike query(), this deliberately bypasses the released SQLite driver's
	 * legacy result materialization. Public wpdb callers must continue to use
	 * query() and receive their established array or affected-row result.
	 *
	 * @return \PDOStatement|false Query cursor.
	 */
	public function query_cursor( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		// Rewrite `post_content LIKE '%needle%'` clauses into `ID IN (...)`
		// by grepping the source .md files. Skipped during sync so the
		// loader's own SELECTs never bounce through the file system.
		if ( ! $this->syncing ) {
			$rewritten = $this->get_search()->maybe_rewrite_query( $query );
			if ( null !== $rewritten ) {
				$query = $rewritten;
			}
		}

		// Execute via parent SQLite driver.
		$result = parent::query( $query, $fetch_mode, ...$fetch_mode_args );

		// Wrap content reads so the canonical PDO statement retains lazy markdown
		// hydration when callers fetch rows.
		if ( $result instanceof \PDOStatement && ! $this->syncing && $this->is_posts_content_query( $query ) ) {
			$this->backend_capabilities->require( 'lazy_post_content_resolution' );
			$result = new WP_Markdown_PDO_Statement(
				$result,
				function ( array $rows ): array {
					return $this->resolve_content( $rows );
				}
			);
		}

		return $result;
	}

	/** Keep canonical hydration transactions inside the parent adapter's bookkeeping. */
	public function begin_canonical_transaction(): void {
		if ( null !== $this->canonical_transaction_scope ) { throw new \RuntimeException( 'Canonical hydration transaction is already active.' ); }
		$adapter_transactions = $this->parent_owns_transaction_api();
		$connection_transaction = $this->get_connection()->get_pdo()->inTransaction();
		if ( ( $adapter_transactions && parent::inTransaction() ) || ( ! $adapter_transactions && $connection_transaction ) ) {
			$this->get_connection()->get_pdo()->exec( 'SAVEPOINT mdi_canonical_hydration' );
			$this->canonical_transaction_scope = 'savepoint';
			return;
		}
		if ( $adapter_transactions && method_exists( get_parent_class( $this ), 'beginTransaction' ) ) { parent::beginTransaction(); } else { $this->get_connection()->get_pdo()->exec( 'BEGIN IMMEDIATE' ); }
		$this->canonical_transaction_scope = 'transaction';
	}

	public function commit_canonical_transaction(): void {
		if ( 'savepoint' === $this->canonical_transaction_scope ) { $this->get_connection()->get_pdo()->exec( 'RELEASE SAVEPOINT mdi_canonical_hydration' ); }
		elseif ( 'transaction' === $this->canonical_transaction_scope && $this->parent_owns_transaction_api() ) { parent::commit(); }
		elseif ( 'transaction' === $this->canonical_transaction_scope ) { $this->get_connection()->get_pdo()->exec( 'COMMIT' ); }
		$this->canonical_transaction_scope = null;
	}

	public function rollback_canonical_transaction(): void {
		if ( 'savepoint' === $this->canonical_transaction_scope ) {
			$this->get_connection()->get_pdo()->exec( 'ROLLBACK TO SAVEPOINT mdi_canonical_hydration' );
			$this->get_connection()->get_pdo()->exec( 'RELEASE SAVEPOINT mdi_canonical_hydration' );
		} elseif ( 'transaction' === $this->canonical_transaction_scope && $this->parent_owns_transaction_api() ) { parent::rollBack(); }
		elseif ( 'transaction' === $this->canonical_transaction_scope ) { $this->get_connection()->get_pdo()->exec( 'ROLLBACK' ); }
		$this->canonical_transaction_scope = null;
	}

	private function parent_owns_transaction_api(): bool {
		$parent = get_parent_class( $this );
		foreach ( array( 'beginTransaction', 'commit', 'rollBack', 'inTransaction' ) as $method ) {
			if ( ! method_exists( $parent, $method ) || 'PDO' === ( new \ReflectionMethod( $parent, $method ) )->getDeclaringClass()->getName() ) {
				return false;
			}
		}
		return true;
	}

	public function canonical_transaction_active(): bool {
		return null !== $this->canonical_transaction_scope;
	}

	/**
	 * Return the last query result for released WP_SQLite_DB consumers.
	 *
	 * @return mixed
	 */
	public function get_query_results() {
		return $this->last_legacy_result;
	}

	/**
	 * Return the affected-row value for released WP_SQLite_DB consumers.
	 *
	 * @return mixed
	 */
	public function get_last_return_value() {
		return $this->last_legacy_result;
	}

	/**
	 * Check if a query is a SELECT on wp_posts that may need content resolution.
	 *
	 * Only intercepts queries that SELECT from wp_posts and include
	 * post_content in the result set (SELECT * or explicit post_content).
	 *
	 * @param string $query The SQL query.
	 * @return bool
	 */
	private function is_posts_content_query( string $query ): bool {
		// Must be a SELECT.
		if ( ! preg_match( '/^\s*SELECT\b/i', $query ) ) {
			return false;
		}

		// Must reference wp_posts table.
		$posts_table = $this->table_prefix . 'posts';
		if ( ! preg_match( '/\b' . preg_quote( $posts_table, '/' ) . '\b/i', $query ) ) {
			return false;
		}

		// Check if post_content is in the SELECT list.
		// SELECT * includes everything, so always resolve.
		if ( preg_match( '/SELECT\s+.*\*.*\s+FROM/is', $query ) ) {
			return true;
		}
		if ( preg_match( '/\bpost_content\b/i', $query ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve empty post_content by reading from .md files on disk.
	 *
	 * For each row that has an ID and empty post_content, looks up the
	 * file path in the _markdown_file_index and reads the content body.
	 *
	 * @param array $rows Query result rows.
	 * @return array Rows with post_content resolved.
	 */
	private function resolve_content( array $rows ): array {
		if ( empty( $rows ) ) {
			return $rows;
		}

		// Load the file index cache on first use.
		if ( null === $this->file_index_cache ) {
			$this->load_file_index_cache();
		}

		$content_dir = $this->storage->get_content_dir();

		foreach ( $rows as &$row ) {
			// Support both object and array result formats.
			$is_object = is_object( $row );
			$id = $is_object ? ( $row->ID ?? null ) : ( $row['ID'] ?? null );
			$content = $is_object ? ( $row->post_content ?? null ) : ( $row['post_content'] ?? null );

			// Only resolve if we have an ID and content is empty.
			if ( null === $id || ( null !== $content && '' !== $content ) ) {
				continue;
			}

			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}

			// Look up the file path in the index.
			$relative_path = $this->file_index_cache[ $id ] ?? null;
			if ( null === $relative_path ) {
				continue;
			}

			$file_path = $content_dir . '/' . $relative_path;
			$resolved = $this->storage->read_content_from_file( $file_path );

			if ( null !== $resolved ) {
				if ( $is_object ) {
					$row->post_content = $resolved;
				} else {
					$row['post_content'] = $resolved;
				}
			}
		}
		unset( $row );

		return $rows;
	}

	/** @param array{type:string,op:string,table:string} $op */
	private function require_mutation_capability( array $op ): void {
		if ( 'DDL' === $op['type'] ) {
			$this->backend_capabilities->require( 'schema_persistence' );
			return;
		}

		$this->backend_capabilities->require( 'table_mutation_capture' );
		if ( $op['table'] === $this->table_prefix . 'posts' ) {
			$this->backend_capabilities->require( 'content_mutation_capture' );
		}
	}

	/**
	 * Get the file index cache (post_id → relative file path).
	 *
	 * Loads the cache from `_markdown_file_index` on first access. Used by
	 * the search helper to iterate source .md files without re-querying
	 * SQLite on every search.
	 *
	 * @return array<int, string>
	 */
	public function get_file_index_cache(): array {
		if ( null === $this->file_index_cache ) {
			$this->load_file_index_cache();
		}
		return $this->file_index_cache;
	}

	/**
	 * Get (lazily construct) the file-grep search helper.
	 *
	 * @return WP_Markdown_Search
	 */
	public function get_search(): WP_Markdown_Search {
		if ( null === $this->search ) {
			$this->search = new WP_Markdown_Search( $this, $this->storage );
		}
		return $this->search;
	}

	/**
	 * Load the file index into memory from the _markdown_file_index table.
	 */
	private function load_file_index_cache(): void {
		$this->file_index_cache = array();

		try {
			$pdo = $this->get_connection()->get_pdo();
			$stmt = $pdo->query( 'SELECT post_id, file_path FROM `_markdown_file_index`' );
			if ( $stmt ) {
				while ( $row = $stmt->fetch( \PDO::FETCH_OBJ ) ) {
					$this->file_index_cache[ (int) $row->post_id ] = $row->file_path;
				}
			}
		} catch ( \Throwable $e ) {
			// Table might not exist yet during early boot.
			$this->file_index_cache = array();
		}
	}

	/**
	 * Update the file index cache entry for a post.
	 *
	 * Called by the write engine after writing a .md file.
	 *
	 * @param int    $post_id       The post ID.
	 * @param string $relative_path File path relative to MARKDOWN_DB_CONTENT_DIR.
	 * @param int    $mtime         File modification time.
	 * @param int    $size          File size in bytes.
	 */
	public function update_file_index( int $post_id, string $relative_path, int $mtime, int $size ): void {
		try {
			$pdo = $this->get_connection()->get_pdo();
			$stmt = $pdo->prepare(
				'INSERT OR REPLACE INTO `_markdown_file_index` (`post_id`, `file_path`, `file_mtime`, `file_size`) VALUES (?, ?, ?, ?)'
			);
			$stmt->execute( array( $post_id, $relative_path, $mtime, $size ) );

			// Update in-memory cache too.
			if ( null !== $this->file_index_cache ) {
				$this->file_index_cache[ $post_id ] = $relative_path;
			}
		} catch ( \Throwable $e ) {
			// Non-fatal — the index will be rebuilt on next boot.
			error_log( 'Markdown DB: Failed to update file index: ' . $e->getMessage() );
		}
	}

	/**
	 * Remove a post from the file index.
	 *
	 * Called by the write engine after deleting a .md file.
	 *
	 * @param int $post_id The post ID.
	 */
	public function remove_from_file_index( int $post_id ): void {
		try {
			$pdo = $this->get_connection()->get_pdo();
			$stmt = $pdo->prepare( 'DELETE FROM `_markdown_file_index` WHERE `post_id` = ?' );
			$stmt->execute( array( $post_id ) );

			if ( null !== $this->file_index_cache ) {
				unset( $this->file_index_cache[ $post_id ] );
			}
		} catch ( \Throwable $e ) {
			error_log( 'Markdown DB: Failed to remove from file index: ' . $e->getMessage() );
		}
	}

	/**
	 * Upsert rows into the _options_file_index table.
	 *
	 * Called by the write engine after writing per-option files, so the
	 * loader's incremental sync can diff per-row instead of per-table.
	 * See issue #55.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int, array{option_name:string,file_path:string,file_mtime:int,file_size:int,option_id:int,autoload:string}> $rows
	 */
	public function upsert_options_index( array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}

		try {
			$pdo  = $this->get_connection()->get_pdo();
			$stmt = $pdo->prepare(
				'INSERT OR REPLACE INTO `_options_file_index`
				 (`option_name`, `file_path`, `file_mtime`, `file_size`, `option_id`, `autoload`)
				 VALUES (?, ?, ?, ?, ?, ?)'
			);

			$pdo->exec( 'BEGIN TRANSACTION' );
			try {
				foreach ( $rows as $row ) {
					$stmt->execute( array(
						$row['option_name'],
						$row['file_path'],
						$row['file_mtime'],
						$row['file_size'],
						$row['option_id'],
						$row['autoload'],
					) );
				}
				$pdo->exec( 'COMMIT' );
			} catch ( \Throwable $e ) {
				$pdo->exec( 'ROLLBACK' );
				throw $e;
			}
		} catch ( \Throwable $e ) {
			// Index failure is non-fatal — sync_incremental() will just see
			// the files as "new" next boot and re-index them.
			error_log( 'Markdown DB: Failed to upsert options index: ' . $e->getMessage() );
		}
	}

	/**
	 * Remove rows from the _options_file_index table.
	 *
	 * Called by the write engine when options are deleted.
	 *
	 * @since 0.4.0
	 *
	 * @param string[] $option_names
	 */
	public function remove_from_options_index( array $option_names ): void {
		if ( empty( $option_names ) ) {
			return;
		}

		try {
			$pdo = $this->get_connection()->get_pdo();
			$stmt = $pdo->prepare( 'DELETE FROM `_options_file_index` WHERE `option_name` = ?' );
			foreach ( $option_names as $name ) {
				$stmt->execute( array( $name ) );
			}
		} catch ( \Throwable $e ) {
			error_log( 'Markdown DB: Failed to remove from options index: ' . $e->getMessage() );
		}
	}

	/**
	 * Detect the type of SQL operation and affected table.
	 *
	 * All DML is persisted unless the table is ephemeral.
	 * DDL for plugin tables (non-core) is persisted to _schema/.
	 * DDL for core tables is skipped (schemas are hardcoded in the loader).
	 *
	 * @param string $query The MySQL query.
	 * @return array|null { type: 'DML'|'DDL', op: string, table: string } or null.
	 */
	private function detect_operation( string $query ): ?array {
		$operation = WP_Markdown_SQL_Classifier::mutation( $query );
		if ( null === $operation ) {
			return null;
		}
		// mysql-full owns multi-table DELETE and index creation; retain SQLite's prior set.
		if ( ! isset( $operation['table'] ) || 'TRUNCATE' === $operation['op'] || preg_match( '/^\s*CREATE\s+(?:(?:OR\s+REPLACE)\s+)?(?:(?:UNIQUE|FULLTEXT|SPATIAL|VECTOR)\s+)?INDEX\b/i', $query ) ) {
			return null;
		}
		if ( 'DML' === $operation['type'] && $this->is_ephemeral_table( $operation['table'] ) ) {
			return null;
		}
		if ( 'DDL' === $operation['type'] && $this->is_core_table( $operation['table'] ) && preg_match( '/^\s*(?:CREATE\s+TABLE|DROP\s+INDEX)/i', $query ) ) {
			return null;
		}
		return $operation;
	}

	/**
	 * Check if a table is ephemeral (should NOT be persisted).
	 *
	 * @param string $table The full table name.
	 * @return bool
	 */
	private function is_ephemeral_table( string $table ): bool {
		return isset( $this->ephemeral_tables[ $table ] );
	}

	/**
	 * Check if a table is a core WordPress table.
	 *
	 * Core table schemas are hardcoded in the loader, so we don't
	 * need to persist their CREATE TABLE statements to _schema/.
	 *
	 * @param string $table The full table name.
	 * @return bool
	 */
	private function is_core_table( string $table ): bool {
		foreach ( self::CORE_TABLE_SUFFIXES as $suffix ) {
			if ( $table === $this->table_prefix . $suffix ) {
				return true;
			}
		}
		return false;
	}
}

/**
 * Decorates canonical PDO statements with MDI's lazy post-content hydration.
 */
class WP_Markdown_PDO_Statement extends PDOStatement {
	private PDOStatement $statement;
	private $hydrate_rows;

	public function __construct( PDOStatement $statement, callable $hydrate_rows ) {
		$this->statement    = $statement;
		$this->hydrate_rows = $hydrate_rows;
	}

	#[\ReturnTypeWillChange]
	public function fetch( $mode = PDO::FETCH_DEFAULT, $cursor_orientation = PDO::FETCH_ORI_NEXT, $cursor_offset = 0 ) {
		$row = $this->statement->fetch( $mode, $cursor_orientation, $cursor_offset );
		if ( false === $row ) {
			return false;
		}
		$rows = ( $this->hydrate_rows )( array( $row ) );
		return $rows[0];
	}

	#[\ReturnTypeWillChange]
	public function fetchAll( $mode = PDO::FETCH_DEFAULT, ...$args ): array {
		return ( $this->hydrate_rows )( $this->statement->fetchAll( $mode, ...$args ) );
	}

	public function columnCount(): int {
		return $this->statement->columnCount();
	}

	public function rowCount(): int {
		return $this->statement->rowCount();
	}
}
