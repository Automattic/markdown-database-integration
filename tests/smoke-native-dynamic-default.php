<?php
/** Dynamic column defaults evaluate at write time in UTC. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-dynamic-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		"CREATE TABLE wp_plugin_records (
	record_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	label VARCHAR(200) NOT NULL,
	created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	birthday DATE NULL DEFAULT NULL,
	PRIMARY KEY (record_id)
)",
		'wp_'
	)
);

$before = gmdate( 'Y-m-d H:i:s' );
$inserted = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_records (label) VALUES ('probe')", 'wp_' ) );
sleep( 2 );
$second = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_records (label) VALUES ('later')", 'wp_' ) );
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT record_id, label, created_at FROM wp_plugin_records ORDER BY record_id', 'wp_' ) );
$rows = array();
foreach ( $read->wpdb_state()['last_result'] as $row ) {
	$rows[ (string) $row->label ] = (string) $row->created_at;
}

$checks = array(
	'an omitted CURRENT_TIMESTAMP column fills with the UTC clock' => 1 === $inserted->return_value()
		&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rows['probe'] ?? '' )
		&& $rows['probe'] >= $before,
	'each insert evaluates the default at its own write time' => ( $rows['later'] ?? '' ) > ( $rows['probe'] ?? '' ),
	'both rows persisted' => 1 === $second->return_value() && 2 === count( $rows ),
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
