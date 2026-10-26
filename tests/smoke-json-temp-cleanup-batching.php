<?php
/** Regression coverage: JSON temp-file recovery scans stay bounded per flush. See issue #243. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['mdi_temp_batch_filters'] = array();
$GLOBALS['mdi_temp_batch_scans'] = array();

function add_filter( string $tag, callable $callback ): void { $GLOBALS['mdi_temp_batch_filters'][ $tag ][] = $callback; }
function has_filter( string $tag ): bool { return ! empty( $GLOBALS['mdi_temp_batch_filters'][ $tag ] ); }
function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed { foreach ( $GLOBALS['mdi_temp_batch_filters'][ $tag ] ?? array() as $callback ) { $value = $callback( $value, ...$args ); } return $value; }
function do_action(): void {}

require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';

require_once __DIR__ . '/stubs/stub-backend-operations.php';
require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';

class MDI_Temp_Batch_Operations extends MDI_Stub_Backend_Operations {
	public function __construct( int $row_count ) {
		for ( $id = 1; $id <= $row_count; $id++ ) { $this->rows['runtime_events'][ $id ] = array( 'event_id' => $id, 'payload' => "payload-{$id}" ); }
	}
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable {
		$rows = $this->rows[ $table_suffix ] ?? array();
		return isset( $policy['resource_ids'] ) ? array_intersect_key( $rows, array_flip( $policy['resource_ids'] ) ) : $rows;
	}
	public function mutations_for_query( string $query, array $operation ): array {
		return array( array( 'stable_id' => 'event:2', 'operation' => $operation['op'], 'table' => $operation['table'], 'resource_ids' => array( '2' ), 'scope' => array( 'resource_ids_by_column' => array( 'event_id' => array( '2' ) ) ) ) );
	}
}

$failed = 0;
function mdi_temp_batch_assert( bool $condition, string $label ): void { global $failed; echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL; if ( ! $condition ) { $failed++; } }
function mdi_temp_batch_rm( string $path ): void { if ( ! is_dir( $path ) ) { return; } foreach ( scandir( $path ) ?: array() as $entry ) { if ( '.' === $entry || '..' === $entry ) { continue; } $child = $path . '/' . $entry; is_dir( $child ) ? mdi_temp_batch_rm( $child ) : unlink( $child ); } rmdir( $path ); }
function mdi_temp_batch_generation_scans( string $generation_directory ): int { return count( array_filter( $GLOBALS['mdi_temp_batch_scans'], static fn ( string $path ): bool => $generation_directory === dirname( $path ) ) ); }

// Every executed temp-cleanup attempt applies the limit filter before touching
// the filesystem, so this counter observes real directory-scan work: without
// per-directory batching it would grow with every partition-row write.
add_filter( 'markdown_database_integration_json_temp_cleanup_limit', static function ( int $limit, string $path ): int {
	$GLOBALS['mdi_temp_batch_scans'][] = $path;
	return $limit;
} );
add_filter( 'markdown_db_table_persistence_policy', static function ( array $policy ): array { $policy['runtime_events'] = array( 'partition_by' => 'event_id' ); return $policy; } );

$row_count = 2000;
$root = sys_get_temp_dir() . '/mdi-temp-batch-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );
$driver = new MDI_Temp_Batch_Operations( $row_count );
$engine = new WP_Markdown_Canonical_Persistence( $root, new WP_Markdown_Storage( $root ), $driver, 'wp_' );

$engine->persist_write( "UPDATE wp_runtime_events SET payload = 'two' WHERE event_id = 2", 'wp_runtime_events', 'UPDATE' );
$engine->flush_dirty( true );
$marker = json_decode( (string) file_get_contents( $root . '/_tables/runtime_events/.mdi-partition.json' ), true );
$generation_directory = $root . '/_tables/runtime_events/' . $marker['generation'];
$written_rows = count( glob( $generation_directory . '/*.json' ) ?: array() );
mdi_temp_batch_assert( $row_count === $written_rows, "full migration flush writes all {$row_count} partition rows" );
mdi_temp_batch_assert( 1 === mdi_temp_batch_generation_scans( $generation_directory ), "flush scans the {$row_count}-row partition directory exactly once instead of once per row" );
mdi_temp_batch_assert( 2 === count( $GLOBALS['mdi_temp_batch_scans'] ), 'flush cleanup scans are bounded by directories written (partition plus marker), not by rows' );

// The single per-directory sweep per flush must preserve interrupted-write
// recovery and live-writer safety: the next scoped flush re-arms the directory
// and its one sweep reclaims a stale orphan while sparing fresh and locked temps.
$scoped_destination = $generation_directory . '/' . hash( 'sha256', '2' ) . '.json';
$stale = $scoped_destination . '.tmp.999999.deadbeef';
$fresh = $scoped_destination . '.tmp.999998.cafebabe';
$locked = $scoped_destination . '.tmp.999997.8badf00d';
$other_stale = $generation_directory . '/' . hash( 'sha256', '3' ) . '.json.tmp.999996.feedface';
file_put_contents( $stale, 'orphan' );
touch( $stale, time() - 400 );
file_put_contents( $fresh, 'live' );
file_put_contents( $locked, 'live' );
touch( $locked, time() - 400 );
file_put_contents( $other_stale, 'orphan' );
touch( $other_stale, time() - 400 );
$locked_handle = fopen( $locked, 'rb' );
flock( $locked_handle, LOCK_EX );

$driver->rows['runtime_events'][2]['payload'] = 'changed';
$engine->persist_write( "UPDATE wp_runtime_events SET payload = 'changed' WHERE event_id = 2", 'wp_runtime_events', 'UPDATE' );
$engine->flush_dirty( true );
mdi_temp_batch_assert( ! file_exists( $stale ), 're-armed sweep on the next flush reclaims a stale interrupted-write temp' );
mdi_temp_batch_assert( ! file_exists( $other_stale ), 'one scoped-flush sweep reclaims stale temps for every canonical row in the directory' );
mdi_temp_batch_assert( file_exists( $fresh ), 'fresh temp from a live concurrent writer is preserved' );
mdi_temp_batch_assert( file_exists( $locked ), 'locked temp from a live concurrent writer is preserved' );
mdi_temp_batch_assert( 2 === mdi_temp_batch_generation_scans( $generation_directory ), 'each subsequent flush adds exactly one more partition-directory scan' );

flock( $locked_handle, LOCK_UN );
fclose( $locked_handle );
mdi_temp_batch_rm( $root );
exit( $failed ? 1 : 0 );
