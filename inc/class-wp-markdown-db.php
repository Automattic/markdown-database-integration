<?php
/**
 * Markdown Database — wpdb override.
 *
 * Extends WP_SQLite_DB to:
 * - Use persistent on-disk SQLite as the index/query engine
 * - Load all data from markdown/JSON files on cold boot
 * - Incrementally sync only changed files on warm boot
 * - Persist all writes back to markdown/JSON files
 *
 * Boot modes:
 *   Cold boot: SQLite file doesn't exist → full load from disk
 *   Warm boot: SQLite file exists → incremental sync (stat changed files only)
 *
 * @package Markdown_Database_Integration
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_DB extends WP_SQLite_DB {

	/**
	 * The boot loader (null until boot completes).
	 *
	 * @var WP_Markdown_Loader|null
	 */
	private $loader = null;

	/**
	 * Deferred primary loader action, if the table prefix is not ready yet.
	 *
	 * @var string|null 'load_all' or 'sync_incremental'.
	 */
	private $deferred_primary_loader_action = null;

	/**
	 * Connects to the database.
	 *
	 * In 'primary' mode: uses a persistent on-disk SQLite file as the index.
	 * On cold boot (no file), does a full load from markdown/JSON files.
	 * On warm boot (file exists), does an incremental sync — only re-parses
	 * .md files whose mtime/size changed since last boot.
	 *
	 * In 'mirror' mode: uses the standard on-disk SQLite file and mirrors
	 * writes to markdown files.
	 *
	 * @param bool $allow_bail Not used.
	 * @return void
	 */
	public function db_connect( $allow_bail = true ) {
		if ( $this->dbh ) {
			return;
		}
		$this->init_charset();

		$pdo = null;
		if ( isset( $GLOBALS['@pdo'] ) ) {
			$pdo = $GLOBALS['@pdo'];
		}

		if ( null === $this->dbname || '' === $this->dbname ) {
			$this->bail(
				'The database name was not set.',
				'db_connect_fail'
			);
			return false;
		}

		$mode = defined( 'MARKDOWN_DB_MODE' ) ? MARKDOWN_DB_MODE : 'mirror';

		// Determine the SQLite path.
		if ( 'primary' === $mode ) {
			// Persistent on-disk SQLite — the index file.
			// Stored alongside the content directory for co-location.
			$content_dir_for_path = defined( 'MARKDOWN_DB_CONTENT_DIR' )
				? MARKDOWN_DB_CONTENT_DIR
				: WP_CONTENT_DIR . '/markdown';
			$state_dir_for_path = defined( 'MARKDOWN_DB_STATE_DIR' )
				? MARKDOWN_DB_STATE_DIR
				: $content_dir_for_path;
			$db_path = defined( 'MARKDOWN_DB_INDEX_PATH' )
				? MARKDOWN_DB_INDEX_PATH
				: ( function_exists( 'markdown_database_integration_primary_index_path' )
					? markdown_database_integration_primary_index_path( $content_dir_for_path, $state_dir_for_path )
					: ( rtrim( $state_dir_for_path, '/\\' ) !== rtrim( $content_dir_for_path, '/\\' )
						? rtrim( $state_dir_for_path, '/\\' ) . '/markdown-index.sqlite'
						: dirname( rtrim( $content_dir_for_path, '/\\' ) ) . '/markdown-index.sqlite' ) );
			$db_path = $this->resolve_primary_database_path( $db_path );
			$this->ensure_directory_exists( dirname( $db_path ) );
			// Don't reuse any existing PDO — we need our own connection.
			$pdo = null;
		} else {
			// Phase 1 / mirror: use on-disk SQLite file.
			$db_path = FQDB;
			$this->ensure_database_directory_exists();
		}

		// Create the markdown storage engine.
		$content_dir = defined( 'MARKDOWN_DB_CONTENT_DIR' )
			? MARKDOWN_DB_CONTENT_DIR
			: WP_CONTENT_DIR . '/markdown';
		$state_dir = defined( 'MARKDOWN_DB_STATE_DIR' )
			? MARKDOWN_DB_STATE_DIR
			: $content_dir;

		// Excluded post types — everything else is stored as markdown.
		$excluded_types_raw = defined( 'MARKDOWN_DB_EXCLUDED_TYPES' )
			? MARKDOWN_DB_EXCLUDED_TYPES
			: '';

		$excluded_types = array_filter( array_map( 'trim', explode( ',', $excluded_types_raw ) ) );

		$storage = new WP_Markdown_Storage( $content_dir, $excluded_types );

		// Primary mode: atomic index build to prevent concurrent boot races.
		// See GitHub issue #50.
		//
		// The index file (.sqlite) is the signal for warm vs cold boot.
		// Cold boot builds into a temp file, then renames atomically.
		// Other workers either see the complete file (warm boot) or no
		// file (wait for build to finish, then warm boot).
		if ( 'primary' === $mode ) {
			$this->boot_primary( $db_path, $content_dir, $state_dir, $storage );
		} else {
			try {
				$this->boot_connection( $db_path, $pdo, $mode, false, $content_dir, $state_dir, $storage );
			} catch ( \Throwable $e ) {
				$this->last_error = $e->getMessage();
				error_log( 'Markdown DB connect error: ' . $e->getMessage() . "\n" . $e->getTraceAsString() );
			}
		}

		if ( $this->last_error ) {
			return false;
		}

		$this->ready = true;
		$this->set_sql_mode();
	}

	/**
	 * Boot the database connection, write engine, resolvers, and loader.
	 *
	 * Extracted from db_connect() so it can be retried on corruption.
	 * See GitHub issue #46.
	 *
	 * @param string              $db_path       Path to the SQLite file.
	 * @param \PDO|null           $pdo           Existing PDO connection (null for fresh).
	 * @param string              $mode          Operating mode ('primary' or 'mirror').
	 * @param bool                $is_warm_boot  Whether the SQLite file already exists.
	 * @param string              $content_dir   Path to the markdown content directory.
	 * @param string              $state_dir     Path to the runtime state directory.
	 * @param WP_Markdown_Storage $storage       The markdown storage engine.
	 */
	private function boot_connection(
		string $db_path,
		?\PDO $pdo,
		string $mode,
		bool $is_warm_boot,
		string $content_dir,
		string $state_dir,
		WP_Markdown_Storage $storage
	): void {
		$connection = new WP_SQLite_Connection(
			array(
				'pdo'          => $pdo,
				'path'         => $db_path,
				'journal_mode' => defined( 'SQLITE_JOURNAL_MODE' ) ? SQLITE_JOURNAL_MODE : null,
			)
		);

		$this->dbh       = new WP_Markdown_Driver( $connection, $this->dbname, $storage );
		$GLOBALS['@pdo'] = $this->dbh->get_connection()->get_pdo();

		// Resolve the table prefix.
		//
		// IMPORTANT: in some boot paths (wp-phpunit / homeboy bench
		// dispatcher / any caller where wp-config.php sets $table_prefix
		// as a local variable instead of a global), $table_prefix is
		// NULL at this moment. The fallback to 'wp_' would then bake the
		// wrong prefix into every table name the loader creates,
		// breaking every WordPress query that uses the canonical prefix
		// (e.g. wptests_*). See GitHub issue #77.
		//
		// Instead of capturing the prefix as a value at construct time,
		// we use a closure that resolves the current prefix at call
		// time. By the time anything actually USES the prefix (a
		// resolver query, a write_engine call, the loader's table
		// creation), $table_prefix is set globally even in the
		// pathological boot paths.
		$prefix_resolver = static function (): string {
			global $table_prefix, $wpdb;
			if ( isset( $table_prefix ) && '' !== $table_prefix ) {
				return $table_prefix;
			}
			if ( isset( $wpdb ) && '' !== $wpdb->prefix ) {
				return $wpdb->prefix;
			}
			return 'wp_';
		};

		// Set up the write engine. Pass the resolver so the engine
		// re-reads the prefix on every method call instead of baking
		// the boot-time value (which may be NULL in test boots).
		$write_engine = new WP_Markdown_Write_Engine(
			$content_dir,
			$storage,
			$this->dbh,
			$prefix_resolver,
			$state_dir
		);
		$this->dbh->set_write_engine( $write_engine );

		// Set up the post resolver so the storage engine can build
		// hierarchical directory paths. See GitHub issue #14.
		$driver_ref = $this->dbh;
		$storage->set_post_resolver( function ( int $post_id ) use ( $driver_ref, $prefix_resolver ) {
			$table = $prefix_resolver() . 'posts';
			try {
				$rows = $driver_ref->query(
					"SELECT post_name, post_parent, post_type FROM `{$table}` WHERE ID = {$post_id}"
				);
				if ( is_array( $rows ) && ! empty( $rows ) ) {
					return $rows[0];
				}
			} catch ( \Throwable $e ) {
				// Silently fail — the write engine will use a flat path.
			}
			return null;
		} );

		// Meta resolver — fetches all post meta for a given post ID.
		// Used by build_frontmatter() to embed meta in .md files. See issue #6.
		$storage->set_meta_resolver( function ( int $post_id ) use ( $driver_ref, $prefix_resolver ) {
			$table = $prefix_resolver() . 'postmeta';
			try {
				$rows = $driver_ref->query(
					"SELECT meta_key, meta_value FROM `{$table}` WHERE post_id = {$post_id}"
				);
				return is_array( $rows ) ? $rows : array();
			} catch ( \Throwable $e ) {
				return array();
			}
		} );

		// Terms resolver — fetches all terms for a given post ID.
		// Used by build_frontmatter() to embed terms in .md files. See issue #6.
		$storage->set_terms_resolver( function ( int $post_id ) use ( $driver_ref, $prefix_resolver ) {
			$prefix         = $prefix_resolver();
			$terms_table    = $prefix . 'terms';
			$taxonomy_table = $prefix . 'term_taxonomy';
			$rel_table      = $prefix . 'term_relationships';
			try {
				$rows = $driver_ref->query(
					"SELECT tt.taxonomy, t.slug
					 FROM `{$rel_table}` tr
					 JOIN `{$taxonomy_table}` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					 JOIN `{$terms_table}` t ON tt.term_id = t.term_id
					 WHERE tr.object_id = {$post_id}"
				);
				return is_array( $rows ) ? $rows : array();
			} catch ( \Throwable $e ) {
				return array();
			}
		} );

		// Index writer — upserts `_markdown_file_index` when storage renames
		// a file as a side effect of another write. Without this hook,
		// resolve_parent_dir's promotion of a leaf file to `index.md`
		// leaves the index row pointing at a path that no longer exists,
		// and warm boot churns the promoted post through a full delete
		// and reinsert on every sync. See GitHub issue #68.
		$storage->set_index_writer( function ( int $post_id, string $relative_path, int $mtime, int $size ) use ( $driver_ref ) {
			if ( $driver_ref instanceof WP_Markdown_Driver ) {
				$driver_ref->update_file_index( $post_id, $relative_path, $mtime, $size );
			}
		} );

		// In primary mode, load or sync data.
		//
		if ( 'primary' === $mode ) {
			$this->loader = new WP_Markdown_Loader(
				$content_dir,
				$this->dbh,
				$storage,
				$prefix_resolver,
				$state_dir
			);

			$loader_action = $is_warm_boot ? 'sync_incremental' : 'load_all';
			if ( $this->has_resolved_table_prefix() ) {
				$this->run_primary_loader_action( $loader_action );
			} else {
				$this->deferred_primary_loader_action = $loader_action;
			}
		}
	}

	/**
	 * Sets the table prefix and runs any deferred primary-mode loader work.
	 *
	 * In wp-phpunit-style boots, the drop-in connects before $table_prefix is
	 * global. WordPress calls set_prefix() shortly after require_wp_db(); that is
	 * the earliest safe point to create/sync prefixed tables.
	 *
	 * @param string $prefix          Table prefix.
	 * @param bool   $set_table_names Whether to update table name properties.
	 * @return string|WP_Error Old prefix or WP_Error, matching wpdb::set_prefix().
	 */
	public function set_prefix( $prefix, $set_table_names = true ) {
		$result = parent::set_prefix( $prefix, $set_table_names );
		$this->run_deferred_primary_loader();
		return $result;
	}

	/**
	 * Whether WordPress has finalized a usable table prefix.
	 *
	 * @return bool True when a real prefix is available.
	 */
	private function has_resolved_table_prefix(): bool {
		global $table_prefix;

		if ( isset( $table_prefix ) && '' !== $table_prefix ) {
			return true;
		}

		return isset( $this->prefix ) && '' !== $this->prefix;
	}

	/**
	 * Run deferred primary-mode loader work once the prefix is ready.
	 */
	private function run_deferred_primary_loader(): void {
		if ( null === $this->deferred_primary_loader_action || ! $this->has_resolved_table_prefix() ) {
			return;
		}

		$action = $this->deferred_primary_loader_action;
		$this->deferred_primary_loader_action = null;
		$this->run_primary_loader_action( $action );
	}

	/**
	 * Run the requested primary loader action.
	 *
	 * @param string $action 'load_all' or 'sync_incremental'.
	 */
	private function run_primary_loader_action( string $action ): void {
		if ( null === $this->loader ) {
			return;
		}

		if ( 'sync_incremental' === $action ) {
			$this->loader->sync_incremental();
			return;
		}

		$this->loader->load_all();
	}

	/**
	 * Boot in primary mode with isolated cold boot.
	 *
	 * Each worker builds its index independently — no shared writes.
	 *
	 * Warm boot: index file exists → connect to it, sync incrementally.
	 * Cold boot: index file doesn't exist → build into a private temp
	 *   file (unique per worker), then rename atomically into place.
	 *   If rename fails (another worker beat us), discard our temp file
	 *   and warm-boot from theirs.
	 *
	 * This avoids all cross-worker coordination. Concurrent cold boots
	 * each build independently, the first rename wins, losers discard
	 * their work. No locking, no sentinels, no shared SQLite writes.
	 *
	 * See GitHub issue #50.
	 *
	 * @param string              $db_path     Path to the final SQLite index file.
	 * @param string              $content_dir Path to the markdown content directory.
	 * @param string              $state_dir   Path to the runtime state directory.
	 * @param WP_Markdown_Storage $storage     The markdown storage engine.
	 */
	private function boot_primary(
		string $db_path,
		string $content_dir,
		string $state_dir,
		WP_Markdown_Storage $storage
	): void {
		// --- Warm boot: index exists ---
		if ( file_exists( $db_path ) ) {
			try {
				$this->boot_connection( $db_path, null, 'primary', true, $content_dir, $state_dir, $storage );
				return;
			} catch ( \Throwable $e ) {
				// Index exists but is unusable (corrupted, incomplete from
				// a concurrent worker's rename, etc.). Fall through to cold
				// boot — we'll build our own and try to rename over it.
				$this->dbh        = null;
				$this->last_error = '';
				$this->loader     = null;
			}
		}

		// --- Cold boot: build into a private temp file ---
		//
		// Each worker gets its own temp file (unique suffix). Multiple
		// workers may build simultaneously — that's fine, they don't
		// share a file. The first to finish renames into place.
		$tmp_path = $db_path . '.tmp.' . getmypid() . '.' . substr( md5( uniqid( '', true ) ), 0, 8 );

		try {
			$this->boot_connection( $tmp_path, null, 'primary', false, $content_dir, $state_dir, $storage );

			// Checkpoint WAL so the temp file is self-contained.
			$pdo = $this->dbh->get_connection()->get_pdo();
			$pdo->exec( 'PRAGMA wal_checkpoint(TRUNCATE)' );

			// Check: did another worker finish first?
			if ( file_exists( $db_path ) ) {
				// Someone beat us. Discard our temp file, use theirs.
				$this->dbh        = null;
				$this->last_error = '';
				$this->loader     = null;
				$this->cleanup_index_files( $tmp_path );

				try {
					$this->boot_connection( $db_path, null, 'primary', true, $content_dir, $state_dir, $storage );
					return;
				} catch ( \Throwable $e ) {
					// Their file is bad too? Delete it, fall through to our rename.
					error_log( 'Markdown DB: Rival index unreadable (' . $e->getMessage() . '). Using ours.' );
					$this->cleanup_index_files( $db_path );
					$this->dbh        = null;
					$this->last_error = '';
					$this->loader     = null;
					// Re-build connection to our temp file for the rename below.
					$this->boot_connection( $tmp_path, null, 'primary', true, $content_dir, $state_dir, $storage );
				}
			}

			// Atomic swap: rename our temp file into place.
			// The open PDO connection follows the inode, not the path,
			// so it keeps working after rename. No reconnect needed.
			$renamed = @rename( $tmp_path, $db_path );

			// Also move any leftover WAL/journal files.
			if ( file_exists( $tmp_path . '-wal' ) ) {
				@rename( $tmp_path . '-wal', $db_path . '-wal' );
			}
			if ( file_exists( $tmp_path . '-shm' ) ) {
				@rename( $tmp_path . '-shm', $db_path . '-shm' );
			}

			if ( ! $renamed ) {
				// Rename failed — another worker likely beat us.
				// Discard our connection and use the winner's index.
				$this->dbh        = null;
				$this->last_error = '';
				$this->loader     = null;
				$this->cleanup_index_files( $tmp_path );

				$this->boot_connection( $db_path, null, 'primary', true, $content_dir, $state_dir, $storage );
			}
			// If rename succeeded, we keep our existing connection — it's
			// already fully loaded from the cold boot above.

		} catch ( \Throwable $e ) {
			// Build failed — clean up.
			$this->cleanup_index_files( $tmp_path );
			$this->last_error = $e->getMessage();
			error_log( 'Markdown DB: Cold boot failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString() );
		}
	}

	/**
	 * Resolve the writable SQLite index path for primary mode.
	 *
	 * Markdown files are the durable database in primary mode; the SQLite index is
	 * runtime query state. When the markdown store is mounted read-only, such as a
	 * WordPress Playground plugin mount, keep the index in PHP's temp directory.
	 *
	 * @param string $db_path Preferred SQLite index path.
	 * @return string Writable SQLite index path.
	 */
	private function resolve_primary_database_path( string $db_path ): string {
		$dir = dirname( $db_path );
		if ( ! $this->is_playground_runtime() && is_dir( $dir ) && is_writable( $dir ) ) {
			return $db_path;
		}

		$temp_dir = rtrim( sys_get_temp_dir(), '/\\' );
		$hash     = substr( md5( $db_path ), 0, 12 );

		return $temp_dir . '/markdown-index-' . $hash . '.sqlite';
	}

	/**
	 * Whether this request is running inside WordPress Playground.
	 *
	 * Playground mounts plugin directories through a VFS layer where PHP file
	 * writes may appear available, but SQLite write transactions can fail as
	 * read-only. Keep runtime indexes in PHP temp there.
	 *
	 * @return bool True when running in WordPress Playground.
	 */
	private function is_playground_runtime(): bool {
		return file_exists( '/internal/shared/sqlite-database-integration/wp-includes/sqlite/db.php' );
	}

	/**
	 * Delete a SQLite file and its associated journal/WAL files.
	 *
	 * @param string $path Path to the SQLite file.
	 */
	private function cleanup_index_files( string $path ): void {
		@unlink( $path );
		@unlink( $path . '-wal' );
		@unlink( $path . '-shm' );
		@unlink( $path . '-journal' );
	}

	/**
	 * Get the boot loader (for debugging/timing).
	 *
	 * @return WP_Markdown_Loader|null
	 */
	public function get_loader(): ?WP_Markdown_Loader {
		return $this->loader;
	}

	/**
	 * Ensure a directory exists.
	 *
	 * @param string $dir Directory path.
	 */
	private function ensure_directory_exists( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			$umask = umask( 0 );
			@mkdir( $dir, 0755, true );
			umask( $umask );
		}
	}

	/**
	 * Ensure the SQLite database directory exists with protections.
	 */
	private function ensure_database_directory_exists(): void {
		$dir = dirname( FQDB );
		$this->ensure_directory_exists( $dir );

		// .htaccess protection.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, 'DENY FROM ALL', LOCK_EX );
		}

		// index.php protection.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '<?php // Silence is gold. ?>', LOCK_EX );
		}
	}
}
