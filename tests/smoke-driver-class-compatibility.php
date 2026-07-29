<?php
/**
 * Regression coverage for released and renamed SQLite PDO driver classes.
 *
 * Usage: php tests/smoke-driver-class-compatibility.php
 */

declare( strict_types=1 );

if ( 2 === $argc ) {
	$surface = $argv[1];
	$root    = sys_get_temp_dir() . '/mdi-driver-compat-' . $surface . '-' . getmypid();
	$content = $root . '/wp-content';
	$sqlite  = $content . '/mu-plugins/sqlite-database-integration';
	$mdi     = $content . '/plugins/markdown-database-integration';
	register_shutdown_function(
		static function () use ( $root ): void {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $files as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $root );
		}
	);

	foreach ( array(
		$sqlite . '/wp-includes/database',
		$sqlite . '/wp-includes/sqlite',
		$mdi . '/inc',
	) as $directory ) {
		mkdir( $directory, 0755, true );
	}

	$driver_class = match ( $surface ) {
		'released' => 'class WP_PDO_MySQL_On_SQLite extends PDO {}',
		'renamed'  => 'class WP_MySQL_On_SQLite extends PDO {}',
		default    => '',
	};

	file_put_contents( $sqlite . '/wp-includes/database/version.php', "<?php\n" );
	file_put_contents( $sqlite . '/constants.php', "<?php\n" );
	file_put_contents( $sqlite . '/wp-includes/database/load.php', "<?php\n{$driver_class}\nclass WP_SQLite_Connection {}\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/db.php', "<?php\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/class-wp-sqlite-db.php', "<?php\nclass WP_SQLite_DB {}\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/install-functions.php', "<?php\n" );

	file_put_contents( $mdi . '/inc/class-wp-markdown-frontmatter-profiles.php', "<?php\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-storage.php', "<?php\nclass WP_Markdown_Storage {}\n" );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-driver.php', $mdi . '/inc/class-wp-markdown-driver.php' );
	file_put_contents( $mdi . '/inc/class-wp-markdown-search.php', "<?php\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-write-engine.php', "<?php\nclass WP_Markdown_Write_Engine {}\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-loader.php', "<?php\nclass WP_Markdown_Loader {}\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-db.php', "<?php\nclass WP_Markdown_DB { public function __construct( string \$database ) {} }\n" );
	file_put_contents( $mdi . '/markdown-database-integration.php', "<?php\n" );
	copy( dirname( __DIR__ ) . '/db.php', $content . '/db.php' );

	define( 'ABSPATH', $root . '/' );
	define( 'WP_CONTENT_DIR', $content );
	define( 'DB_NAME', 'wordpress' );
	define( 'MARKDOWN_DB_VERSION', 'test' );

	try {
		require $content . '/db.php';
		if ( 'unsupported' === $surface ) {
			fwrite( STDERR, "FAIL: unsupported driver surface did not fail\n" );
			exit( 1 );
		}
		if ( ! class_exists( 'WP_MySQL_On_SQLite', false ) || ! isset( $GLOBALS['wpdb'] ) ) {
			fwrite( STDERR, "FAIL: {$surface} driver surface did not boot\n" );
			exit( 1 );
		}
	} catch ( RuntimeException $error ) {
		if ( 'unsupported' !== $surface || ! str_contains( $error->getMessage(), 'WP_PDO_MySQL_On_SQLite' ) ) {
			throw $error;
		}
	}

	echo "PASS: {$surface} SQLite driver surface\n";
	exit( 0 );
}

$failed = 0;
foreach ( array( 'released', 'renamed', 'unsupported' ) as $surface ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $surface );
	passthru( $command, $status );
	if ( 0 !== $status ) {
		++$failed;
	}
}

exit( $failed ? 1 : 0 );
