<?php
/**
 * WordPress-shaped db.php bootstrap coverage for backend selection.
 *
 * Usage: php tests/smoke-dropin-backend-selection.php
 */

declare( strict_types=1 );

if ( 2 === $argc ) {
	$scenario = $argv[1];
	$root     = sys_get_temp_dir() . '/mdi-dropin-backend-' . $scenario . '-' . getmypid();
	$content  = $root . '/wp-content';
	$sqlite   = $content . '/mu-plugins/sqlite-database-integration';
	$mdi      = $content . '/plugins/markdown-database-integration';
	foreach ( array( $sqlite . '/wp-includes/database', $sqlite . '/wp-includes/sqlite', $mdi . '/inc' ) as $directory ) {
		mkdir( $directory, 0755, true );
	}
	register_shutdown_function( static function () use ( $root ): void {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $root );
	} );

	file_put_contents( $sqlite . '/wp-includes/database/version.php', "<?php\n" );
	file_put_contents( $sqlite . '/constants.php', "<?php\n" );
	file_put_contents( $sqlite . '/wp-includes/database/load.php', "<?php\nclass WP_MySQL_On_SQLite extends PDO {}\nclass WP_SQLite_Connection {}\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/db.php', "<?php\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/class-wp-sqlite-db.php', "<?php\nclass WP_SQLite_DB {}\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/install-functions.php', "<?php\n" );
	foreach ( array( 'class-wp-markdown-frontmatter-profiles.php', 'class-wp-markdown-content-layout-profiles.php', 'class-wp-markdown-storage.php', 'class-wp-markdown-search.php', 'class-wp-markdown-write-engine.php', 'class-wp-markdown-loader.php' ) as $file ) {
		file_put_contents( $mdi . '/inc/' . $file, "<?php\n" );
	}
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-backend-capabilities.php', $mdi . '/inc/class-wp-markdown-backend-capabilities.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-durable-reconciliation-operations.php', $mdi . '/inc/class-wp-markdown-durable-reconciliation-operations.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-reconciliation-adapters.php', $mdi . '/inc/class-wp-markdown-reconciliation-adapters.php' );
	file_put_contents( $mdi . '/inc/class-wp-markdown-driver.php', "<?php\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-db.php', "<?php\nclass WP_Markdown_DB { public function __construct( string \$database ) {} }\n" );
	file_put_contents( $mdi . '/markdown-database-integration.php', "<?php\n" );
	copy( dirname( __DIR__ ) . '/db.php', $content . '/db.php' );

	define( 'ABSPATH', $root . '/' );
	define( 'WP_CONTENT_DIR', $content );
	define( 'DB_NAME', 'wordpress' );
	define( 'MARKDOWN_DB_VERSION', 'test' );
	if ( 'unknown' === $scenario ) {
		define( 'MARKDOWN_DB_BACKEND', 'unknown' );
	} elseif ( 'incomplete' === $scenario ) {
		define( 'MARKDOWN_DB_BACKEND', 'incomplete' );
		$GLOBALS['markdown_db_backend_declarations'] = array( 'incomplete' => array() );
	}

	try {
		require $content . '/db.php';
		if ( 'sqlite' !== $scenario || ! isset( $GLOBALS['wpdb'] ) ) {
			throw new RuntimeException( 'Expected backend bootstrap failure.' );
		}
		echo "PASS: SQLite remains the default drop-in backend.\n";
	} catch ( WP_Markdown_Unknown_Backend|WP_Markdown_Unsupported_Backend_Capability $error ) {
		$diagnostic = $error->get_diagnostic();
		if ( 'unknown' === $scenario && 'markdown_db_unknown_backend' === $diagnostic['code'] && 'unknown' === $diagnostic['backend'] ) {
			echo "PASS: unknown backend fails closed with a structured diagnostic.\n";
			exit( 0 );
		}
		if ( 'incomplete' === $scenario && 'markdown_db_unsupported_backend_capability' === $diagnostic['code'] && 'incomplete' === $diagnostic['backend'] ) {
			echo "PASS: incomplete backend fails closed with a structured diagnostic.\n";
			exit( 0 );
		}
		throw $error;
	}
	exit( 0 );
}

$failed = 0;
foreach ( array( 'sqlite', 'unknown', 'incomplete' ) as $scenario ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario ), $status );
	if ( 0 !== $status ) {
		++$failed;
	}
}
exit( $failed ? 1 : 0 );
