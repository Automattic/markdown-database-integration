<?php
/** COUNT and SUM aggregates grouped over a LEFT JOIN. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-aggregate-join-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_groups (id int unsigned NOT NULL auto_increment, name varchar(64) NOT NULL, PRIMARY KEY (id))',
		'wp_'
	)
);
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_items (id int unsigned NOT NULL auto_increment, group_id int unsigned NOT NULL, last_count int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id), KEY group_id (group_id))',
		'wp_'
	)
);
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_groups (name) VALUES ('first')", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_groups (name) VALUES ('empty')", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'INSERT INTO wp_plugin_items (group_id, last_count) VALUES (1, 4)', 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'INSERT INTO wp_plugin_items (group_id, last_count) VALUES (1, 6)', 'wp_' ) );

$aggregated = $runtime->execute(
	new WP_Markdown_Query_Request(
		'SELECT wp_plugin_groups.*,COUNT( wp_plugin_items.id ) AS items,SUM( wp_plugin_items.last_count ) AS redirects FROM wp_plugin_groups LEFT JOIN wp_plugin_items ON wp_plugin_groups.id = wp_plugin_items.group_id GROUP BY wp_plugin_groups.id',
		'wp_'
	)
);
$rows = array();
foreach ( $aggregated->wpdb_state()['last_result'] as $row ) {
	$rows[ (string) $row->name ] = array( (string) $row->items, null === $row->redirects ? null : (string) $row->redirects );
}
$columns = array_map( static fn( object $column ): string => $column->name, $aggregated->wpdb_state()['col_info'] );
$ungrouped = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT wp_plugin_groups.id,COUNT( wp_plugin_groups.id ) AS items FROM wp_plugin_groups', 'wp_' )
);

$checks = array(
	'COUNT over a LEFT JOIN counts matched rows per group' => array( '2', '10' ) === ( $rows['first'] ?? null ),
	'an unmatched outer group counts zero and sums to NULL' => array( '0', null ) === ( $rows['empty'] ?? null ),
	'aggregate aliases join the projection metadata' => in_array( 'items', $columns, true )
		&& in_array( 'redirects', $columns, true )
		&& in_array( 'name', $columns, true ),
	'an aggregate without GROUP BY fails closed' => false === $ungrouped->return_value()
		&& 'unsupported_grammar' === ( $ungrouped->diagnostic()['reason'] ?? null ),
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
