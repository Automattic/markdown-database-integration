<?php
/** Tables keyed by an exact ASCII string primary key are executable. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-string-pk-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		"CREATE TABLE wp_plugin_config (`name` varchar(100) NOT NULL, `val` longblob, `autoload` enum('no','yes') NOT NULL DEFAULT 'yes', PRIMARY KEY (`name`))",
		'wp_'
	)
);

$inserted = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_config (name, val) VALUES ('probe_key', 'probe value')", 'wp_' ) );
$read = $runtime->execute( new WP_Markdown_Query_Request( "SELECT name, val, autoload FROM wp_plugin_config WHERE name = 'probe_key'", 'wp_' ) );
$duplicate = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_config (name, val) VALUES ('probe_key', 'again')", 'wp_' ) );
$updated = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_plugin_config SET val = 'changed' WHERE name = 'probe_key'", 'wp_' ) );
$after = $runtime->execute( new WP_Markdown_Query_Request( "SELECT val FROM wp_plugin_config WHERE name = 'probe_key'", 'wp_' ) );
$unicode = $runtime->execute( new WP_Markdown_Query_Request( "SELECT val FROM wp_plugin_config WHERE name = 'prôbe'", 'wp_' ) );
$prefix_keyed = $runtime->execute(
	new WP_Markdown_Query_Request( 'CREATE TABLE wp_plugin_prefix_keyed (`name` varchar(255) NOT NULL, PRIMARY KEY (`name`(50)))', 'wp_' )
);
$prefix_query = $runtime->execute( new WP_Markdown_Query_Request( "SELECT name FROM wp_plugin_prefix_keyed WHERE name = 'x'", 'wp_' ) );

$checks = array(
	'a string primary key accepts inserts' => 1 === $inserted->return_value()
		&& 'probe value' === (string) ( $read->wpdb_state()['last_result'][0]->val ?? '' )
		&& 'yes' === (string) ( $read->wpdb_state()['last_result'][0]->autoload ?? '' ),
	'a duplicate string identity fails closed' => false === $duplicate->return_value()
		&& 'duplicate_key' === ( $duplicate->diagnostic()['reason'] ?? null ),
	'a string identity updates in place' => 1 === $updated->return_value()
		&& 'changed' === (string) ( $after->wpdb_state()['last_result'][0]->val ?? '' ),
	'a non-ASCII string identity fails closed' => false === $unicode->return_value()
		&& 'unsupported_lookup' === ( $unicode->diagnostic()['reason'] ?? null ),
	'a prefix-length string primary key stays unexecutable' => false !== $prefix_keyed->return_value()
		&& false === $prefix_query->return_value()
		&& 'unsupported_table' === ( $prefix_query->diagnostic()['reason'] ?? null ),
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
