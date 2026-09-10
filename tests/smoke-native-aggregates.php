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
$conditional_sql = "SELECT COUNT(*) AS total, SUM(CASE WHEN kind = 'a' THEN 1 ELSE 0 END) AS completed, SUM(CASE WHEN kind LIKE 'b%' OR kind = 'c' THEN 1 ELSE 0 END) AS skipped FROM wp_items";
$conditional = mdi_aggregate_row( $runtime, $conditional_sql );
$conditional_empty = mdi_aggregate_row( $runtime, $conditional_sql . " WHERE kind = 'missing'" );
$numeric_scalar = mdi_aggregate_row( $runtime, 'SELECT SUM(COALESCE(score, 0)) AS total, AVG(ABS(score)) AS mean FROM wp_items' );
$scalar_null = mdi_aggregate_row( $runtime, "SELECT SUM(ABS(score)) AS total FROM wp_items WHERE kind = 'c'" );
$bad_scalar = mdi_aggregate_row( $runtime, 'SELECT SUM(ABS(missing)) AS total FROM wp_items' );
$bad_type = mdi_aggregate_row( $runtime, "SELECT SUM(COALESCE(kind, 'bad')) AS total FROM wp_items WHERE id = 999" );
$conditional_groups = $runtime->execute( new WP_Markdown_Query_Request( "SELECT kind, COUNT(*) AS total, SUM(CASE WHEN score > 15 THEN 1 ELSE 0 END) AS high FROM wp_items GROUP BY kind ORDER BY kind" ) );
$counts = mdi_aggregate_row( $runtime, 'SELECT COUNT(score) AS scored FROM wp_items' );
$all_rows = mdi_aggregate_row( $runtime, 'SELECT COUNT(*) FROM wp_items' );
$filtered = mdi_aggregate_row( $runtime, "SELECT SUM(score) AS total FROM wp_items WHERE kind = 'a'" );
$empty = mdi_aggregate_row( $runtime, "SELECT SUM(score) AS total, COUNT(score) AS scored FROM wp_items WHERE kind = 'missing'" );
$textual = mdi_aggregate_row( $runtime, 'SELECT SUM(kind) AS total FROM wp_items' );
$default_names = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT MAX(score), COUNT(score) FROM wp_items', 'wp_' ) );
$default_empty = $runtime->execute( new WP_Markdown_Query_Request( "SELECT MAX(score), COUNT(score) FROM wp_items WHERE kind = 'missing'", 'wp_' ) );
$grouped = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT kind, score, COUNT(id) AS n FROM wp_items GROUP BY kind, score' ) );
$grouped_alias = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT kind, score AS points, COUNT(id) AS n FROM wp_items GROUP BY kind, score' ) );
$grouped_join = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT a.kind, b.score, COUNT(a.id) AS n FROM wp_items a JOIN wp_items b ON a.id = b.id GROUP BY a.kind, b.score' ) );
$grouped_rows = $grouped->corpus_result()['rows'];
$alias_rows = $grouped_alias->corpus_result()['rows'];
$joined_rows = $grouped_join->corpus_result()['rows'];
usort( $grouped_rows, static fn( array $left, array $right ): int => (int) $left['score'] <=> (int) $right['score'] );
usort( $alias_rows, static fn( array $left, array $right ): int => (int) $left['points'] <=> (int) $right['points'] );
usort( $joined_rows, static fn( array $left, array $right ): int => (int) $left['score'] <=> (int) $right['score'] );

$checks = array(
	'aliased row counts compose with conditional aggregates' => array( 'total' => '4', 'completed' => '2', 'skipped' => '2' ) === $conditional,
	'conditional aggregates retain empty-set NULL and count semantics' => array( 'total' => '0', 'completed' => null, 'skipped' => null ) === $conditional_empty,
	'numeric scalar aggregates reuse row-local evaluation' => array( 'total' => '60', 'mean' => '20' ) === $numeric_scalar && array( 'total' => null ) === $scalar_null,
	'aggregate expressions validate columns and types even on empty sets' => 'unsupported_column' === ( $bad_scalar['unsupported'] ?? null ) && 'unsupported_aggregate' === ( $bad_type['unsupported'] ?? null ),
	'conditional aggregates execute independently per group' => $conditional_groups->succeeded() && array( array( 'kind' => 'a', 'total' => '2', 'high' => '1' ), array( 'kind' => 'b', 'total' => '1', 'high' => '1' ), array( 'kind' => 'c', 'total' => '1', 'high' => '0' ) ) === $conditional_groups->corpus_result()['rows'],
	'multiple explicit grouping columns preserve every projected value' => $grouped->succeeded() && array(
		array( 'kind' => 'c', 'score' => null, 'n' => '1' ),
		array( 'kind' => 'a', 'score' => '10', 'n' => '1' ),
		array( 'kind' => 'b', 'score' => '20', 'n' => '1' ),
		array( 'kind' => 'a', 'score' => '30', 'n' => '1' ),
	) === $grouped_rows,
	'aliased grouping columns retain their position and numeric type' => $grouped_alias->succeeded() && array( 'kind' => 'a', 'points' => '10', 'n' => '1' ) === $alias_rows[1] && '8' === $grouped_alias->corpus_result()['columns'][1]['type'],
	'joined grouping keys include all explicit columns' => $grouped_join->succeeded() && array(
		array( 'kind' => 'c', 'score' => null, 'n' => '1' ),
		array( 'kind' => 'a', 'score' => '10', 'n' => '1' ),
		array( 'kind' => 'b', 'score' => '20', 'n' => '1' ),
		array( 'kind' => 'a', 'score' => '30', 'n' => '1' ),
	) === $joined_rows,
	'one row reports every ungrouped aggregate' => array( 'total' => '60', 'mean' => '20', 'lowest' => '10', 'highest' => '30' ) === $totals,
	'COUNT over a column skips its NULL rows' => array( 'scored' => '3' ) === $counts,
	'COUNT over rows keeps them' => '4' === ( $all_rows['COUNT(*)'] ?? null ),
	'a restriction narrows the aggregate' => array( 'total' => '40' ) === $filtered,
	'an aggregate over no rows is NULL, and a count is zero' => array( 'total' => null, 'scored' => '0' ) === $empty,
	'summing a text column stays fail-closed' => 'unsupported_aggregate' === ( $textual['unsupported'] ?? null ),
	'unaliased column aggregates retain MySQL result names, values, and metadata' => array( 'MAX(score)' => '30', 'COUNT(score)' => '3' ) === (array) ( $default_names->wpdb_state()['last_result'][0] ?? array() )
		&& array( 'MAX(score)', 'COUNT(score)' ) === array_map( static fn( object $column ): string => $column->name, $default_names->wpdb_state()['col_info'] ),
	'unaliased column aggregates retain NULL and empty-set semantics' => array( 'MAX(score)' => null, 'COUNT(score)' => '0' ) === (array) ( $default_empty->wpdb_state()['last_result'][0] ?? array() ),
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
