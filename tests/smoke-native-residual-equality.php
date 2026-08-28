<?php
/** Residual equality filters on non-lookup integer columns. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-residual-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_yoast_indexable (id int unsigned NOT NULL auto_increment, object_id int unsigned, object_type varchar(32) NOT NULL, PRIMARY KEY (id))',
		'wp_'
	)
);
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (object_id, object_type) VALUES (7, 'post')", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (object_id, object_type) VALUES (8, 'post')", 'wp_' ) );
$hit = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id, object_id FROM wp_yoast_indexable WHERE object_id = 7', 'wp_' ) );
$miss = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_yoast_indexable WHERE object_id = 404', 'wp_' ) );

$checks = array(
	'equality on a non-lookup integer column scans matching rows' => array( '7' ) === array_map(
		static fn( object $row ): string => (string) $row->object_id,
		$hit->wpdb_state()['last_result']
	),
	'a residual equality miss is an empty success' => array() === $miss->wpdb_state()['last_result']
		&& false !== $miss->return_value(),
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
