<?php
/** Native post mutations fail closed while their journal cannot roll them back. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-post-transaction-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/post', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_' );
$begin = $runtime->execute( new WP_Markdown_Query_Request( 'BEGIN', 'wp_' ) );
$insert = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', 'Blocked', '', 'publish', 'open', 'open', '', 'blocked', '', '', '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', 0, '', 0, 'post', '', 0)", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK', 'wp_' ) );
$passed = 0 === $begin->return_value() && false === $insert->return_value() && 'unsupported_transaction_boundary' === ( $insert->diagnostic()['reason'] ?? null ) && array() === ( glob( $root . '/post/*.md' ) ?: array() );
echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . "native post mutation rejects an active transaction without writing Markdown\n";
@unlink( $root . '/.mdi-native-posts.lock' );
@rmdir( $root . '/post' );
@rmdir( $root . '/_options' );
@rmdir( $root . '/_journal' );
@rmdir( $root );
exit( $passed ? 0 : 1 );
