<?php
/** CREATE TABLE IF NOT EXISTS is a no-op when the table is already persisted. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-ine-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$definition = '(`id` int(11) unsigned NOT NULL AUTO_INCREMENT, `owner_id` bigint(20) unsigned NOT NULL, PRIMARY KEY (`id`), KEY `owner_id` (`owner_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8';
$first = $runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE IF NOT EXISTS `wp_plugin_records` ' . $definition, 'wp_' ) );
$again = $runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE IF NOT EXISTS `wp_plugin_records` ' . $definition, 'wp_' ) );
$inserted = $runtime->execute( new WP_Markdown_Query_Request( 'INSERT INTO wp_plugin_records (owner_id) VALUES (5)', 'wp_' ) );
$strict = $runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE `wp_plugin_records` ' . $definition, 'wp_' ) );
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id, owner_id FROM wp_plugin_records WHERE owner_id = 5', 'wp_' ) );
$persisted = (string) file_get_contents( $root . '/_schema/plugin_records.sql' );

$checks = array(
	'CREATE TABLE IF NOT EXISTS creates the table' => false !== $first->return_value(),
	'a repeat IF NOT EXISTS succeeds without changing state' => false !== $again->return_value()
		&& 1 === substr_count( $persisted, 'CREATE TABLE' ),
	'the table stays usable after the repeat' => 1 === $inserted->return_value()
		&& array( '5' ) === array_map(
			static fn( object $row ): string => (string) $row->owner_id,
			$read->wpdb_state()['last_result']
		),
	'CREATE TABLE without IF NOT EXISTS still fails closed' => false === $strict->return_value()
		&& 'table_exists' === ( $strict->diagnostic()['reason'] ?? null ),
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
