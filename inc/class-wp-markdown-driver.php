<?php
/**
 * Markdown Database Driver
 *
 * Extends the SQLite v2 driver to persist all writes to markdown/JSON files.
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

class WP_Markdown_Driver extends WP_SQLite_Driver {

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
	 * Constructor.
	 *
	 * @param WP_SQLite_Connection $connection The SQLite connection.
	 * @param string               $database   The database name.
	 * @param WP_Markdown_Storage  $storage    The markdown storage engine.
	 */
	public function __construct(
		WP_SQLite_Connection $connection,
		string $database,
		WP_Markdown_Storage $storage
	) {
		parent::__construct( $connection, $database );

		$this->storage = $storage;

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
	public function set_write_engine( WP_Markdown_Write_Engine $engine ): void {
		$this->write_engine = $engine;
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
	 * @return mixed Query results.
	 * @throws WP_SQLite_Driver_Exception On query failure.
	 */
	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
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

		// Lazy-load post_content from .md files for SELECT queries on wp_posts.
		// This must run before the write engine check (reads don't trigger writes).
		if ( is_array( $result ) && ! $this->syncing && $this->is_posts_content_query( $query ) ) {
			$result = $this->resolve_content( $result );
		}

		// If we're already syncing or no write engine, skip.
		if ( $this->syncing || null === $this->write_engine ) {
			return $result;
		}

		// Detect the operation type and affected table.
		$op = $this->detect_operation( $query );

		if ( null !== $op ) {
			$this->syncing = true;
			try {
				if ( $op['type'] === 'DDL' ) {
					$this->write_engine->persist_schema( $query, $op['table'], $op['op'] );
				} else {
					$this->write_engine->persist_write( $query, $op['table'], $op['op'] );
				}
			} catch ( \Throwable $e ) {
				error_log( 'Markdown DB persist error: ' . $e->getMessage() );
			}
			$this->syncing = false;
		}

		return $result;
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
		$trimmed = ltrim( $query );

		// DML operations: INSERT, UPDATE, DELETE, REPLACE.
		// All tables are persisted unless explicitly ephemeral.
		if ( preg_match( '/^\s*(INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`?(\w+)`?/i', $trimmed, $m ) ) {
			$table = $m[2];
			$op = strtoupper( str_contains( strtoupper( $m[1] ), 'REPLACE' ) ? 'REPLACE' : 'INSERT' );
			if ( ! $this->is_ephemeral_table( $table ) ) {
				return array( 'type' => 'DML', 'op' => $op, 'table' => $table );
			}
		} elseif ( preg_match( '/^\s*UPDATE\s+`?(\w+)`?/i', $trimmed, $m ) ) {
			$table = $m[1];
			if ( ! $this->is_ephemeral_table( $table ) ) {
				return array( 'type' => 'DML', 'op' => 'UPDATE', 'table' => $table );
			}
		} elseif ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $trimmed, $m ) ) {
			$table = $m[1];
			if ( ! $this->is_ephemeral_table( $table ) ) {
				return array( 'type' => 'DML', 'op' => 'DELETE', 'table' => $table );
			}
		}
		// DDL operations: CREATE TABLE, ALTER TABLE, DROP TABLE.
		// Only persist schema for non-core tables (core schemas are in the loader).
		elseif ( preg_match( '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $trimmed, $m ) ) {
			$table = $m[1];
			if ( ! $this->is_core_table( $table ) ) {
				return array( 'type' => 'DDL', 'op' => 'CREATE', 'table' => $table );
			}
		} elseif ( preg_match( '/^\s*ALTER\s+TABLE\s+`?(\w+)`?/i', $trimmed, $m ) ) {
			return array( 'type' => 'DDL', 'op' => 'ALTER', 'table' => $m[1] );
		} elseif ( preg_match( '/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i', $trimmed, $m ) ) {
			return array( 'type' => 'DDL', 'op' => 'DROP', 'table' => $m[1] );
		}

		return null;
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
