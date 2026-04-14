<?php
/**
 * Markdown Database Driver
 *
 * Extends the SQLite v2 driver to persist all writes to markdown/JSON files.
 * In Phase 2 ('primary' mode), the in-memory SQLite is the query engine
 * and markdown files on disk are the source of truth.
 *
 * @package Markdown_Database_Integration
 * @since 0.1.0
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
	 * Operating mode: 'mirror' or 'primary'.
	 *
	 * @var string
	 */
	private $mode;

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
	 * Tables known to the write engine (core WordPress tables).
	 *
	 * @var string[]
	 */
	private $managed_tables = array();

	/**
	 * Constructor.
	 *
	 * @param WP_SQLite_Connection $connection The SQLite connection.
	 * @param string               $database   The database name.
	 * @param WP_Markdown_Storage  $storage    The markdown storage engine.
	 * @param string               $mode       Operating mode: 'mirror' or 'primary'.
	 */
	public function __construct(
		WP_SQLite_Connection $connection,
		string $database,
		WP_Markdown_Storage $storage,
		string $mode = 'mirror'
	) {
		parent::__construct( $connection, $database );

		$this->storage = $storage;
		$this->mode    = $mode;

		global $table_prefix;
		$this->table_prefix = $table_prefix ?? 'wp_';

		// Build list of tables we manage.
		$suffixes = array(
			'options', 'users', 'usermeta', 'posts', 'postmeta',
			'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
			'comments', 'commentmeta', 'links',
		);
		foreach ( $suffixes as $s ) {
			$this->managed_tables[] = $this->table_prefix . $s;
		}
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
	 * @param string $query The MySQL query.
	 * @return array|null { type: 'DML'|'DDL', op: string, table: string } or null.
	 */
	private function detect_operation( string $query ): ?array {
		$trimmed = ltrim( $query );

		// DML operations: INSERT, UPDATE, DELETE, REPLACE.
		if ( preg_match( '/^\s*(INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`?(\w+)`?/i', $trimmed, $m ) ) {
			$table = $m[2];
			$op = strtoupper( str_contains( strtoupper( $m[1] ), 'REPLACE' ) ? 'REPLACE' : 'INSERT' );
			if ( $this->is_managed_table( $table ) ) {
				return array( 'type' => 'DML', 'op' => $op, 'table' => $table );
			}
		} elseif ( preg_match( '/^\s*UPDATE\s+`?(\w+)`?/i', $trimmed, $m ) ) {
			$table = $m[1];
			if ( $this->is_managed_table( $table ) ) {
				return array( 'type' => 'DML', 'op' => 'UPDATE', 'table' => $table );
			}
		} elseif ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $trimmed, $m ) ) {
			$table = $m[1];
			if ( $this->is_managed_table( $table ) ) {
				return array( 'type' => 'DML', 'op' => 'DELETE', 'table' => $table );
			}
		}
		// DDL operations: CREATE TABLE, ALTER TABLE, DROP TABLE.
		elseif ( preg_match( '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $trimmed, $m ) ) {
			$table = $m[1];
			// Only persist schema for non-core tables (core tables are hardcoded in loader).
			if ( ! in_array( $table, $this->managed_tables, true ) ) {
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
	 * Check if a table is managed by the write engine.
	 *
	 * @param string $table The table name.
	 * @return bool
	 */
	private function is_managed_table( string $table ): bool {
		return in_array( $table, $this->managed_tables, true );
	}
}
