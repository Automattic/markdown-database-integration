<?php
/** Ungrouped aggregates over one table, including their NULL semantics. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-aggregates-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the aggregate fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_items (id bigint unsigned NOT NULL auto_increment, score bigint NULL, kind varchar(16) NOT NULL, PRIMARY KEY (id), KEY kind (kind))',
		'wp_'
	)
);
foreach ( array( array( '10', 'a' ), array( '20', 'b' ), array( '30', 'a' ) ) as $row ) {
	$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (score, kind) VALUES ({$row[0]}, '{$row[1]}')", 'wp_' ) );
}
// One row carries no score, which every aggregate except COUNT(*) ignores.
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (score, kind) VALUES (NULL, 'c')", 'wp_' ) );

/** @return array<string,string|null> */
function mdi_aggregate_row( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): array {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
	if ( false === $result->return_value() ) {
		return array( 'unsupported' => (string) ( $result->diagnostic()['reason'] ?? 'unknown' ) );
	}
	$row = $result->wpdb_state()['last_result'][0] ?? null;
	return null === $row ? array() : (array) $row;
}

$totals = mdi_aggregate_row( $runtime, 'SELECT SUM(score) AS total, AVG(score) AS mean, MIN(score) AS lowest, MAX(score) AS highest FROM wp_items' );
$counts = mdi_aggregate_row( $runtime, 'SELECT COUNT(score) AS scored FROM wp_items' );
$all_rows = mdi_aggregate_row( $runtime, 'SELECT COUNT(*) FROM wp_items' );
$filtered = mdi_aggregate_row( $runtime, "SELECT SUM(score) AS total FROM wp_items WHERE kind = 'a'" );
$empty = mdi_aggregate_row( $runtime, "SELECT SUM(score) AS total, COUNT(score) AS scored FROM wp_items WHERE kind = 'missing'" );
$textual = mdi_aggregate_row( $runtime, 'SELECT SUM(kind) AS total FROM wp_items' );

$checks = array(
	'one row reports every ungrouped aggregate' => array( 'total' => '60', 'mean' => '20', 'lowest' => '10', 'highest' => '30' ) === $totals,
	'COUNT over a column skips its NULL rows' => array( 'scored' => '3' ) === $counts,
	'COUNT over rows keeps them' => '4' === ( $all_rows['COUNT(*)'] ?? null ),
	'a restriction narrows the aggregate' => array( 'total' => '40' ) === $filtered,
	'an aggregate over no rows is NULL, and a count is zero' => array( 'total' => null, 'scored' => '0' ) === $empty,
	'summing a text column stays fail-closed' => 'unsupported_aggregate' === ( $textual['unsupported'] ?? null ),
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
