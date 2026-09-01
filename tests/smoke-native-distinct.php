<?php
/** DISTINCT over a single table, and how it meets LIMIT and FOUND_ROWS. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-distinct-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the DISTINCT fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_items (id bigint unsigned NOT NULL auto_increment, kind varchar(32) NOT NULL, PRIMARY KEY (id), KEY kind (kind))',
		'wp_'
	)
);
// Repeats are deliberate: 'a' three times, 'b' twice, 'c' once.
foreach ( array( 'a', 'b', 'a', 'c', 'b', 'a' ) as $kind ) {
	$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (kind) VALUES ('{$kind}')", 'wp_' ) );
}

/** @return array<int,string> */
function mdi_distinct_values( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): array {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
	if ( false === $result->return_value() ) {
		return array( 'unsupported:' . ( $result->diagnostic()['reason'] ?? 'unknown' ) );
	}
	return array_map( static fn( object $row ): string => (string) $row->kind, $result->wpdb_state()['last_result'] );
}

$distinct = mdi_distinct_values( $runtime, 'SELECT DISTINCT kind FROM wp_items ORDER BY kind ASC' );
$repeated = mdi_distinct_values( $runtime, 'SELECT kind FROM wp_items ORDER BY kind ASC' );
$bounded = mdi_distinct_values( $runtime, 'SELECT DISTINCT kind FROM wp_items ORDER BY kind ASC LIMIT 2' );
$offset = mdi_distinct_values( $runtime, 'SELECT DISTINCT kind FROM wp_items ORDER BY kind ASC LIMIT 1, 2' );

$runtime->execute( new WP_Markdown_Query_Request( 'SELECT SQL_CALC_FOUND_ROWS DISTINCT kind FROM wp_items ORDER BY kind ASC LIMIT 1', 'wp_' ) );
$found = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT FOUND_ROWS()', 'wp_' ) );
$found_rows = (string) ( $found->wpdb_state()['last_result'][0]->{'FOUND_ROWS()'} ?? '' );

$checks = array(
	'DISTINCT collapses repeated rows' => array( 'a', 'b', 'c' ) === $distinct,
	'the same query without DISTINCT keeps every row' => array( 'a', 'a', 'a', 'b', 'b', 'c' ) === $repeated,
	'a repeated row does not consume the bound' => array( 'a', 'b' ) === $bounded,
	'the offset counts collapsed rows' => array( 'b', 'c' ) === $offset,
	'FOUND_ROWS reports the collapsed total' => '3' === $found_rows,
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
array_map( 'unlink', glob( $root . '/_options/*' ) ?: array() );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_schema' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
