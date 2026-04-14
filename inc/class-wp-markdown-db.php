<?php
/**
 * Markdown Database — wpdb override.
 *
 * Extends WP_SQLite_DB to inject the WP_Markdown_Driver as the database handle.
 * This is the class that gets assigned to $GLOBALS['wpdb'].
 *
 * Everything works exactly like the SQLite integration, except writes to
 * wp_posts are also synced to markdown files on disk.
 *
 * @package Markdown_Database_Integration
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_DB extends WP_SQLite_DB {

	/**
	 * Connects to the database.
	 *
	 * Overrides WP_SQLite_DB::db_connect() to use our WP_Markdown_Driver
	 * instead of the standard WP_SQLite_Driver.
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

		// Ensure the database directory exists.
		$this->ensure_database_directory_exists();

		// Create the markdown storage engine.
		$content_dir = defined( 'MARKDOWN_DB_CONTENT_DIR' )
			? MARKDOWN_DB_CONTENT_DIR
			: WP_CONTENT_DIR . '/markdown';

		$post_types_raw = defined( 'MARKDOWN_DB_POST_TYPES' )
			? MARKDOWN_DB_POST_TYPES
			: 'post,page';

		$post_types = array_map( 'trim', explode( ',', $post_types_raw ) );

		$storage = new WP_Markdown_Storage( $content_dir, $post_types );

		$mode = defined( 'MARKDOWN_DB_MODE' ) ? MARKDOWN_DB_MODE : 'mirror';

		try {
			$connection = new WP_SQLite_Connection(
				array(
					'pdo'          => $pdo,
					'path'         => FQDB,
					'journal_mode' => defined( 'SQLITE_JOURNAL_MODE' ) ? SQLITE_JOURNAL_MODE : null,
				)
			);

			$this->dbh       = new WP_Markdown_Driver( $connection, $this->dbname, $storage, $mode );
			$GLOBALS['@pdo'] = $this->dbh->get_connection()->get_pdo();
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();
		}

		if ( $this->last_error ) {
			return false;
		}

		$this->ready = true;
		$this->set_sql_mode();
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
