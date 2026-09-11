<?php
/** Canonical Markdown post INSERT, UPDATE, and DELETE. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-post-write-' . bin2hex( random_bytes( 6 ) );
$state = $root . '/state';
$content = $root . '/content';
if ( ! mkdir( $state . '/_options', 0777, true ) || ! mkdir( $content . '/post', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the post write fixture.' );
}

$markdown_files = static function ( string $root ): array {
	if ( ! is_dir( $root ) ) {
		return array();
	}
	$found = array();
	$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $entries as $entry ) {
		if ( $entry->isFile() && str_ends_with( strtolower( $entry->getFilename() ), '.md' ) ) {
			$found[] = $entry->getPathname();
		}
	}
	return $found;
};

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $state, 'wp_', null, false, $content );
$insert = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, '2026-08-27 12:00:00', '2026-08-27 12:00:00', 'Hello body', 'Hello title', '', 'publish', 'open', 'open', '', 'hello-title', '', '', '2026-08-27 12:00:00', '2026-08-27 12:00:00', '', 0, 'http://localhost/hello-title/', 0, 'post', '', 0)",
		'wp_'
	)
);
$files_after_insert = $markdown_files( $content );
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title, post_content, post_name FROM wp_posts WHERE ID = 1', 'wp_' ) );
$update = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_posts SET post_title = 'Renamed title', post_content = 'Changed body' WHERE ID = 1", 'wp_' ) );
$after_update = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title, post_content FROM wp_posts WHERE ID = 1', 'wp_' ) );
$delete = $runtime->execute( new WP_Markdown_Query_Request( 'DELETE FROM wp_posts WHERE ID = 1', 'wp_' ) );
$after_delete = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts WHERE ID = 1', 'wp_' ) );
$files_after_delete = $markdown_files( $content );
$wp_insert = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO `wp_posts` (`post_author`, `post_date`, `post_date_gmt`, `post_content`, `post_content_filtered`, `post_title`, `post_excerpt`, `post_status`, `post_type`, `comment_status`, `ping_status`, `post_password`, `post_name`, `to_ping`, `pinged`, `post_modified`, `post_modified_gmt`, `post_parent`, `menu_order`, `post_mime_type`, `guid`) VALUES (1, '2026-08-28 00:03:38', '2026-08-28 00:03:38', 'Written by native wp_insert_post.', '', 'Native Save Probe', '', 'publish', 'post', 'open', 'open', '', 'native-save-probe', '', '', '2026-08-28 00:03:38', '2026-08-28 00:03:38', 0, 0, '', '')",
		'wp_'
	)
);
$wp_id = (int) $wp_insert->wpdb_state()['insert_id'];
$wp_read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title, comment_count FROM wp_posts WHERE ID = ' . $wp_id, 'wp_' ) );

// A child post belongs inside its parent's directory. The writer walks the
// ancestor chain through the runtime's post resolver to find that directory,
// so a runtime without one writes the child flat while the row still records
// the parent, and the canonical path then disagrees with the hierarchy.
$parent_insert = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, '2026-08-27 12:00:00', '2026-08-27 12:00:00', 'Parent body', 'Parent', '', 'publish', 'open', 'open', '', 'parent-slug', '', '', '2026-08-27 12:00:00', '2026-08-27 12:00:00', '', 0, 'http://localhost/parent-slug/', 0, 'post', '', 0)",
		'wp_'
	)
);
$parent_id = (int) ( $parent_insert->wpdb_state()['insert_id'] ?? 0 );
$child_insert = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, '2026-08-27 12:00:00', '2026-08-27 12:00:00', 'Child body needle', 'Child', '', 'publish', 'open', 'open', '', 'child-slug', '', '', '2026-08-27 12:00:00', '2026-08-27 12:00:00', '', {$parent_id}, 'http://localhost/child-slug/', 0, 'post', '', 0)",
		'wp_'
	)
);
$child_id = (int) ( $child_insert->wpdb_state()['insert_id'] ?? 0 );
$child_files = array_values( array_filter( $markdown_files( $content ), static fn( string $path ): bool => str_ends_with( $path, 'child-slug.md' ) ) );
$child_nested = array() !== $child_files && str_ends_with( $child_files[0], '/post/parent-slug/child-slug.md' );
// A body predicate is matched after the provider read, so a child whose file
// the provider cannot resolve returns empty content and matches nothing.
$child_body = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE post_content LIKE '%needle%'", 'wp_' ) );
$child_body_ids = array_map( static fn( object $row ): int => (int) $row->ID, $child_body->wpdb_state()['last_result'] ?? array() );

$checks = array(
	'an INSERT assigns an identity and writes markdown' => 1 === $insert->return_value()
		&& 1 === $insert->wpdb_state()['insert_id']
		&& array() !== $files_after_insert,
	'the inserted post is readable by ID' => 'Hello title' === ( $read->wpdb_state()['last_result'][0]->post_title ?? null )
		&& 'Hello body' === ( $read->wpdb_state()['last_result'][0]->post_content ?? null ),
	'an UPDATE rewrites the canonical file' => 1 === $update->return_value()
		&& 'Renamed title' === ( $after_update->wpdb_state()['last_result'][0]->post_title ?? null )
		&& 'Changed body' === ( $after_update->wpdb_state()['last_result'][0]->post_content ?? null ),
	'a DELETE removes the canonical file' => 1 === $delete->return_value()
		&& 0 === $after_delete->return_value()
		&& array() === $files_after_delete,
	'a child post is written inside its parent directory' => $parent_id > 0 && $child_id > 0 && $child_nested,
	'a child post body is searchable at its canonical path' => in_array( $child_id, $child_body_ids, true ),
	'a WordPress wp_insert_post row fills integer defaults' => 1 === $wp_insert->return_value()
		&& $wp_id > 0
		&& 'Native Save Probe' === ( $wp_read->wpdb_state()['last_result'][0]->post_title ?? null )
		&& '0' === (string) ( $wp_read->wpdb_state()['last_result'][0]->comment_count ?? '' ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $content . '/post/parent-slug/*' ) ?: array() );
@rmdir( $content . '/post/parent-slug' );
array_map( 'unlink', glob( $content . '/post/*' ) ?: array() );
@rmdir( $content . '/post' );
@rmdir( $content );
array_map( 'unlink', glob( $state . '/_options/*' ) ?: array() );
@rmdir( $state . '/_options' );
@rmdir( $state );
@rmdir( $root );

exit( $failed ? 1 : 0 );
