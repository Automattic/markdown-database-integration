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
 *   - wp_options   → single options.json
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

	/**
	 * The base content directory.
	 *
	 * @var string
	 */
	private $content_dir;

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
	 * Table prefix.
	 *
	 * @var string
	 */
	private $prefix;

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
	 * Whether we're currently writing (prevents recursion).
	 *
	 * @var bool
	 */
	private $writing = false;

	/**
	 * Constructor.
	 *
	 * @param string              $content_dir Content directory.
	 * @param WP_Markdown_Storage $storage     Markdown storage for posts.
	 * @param WP_SQLite_Driver    $driver      SQLite driver.
	 * @param string              $prefix      Table prefix.
	 */
	public function __construct(
		string $content_dir,
		WP_Markdown_Storage $storage,
		WP_SQLite_Driver $driver,
		string $prefix = 'wp_'
	) {
		$this->content_dir = rtrim( $content_dir, '/' );
		$this->storage     = $storage;
		$this->driver      = $driver;
		$this->prefix      = $prefix;
	}

	/**
	 * Handle a write query — persist affected data to disk.
	 *
	 * Called by WP_Markdown_Driver after a successful write query.
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

			if ( $table_suffix === 'options' ) {
				$this->persist_options();
			} elseif ( $table_suffix === 'posts' ) {
				$this->persist_post_write( $query, $op_type );
			} elseif ( $table_suffix === 'postmeta' ) {
				$this->persist_postmeta_write( $query, $op_type );
			} elseif ( in_array( $table_suffix, array( 'term_relationships', 'term_taxonomy', 'terms' ), true ) ) {
				$this->persist_terms_write( $query, $op_type, $table_suffix );
			} else {
				$this->persist_table( $table_suffix );
			}
		} catch ( \Throwable $e ) {
			// Write failures should never break WordPress.
			error_log( 'Markdown DB write error: ' . $e->getMessage() );
		}

		$this->writing = false;
	}

	/**
	 * Persist the entire wp_options table to options.json.
	 *
	 * Filters out ephemeral options (transients, cron).
	 */
	private function persist_options(): void {
		$table = $this->prefix . 'options';

		try {
			$rows = $this->driver->query(
				"SELECT option_id, option_name, option_value, autoload FROM `{$table}` ORDER BY option_id"
			);
		} catch ( \Throwable $e ) {
			error_log( 'Markdown DB: Failed to read options for persist: ' . $e->getMessage() );
			return;
		}

		if ( ! is_array( $rows ) ) {
			return;
		}

		$options = array();
		foreach ( $rows as $row ) {
			$name = $row->option_name;

			// Skip ephemeral options.
			if ( $this->is_ephemeral_option( $name ) ) {
				continue;
			}

			$options[] = array(
				'option_id'    => (int) $row->option_id,
				'option_name'  => $name,
				'option_value' => $row->option_value,
				'autoload'     => $row->autoload,
			);
		}

		$this->write_json( $this->content_dir . '/options.json', $options );
	}

	/**
	 * Persist a post write to markdown files.
	 *
	 * @param string $query   The SQL query.
	 * @param string $op_type INSERT, UPDATE, DELETE, REPLACE.
	 */
	private function persist_post_write( string $query, string $op_type ): void {
		if ( 'DELETE' === $op_type ) {
			// Extract IDs and delete markdown files.
			$ids = $this->extract_ids_from_query( $query, 'ID' );
			foreach ( $ids as $id ) {
				$this->storage->delete_post( $id );
			}

			// Deletions may affect the non-markdown fallback JSON.
			$this->persist_non_markdown_posts();
			return;
		}

		// For INSERT/UPDATE/REPLACE, read back the affected row.
		$affected_is_non_markdown = false;

		if ( 'INSERT' === $op_type || 'REPLACE' === $op_type ) {
			$id = $this->driver->get_insert_id();
			if ( $id ) {
				$affected_is_non_markdown = $this->persist_single_post( (int) $id );
			}
		} elseif ( 'UPDATE' === $op_type ) {
			$ids = $this->extract_ids_from_query( $query, 'ID' );
			foreach ( $ids as $id ) {
				if ( $this->persist_single_post( $id ) ) {
					$affected_is_non_markdown = true;
				}
			}
		}

		// Only dump the non-markdown JSON if the affected post was non-markdown.
		// This avoids a full table scan on every regular post write.
		if ( $affected_is_non_markdown ) {
			$this->persist_non_markdown_posts();
		}
	}

	/**
	 * Persist a single post — either to markdown or to the JSON fallback.
	 *
	 * For markdown-type posts, converts block HTML → clean markdown
	 * before writing to disk. See GitHub issue #11.
	 *
	 * @param int $post_id
	 * @return bool True if the post type is non-markdown (caller should update JSON).
	 */
	private function persist_single_post( int $post_id ): bool {
		$table = $this->prefix . 'posts';

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

		// Convert block HTML to clean markdown before writing to disk.
		$content = $row->post_content ?? '';
		if ( ! empty( $content ) ) {
			$converter = WP_Markdown_Converter::get_instance();
			$row->post_content = $converter->blocks_to_markdown( $content );
		}

		$this->storage->write_post( $row );
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
		$post_ids = $this->extract_post_ids_from_meta_query( $query );

		// Rewrite the .md file for each affected markdown-type post.
		foreach ( $post_ids as $post_id ) {
			$this->rewrite_post_if_markdown( $post_id );
		}

		// Dump non-markdown postmeta to JSON (excluding markdown-post rows).
		$this->persist_table_excluding_markdown_posts( 'postmeta', 'post_id' );
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
			// Find affected post IDs and rewrite their .md files.
			$post_ids = $this->extract_ids_from_query( $query, 'object_id' );
			foreach ( $post_ids as $post_id ) {
				$this->rewrite_post_if_markdown( $post_id );
			}

			// Dump non-markdown term_relationships to JSON.
			$this->persist_table_excluding_markdown_posts( 'term_relationships', 'object_id' );
		}

		// Always persist the terms and term_taxonomy tables fully
		// (they're shared across all posts, not post-specific).
		if ( $table_suffix === 'terms' || $table_suffix === 'term_taxonomy' ) {
			$this->persist_table( $table_suffix );
		}
	}

	/**
	 * Rewrite a post's .md file if it's a markdown type.
	 *
	 * Used when meta or terms change — the .md file needs to be
	 * regenerated with updated frontmatter.
	 *
	 * @param int $post_id
	 */
	private function rewrite_post_if_markdown( int $post_id ): void {
		$table = $this->prefix . 'posts';

		try {
			$rows = $this->driver->query(
				"SELECT * FROM `{$table}` WHERE ID = {$post_id}"
			);
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$row = $rows[0];
		$post_type = $row->post_type ?? 'post';

		if ( ! $this->storage->is_markdown_type( $post_type ) ) {
			return;
		}

		// Convert block HTML to clean markdown.
		$content = $row->post_content ?? '';
		if ( ! empty( $content ) ) {
			$converter = WP_Markdown_Converter::get_instance();
			$row->post_content = $converter->blocks_to_markdown( $content );
		}

		$this->storage->write_post( $row );
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
		$table      = $this->prefix . $table_suffix;
		$posts_table = $this->prefix . 'posts';

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
		$this->write_json( $this->content_dir . '/_tables/' . $table_suffix . '.json', $data );
	}

	/**
	 * Get a set of post IDs that belong to markdown-type post types.
	 *
	 * @return array<int, bool>
	 */
	private function get_markdown_post_ids(): array {
		$table = $this->prefix . 'posts';
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
	private function extract_post_ids_from_meta_query( string $query ): array {
		return $this->extract_ids_from_query( $query, 'post_id' );
	}

	/**
	 * Persist posts that are excluded from markdown to the JSON fallback.
	 */
	private function persist_non_markdown_posts(): void {
		$table = $this->prefix . 'posts';

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
		$this->write_json( $this->content_dir . '/_tables/posts.json', $non_markdown );
	}

	/**
	 * Persist a full table to JSON.
	 *
	 * @param string $table_suffix Table name without prefix.
	 */
	private function persist_table( string $table_suffix ): void {
		$table = $this->prefix . $table_suffix;

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

		$this->ensure_tables_dir();
		$this->write_json( $this->content_dir . '/_tables/' . $table_suffix . '.json', $data );
	}

	/**
	 * Persist a schema change (CREATE TABLE, ALTER TABLE, DROP TABLE).
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
			$schema_dir = $this->content_dir . '/_schema';

			if ( ! is_dir( $schema_dir ) ) {
				mkdir( $schema_dir, 0755, true );
			}

			$schema_path = $schema_dir . '/' . $table_suffix . '.sql';

			if ( 'DROP' === $ddl_type ) {
				// Remove schema and data files.
				@unlink( $schema_path );
				@unlink( $this->content_dir . '/_tables/' . $table_suffix . '.json' );
			} elseif ( 'CREATE' === $ddl_type ) {
				// Save the CREATE TABLE statement (overwrites any existing file).
				file_put_contents(
					$schema_path,
					$query . ";\n",
					LOCK_EX
				);
			} elseif ( 'ALTER' === $ddl_type ) {
				// Append ALTER statements after the CREATE TABLE.
				// This preserves the full DDL history needed to recreate the table.
				file_put_contents(
					$schema_path,
					$query . ";\n",
					FILE_APPEND | LOCK_EX
				);
			}
		} catch ( \Throwable $e ) {
			error_log( 'Markdown DB schema persist error: ' . $e->getMessage() );
		}

		$this->writing = false;
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
		if ( str_starts_with( $table, $this->prefix ) ) {
			return substr( $table, strlen( $this->prefix ) );
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

		// Atomic write: write to temp file then rename.
		$tmp = $path . '.tmp.' . getmypid();
		if ( false !== file_put_contents( $tmp, $json, LOCK_EX ) ) {
			rename( $tmp, $path );
		}
	}

	/**
	 * Ensure the _tables directory exists.
	 */
	private function ensure_tables_dir(): void {
		$dir = $this->content_dir . '/_tables';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
	}

	/**
	 * Check if the write engine is currently writing (prevents recursion).
	 *
	 * @return bool
	 */
	public function is_writing(): bool {
		return $this->writing;
	}
}
