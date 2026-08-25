<?php
/** Exact bounded reads over canonical row partitions. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-query-runtime.php';

function mdi_native_partition_write( string $generation, string $identity, array $row ): string {
	$path = $generation . '/' . hash( 'sha256', $identity ) . '.json';
	file_put_contents(
		$path,
		json_encode(
			array(
				'_mdi_partition' => array( 'version' => 1, 'identity_column' => 'event_id', 'identity' => $identity ),
				'row'            => $row,
			),
			JSON_THROW_ON_ERROR
		)
	);
	return $path;
}

$root = sys_get_temp_dir() . '/mdi-native-partitions-' . bin2hex( random_bytes( 6 ) );
$table = $root . '/_tables/runtime_events';
$generation_name = 'generation-' . str_repeat( 'a', 24 );
$generation = $table . '/' . $generation_name;
if ( ! mkdir( $generation, 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the native partition fixture.' );
}
file_put_contents(
	$table . '/.mdi-partition.json',
	json_encode( array( 'version' => 1, 'table' => 'runtime_events', 'identity_column' => 'event_id', 'generation' => $generation_name ), JSON_THROW_ON_ERROR )
);
mdi_native_partition_write( $generation, '1', array( 'event_id' => 1, 'payload' => 'first' ) );
mdi_native_partition_write( $generation, '2', array( 'event_id' => 2, 'payload' => 'second' ) );
mdi_native_partition_write( $generation, '10', array( 'event_id' => 10, 'payload' => 'tenth' ) );
file_put_contents( $generation . '/' . hash( 'sha256', '999' ) . '.json', '{malformed-unrelated' );

$schema = new WP_Markdown_Native_Table_Schema(
	array(
		'event_id' => new WP_Markdown_Native_Column( 8, false, 'is_int', null, array( '=', 'IN' ) ),
		'payload'  => new WP_Markdown_Native_Column( 253, false, 'is_string' ),
	),
	'event_id'
);
$registry = new WP_Markdown_Native_Table_Registry();
$registry->register(
	'wp_runtime_events',
	$schema,
	new WP_Markdown_Native_JSON_Partition_Provider( $root, $schema, 'runtime_events', 'event_id' )
);
$runtime = new WP_Markdown_Native_Query_Runtime( $registry );

$bounded = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT payload, event_id FROM wp_runtime_events WHERE event_id IN (10, 2) ORDER BY event_id ASC LIMIT 1' )
);
$missing = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT payload FROM wp_runtime_events WHERE event_id = 404 LIMIT 1' )
);
$scan = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT payload FROM wp_runtime_events' ) );
$conjunctive = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT payload FROM wp_runtime_events WHERE payload = 'second' AND event_id IN (10, 2) ORDER BY event_id ASC LIMIT 1" )
);

$requested_malformed_path = $generation . '/' . hash( 'sha256', '3' ) . '.json';
file_put_contents( $requested_malformed_path, '{malformed-requested' );
$limited_before_malformed = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT payload FROM wp_runtime_events WHERE event_id IN (3, 1) ORDER BY event_id ASC LIMIT 1' )
);
$requested_malformed = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT payload FROM wp_runtime_events WHERE event_id IN (1, 3) ORDER BY event_id ASC' )
);

$outside = dirname( $root ) . '/mdi-native-partition-outside-' . bin2hex( random_bytes( 4 ) ) . '.json';
file_put_contents( $outside, json_encode( array( 'private' => true ), JSON_THROW_ON_ERROR ) );
$linked_path = $generation . '/' . hash( 'sha256', '4' ) . '.json';
$symlink = function_exists( 'symlink' ) && @symlink( $outside, $linked_path );
$unsafe_symlink = $symlink
	? $runtime->execute( new WP_Markdown_Query_Request( 'SELECT payload FROM wp_runtime_events WHERE event_id = 4' ) )
	: null;
if ( $symlink ) {
	@unlink( $linked_path );
}
$hardlink = function_exists( 'link' ) && @link( $outside, $linked_path );
$unsafe_hardlink = $hardlink
	? $runtime->execute( new WP_Markdown_Query_Request( 'SELECT payload FROM wp_runtime_events WHERE event_id = 4' ) )
	: null;
if ( $hardlink ) {
	@unlink( $linked_path );
}

file_put_contents(
	$table . '/.mdi-partition.json',
	json_encode( array( 'version' => 1, 'table' => 'runtime_events', 'identity_column' => 'wrong_id', 'generation' => $generation_name ), JSON_THROW_ON_ERROR )
);
$marker_mismatch = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT payload FROM wp_runtime_events WHERE event_id = 1' )
);
file_put_contents(
	$table . '/.mdi-partition.json',
	json_encode( array( 'version' => 1, 'table' => 'runtime_events', 'identity_column' => 'event_id', 'generation' => 'generation-' . str_repeat( 'b', 24 ) ), JSON_THROW_ON_ERROR )
);
$stale_generation = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT payload FROM wp_runtime_events WHERE event_id = 1' )
);

$checks = array(
	'exact IN lookup is ordered, projected, and limited provider-side' => 1 === $bounded->return_value()
		&& 'second' === ( $bounded->wpdb_state()['last_result'][0]->payload ?? null )
		&& '2' === ( $bounded->wpdb_state()['last_result'][0]->event_id ?? null ),
	'missing identities are successful and unrelated malformed rows are untouched' => 0 === $missing->return_value(),
	'unbounded partition scans fail closed' => false === $scan->return_value()
		&& 'unsupported_partition_access' === ( $scan->diagnostic()['reason'] ?? null ),
	'partition identity pushes down while residual columns filter before LIMIT' => 1 === $conjunctive->return_value()
		&& 'second' === ( $conjunctive->wpdb_state()['last_result'][0]->payload ?? null ),
	'identity ordering applies LIMIT before opening later requested partitions' => 1 === $limited_before_malformed->return_value()
		&& 'first' === ( $limited_before_malformed->wpdb_state()['last_result'][0]->payload ?? null ),
	'malformed requested partitions fail without partial rows' => false === $requested_malformed->return_value()
		&& array() === $requested_malformed->wpdb_state()['last_result']
		&& 'markdown_db_native_malformed_partition' === ( $requested_malformed->diagnostic()['code'] ?? null ),
	'partition row links fail closed' => ( ! $symlink || false === $unsafe_symlink->return_value() )
		&& ( ! $symlink || 'markdown_db_native_unsafe_path' === ( $unsafe_symlink->diagnostic()['code'] ?? null ) )
		&& ( ! $hardlink || false === $unsafe_hardlink->return_value() )
		&& ( ! $hardlink || 'markdown_db_native_unsafe_path' === ( $unsafe_hardlink->diagnostic()['code'] ?? null ) ),
	'mismatched markers and stale generations fail closed' => false === $marker_mismatch->return_value()
		&& false === $stale_generation->return_value(),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

@unlink( $table . '/.mdi-partition.json' );
foreach ( glob( $generation . '/*.json' ) ?: array() as $path ) {
	@unlink( $path );
}
@rmdir( $generation );
@rmdir( $table );
@rmdir( $root . '/_tables' );
@rmdir( $root );
@unlink( $outside );
exit( $failed ? 1 : 0 );
