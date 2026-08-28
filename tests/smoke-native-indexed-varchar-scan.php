<?php
/** IS NOT NULL and indexed varchar-only conjunctions. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-varchar-scan-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_records (id int unsigned NOT NULL auto_increment, object_type varchar(32) NOT NULL, object_sub_type varchar(32) NULL, label varchar(64) NULL, PRIMARY KEY (id), KEY object_type_and_sub_type (object_type, object_sub_type))',
		'wp_'
	)
);
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_records (object_type, object_sub_type, label) VALUES ('post-type-archive', 'product', 'archive')", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_records (object_type, object_sub_type, label) VALUES ('home-page', NULL, NULL)", 'wp_' ) );

$archive = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT id, label FROM `wp_plugin_records` WHERE `object_type` = 'post-type-archive' AND `object_sub_type` = 'product' LIMIT 1", 'wp_' )
);
$home = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_plugin_records WHERE object_type = 'home-page' LIMIT 1", 'wp_' ) );
$not_null = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT id FROM wp_plugin_records WHERE object_type = 'post-type-archive' AND object_sub_type IS NOT NULL", 'wp_' )
);
$is_null = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_plugin_records WHERE object_type = 'home-page' AND object_sub_type IS NULL", 'wp_' ) );
$unindexed = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_plugin_records WHERE label = 'archive'", 'wp_' ) );

$checks = array(
	'an indexed varchar conjunction scans without a numeric lookup' => 1 === $archive->return_value()
		&& 'archive' === (string) ( $archive->wpdb_state()['last_result'][0]->label ?? '' ),
	'a single indexed varchar equality scans' => 1 === $home->return_value(),
	'IS NOT NULL filters non-null values' => array( '1' ) === array_map(
		static fn( object $row ): string => (string) $row->id,
		$not_null->wpdb_state()['last_result']
	),
	'IS NULL filters null values' => array( '2' ) === array_map(
		static fn( object $row ): string => (string) $row->id,
		$is_null->wpdb_state()['last_result']
	),
	'an unindexed varchar equality still fails closed' => false === $unindexed->return_value()
		&& 'unsupported_lookup' === ( $unindexed->diagnostic()['reason'] ?? null ),
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
