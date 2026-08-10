<?php
/**
 * Regression coverage for warning-free Playground runtime detection.
 *
 * Usage: php tests/smoke-playground-runtime-detection.php
 */

declare( strict_types=1 );

if ( 2 === $argc ) {
	$is_playground = 'playground' === $argv[1];
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'MARKDOWN_DB_PLAYGROUND_RUNTIME', $is_playground );
	class WP_SQLite_DB {}
	set_error_handler(
		static function ( int $severity, string $message, string $file, int $line ): never {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
	);

	require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-db.php';
	$database = ( new ReflectionClass( WP_Markdown_DB::class ) )->newInstanceWithoutConstructor();
	$detector = new ReflectionMethod( $database, 'is_playground_runtime' );
	$actual   = $detector->invoke( $database );

	if ( $is_playground !== $actual ) {
		fwrite( STDERR, 'FAIL: runtime constant was not honored' . PHP_EOL );
		exit( 1 );
	}

	echo 'PASS: ' . $argv[1] . ' runtime detection' . PHP_EOL;
	exit( 0 );
}

$failed       = 0;
$open_basedir = dirname( __DIR__ ) . PATH_SEPARATOR . sys_get_temp_dir();
foreach ( array( 'native', 'playground' ) as $runtime ) {
	$command = escapeshellarg( PHP_BINARY ) . ' -d ' . escapeshellarg( 'open_basedir=' . $open_basedir ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $runtime );
	passthru( $command, $status );
	if ( 0 !== $status ) {
		++$failed;
	}
}

exit( $failed ? 1 : 0 );
