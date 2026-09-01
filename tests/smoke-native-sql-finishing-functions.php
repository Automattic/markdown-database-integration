<?php
/** Grouping, post-aggregation filters, expression ordering, regexes, and SHOW TABLES. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-finishing-functions-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_records (id int unsigned NOT NULL auto_increment, status varchar(16) NOT NULL, title varchar(32) NOT NULL, PRIMARY KEY (id), KEY status (status))', 'wp_' ) );
foreach ( array( array( 'publish', 'alpha' ), array( 'publish', 'able' ), array( 'draft', 'beta' ), array( 'other', 'zeta' ) ) as $record ) {
	$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_records (status, title) VALUES ('{$record[0]}', '{$record[1]}')", 'wp_' ) );
}

$grouped = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT status, COUNT(*) AS total, GROUP_CONCAT(id) AS ids FROM wp_records GROUP BY status HAVING total > 1', 'wp_' ) );
$field = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_records ORDER BY FIELD(status, 'publish', 'draft') LIMIT 4", 'wp_' ) );
$regexp = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_records WHERE title REGEXP '^a' ORDER BY id", 'wp_' ) );
$shown = $runtime->execute( new WP_Markdown_Query_Request( 'SHOW TABLES', 'wp_' ) );
$unsupported = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_records WHERE title REGEXP 'a.*'", 'wp_' ) );

$checks = array(
	'GROUP_CONCAT and HAVING aggregate groups before filtering' => array( 'publish' => array( '2', '1,2' ) ) === array_reduce( $grouped->wpdb_state()['last_result'], static function ( array $rows, object $row ): array { $rows[ $row->status ] = array( $row->total, $row->ids ); return $rows; }, array() ),
	'FIELD returns MySQL positions and sorts unmatched values first' => array( '4', '1', '2', '3' ) === array_map( static fn( object $row ): string => $row->id, $field->wpdb_state()['last_result'] ),
	'anchored ASCII REGEXP filters matching rows' => array( '1', '2' ) === array_map( static fn( object $row ): string => $row->id, $regexp->wpdb_state()['last_result'] ),
	'SHOW TABLES reports the current-database MySQL column name' => array( 'Tables_in_' ) === array_map( static fn( object $column ): string => $column->name, $shown->wpdb_state()['col_info'] )
		&& in_array( 'wp_records', array_map( static fn( object $row ): string => $row->{'Tables_in_'}, $shown->wpdb_state()['last_result'] ), true ),
	'unsupported REGEXP syntax fails closed' => false === $unsupported->return_value() && 'unsupported_literal' === ( $unsupported->diagnostic()['reason'] ?? null ),
);
$failed = false;
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; $failed = $failed || ! $passed; }
array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_schema/*' ) ?: array() );
@rmdir( $root . '/_tables' ); @rmdir( $root . '/_schema' ); @rmdir( $root . '/_options' ); @rmdir( $root );
exit( $failed ? 1 : 0 );
