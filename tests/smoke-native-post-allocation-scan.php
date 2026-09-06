<?php
/** A generated native ID reuses its current locked allocation scan only. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

final class MDI_Native_Post_Allocation_Scan_Storage extends WP_Markdown_Storage {
	public int $manifest_scans = 0;

	public function get_markdown_file_manifest_iterator( bool $strict = false, ?array $post_types = null ): Generator {
		++$this->manifest_scans;
		yield from parent::get_markdown_file_manifest_iterator( $strict, $post_types );
	}
}

function mdi_allocation_scan_insert( WP_Markdown_Native_Query_Runtime $runtime, string $slug ): int {
	$result = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', '{$slug}', '', 'publish', 'open', 'open', '', '{$slug}', '', '', '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', 0, '', 0, 'post', '', 0)", 'wp_' ) );
	return (int) ( $result->wpdb_state()['insert_id'] ?? 0 );
}

$root = sys_get_temp_dir() . '/mdi-native-post-allocation-scan-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/state', 0777, true );
mkdir( $root . '/content/post', 0777, true );
$storage = new MDI_Native_Post_Allocation_Scan_Storage( $root . '/content' );
$schema = WP_Markdown_Native_Runtime_Factory::posts_schema();
$registry = new WP_Markdown_Native_Table_Registry();
$registry->register( 'wp_posts', $schema, new WP_Markdown_Native_Post_Provider( $root . '/content', $schema, $storage, $root . '/state' ) );
$runtime = new WP_Markdown_Native_Query_Runtime( $registry, new WP_Markdown_Native_Query_Parser(), null, null, null, null, new WP_Markdown_Native_Post_Mutation_Runtime( $registry, new WP_Markdown_Native_Table_Insert_Parser(), $storage ) );
$first = mdi_allocation_scan_insert( $runtime, 'first' );
$first_scans = $storage->manifest_scans;
file_put_contents( $root . '/content/post/external.md', "---\nid: 2\ntitle: External\nstatus: publish\ntype: post\nauthor: 1\ndate: 2026-09-06 00:00:00\nmodified: 2026-09-06 00:00:00\nslug: external\ncomment_status: open\nping_status: open\n---\n\n" );
$second = mdi_allocation_scan_insert( $runtime, 'second' );
$passed = 1 === $first && 1 === $first_scans && 3 === $second;
echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . "a generated native INSERT reuses only its locked allocation scan and rescans external edits later\n";
foreach ( glob( $root . '/content/post/*.md' ) ?: array() as $file ) { @unlink( $file ); }
@unlink( $root . '/content/.mdi-native-posts.lock' );
@rmdir( $root . '/content/post' );
@rmdir( $root . '/content' );
@rmdir( $root . '/state' );
@rmdir( $root );
exit( $passed ? 0 : 1 );
