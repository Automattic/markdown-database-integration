<?php
/**
 * Markdown Database — wpdb override.
 *
 * Extends WP_SQLite_DB to:
 * - Use in-memory SQLite (:memory:) as the runtime query engine
 * - Load all data from markdown/JSON files at boot
 * - Persist all writes back to markdown/JSON files
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
	 * Connects to the database.
	 *
	 * In 'primary' mode (Phase 2): creates an in-memory SQLite database,
	 * loads all data from markdown/JSON files, then WordPress proceeds
	 * with the in-memory database as its query engine.
	 *
	 * In 'mirror' mode (Phase 1): uses the on-disk SQLite file and
	 * mirrors writes to markdown files.
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
			// Phase 2: in-memory database — markdown files are the source of truth.
			$db_path = ':memory:';
			// Don't reuse any existing PDO — we need a fresh in-memory database.
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

		// Excluded post types — everything else is stored as markdown.
		$excluded_types_raw = defined( 'MARKDOWN_DB_EXCLUDED_TYPES' )
			? MARKDOWN_DB_EXCLUDED_TYPES
			: '';

		$excluded_types = array_filter( array_map( 'trim', explode( ',', $excluded_types_raw ) ) );

		$storage = new WP_Markdown_Storage( $content_dir, $excluded_types );

		try {
			$connection = new WP_SQLite_Connection(
				array(
					'pdo'          => $pdo,
					'path'         => $db_path,
					'journal_mode' => defined( 'SQLITE_JOURNAL_MODE' ) ? SQLITE_JOURNAL_MODE : null,
				)
			);

			$this->dbh       = new WP_Markdown_Driver( $connection, $this->dbname, $storage );
			$GLOBALS['@pdo'] = $this->dbh->get_connection()->get_pdo();

			// Set up the write engine.
			global $table_prefix;
			$prefix = $table_prefix ?? 'wp_';

			$write_engine = new WP_Markdown_Write_Engine(
				$content_dir,
				$storage,
				$this->dbh,
				$prefix
			);
			$this->dbh->set_write_engine( $write_engine );

			// Set up the post resolver so the storage engine can build
			// hierarchical directory paths. See GitHub issue #14.
			$driver_ref = $this->dbh;
			$prefix_ref = $prefix;
			$storage->set_post_resolver( function ( int $post_id ) use ( $driver_ref, $prefix_ref ) {
				$table = $prefix_ref . 'posts';
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
			$storage->set_meta_resolver( function ( int $post_id ) use ( $driver_ref, $prefix_ref ) {
				$table = $prefix_ref . 'postmeta';
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
			$storage->set_terms_resolver( function ( int $post_id ) use ( $driver_ref, $prefix_ref ) {
				$terms_table    = $prefix_ref . 'terms';
				$taxonomy_table = $prefix_ref . 'term_taxonomy';
				$rel_table      = $prefix_ref . 'term_relationships';
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

			// In primary mode, load all data from files into memory.
			if ( 'primary' === $mode ) {
				$this->loader = new WP_Markdown_Loader(
					$content_dir,
					$this->dbh,
					$storage,
					$prefix
				);
				$this->loader->load_all();
			}
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
			error_log( 'Markdown DB connect error: ' . $e->getMessage() . "\n" . $e->getTraceAsString() );
		}

		if ( $this->last_error ) {
			return false;
		}

		$this->ready = true;
		$this->set_sql_mode();
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
	 * Ensure the SQLite database directory exists.
	 */
	private function ensure_database_directory_exists(): void {
		$dir = dirname( FQDB );
		if ( ! is_dir( $dir ) ) {
			$umask = umask( 0 );
			@mkdir( $dir, 0700, true );
			umask( $umask );
		}

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
