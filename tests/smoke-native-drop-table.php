<?php
/** DROP TABLE removes persisted plugin state and stays fail-closed for core. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-drop-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_records (id int unsigned NOT NULL auto_increment, owner_id bigint unsigned NOT NULL, PRIMARY KEY (id))',
		'wp_'
	)
);
$runtime->execute( new WP_Markdown_Query_Request( 'INSERT INTO wp_plugin_records (owner_id) VALUES (5)', 'wp_' ) );

$dropped = $runtime->execute( new WP_Markdown_Query_Request( 'DROP TABLE IF EXISTS `wp_plugin_records`', 'wp_' ) );
$schema_gone = ! file_exists( $root . '/_schema/plugin_records.sql' );
$snapshot_gone = ! file_exists( $root . '/_tables/plugin_records.json' );
$query_after = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_plugin_records WHERE owner_id = 5', 'wp_' ) );
$missing_tolerated = $runtime->execute( new WP_Markdown_Query_Request( 'DROP TABLE IF EXISTS wp_never_existed', 'wp_' ) );
$missing_strict = $runtime->execute( new WP_Markdown_Query_Request( 'DROP TABLE wp_never_existed', 'wp_' ) );
$core = $runtime->execute( new WP_Markdown_Query_Request( 'DROP TABLE IF EXISTS wp_posts', 'wp_' ) );
$recreated = $runtime->execute(
	new WP_Markdown_Query_Request( 'CREATE TABLE wp_plugin_records (id int unsigned NOT NULL auto_increment, owner_id bigint unsigned NOT NULL, PRIMARY KEY (id))', 'wp_' )
);

$checks = array(
	'DROP TABLE removes the canonical definition and snapshot' => false !== $dropped->return_value()
		&& $schema_gone && $snapshot_gone,
	'a dropped table is no longer queryable' => false === $query_after->return_value()
		&& 'unsupported_table' === ( $query_after->diagnostic()['reason'] ?? null ),
	'IF EXISTS tolerates a missing table' => false !== $missing_tolerated->return_value(),
	'DROP without IF EXISTS fails closed on a missing table' => false === $missing_strict->return_value()
		&& 'unknown_table' === ( $missing_strict->diagnostic()['reason'] ?? null ),
	'core tables cannot be dropped' => false === $core->return_value()
		&& 'unsupported_schema' === ( $core->diagnostic()['reason'] ?? null ),
	'the name is reusable after a drop' => false !== $recreated->return_value(),
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
