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
	 * Tables that have been modified and need flushing at shutdown.
	 *
	 * @var array<string, bool>
	 */
	private $dirty = array();

	/**
	 * Specific wp_options names changed in this request.
	 *
	 * Populated by persist_write() when it can cleanly parse the option_name
	 * from the query. Used by persist_options() to merge only these keys
	 * into options.json rather than overwriting the entire file.
	 *
	 * Concurrent requests (e.g., wp-cron spawned mid-request) race on
	 * options.json writes; whole-file replacement causes lost writes
	 * (the slower writer overwrites the faster writer's in-memory SQLite
	 * state, which never saw the other request's changes). Tracking
	 * specific names + read-merge-write + flock eliminates the race for
	 * disjoint-key writers.
	 *
	 * Keyed by option_name for uniqueness and O(1) presence checks.
	 *
	 * @var array<string, bool>
	 * @since 0.3.0
	 */
	private $dirty_option_names = array();

	/**
	 * When true, persist_options() writes the entire options table (legacy
	 * behavior) instead of the surgical merge. Set when query parsing fails
	 * for any options write in this request — ensures we never silently
	 * drop a write we couldn't analyze.
	 *
	 * @var bool
	 * @since 0.3.0
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

			if ( $table_suffix === 'posts' ) {
				$this->persist_post_write( $query, $op_type );
			} elseif ( $table_suffix === 'postmeta' ) {
				$this->persist_postmeta_write( $query, $op_type );
			} elseif ( in_array( $table_suffix, array( 'term_relationships', 'term_taxonomy', 'terms' ), true ) ) {
				$this->persist_terms_write( $query, $op_type, $table_suffix );
			} elseif ( $table_suffix === 'options' ) {
				// Defer to shutdown. Track specific option_name(s) so we can merge
				// into options.json without overwriting concurrent writers.
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
	 * Extract the option_name(s) touched by an options-table query and record
	 * them in $dirty_option_names.
	 *
	 * If the query shape cannot be parsed, set $dirty_options_all so
	 * persist_options() falls back to a full-table rewrite. Better to
	 * over-write than silently lose a change.
	 *
	 * Supported shapes (all WordPress-generated):
	 *   INSERT INTO `...options` (`option_name`, `option_value`, `autoload`) VALUES ('name', ...)
	 *   REPLACE INTO `...options` (`option_name`, ...) VALUES ('name', ...)
	 *   INSERT ... ON DUPLICATE KEY UPDATE ... (MySQL upsert — option_name in VALUES list)
	 *   UPDATE `...options` SET ... WHERE `option_name` = 'name'
	 *   DELETE FROM `...options` WHERE `option_name` = 'name'
	 *
	 * @since 0.3.0
	 *
	 * @param string $query The SQL query string.
	 */
	private function track_options_change( string $query ): void {
		// INSERT / REPLACE: option_name appears as the first VALUES entry
		// when the column list starts with option_name. WordPress always
		// emits the column list in this order.
		if ( preg_match( '/^\s*(?:INSERT|REPLACE)\b/i', $query ) ) {
			// Match VALUES ('name', ...) — the first string literal after VALUES(
			// is option_name per WordPress's column order.
			if ( preg_match(
				'/VALUES\s*\(\s*\'((?:\\\\\'|[^\'])*)\'/i',
				$query,
				$m
			) ) {
				$this->dirty_option_names[ $this->unslash_sql_string( $m[1] ) ] = true;
				return;
			}
			$this->dirty_options_all = true;
			return;
		}

		// UPDATE / DELETE: option_name appears in WHERE clause.
		if ( preg_match( '/^\s*(?:UPDATE|DELETE)\b/i', $query ) ) {
			if ( preg_match(
				'/WHERE\s+`?option_name`?\s*=\s*\'((?:\\\\\'|[^\'])*)\'/i',
				$query,
				$m
			) ) {
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
	 * Handles the two escape styles WordPress/the SQLite driver produce:
	 *   \\' → '
	 *   \\\\ → \
	 *
	 * @since 0.3.0
	 *
	 * @param string $raw String captured from inside single quotes.
	 * @return string
	 */
	private function unslash_sql_string( string $raw ): string {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $raw );
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
	 * Flush all dirty tables to disk.
	 *
	 * Called at shutdown. Each table is persisted once, regardless of how
	 * many writes happened during the request.
	 */
	public function flush_dirty(): void {
		if ( empty( $this->dirty ) ) {
			return;
		}

		$this->writing = true;

		try {
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
			$this->dirty              = array();
			$this->dirty_option_names = array();
			$this->dirty_options_all  = false;
		} catch ( \Throwable $e ) {
			error_log( 'Markdown DB flush error: ' . $e->getMessage() );
		}

		$this->writing = false;
	}

	/**
	 * Persist wp_options writes to options.json.
	 *
	 * Strategy: read-merge-write under an exclusive file lock so concurrent
	 * writers (e.g. wp-cron spawned mid-request) don't overwrite each
	 * other's changes.
	 *
	 * - Read the current options.json into a name-keyed map.
	 * - For each option_name marked dirty in this request, read its current
	 *   value from SQLite and either overwrite the map entry or delete it
	 *   (if the option no longer exists in SQLite → was deleted).
	 * - Untouched keys in options.json are preserved as-is, so concurrent
	 *   writers that modified disjoint keys don't lose their work.
	 * - Write the merged map back atomically.
	 *
	 * Fallback: if $dirty_options_all is set (query parsing failed for any
	 * options write this request), we persist the full current SQLite
	 * snapshot instead of merging. Correct but loses the concurrency
	 * protection for that request — the surgical path is preferred.
	 *
	 * @since 0.3.0 Rewritten as read-merge-write with flock.
	 */
	private function persist_options(): void {
		$path = $this->content_dir . '/options.json';

		// Ensure the directory exists so we can open the lock file.
		if ( ! is_dir( $this->content_dir ) ) {
			mkdir( $this->content_dir, 0755, true );
		}

		// Open (or create) the options.json for read+write with an
		// exclusive lock. Serializes concurrent persist_options() calls.
		$fh = @fopen( $path, 'c+' );
		if ( false === $fh ) {
			error_log( 'Markdown DB: Failed to open options.json for persist.' );
			return;
		}

		try {
			if ( ! flock( $fh, LOCK_EX ) ) {
				error_log( 'Markdown DB: Failed to acquire lock on options.json.' );
				return;
			}

			if ( $this->dirty_options_all || empty( $this->dirty_option_names ) ) {
				// Full-table fallback — either we couldn't parse a query,
				// or nothing name-specific was recorded (defensive: should
				// not happen since persist_write always calls track_options_change
				// for options, but handle it rather than write an empty file).
				$options = $this->read_full_options_from_sqlite();
				if ( null === $options ) {
					return; // SQLite read failed — preserve existing file.
				}
			} else {
				// Surgical merge: start with disk state, overlay SQLite values for dirty keys.
				$options = $this->merge_dirty_options( $fh );
				if ( null === $options ) {
					return; // Error during merge — preserve existing file.
				}
			}

			$this->write_locked( $fh, $options );
		} finally {
			flock( $fh, LOCK_UN );
			fclose( $fh );
		}
	}

	/**
	 * Read the full wp_options table from SQLite into the persisted-shape array.
	 *
	 * Used for the whole-table fallback path.
	 *
	 * @since 0.3.0
	 *
	 * @return array|null Array of option rows, or null on failure.
	 */
	private function read_full_options_from_sqlite(): ?array {
		$table = $this->prefix . 'options';

		try {
			$rows = $this->driver->query(
				"SELECT option_id, option_name, option_value, autoload FROM `{$table}` ORDER BY option_id"
			);
		} catch ( \Throwable $e ) {
			error_log( 'Markdown DB: Failed to read options for persist: ' . $e->getMessage() );
			return null;
		}

		if ( ! is_array( $rows ) ) {
			return null;
		}

		$options = array();
		foreach ( $rows as $row ) {
			$name = $row->option_name;
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

		return $options;
	}

	/**
	 * Read existing options.json through the open handle, then merge this
	 * request's dirty keys from SQLite on top.
	 *
	 * @since 0.3.0
	 *
	 * @param resource $fh File handle opened with 'c+' and holding LOCK_EX.
	 * @return array|null Merged option rows (persisted shape), or null on
	 *                    catastrophic error — caller preserves existing file.
	 */
	private function merge_dirty_options( $fh ): ?array {
		// Read existing file via the locked handle (safe against concurrent writers).
		$existing = $this->read_options_from_handle( $fh );

		// Index by option_name for O(1) merge. Preserve original option_id.
		$by_name = array();
		foreach ( $existing as $row ) {
			if ( isset( $row['option_name'] ) ) {
				$by_name[ $row['option_name'] ] = $row;
			}
		}

		$table = $this->prefix . 'options';

		foreach ( array_keys( $this->dirty_option_names ) as $name ) {
			if ( '' === $name || $this->is_ephemeral_option( $name ) ) {
				unset( $by_name[ $name ] );
				continue;
			}

			// Inline-escape the option_name for MySQL string literal context.
			// Option names are already WP-sanitized but be defensive.
			$escaped_name = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $name );

			try {
				$rows = $this->driver->query(
					"SELECT option_id, option_name, option_value, autoload FROM `{$table}` WHERE option_name = '{$escaped_name}'"
				);
			} catch ( \Throwable $e ) {
				error_log( 'Markdown DB: Failed to read dirty option "' . $name . '": ' . $e->getMessage() );
				// Keep existing value rather than lose data on a read error.
				continue;
			}

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				// Option no longer exists in SQLite → it was deleted. Drop from merged set.
				unset( $by_name[ $name ] );
				continue;
			}

			$row                  = $rows[0];
			$by_name[ $name ] = array(
				'option_id'    => (int) $row->option_id,
				'option_name'  => $row->option_name,
				'option_value' => $row->option_value,
				'autoload'     => $row->autoload,
			);
		}

		// Return as a stable-ordered list. Preserve on-disk ordering where
		// possible (options that existed before come first in their
		// original order), with net-new options appended by option_id.
		$out  = array();
		$seen = array();
		foreach ( $existing as $row ) {
			$name = $row['option_name'] ?? '';
			if ( '' === $name ) {
				continue;
			}
			if ( isset( $by_name[ $name ] ) ) {
				$out[]          = $by_name[ $name ];
				$seen[ $name ] = true;
			}
		}
		foreach ( $by_name as $name => $row ) {
			if ( ! isset( $seen[ $name ] ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Read JSON contents through an already-open, locked file handle.
	 *
	 * @since 0.3.0
	 *
	 * @param resource $fh File handle.
	 * @return array Parsed options, or empty array if file is new/empty/unparseable.
	 */
	private function read_options_from_handle( $fh ): array {
		if ( -1 === fseek( $fh, 0 ) ) {
			return array();
		}

		$contents = stream_get_contents( $fh );
		if ( false === $contents || '' === $contents ) {
			return array();
		}

		$decoded = json_decode( $contents, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Write JSON contents through an already-open, locked file handle.
	 *
	 * Uses the locked handle directly — no temp-file rename — because the
	 * flock already serializes writers. Truncate + rewind + write preserves
	 * the lock across the write.
	 *
	 * @since 0.3.0
	 *
	 * @param resource $fh   File handle.
	 * @param array    $data Data to encode.
	 */
	private function write_locked( $fh, array $data ): void {
		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			error_log( 'Markdown DB: Failed to encode options.json.' );
			return;
		}

		if ( -1 === fseek( $fh, 0 ) ) {
			error_log( 'Markdown DB: Failed to seek options.json.' );
			return;
		}

		if ( ! ftruncate( $fh, 0 ) ) {
			error_log( 'Markdown DB: Failed to truncate options.json.' );
			return;
		}

		if ( false === fwrite( $fh, $json ) ) {
			error_log( 'Markdown DB: Failed to write options.json.' );
			return;
		}

		fflush( $fh );
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
			// Extract IDs and delete markdown files + file index entries.
			$ids = $this->extract_ids_from_query( $query, 'ID' );
			foreach ( $ids as $id ) {
				$this->storage->delete_post( $id );
				if ( $this->driver instanceof WP_Markdown_Driver ) {
					$this->driver->remove_from_file_index( $id );
				}
			}

			// Non-markdown posts JSON also needs updating.
			$this->mark_dirty( 'posts_non_markdown' );
			return;
		}

		// For INSERT/UPDATE/REPLACE, read back the affected row and write immediately.
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

		// Defer non-markdown posts JSON update to shutdown.
		if ( $affected_is_non_markdown ) {
			$this->mark_dirty( 'posts_non_markdown' );
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
		// Only convert if the content contains block markup (<!-- wp: --> delimiters).
		// Content written directly as markdown (e.g. via the wiki ability or CLI)
		// should NOT be re-converted — running html_to_markdown on markdown text
		// escapes syntax characters (**→\*\*, [→\[) and produces corrupt files.
		// See GitHub issue #27.
		$content = $row->post_content ?? '';
		if ( ! empty( $content ) && str_contains( $content, '<!-- wp:' ) ) {
			$converter = WP_Markdown_Converter::get_instance();
			$row->post_content = $converter->blocks_to_markdown( $content );
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
		$post_ids = $this->extract_post_ids_from_meta_query( $query );

		// Rewrite the .md file for each affected markdown-type post (immediate).
		foreach ( $post_ids as $post_id ) {
			$this->rewrite_post_if_markdown( $post_id );
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
			// Find affected post IDs and rewrite their .md files (immediate).
			$post_ids = $this->extract_ids_from_query( $query, 'object_id' );
			foreach ( $post_ids as $post_id ) {
				$this->rewrite_post_if_markdown( $post_id );
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
	 * Rewrite a post's .md file if it's a markdown type.
	 *
	 * Used when meta or terms change — the .md file needs to be
	 * regenerated with updated frontmatter.
	 *
	 * @param int $post_id
	 */
	private function rewrite_post_if_markdown( int $post_id ): void {
		$this->persist_single_post( $post_id );
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
			$schema_dir = $this->content_dir . '/_schema';

			if ( ! is_dir( $schema_dir ) ) {
				mkdir( $schema_dir, 0755, true );
			}

			$schema_path = $schema_dir . '/' . $table_suffix . '.sql';

			if ( 'DROP' === $ddl_type ) {
				// Remove schema and data files.
				@unlink( $schema_path );
				@unlink( $this->content_dir . '/_tables/' . $table_suffix . '.json' );
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

}
