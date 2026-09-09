<?php
/** A PHPUnit-style prefix gets the canonical Markdown posts provider on install. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-prefix-post-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/state', 0777, true );
mkdir( $root . '/content', 0777, true );
mkdir( $root . '/state/_options', 0777, true );
file_put_contents( $root . '/state/_options/siteurl.json', json_encode( array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://example.test', 'autoload' => 'on' ), JSON_THROW_ON_ERROR ) );
$runtime = WP_Markdown_Native_Runtime_Factory::prefix_runtime( $root . '/state', $root . '/content' );
$prefix = 'wptests_';

$insert = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wptests_posts (post_author, post_date, post_date_gmt, post_content, post_content_filtered, post_title, post_excerpt, post_status, post_type, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_parent, menu_order, post_mime_type, guid) VALUES (0, '2026-09-09 01:25:53', '2026-09-09 01:25:53', 'first content', '', 'First title', '', 'publish', 'post', '', '', '', 'first-title', '', '', '2026-09-09 01:25:53', '2026-09-09 01:25:53', 0, 0, '', '')",
		$prefix
	)
);
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title, post_content FROM wptests_posts WHERE ID = 1', $prefix ) );
$update = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wptests_posts SET post_title = 'Updated title', post_content = 'updated content' WHERE ID = 1", $prefix ) );
$updated = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title, post_content FROM wptests_posts WHERE ID = 1', $prefix ) );
$begin = $runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION', $prefix ) );
$transient = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wptests_posts (post_author, post_date, post_date_gmt, post_content, post_content_filtered, post_title, post_excerpt, post_status, post_type, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_parent, menu_order, post_mime_type, guid) VALUES (0, '2026-09-09 01:25:53', '2026-09-09 01:25:53', 'transient content', '', 'Transient title', '', 'publish', 'post', '', '', '', 'transient-title', '', '', '2026-09-09 01:25:53', '2026-09-09 01:25:53', 0, 0, '', '')", $prefix ) );
// A later prefix runtime shares this root's transaction owner. Its cold-root
// initialization must not recover the live post write.
$switched_runtime = WP_Markdown_Native_Runtime_Factory::prefix_runtime( $root . '/state', $root . '/content' );
$switched_runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wptests_posts WHERE post_name = \'transient-title\'', $prefix ) );
$during_transaction = array() !== glob( $root . '/content/post/transient-title*' );
$rollback = $runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK', $prefix ) );
$after_rollback = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wptests_posts WHERE post_name = \'transient-title\'', $prefix ) );
$commit_begin = $runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION', $prefix ) );
$commit_write = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wptests_posts SET post_title = 'Committed title' WHERE ID = 1", $prefix ) );
$commit = $runtime->execute( new WP_Markdown_Query_Request( 'COMMIT', $prefix ) );
$commit_read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title FROM wptests_posts WHERE ID = 1', $prefix ) );
$savepoint_begin = $runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION', $prefix ) );
$before_savepoint = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wptests_posts SET post_title = 'Before savepoint' WHERE ID = 1", $prefix ) );
$savepoint = $runtime->execute( new WP_Markdown_Query_Request( 'SAVEPOINT post_stage', $prefix ) );
$after_savepoint = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wptests_posts SET post_title = 'After savepoint' WHERE ID = 1", $prefix ) );
$rewind = $runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK TO SAVEPOINT post_stage', $prefix ) );
$savepoint_commit = $runtime->execute( new WP_Markdown_Query_Request( 'COMMIT', $prefix ) );
$savepoint_read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title FROM wptests_posts WHERE ID = 1', $prefix ) );
$move_begin = $runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION', $prefix ) );
$move = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wptests_posts SET post_name = 'moved-title', post_title = 'Moved title', post_content = 'moved content' WHERE ID = 1", $prefix ) );
$delete = $runtime->execute( new WP_Markdown_Query_Request( 'DELETE FROM wptests_posts WHERE ID = 1', $prefix ) );
$move_rollback = $runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK', $prefix ) );
$after_move_rollback = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_name, post_title, post_content FROM wptests_posts WHERE ID = 1', $prefix ) );
$cold_runtime = WP_Markdown_Native_Runtime_Factory::prefix_runtime( $root . '/state', $root . '/content' );
$cold_read = $cold_runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wptests_posts WHERE post_name = \'transient-title\'', $prefix ) );
$cold_committed = $cold_runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title FROM wptests_posts WHERE ID = 1', $prefix ) );

$checks = array(
	'existing canonical state registers the active prefixed post provider' => null === $insert->diagnostic(),
	'WordPress post insert returns an identity from canonical Markdown storage' => 1 === $insert->return_value() && 1 === $insert->wpdb_state()['insert_id'],
	'inserted posts are readable through the test prefix' => 'First title' === ( $read->wpdb_state()['last_result'][0]->post_title ?? null ) && 'first content' === ( $read->wpdb_state()['last_result'][0]->post_content ?? null ),
	'updates preserve the test prefix canonical provider' => 1 === $update->return_value() && 'Updated title' === ( $updated->wpdb_state()['last_result'][0]->post_title ?? null ) && 'updated content' === ( $updated->wpdb_state()['last_result'][0]->post_content ?? null ),
	'a prefix runtime created during a transaction reads its journaled Markdown write' => null === $begin->diagnostic() && 1 === $transient->return_value() && $during_transaction,
	'rollback removes a journaled split-root Markdown insert from warm and cold reads' => null === $rollback->diagnostic() && 0 === $after_rollback->wpdb_state()['num_rows'] && 0 === $cold_read->wpdb_state()['num_rows'] && array() === glob( $root . '/content/post/transient-title*' ),
	'commit persists a journaled split-root Markdown update' => null === $commit_begin->diagnostic() && 1 === $commit_write->return_value() && null === $commit->diagnostic() && 'Committed title' === ( $commit_read->wpdb_state()['last_result'][0]->post_title ?? null ),
	'savepoint rewind restores the journaled split-root Markdown update' => null === $savepoint_begin->diagnostic() && 1 === $before_savepoint->return_value() && null === $savepoint->diagnostic() && 1 === $after_savepoint->return_value() && null === $rewind->diagnostic() && null === $savepoint_commit->diagnostic() && 'Before savepoint' === ( $savepoint_read->wpdb_state()['last_result'][0]->post_title ?? null ) && 'Before savepoint' === ( $cold_committed->wpdb_state()['last_result'][0]->post_title ?? null ),
	'rollback restores a journaled slug move and delete with the provider cache invalidated' => null === $move_begin->diagnostic() && 1 === $move->return_value() && 1 === $delete->return_value() && null === $move_rollback->diagnostic() && 'first-title' === ( $after_move_rollback->wpdb_state()['last_result'][0]->post_name ?? null ) && 'Before savepoint' === ( $after_move_rollback->wpdb_state()['last_result'][0]->post_title ?? null ) && 'updated content' === ( $after_move_rollback->wpdb_state()['last_result'][0]->post_content ?? null ) && is_file( $root . '/content/post/first-title.md' ) && ! file_exists( $root . '/content/post/moved-title.md' ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/content/post/*' ) ?: array() );
@rmdir( $root . '/content/post' );
@rmdir( $root . '/content' );
@unlink( $root . '/state/_options/siteurl.json' );
@rmdir( $root . '/state/_options' );
@rmdir( $root . '/state' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
