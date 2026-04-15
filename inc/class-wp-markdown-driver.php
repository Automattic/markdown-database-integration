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
	 * Execute a MySQL query.
	 *
	 * All queries go through the parent SQLite driver. For write operations,
	 * we also persist to disk via the write engine.
	 *
	 * @param string $query              Full MySQL query string.
	 * @param int    $fetch_mode         PDO fetch mode.
	 * @param array  ...$fetch_mode_args Additional fetch mode args.
	 *
	 * @return mixed Query results.
	 * @throws WP_SQLite_Driver_Exception On query failure.
	 */
	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		// Execute via parent SQLite driver.
		$result = parent::query( $query, $fetch_mode, ...$fetch_mode_args );

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
