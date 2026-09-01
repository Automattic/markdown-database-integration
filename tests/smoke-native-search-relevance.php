<?php
/** Ranking rows by a LIKE match, which is how WordPress orders search results. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-relevance-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the relevance fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_docs (id bigint unsigned NOT NULL auto_increment, title varchar(191) NOT NULL, body text NOT NULL, PRIMARY KEY (id), KEY title (title))',
		'wp_'
	)
);
// Row 1 matches in its title, row 2 only in its body, row 3 not at all.
foreach ( array(
	array( 'alpha guide', 'nothing here' ),
	array( 'beta', 'the guide lives in the body' ),
	array( 'gamma', 'unrelated' ),
) as $row ) {
	$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_docs (title, body) VALUES ('{$row[0]}', '{$row[1]}')", 'wp_' ) );
}

function mdi_relevance_ids( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): string {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
	if ( false === $result->return_value() ) {
		return 'unsupported:' . ( $result->diagnostic()['reason'] ?? 'unknown' );
	}
	return implode( ',', array_map( static fn( object $row ): string => (string) $row->id, $result->wpdb_state()['last_result'] ) );
}

$ranked = mdi_relevance_ids( $runtime, "SELECT id FROM wp_docs WHERE ((title LIKE '%guide%') OR (body LIKE '%guide%')) ORDER BY title LIKE '%guide%' DESC, id ASC" );
$reversed = mdi_relevance_ids( $runtime, "SELECT id FROM wp_docs WHERE ((title LIKE '%guide%') OR (body LIKE '%guide%')) ORDER BY title LIKE '%guide%' ASC, id ASC" );
$unfiltered = mdi_relevance_ids( $runtime, "SELECT id FROM wp_docs ORDER BY title LIKE '%guide%' DESC, id ASC" );
$unicode = mdi_relevance_ids( $runtime, "SELECT id FROM wp_docs ORDER BY title LIKE '%guidé%' DESC, id ASC" );

$checks = array(
	'a title match outranks a body match' => '1,2' === $ranked,
	'ascending relevance puts the match last' => '2,1' === $reversed,
	'unmatched rows keep the following order term' => '1,2,3' === $unfiltered,
	'a non-ASCII ranking pattern stays fail-closed' => str_starts_with( $unicode, 'unsupported:' ),
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
