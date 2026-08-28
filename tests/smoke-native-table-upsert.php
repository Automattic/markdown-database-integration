<?php
/** INSERT ON DUPLICATE KEY UPDATE for generic snapshot tables. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-upsert-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_yoast_indexable (id int unsigned NOT NULL auto_increment, object_id int unsigned NOT NULL, title varchar(191) NULL, PRIMARY KEY (id), UNIQUE KEY object_id (object_id))',
		'wp_'
	)
);

$insert = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (object_id, title) VALUES (7, 'one')", 'wp_' ) );
$upsert = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO `wp_yoast_indexable` (`object_id`, `title`) VALUES ( 7, 'two' ) ON DUPLICATE KEY UPDATE `object_id` = VALUES(`object_id`), `title` = VALUES(`title`)",
		'wp_'
	)
);
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id, object_id, title FROM wp_yoast_indexable WHERE object_id = 7', 'wp_' ) );
$fresh = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (object_id, title) VALUES (8, 'eight') ON DUPLICATE KEY UPDATE title = VALUES(title)", 'wp_' ) );

$checks = array(
	'the first insert persists' => 1 === $insert->return_value() && 1 === $insert->wpdb_state()['insert_id'],
	'ON DUPLICATE KEY UPDATE rewrites the conflicting row' => 2 === $upsert->return_value()
		&& 1 === $upsert->wpdb_state()['insert_id']
		&& 1 === count( $read->wpdb_state()['last_result'] )
		&& 'two' === (string) ( $read->wpdb_state()['last_result'][0]->title ?? '' )
		&& '1' === (string) ( $read->wpdb_state()['last_result'][0]->id ?? '' ),
	'ON DUPLICATE KEY UPDATE inserts when no unique key conflicts' => 1 === $fresh->return_value() && 2 === $fresh->wpdb_state()['insert_id'],
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
