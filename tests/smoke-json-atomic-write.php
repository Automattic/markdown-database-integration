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
mkdir( $base . '/_options', 0755, true );

$engine       = build_write_engine( $base );
$tmp_method   = new ReflectionMethod( $engine, 'json_tmp_path' );
$write_method = new ReflectionMethod( $engine, 'write_json' );
$write_option = new ReflectionMethod( $engine, 'write_option_file' );
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

// 3. Option files use the same bounded atomic temp-path primitive as table
// snapshots and leave no process-local temp artifacts after replacement.
$source       = (string) file_get_contents( __DIR__ . '/../inc/class-wp-markdown-write-engine.php' );
$option_start = strpos( $source, 'private function write_option_file' );
$option_end   = strpos( $source, 'private function delete_option_file', $option_start );
$option_code  = substr( $source, $option_start, $option_end - $option_start );
$option_path  = $base . '/_options/siteurl.json';
$option_row   = array(
	'option_id'    => 1,
	'option_name'  => 'siteurl',
	'option_value' => 'https://first.example',
	'autoload'     => 'yes',
);

$write_option->invoke( $engine, 'siteurl', $option_row );
$option_row['option_value'] = 'https://second.example';
$write_option->invoke( build_write_engine( $base ), 'siteurl', $option_row );

$option_decoded = json_decode( (string) file_get_contents( $option_path ), true );
$option_stale   = glob( $option_path . '.tmp.*' ) ?: array();

assert_true( str_contains( $option_code, '$this->json_tmp_path( $abs )' ), 'option writes share the bounded temp-path primitive' );
assert_eq( $option_decoded['option_value'] ?? null, 'https://second.example', 'final option write wins' );
assert_true( empty( $option_stale ), 'option writes leave no stale temp files', implode( ', ', $option_stale ) );

foreach ( glob( $base . '/_tables/*' ) ?: array() as $file ) {
	@unlink( $file );
}
foreach ( glob( $base . '/_options/*' ) ?: array() as $file ) {
	@unlink( $file );
}
@rmdir( $base . '/_tables' );
@rmdir( $base . '/_options' );
@rmdir( $base );

if ( $failed > 0 ) {
	echo PHP_EOL . "Failed: {$failed}" . PHP_EOL;
	exit( 1 );
}

echo PHP_EOL . "All {$passed} assertions passed." . PHP_EOL;
