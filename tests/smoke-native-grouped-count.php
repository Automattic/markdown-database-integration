<?php
/** SELECT col, COUNT(*) AS alias … GROUP BY col. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-grouped-count-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_records (id int unsigned NOT NULL auto_increment, object_type varchar(32) NOT NULL, record_status varchar(20) NOT NULL, PRIMARY KEY (id), KEY object_type (object_type))',
		'wp_'
	)
);
foreach ( array( array( 'post', 'publish' ), array( 'post', 'publish' ), array( 'post', 'draft' ), array( 'term', 'publish' ) ) as $row ) {
	$runtime->execute(
		new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_records (object_type, record_status) VALUES ('{$row[0]}', '{$row[1]}')", 'wp_' )
	);
}

$grouped = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT record_status, COUNT(*) AS num_records FROM wp_plugin_records WHERE object_type = 'post' GROUP BY record_status", 'wp_' )
);
$counts = array();
foreach ( $grouped->wpdb_state()['last_result'] as $row ) {
	$counts[ (string) $row->record_status ] = (string) $row->num_records;
}
$columns = array_map( static fn( object $column ): string => $column->name, $grouped->wpdb_state()['col_info'] );
$plain_count = $runtime->execute( new WP_Markdown_Query_Request( "SELECT COUNT(*) FROM wp_plugin_records WHERE object_type = 'post'", 'wp_' ) );
$ungrouped = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT record_status, COUNT(*) AS num_records FROM wp_plugin_records', 'wp_' ) );

$checks = array(
	'grouped counts aggregate per value' => array( 'publish' => '2', 'draft' => '1' ) === $counts,
	'grouped counts expose the alias column' => array( 'record_status', 'num_records' ) === $columns,
	'plain COUNT(*) still returns one scalar row' => '3' === (string) ( $plain_count->wpdb_state()['last_result'][0]->{'COUNT(*)'} ?? '' ),
	'an aliased count without GROUP BY fails closed' => false === $ungrouped->return_value()
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
