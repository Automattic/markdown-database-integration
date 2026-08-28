<?php
/** SQL_CALC_FOUND_ROWS COUNT(*) and ordering by indexed columns. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-calc-count-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_records (record_id bigint unsigned NOT NULL auto_increment, owner_id bigint unsigned NOT NULL, name varchar(64) NOT NULL, note varchar(64) NULL, PRIMARY KEY (record_id), KEY name (name))',
		'wp_'
	)
);
foreach ( array( array( 12, 'gamma' ), array( 12, 'alpha' ), array( 12, 'beta' ), array( 99, 'delta' ) ) as $row ) {
	$runtime->execute(
		new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_records (owner_id, name, note) VALUES ({$row[0]}, '{$row[1]}', 'x')", 'wp_' )
	);
}

$calc_count = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT SQL_CALC_FOUND_ROWS COUNT(*) FROM wp_plugin_records WHERE owner_id = 12', 'wp_' )
);
$found = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT FOUND_ROWS()', 'wp_' ) );
$ordered = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT name FROM wp_plugin_records WHERE owner_id = 12 ORDER BY name', 'wp_' )
);
$unindexed_order = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT name FROM wp_plugin_records WHERE owner_id = 12 ORDER BY note', 'wp_' )
);

$checks = array(
	'SQL_CALC_FOUND_ROWS COUNT(*) returns the aggregate' => '3' === (string) ( $calc_count->wpdb_state()['last_result'][0]->{'COUNT(*)'} ?? '' ),
	'FOUND_ROWS() answers the same unbounded count' => '3' === (string) ( $found->wpdb_state()['last_result'][0]->{'FOUND_ROWS()'} ?? '' ),
	'an indexed column is orderable' => array( 'alpha', 'beta', 'gamma' ) === array_map(
		static fn( object $row ): string => (string) $row->name,
		$ordered->wpdb_state()['last_result']
	),
	'an unindexed column ordering still fails closed' => false === $unindexed_order->return_value()
		&& 'unsupported_order' === ( $unindexed_order->diagnostic()['reason'] ?? null ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_schema/*' ) ?: array() );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_schema' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
