<?php
/** Native post mutations journal canonical files across transaction boundaries. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-post-transaction-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/post', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_' );
$sql = "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', 'Transactional', '', 'publish', 'open', 'open', '', 'transactional', '', '', '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', 0, '', 0, 'post', '', 0)";
$begin = $runtime->execute( new WP_Markdown_Query_Request( 'BEGIN', 'wp_' ) );
$insert = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
$insert_id = (int) ( $insert->wpdb_state()['insert_id'] ?? 0 );
$before_rollback = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title FROM wp_posts WHERE ID = ' . $insert_id, 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK', 'wp_' ) );
$after_rollback = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts WHERE ID = ' . $insert_id, 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'BEGIN', 'wp_' ) );
$committed = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
$committed_id = (int) ( $committed->wpdb_state()['insert_id'] ?? 0 );
$runtime->execute( new WP_Markdown_Query_Request( 'COMMIT', 'wp_' ) );
$after_commit = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title FROM wp_posts WHERE ID = ' . $committed_id, 'wp_' ) );
$passed = 0 === $begin->return_value()
	&& 1 === $insert->return_value()
	&& 0 < $insert_id
	&& 'Transactional' === ( $before_rollback->wpdb_state()['last_result'][0]->post_title ?? null )
	&& array() === $after_rollback->wpdb_state()['last_result']
	&& 1 === $committed->return_value()
	&& 0 < $committed_id
	&& 'Transactional' === ( $after_commit->wpdb_state()['last_result'][0]->post_title ?? null )
	&& 1 === count( glob( $root . '/post/*.md' ) ?: array() );
echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . "native post mutation journals rollback and commit canonical files\n";
@unlink( $root . '/.mdi-native-posts.lock' );
@rmdir( $root . '/post' );
@rmdir( $root . '/_options' );
@rmdir( $root . '/_journal' );
@rmdir( $root );
exit( $passed ? 0 : 1 );
