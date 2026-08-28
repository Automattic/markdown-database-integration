<?php
/** CREATE INDEX / CREATE UNIQUE INDEX rewrite persisted plugin DDL. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-create-index-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_records (id int unsigned NOT NULL auto_increment, object_id int unsigned NOT NULL, object_type varchar(32) NOT NULL, PRIMARY KEY (id))',
		'wp_'
	)
);

$unique = $runtime->execute(
	new WP_Markdown_Query_Request( 'CREATE UNIQUE INDEX object_id_and_type ON wp_plugin_records(object_id, object_type)', 'wp_' )
);
$plain = $runtime->execute(
	new WP_Markdown_Query_Request( 'CREATE INDEX object_type ON `wp_plugin_records` (`object_type`)', 'wp_' )
);
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_records (object_id, object_type) VALUES (7, 'post')", 'wp_' ) );
$upsert = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_plugin_records (object_id, object_type) VALUES (7, 'post') ON DUPLICATE KEY UPDATE object_type = VALUES(object_type)",
		'wp_'
	)
);
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_plugin_records WHERE object_id = 7', 'wp_' ) );
$shown = $runtime->execute( new WP_Markdown_Query_Request( "SHOW INDEX FROM wp_plugin_records WHERE key_name = 'object_id_and_type'", 'wp_' ) );
$persisted = (string) file_get_contents( $root . '/_schema/plugin_records.sql' );

$checks = array(
	'CREATE UNIQUE INDEX persists' => false !== $unique->return_value()
		&& str_contains( $persisted, 'UNIQUE KEY `object_id_and_type`' ),
	'CREATE INDEX persists a non-unique key' => false !== $plain->return_value()
		&& str_contains( $persisted, 'KEY `object_type`' ),
	'a unique CREATE INDEX makes ON DUPLICATE KEY UPDATE match' => 2 === $upsert->return_value()
		&& 1 === count( $read->wpdb_state()['last_result'] ),
	'SHOW INDEX sees the created unique key' => array( 'object_id', 'object_type' ) === array_map(
		static fn( object $row ): string => (string) $row->Column_name,
		$shown->wpdb_state()['last_result']
	),
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
