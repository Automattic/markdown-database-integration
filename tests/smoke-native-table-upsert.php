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
$unindexed_read = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_yoast_indexable WHERE title = 'two'" ) );
$unindexed_membership = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_yoast_indexable WHERE title IN ('two', 'eight') ORDER BY id" ) );
$cleared = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (id, object_id, title) VALUES (1, 7, 'ignored') ON DUPLICATE KEY UPDATE title = NULL" ) );
$cleared_row = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT title FROM wp_yoast_indexable WHERE id = 1' ) );
$noop = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (id, object_id, title) VALUES (1, 7, 'ignored') ON DUPLICATE KEY UPDATE object_id = object_id, title = NULL" ) );
$ordered = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (id, object_id, title) VALUES (1, 7, 'incoming') ON DUPLICATE KEY UPDATE object_id = 9, title = object_id" ) );
$ordered_row = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT object_id, title FROM wp_yoast_indexable WHERE id = 1' ) );
$cross_values = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (id, object_id, title) VALUES (1, 9, 'incoming') ON DUPLICATE KEY UPDATE title = VALUES(object_id)" ) );
$bad_target = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (id, object_id, title) VALUES (3, 10, 'new') ON DUPLICATE KEY UPDATE absent_column = NULL" ) );
$new_literal = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (id, object_id, title) VALUES (3, 11, 'new') ON DUPLICATE KEY UPDATE title = NULL" ) );
$new_row = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT title FROM wp_yoast_indexable WHERE id = 3' ) );
$increment = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_yoast_indexable (id, object_id, title) VALUES (1, 9, 'ignored') ON DUPLICATE KEY UPDATE object_id = object_id + 1" ) );
$incremented_row = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT object_id FROM wp_yoast_indexable WHERE id = 1' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_unique_labels (id int NOT NULL, label varchar(20) NOT NULL, PRIMARY KEY(id), UNIQUE KEY label(label))' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_unique_labels (id,label) VALUES (1,'original')" ) );
$unsupported_unique = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_unique_labels (id,label) VALUES (1,'original') ON DUPLICATE KEY UPDATE label='caf\xC3\xA9'" ) );

$checks = array(
	'validated snapshots filter non-indexed equality and membership columns' => $unindexed_read->succeeded() && array( array( 'id' => '1' ) ) === $unindexed_read->corpus_result()['rows'] && $unindexed_membership->succeeded() && array( array( 'id' => '1' ), array( 'id' => '2' ) ) === $unindexed_membership->corpus_result()['rows'],
	'literal assignments preserve fail-closed unique-key enforcement' => ! $unsupported_unique->succeeded() && 'unsupported_unique_collation' === $unsupported_unique->diagnostic()['reason'],
	'literal NULL assignments clear only a conflicting row' => 2 === $cleared->return_value() && null === $cleared_row->corpus_result()['rows'][0]['title'],
	'unchanged duplicate assignments report zero affected rows' => 0 === $noop->return_value() && 0 === $noop->wpdb_state()['insert_id'],
	'column references observe earlier duplicate assignments in order' => 2 === $ordered->return_value() && array( 'object_id' => '9', 'title' => '9' ) === $ordered_row->corpus_result()['rows'][0],
	'VALUES may refer to a different inserted column' => $cross_values->succeeded() && 0 === $cross_values->return_value(),
	'unknown duplicate target fails even on the insert branch' => ! $bad_target->succeeded() && 'unsupported_column' === $bad_target->diagnostic()['reason'],
	'duplicate literals are not applied to a newly inserted row' => 1 === $new_literal->return_value() && 'new' === $new_row->corpus_result()['rows'][0]['title'],
	'duplicate assignments support bounded row-local arithmetic' => 2 === $increment->return_value() && '10' === $incremented_row->corpus_result()['rows'][0]['object_id'],
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
