<?php
/** Signed integer casts used by numeric WordPress meta queries. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-signed-cast-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the signed cast fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_metrics (id bigint unsigned NOT NULL auto_increment, value text, PRIMARY KEY (id))',
		'wp_'
	)
);
foreach ( array( '-5', '2', '10', '10.9', '12abc', 'abc', '  +20rest', '999999999999999999999999' ) as $value ) {
	$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_metrics (value) VALUES ('{$value}')", 'wp_' ) );
}

function mdi_signed_cast_ids( WP_Markdown_Native_Query_Runtime $runtime, string $predicate ): string {
	$result = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_metrics WHERE {$predicate} ORDER BY id ASC", 'wp_' ) );
	if ( false === $result->return_value() ) {
		return 'unsupported:' . ( $result->diagnostic()['reason'] ?? 'unknown' );
	}
	return implode( ',', array_map( static fn( object $row ): string => (string) $row->id, $result->wpdb_state()['last_result'] ) );
}

$greater = mdi_signed_cast_ids( $runtime, "CAST(value AS SIGNED) > '10'" );
$equal = mdi_signed_cast_ids( $runtime, 'CAST(value AS SIGNED) = 10' );
$negative = mdi_signed_cast_ids( $runtime, 'CAST(value AS SIGNED) < 0' );
$zero = mdi_signed_cast_ids( $runtime, 'CAST(value AS SIGNED) = 0' );
$unsigned = mdi_signed_cast_ids( $runtime, 'CAST(value AS UNSIGNED) > 10' );
$expression = mdi_signed_cast_ids( $runtime, 'CAST(value AS SIGNED) + 1 > 10' );

$checks = array(
	'signed casts compare numeric prefixes and clamp positive overflow' => '5,7,8' === $greater,
	'signed casts truncate decimal suffixes' => '3,4' === $equal,
	'signed casts preserve negative prefixes' => '1' === $negative,
	'nonnumeric strings cast to zero' => '6' === $zero,
	'other cast targets stay fail-closed' => str_starts_with( $unsigned, 'unsupported:' ),
	'arithmetic around a cast stays fail-closed' => str_starts_with( $expression, 'unsupported:' ),
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
