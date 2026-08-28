<?php
/** Indexed non-unique varchar columns filter by exact ASCII equality. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-varchar-filter-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_records (id int unsigned NOT NULL auto_increment, object_id int unsigned NOT NULL, object_type varchar(32) NOT NULL, PRIMARY KEY (id), KEY object_id_and_type (object_id, object_type))',
		'wp_'
	)
);
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_records (object_id, object_type) VALUES (8, 'post')", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_records (object_id, object_type) VALUES (8, 'term')", 'wp_' ) );

$scoped = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT id, object_type FROM `wp_plugin_records` WHERE `object_id` = '8' AND `object_type` = 'post' LIMIT 1", 'wp_' )
);
$other = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT id, object_type FROM wp_plugin_records WHERE object_id = 8 AND object_type = 'term'", 'wp_' )
);
$unicode = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT id FROM wp_plugin_records WHERE object_id = 8 AND object_type = 'pöst'", 'wp_' )
);

$checks = array(
	'an indexed varchar scopes an integer lookup' => 1 === $scoped->return_value()
		&& 'post' === (string) ( $scoped->wpdb_state()['last_result'][0]->object_type ?? '' ),
	'each indexed varchar value selects its own row' => 1 === $other->return_value()
		&& 'term' === (string) ( $other->wpdb_state()['last_result'][0]->object_type ?? '' ),
	'a non-ASCII indexed varchar value fails closed' => false === $unicode->return_value()
		&& 'unsupported_lookup' === ( $unicode->diagnostic()['reason'] ?? null ),
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
