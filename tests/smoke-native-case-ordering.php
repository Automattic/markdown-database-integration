<?php
/** Searched CASE ordering used by WordPress search relevance. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-case-order-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the CASE ordering fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_docs (id bigint unsigned NOT NULL auto_increment, title varchar(191) NOT NULL, excerpt text NOT NULL, body text NOT NULL, published bigint NOT NULL, PRIMARY KEY (id), KEY title (title), KEY published (published))',
		'wp_'
	)
);
foreach ( array(
	array( 'searchable coverage', '', '', 10 ),
	array( 'coverage searchable notes', '', '', 20 ),
	array( 'searchable notes', '', '', 30 ),
	array( 'other', 'searchable coverage', '', 40 ),
	array( 'other', '', 'searchable coverage', 50 ),
	array( 'other', '', 'unrelated', 60 ),
) as $row ) {
	$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_docs (title, excerpt, body, published) VALUES ('{$row[0]}', '{$row[1]}', '{$row[2]}', {$row[3]})", 'wp_' ) );
}

function mdi_case_order_ids( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): string {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
	if ( false === $result->return_value() ) {
		return 'unsupported:' . ( $result->diagnostic()['reason'] ?? 'unknown' );
	}
	return implode( ',', array_map( static fn( object $row ): string => (string) $row->id, $result->wpdb_state()['last_result'] ) );
}

$case = "(CASE WHEN title LIKE '%searchable coverage%' THEN 1 WHEN title LIKE '%searchable%' AND title LIKE '%coverage%' THEN 2 WHEN title LIKE '%searchable%' OR title LIKE '%coverage%' THEN 3 WHEN excerpt LIKE '%searchable coverage%' THEN 4 WHEN body LIKE '%searchable coverage%' THEN 5 ELSE 6 END)";
$ranked = mdi_case_order_ids( $runtime, "SELECT id FROM wp_docs ORDER BY {$case}, published DESC" );
$descending = mdi_case_order_ids( $runtime, "SELECT id FROM wp_docs ORDER BY {$case} DESC, published ASC" );
$bounded = mdi_case_order_ids( $runtime, "SELECT id FROM wp_docs ORDER BY {$case}, published DESC LIMIT 2" );
$unsupported = mdi_case_order_ids( $runtime, "SELECT id FROM wp_docs ORDER BY (CASE WHEN title LIKE '%searchable%' THEN 1 ELSE 2 END) + 1" );

$checks = array(
	'CASE branches rank by their first matching condition' => '1,2,3,4,5,6' === $ranked,
	'descending CASE order reverses ranks and keeps the following term' => '6,5,4,3,2,1' === $descending,
	'CASE ordering resolves before LIMIT' => '1,2' === $bounded,
	'arithmetic around CASE stays fail-closed' => str_starts_with( $unsupported, 'unsupported:' ),
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
