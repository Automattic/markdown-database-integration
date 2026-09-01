<?php
/** Grouped aggregate finishing functions retain SQL phase and NULL semantics. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-having-group-concat-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_items (id int unsigned NOT NULL auto_increment, kind varchar(16) NOT NULL, value varchar(16) NULL, PRIMARY KEY (id), KEY kind (kind))', 'wp_' ) );
foreach ( array( array( 'post', "'first'" ), array( 'post', 'NULL' ), array( 'post', "'last'" ), array( 'term', "'only'" ) ) as $item ) {
	$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (kind, value) VALUES ('{$item[0]}', {$item[1]})", 'wp_' ) );
}

$grouped = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT kind, COUNT(*) AS total, GROUP_CONCAT(value) AS values FROM wp_items GROUP BY kind HAVING total > 1', 'wp_' ) );
$distinct = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT kind, GROUP_CONCAT(DISTINCT value) AS values FROM wp_items GROUP BY kind', 'wp_' ) );
$separator = $runtime->execute( new WP_Markdown_Query_Request( "SELECT kind, GROUP_CONCAT(value SEPARATOR '|') AS values FROM wp_items GROUP BY kind", 'wp_' ) );
$rows = array_map( static fn( object $row ): array => (array) $row, $grouped->wpdb_state()['last_result'] );

$checks = array(
	'HAVING filters aggregate aliases after grouping' => array( array( 'kind' => 'post', 'total' => '3', 'values' => 'first,last' ) ) === $rows,
	'GROUP_CONCAT DISTINCT fails closed' => false === $distinct->return_value(),
	'GROUP_CONCAT custom separators fail closed' => false === $separator->return_value(),
);
$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/_tables/.index/*' ) ?: array() );
@rmdir( $root . '/_tables/.index' );
array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_schema/*' ) ?: array() );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_schema' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
