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
		'released', 'released-stale-dropin' => 'class WP_PDO_MySQL_On_SQLite extends PDO {}',
		'canonical-prerelease', 'renamed' => 'class WP_MySQL_On_SQLite extends PDO {}',
		default                           => '',
	};

	$driver_version = match ( $surface ) {
		'released', 'released-stale-dropin' => '3.0.0-rc.6',
		'canonical-prerelease'             => '3.0.0-rc.8',
		default                            => '3.0.0',
	};
	file_put_contents( $sqlite . '/wp-includes/database/version.php', "<?php\ndefine( 'SQLITE_DRIVER_VERSION', '{$driver_version}' );\n" );
	file_put_contents( $sqlite . '/constants.php', "<?php\n" );
	file_put_contents( $sqlite . '/wp-includes/database/load.php', "<?php\n{$driver_class}\nclass WP_SQLite_Connection {}\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/db.php', "<?php\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/class-wp-sqlite-db.php', "<?php\nclass WP_SQLite_DB {}\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/install-functions.php', "<?php\n" );

	file_put_contents( $mdi . '/inc/class-wp-markdown-frontmatter-profiles.php', "<?php\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-content-layout-profiles.php', "<?php\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-storage.php', "<?php\nclass WP_Markdown_Storage {}\n" );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-backend-capabilities.php', $mdi . '/inc/class-wp-markdown-backend-capabilities.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-sql-classifier.php', $mdi . '/inc/class-wp-markdown-sql-classifier.php' );
	copy( dirname( __DIR__ ) . '/inc/interface-wp-markdown-backend-operations.php', $mdi . '/inc/interface-wp-markdown-backend-operations.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-mutation-impact.php', $mdi . '/inc/class-wp-markdown-mutation-impact.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-sqlite-operations.php', $mdi . '/inc/class-wp-markdown-sqlite-operations.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-sqlite-runtime-adapter.php', $mdi . '/inc/class-wp-markdown-sqlite-runtime-adapter.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-driver.php', $mdi . '/inc/class-wp-markdown-driver.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-durable-reconciliation-operations.php', $mdi . '/inc/class-wp-markdown-durable-reconciliation-operations.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-reconciliation-adapters.php', $mdi . '/inc/class-wp-markdown-reconciliation-adapters.php' );
	file_put_contents( $mdi . '/inc/class-wp-markdown-search.php', "<?php\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-write-engine.php', "<?php\nclass WP_Markdown_Write_Engine {}\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-loader.php', "<?php\nclass WP_Markdown_Loader {}\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-db.php', "<?php\nclass WP_Markdown_DB { public function __construct( string \$database ) {} }\n" );
	file_put_contents( $mdi . '/markdown-database-integration.php', "<?php\n" );
	$dropin = file_get_contents( dirname( __DIR__ ) . '/db.php' );
	if ( 'released-stale-dropin' === $surface ) {
		$dropin = preg_replace(
			'/\/\/ SQLite Integration renamed the canonical PDO driver after its latest release\..*?^}\n\n/ms',
			'',
			$dropin,
			1,
			$replacements
		);
		if ( 1 !== $replacements ) {
			fwrite( STDERR, "FAIL: could not construct the previous drop-in fixture\n" );
			exit( 1 );
		}
	}
	file_put_contents( $content . '/db.php', $dropin );

	define( 'ABSPATH', $root . '/' );
	define( 'WP_CONTENT_DIR', $content );
	define( 'DB_NAME', 'wordpress' );
	define( 'MARKDOWN_DB_VERSION', 'test' );
	set_error_handler(
		static function ( int $severity, string $message, string $file, int $line ): never {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
	);

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
		if ( str_starts_with( $surface, 'released' ) && ! defined( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API' ) ) {
			fwrite( STDERR, "FAIL: {$surface} did not enable the bounded legacy result API\n" );
			exit( 1 );
		}
		if ( 'canonical-prerelease' === $surface && defined( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API' ) ) {
			fwrite( STDERR, "FAIL: {$surface} incorrectly enabled the legacy result API\n" );
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
foreach ( array( 'released', 'released-stale-dropin', 'canonical-prerelease', 'renamed', 'unsupported' ) as $surface ) {
	$open_basedir = dirname( __DIR__ ) . PATH_SEPARATOR . sys_get_temp_dir();
	$command      = escapeshellarg( PHP_BINARY ) . ' -d ' . escapeshellarg( 'open_basedir=' . $open_basedir ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $surface );
	passthru( $command, $status );
	if ( 0 !== $status ) {
		++$failed;
	}
}

exit( $failed ? 1 : 0 );
