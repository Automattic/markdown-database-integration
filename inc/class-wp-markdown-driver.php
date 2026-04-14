<?php
/**
 * Markdown Database Driver
 *
 * Extends the SQLite v2 driver to sync content writes to markdown files.
 * On every write to wp_posts, the corresponding markdown file is
 * created/updated/deleted. The parent SQLite driver handles all actual
 * query execution — we're a sync layer on top.
 *
 * In 'mirror' mode: SQLite is primary, markdown is synced.
 * In 'primary' mode: markdown is primary, SQLite index is rebuilt from files.
 *
 * Extends WP_SQLite_Driver so all instanceof checks in WP_SQLite_DB pass.
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
	 * The posts table name (with prefix).
	 *
	 * @var string
	 */
	private $posts_table;

	/**
	 * Whether we're currently inside a sync operation (prevents recursion).
	 *
	 * @var bool
	 */
	private $syncing = false;

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
		$this->posts_table = ( $table_prefix ?? 'wp_' ) . 'posts';
	}

	/**
	 * Execute a MySQL query.
	 *
	 * All queries go through the parent SQLite driver. For write operations
	 * targeting wp_posts, we also sync to markdown.
	 *
	 * @param string $query              Full MySQL query string.
	 * @param int    $fetch_mode         PDO fetch mode.
	 * @param array  ...$fetch_mode_args Additional fetch mode args.
	 *
	 * @return mixed Query results.
	 * @throws WP_SQLite_Driver_Exception On query failure.
	 */
	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		// Execute via parent SQLite driver — it handles parsing, translation, everything.
		$result = parent::query( $query, $fetch_mode, ...$fetch_mode_args );

		// If we're already syncing, don't recurse.
		if ( $this->syncing ) {
			return $result;
		}

		// Sync writes to markdown.
		if ( $this->is_posts_write( $query ) ) {
			$this->sync_to_markdown( $query );
		}

		return $result;
	}

	/**
	 * Check if a query is a write operation targeting the posts table.
	 *
	 * @param string $query The MySQL query.
	 * @return bool
	 */
	private function is_posts_write( string $query ): bool {
		$query_trimmed = ltrim( $query );

		// Only care about INSERT, UPDATE, DELETE, REPLACE.
		if ( ! preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\s/i', $query_trimmed ) ) {
			return false;
		}

		// Check if it targets the posts table.
		$table = $this->posts_table;
		return (bool) preg_match(
			'/(?:INTO|FROM|UPDATE)\s+`?' . preg_quote( $table, '/' ) . '`?/i',
			$query
		);
	}

	/**
	 * Sync a write operation to markdown files.
	 *
	 * @param string $query The MySQL query that was executed.
	 */
	private function sync_to_markdown( string $query ): void {
		$this->syncing = true;

		try {
			$query_trimmed = ltrim( $query );

			if ( preg_match( '/^\s*(INSERT|REPLACE)\s/i', $query_trimmed ) ) {
				$this->sync_insert();
			} elseif ( preg_match( '/^\s*UPDATE\s/i', $query_trimmed ) ) {
				$this->sync_update( $query );
			} elseif ( preg_match( '/^\s*DELETE\s/i', $query_trimmed ) ) {
				$this->sync_delete( $query );
			}
		} catch ( \Throwable $e ) {
			// Markdown sync failures should never break WordPress.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Markdown DB sync error: ' . $e->getMessage() );
			}
		}

		$this->syncing = false;
	}

	/**
	 * Sync an INSERT/REPLACE — read back the inserted row and write markdown.
	 */
	private function sync_insert(): void {
		$insert_id = parent::get_insert_id();
		if ( ! $insert_id ) {
			return;
		}

		$row = $this->fetch_post_by_id( (int) $insert_id );
		if ( $row ) {
			$this->storage->write_post( $row );
		}
	}

	/**
	 * Sync an UPDATE — find affected rows and rewrite their markdown files.
	 *
	 * @param string $query The UPDATE query.
	 */
	private function sync_update( string $query ): void {
		$ids = $this->extract_affected_ids( $query );

		foreach ( $ids as $id ) {
			$row = $this->fetch_post_by_id( $id );
			if ( $row ) {
				$this->storage->write_post( $row );
			}
		}
	}

	/**
	 * Sync a DELETE — delete the corresponding markdown files.
	 *
	 * @param string $query The DELETE query.
	 */
	private function sync_delete( string $query ): void {
		$ids = $this->extract_ids_from_query( $query );

		foreach ( $ids as $id ) {
			$this->storage->delete_post( $id );
		}
	}

	/**
	 * Extract post IDs from a WHERE clause.
	 *
	 * @param string $query The SQL query.
	 * @return int[]
	 */
	private function extract_ids_from_query( string $query ): array {
		$ids = array();

		if ( preg_match( '/WHERE\s+.*?`?ID`?\s*=\s*(\d+)/i', $query, $m ) ) {
			$ids[] = (int) $m[1];
		}

		if ( preg_match( '/WHERE\s+.*?`?ID`?\s+IN\s*\(([^)]+)\)/i', $query, $m ) ) {
			$in_ids = array_map( 'intval', explode( ',', $m[1] ) );
			$ids    = array_merge( $ids, array_filter( $in_ids ) );
		}

		return array_unique( array_filter( $ids ) );
	}

	/**
	 * Extract affected post IDs — try simple extraction, fall back to SELECT.
	 *
	 * @param string $query The UPDATE query.
	 * @return int[]
	 */
	private function extract_affected_ids( string $query ): array {
		$ids = $this->extract_ids_from_query( $query );
		if ( ! empty( $ids ) ) {
			return $ids;
		}

		if ( preg_match( '/\bWHERE\s+(.+?)(?:\s+ORDER\s|\s+LIMIT\s|$)/is', $query, $m ) ) {
			try {
				$select = sprintf(
					"SELECT ID FROM `%s` WHERE %s",
					$this->posts_table,
					$m[1]
				);
				$rows = parent::query( $select );
				if ( is_array( $rows ) ) {
					return array_map( fn( $r ) => (int) $r->ID, $rows );
				}
			} catch ( \Throwable $e ) {
				// Skip.
			}
		}

		return array();
	}

	/**
	 * Fetch a full post row from SQLite by ID.
	 *
	 * @param int $post_id The post ID.
	 * @return object|null
	 */
	private function fetch_post_by_id( int $post_id ): ?object {
		try {
			$rows = parent::query(
				sprintf(
					"SELECT * FROM `%s` WHERE ID = %d",
					$this->posts_table,
					$post_id
				)
			);
			if ( is_array( $rows ) && ! empty( $rows ) ) {
				return $rows[0];
			}
		} catch ( \Throwable $e ) {
			// Silently fail.
		}

		return null;
	}
}
