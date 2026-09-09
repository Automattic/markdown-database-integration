<?php
/** A PHPUnit-style prefix gets the canonical Markdown posts provider on install. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-prefix-post-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/state', 0777, true );
mkdir( $root . '/content', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::prefix_runtime( $root . '/state', $root . '/content' );
$prefix = 'wptests_';

$create = $runtime->execute(
	new WP_Markdown_Query_Request( 'CREATE TABLE wptests_posts (ID bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (ID))', $prefix )
);
$insert = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wptests_posts (post_author, post_date, post_date_gmt, post_content, post_content_filtered, post_title, post_excerpt, post_status, post_type, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_parent, menu_order, post_mime_type, guid) VALUES (0, '2026-09-09 01:25:53', '2026-09-09 01:25:53', 'first content', '', 'First title', '', 'publish', 'post', '', '', '', 'first-title', '', '', '2026-09-09 01:25:53', '2026-09-09 01:25:53', 0, 0, '', '')",
		$prefix
	)
);
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title, post_content FROM wptests_posts WHERE ID = 1', $prefix ) );
$update = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wptests_posts SET post_title = 'Updated title', post_content = 'updated content' WHERE ID = 1", $prefix ) );
$updated = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title, post_content FROM wptests_posts WHERE ID = 1', $prefix ) );

$checks = array(
	'core schema install registers the active prefixed post provider' => null === $create->diagnostic(),
	'WordPress post insert returns an identity from canonical Markdown storage' => 1 === $insert->return_value() && 1 === $insert->wpdb_state()['insert_id'],
	'inserted posts are readable through the test prefix' => 'First title' === ( $read->wpdb_state()['last_result'][0]->post_title ?? null ) && 'first content' === ( $read->wpdb_state()['last_result'][0]->post_content ?? null ),
	'updates preserve the test prefix canonical provider' => 1 === $update->return_value() && 'Updated title' === ( $updated->wpdb_state()['last_result'][0]->post_title ?? null ) && 'updated content' === ( $updated->wpdb_state()['last_result'][0]->post_content ?? null ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/content/post/*' ) ?: array() );
@rmdir( $root . '/content/post' );
@rmdir( $root . '/content' );
@rmdir( $root . '/state' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
