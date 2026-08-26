<?php
/**
 * Smoke tests for bounded orphaned JSON temp-file reclamation.
 *
 * Usage: php tests/smoke-json-temp-reclamation.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$mdi_temp_filters = array();
function add_filter( string $tag, callable $callback ): void {
	global $mdi_temp_filters;
	$mdi_temp_filters[ $tag ][] = $callback;
}
function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
	global $mdi_temp_filters;
	foreach ( $mdi_temp_filters[ $tag ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';
require_once __DIR__ . '/stubs/stub-wp-markdown-backend-operations.php';
require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';

class MDI_Failing_Temp_Cleanup_Engine extends WP_Markdown_Canonical_Persistence {
	protected function remove_json_temp_file( string $path ): bool {
		unset( $path );
		return false;
	}

}

$passed = 0;
$failed = 0;
function mdi_temp_assert( bool $condition, string $label ): void {
	global $passed, $failed;
	echo ( $condition ? '✓ ' : '✗ ' ) . $label . PHP_EOL;
	$condition ? $passed++ : $failed++;
}
function mdi_temp_engine( string $base, bool $failing = false ): WP_Markdown_Canonical_Persistence {
	$class = $failing ? MDI_Failing_Temp_Cleanup_Engine::class : WP_Markdown_Canonical_Persistence::class;
	return new $class( $base, new WP_Markdown_Storage( $base ), new WP_Markdown_Test_Backend_Operations(), 'wp_' );
}
function mdi_temp_file( string $path, int $pid, string $suffix, int $mtime ): string {
	$temp = $path . '.tmp.' . $pid . '.' . $suffix;
	file_put_contents( $temp, 'orphan' );
	touch( $temp, $mtime );
	return $temp;
}

function mdi_temp_locked_file( string $path, int $pid, string $suffix, int $mtime ) {
	$temp = mdi_temp_file( $path, $pid, $suffix, $mtime );
	$handle = fopen( $temp, 'rb' );
	if ( false === $handle || ! flock( $handle, LOCK_EX ) ) {
		throw new RuntimeException( 'Could not lock temp fixture.' );
	}
	return array( $temp, $handle );
}

add_filter( 'markdown_database_integration_json_temp_cleanup_max_age', static fn (): int => 60 );
$base = sys_get_temp_dir() . '/mdi-json-temp-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $base . '/_tables', 0755, true );
$path = $base . '/_tables/events.json';
$write = new ReflectionMethod( WP_Markdown_Canonical_Persistence::class, 'write_json' );

$stale = mdi_temp_file( $path, 999999, 'deadbeef', time() - 120 );
$fresh = mdi_temp_file( $path, 999998, 'cafebabe', time() );
list( $active, $active_handle ) = mdi_temp_locked_file( $path, getmypid(), '8badf00d', time() - 120 );
$wrong = $path . '.tmp.999997.not-hex';
$other = $base . '/_tables/other.json.tmp.999996.deadbeef';
file_put_contents( $wrong, 'unrelated' );
file_put_contents( $other, 'unrelated' );
$write->invoke( mdi_temp_engine( $base ), $path, array( array( 'id' => 1 ) ) );

mdi_temp_assert( ! file_exists( $stale ), 'stale orphan matching the destination is reclaimed' );
mdi_temp_assert( file_exists( $fresh ), 'fresh concurrent temp is preserved' );
mdi_temp_assert( file_exists( $active ), 'old temp locked by an active writer is preserved' );
mdi_temp_assert( file_exists( $wrong ) && file_exists( $other ), 'malformed and unrelated temp-like files are preserved' );
flock( $active_handle, LOCK_UN );
fclose( $active_handle );

$unlocked = mdi_temp_file( $path, 999995, 'decafbad', time() - 120 );
$cleanup = new ReflectionMethod( WP_Markdown_Canonical_Persistence::class, 'cleanup_json_temp_files' );
$cleanup->invoke( mdi_temp_engine( $base ), $path );
mdi_temp_assert( ! file_exists( $unlocked ), 'unlocked temp from a terminated writer is reclaimed without PID liveness checks' );

$bounded_base = $base . '/bounded';
mkdir( $bounded_base, 0755, true );
$bounded_path = $bounded_base . '/bounded.json';
add_filter( 'markdown_database_integration_json_temp_cleanup_limit', static fn ( int $limit, string $path ): int => 'bounded.json' === basename( $path ) ? 1 : $limit );
$unrelated = array();
for ( $i = 1; $i <= 200; $i++ ) {
	$unrelated[] = $bounded_base . '/unrelated-' . $i;
	file_put_contents( $unrelated[ $i - 1 ], 'unrelated' );
}
$bounded = array();
for ( $i = 1; $i <= 3; $i++ ) {
	$bounded[] = mdi_temp_file( $bounded_path, 999990 + $i, sprintf( '%08x', $i ), time() - 120 );
}
$bounded_engine = mdi_temp_engine( $base );
$cleanup->invoke( $bounded_engine, $bounded_path );
mdi_temp_assert( 2 === count( array_filter( $bounded, 'file_exists' ) ), 'cleanup examines one matching candidate despite many unrelated entries' );
$cleanup->invoke( $bounded_engine, $bounded_path );
$cleanup->invoke( $bounded_engine, $bounded_path );
mdi_temp_assert( 0 === count( array_filter( $bounded, 'file_exists' ) ), 'repeated bounded scans rotate through candidates without starvation' );

$failed_path = $base . '/_tables/failed.json';
$failed_temp = mdi_temp_file( $failed_path, 999980, 'feedface', time() - 120 );
$log = $base . '/cleanup.log';
$previous_log = ini_set( 'error_log', $log );
$write->invoke( mdi_temp_engine( $base, true ), $failed_path, array( array( 'id' => 3 ) ) );
ini_set( 'error_log', (string) $previous_log );
mdi_temp_assert( file_exists( $failed_path ) && array( array( 'id' => 3 ) ) === json_decode( (string) file_get_contents( $failed_path ), true ), 'cleanup failure does not break a successful canonical write' );
mdi_temp_assert( file_exists( $failed_temp ) && str_contains( (string) file_get_contents( $log ), 'Failed to reclaim stale JSON temp file' ), 'cleanup failure remains observable' );

foreach ( glob( $base . '/_tables/*' ) ?: array() as $file ) {
	@unlink( $file );
}
@rmdir( $base . '/_tables' );
@unlink( $log );
foreach ( glob( $bounded_base . '/*' ) ?: array() as $file ) {
	@unlink( $file );
}
@rmdir( $bounded_base );
@rmdir( $base );

if ( $failed > 0 ) {
	exit( 1 );
}

echo PHP_EOL . "All {$passed} assertions passed." . PHP_EOL;
