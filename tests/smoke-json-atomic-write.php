<?php
/**
 * Smoke tests for atomic JSON table mirror writes.
 *
 * Usage: php tests/smoke-json-atomic-write.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';

if ( ! class_exists( 'WP_SQLite_Driver' ) ) {
	class WP_SQLite_Driver {}
}

require_once __DIR__ . '/../inc/class-wp-markdown-write-engine.php';

$passed = 0;
$failed = 0;

function assert_eq( $actual, $expected, string $label ): void {
	global $passed, $failed;
	if ( $actual === $expected ) {
		echo '✓ ' . $label . PHP_EOL;
		$passed++;
		return;
	}

	echo '✗ ' . $label . PHP_EOL;
	echo '  expected: ' . var_export( $expected, true ) . PHP_EOL;
	echo '  actual:   ' . var_export( $actual, true ) . PHP_EOL;
	$failed++;
}

function assert_true( bool $cond, string $label, string $detail = '' ): void {
	global $passed, $failed;
	if ( $cond ) {
		echo '✓ ' . $label . PHP_EOL;
		$passed++;
		return;
	}

	echo '✗ ' . $label . ( $detail !== '' ? ' — ' . $detail : '' ) . PHP_EOL;
	$failed++;
}

function build_write_engine( string $content_dir ): WP_Markdown_Write_Engine {
	return new WP_Markdown_Write_Engine(
		$content_dir,
		new WP_Markdown_Storage( $content_dir ),
		new WP_SQLite_Driver(),
		'wp_'
	);
}

$base = sys_get_temp_dir() . '/mdi-json-atomic-write-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $base . '/_tables', 0755, true );

$engine       = build_write_engine( $base );
$tmp_method   = new ReflectionMethod( $engine, 'json_tmp_path' );
$write_method = new ReflectionMethod( $engine, 'write_json' );
$path         = $base . '/_tables/actionscheduler_actions.json';

// 1. Temp paths must be unique within one PHP process. The previous
// {$path}.tmp.{pid} shape let two write-engine instances in the same
// WP-CLI request overwrite each other's temp file before rename().
$tmp_paths = array();
for ( $i = 0; $i < 50; $i++ ) {
	$tmp_paths[] = (string) $tmp_method->invoke( $engine, $path );
}

assert_eq(
	count( array_unique( $tmp_paths ) ),
	count( $tmp_paths ),
	'temp paths are unique per call'
);
assert_eq(
	count( array_filter( $tmp_paths, static function ( string $tmp ) use ( $path ): bool { return str_starts_with( $tmp, $path . '.tmp.' ); } ) ),
	count( $tmp_paths ),
	'temp paths stay beside destination'
);
assert_eq(
	preg_match( '/\.tmp\.' . preg_quote( (string) getmypid(), '/' ) . '\.[a-f0-9]{8}$/', $tmp_paths[0] ),
	1,
	'temp paths include pid and unique suffix'
);

// 2. Repeated writes to the same table mirror path in one request should
// complete without PHP rename warnings and leave no stale temp files.
$warnings = array();
set_error_handler(
	static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
		$warnings[] = $errstr;
		return true;
	}
);

$write_method->invoke( $engine, $path, array( array( 'action_id' => 1, 'status' => 'pending' ) ) );
$write_method->invoke( build_write_engine( $base ), $path, array( array( 'action_id' => 2, 'status' => 'complete' ) ) );
$write_method->invoke( $engine, $path, array( array( 'action_id' => 3, 'status' => 'failed' ) ) );

restore_error_handler();

$decoded = json_decode( (string) file_get_contents( $path ), true );
$stale   = glob( $path . '.tmp.*' ) ?: array();

assert_true( empty( $warnings ), 'repeated writes emit no PHP warnings', implode( ' | ', $warnings ) );
assert_true( is_array( $decoded ), 'final JSON exists and parses' );
assert_eq( isset( $decoded[0]['action_id'] ) ? (int) $decoded[0]['action_id'] : null, 3, 'final write wins' );
assert_true( empty( $stale ), 'no stale temp files remain', implode( ', ', $stale ) );

foreach ( glob( $base . '/_tables/*' ) ?: array() as $file ) {
	@unlink( $file );
}
@rmdir( $base . '/_tables' );
@rmdir( $base );

if ( $failed > 0 ) {
	echo PHP_EOL . "Failed: {$failed}" . PHP_EOL;
	exit( 1 );
}

echo PHP_EOL . "All {$passed} assertions passed." . PHP_EOL;
