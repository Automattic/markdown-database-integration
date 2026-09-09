<?php
/** A generated native ID reuses its current locked allocation scan only. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

final class MDI_Native_Post_Allocation_Scan_Storage extends WP_Markdown_Storage {
	public int $manifest_scans = 0;
	public string $catalogue_path;
	public bool $published_before_write = false;
	public array $file_reads = array();

	public function read_file( string $file_path, bool $metadata_only = false, ?int $parent_id = null ): ?object {
		$this->file_reads[ $file_path ] = ( $this->file_reads[ $file_path ] ?? 0 ) + 1;
		return parent::read_file( $file_path, $metadata_only, $parent_id );
	}

	public function write_post( object $post, bool $persist_auto_draft = false ): string|false {
		$this->published_before_write = $this->published_before_write || is_file( $this->catalogue_path );
		return parent::write_post( $post, $persist_auto_draft );
	}

	public function get_markdown_file_manifest_iterator( bool $strict = false, ?array $post_types = null ): Generator {
		++$this->manifest_scans;
		yield from parent::get_markdown_file_manifest_iterator( $strict, $post_types );
	}
}

function mdi_allocation_scan_result( WP_Markdown_Native_Query_Runtime $runtime, string $slug ): WP_Markdown_Query_Result {
	return $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', '{$slug}', '', 'publish', 'open', 'open', '', '{$slug}', '', '', '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', 0, '', 0, 'post', '', 0)", 'wp_' ) );
}

function mdi_allocation_scan_insert( WP_Markdown_Native_Query_Runtime $runtime, string $slug ): int {
	return (int) ( mdi_allocation_scan_result( $runtime, $slug )->wpdb_state()['insert_id'] ?? 0 );
}

$root = sys_get_temp_dir() . '/mdi-native-post-allocation-scan-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/state', 0777, true );
mkdir( $root . '/content/post', 0777, true );
$storage = new MDI_Native_Post_Allocation_Scan_Storage( $root . '/content' );
$storage->catalogue_path = $root . '/state/_indexes/posts.json';
$schema = WP_Markdown_Native_Runtime_Factory::posts_schema();
$registry = new WP_Markdown_Native_Table_Registry();
$registry->register( 'wp_posts', $schema, new WP_Markdown_Native_Post_Provider( $root . '/content', $schema, $storage, $root . '/state' ) );
$runtime = new WP_Markdown_Native_Query_Runtime( $registry, new WP_Markdown_Native_Query_Parser(), null, null, null, null, new WP_Markdown_Native_Post_Mutation_Runtime( $registry, new WP_Markdown_Native_Table_Insert_Parser(), $storage ) );
WP_Markdown_Operation_Profile::start();
$first = mdi_allocation_scan_insert( $runtime, 'first' );
$profile = WP_Markdown_Operation_Profile::stop();
$profile_valid = 1 === ( $profile['identity_allocation_calls'] ?? 0 )
	&& 1 === ( $profile['query_insert_calls'] ?? 0 )
	&& 1 === ( $profile['post_write_calls'] ?? 0 )
	&& 1 === ( $profile['manifest_scans'] ?? 0 );
$first_scans = $storage->manifest_scans;
$first_catalogue = file_exists( $root . '/state/_indexes/posts.json' );
file_put_contents( $root . '/content/post/external.md', "---\nid: 2\ntitle: External\nstatus: publish\ntype: post\nauthor: 1\ndate: 2026-09-06 00:00:00\nmodified: 2026-09-06 00:00:00\nslug: external\ncomment_status: open\nping_status: open\n---\n\n" );
$second = mdi_allocation_scan_insert( $runtime, 'second' );
$second_catalogue = file_exists( $root . '/state/_indexes/posts.json' );
file_put_contents( $root . '/content/post/malformed.md', "---\ntitle: Malformed\n---\n" );
$failed = mdi_allocation_scan_result( $runtime, 'failed' );
file_put_contents( $root . '/content/post/malformed.md', "---\nid: 4\ntitle: Repaired\nstatus: publish\ntype: post\nauthor: 1\ndate: 2026-09-06 00:00:00\nmodified: 2026-09-06 00:00:00\nslug: repaired\ncomment_status: open\nping_status: open\n---\n\n" );
$repaired = mdi_allocation_scan_insert( $runtime, 'repaired-insert' );
$passed = 1 === $first && 1 === $first_scans && ! $first_catalogue && 3 === $second && ! $second_catalogue && ! $failed->succeeded() && 5 === $repaired && 4 === $storage->manifest_scans;
$passed = $passed && ! $storage->published_before_write;
$passed = $passed && $profile_valid && $profile === WP_Markdown_Operation_Profile::stop();
$reused = 1 === ( $storage->file_reads[ $root . '/content/post/external.md' ] ?? 0 );
$passed = $passed && $reused;
echo ( $reused ? 'PASS: ' : 'FAIL: ' ) . "allocation reuses the witnessed parse of an unchanged external post\n";
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts', 'wp_' ) );
$passed = $passed && $read->succeeded() && is_file( $storage->catalogue_path );
WP_Markdown_Operation_Profile::start();
foreach ( array( 1, 1, 3 ) as $id ) {
	$runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts WHERE ID = ' . $id, 'wp_' ) );
}
WP_Markdown_Operation_Profile::stop();
$shapes = WP_Markdown_Operation_Profile::query_shapes();
$shape = $shapes['SELECT ID FROM wp_posts WHERE ID = ?'] ?? array();
$passed = $passed && 1 === count( $shapes ) && 3 === ( $shape['calls'] ?? 0 )
	&& 1 === ( $shape['exact_repeats'] ?? 0 ) && 2 === ( $shape['distinct_tracked'] ?? 0 );
$provider = $registry->table( 'wp_posts' )['provider'];
$maxima = $provider->identity_maxima( array( 'ID' ) );
$passed = $passed && array( 'ID' => 5 ) === $maxima;
copy( $root . '/content/post/external.md', $root . '/content/post/duplicate.md' );
$duplicate = $provider->identity_maxima( array( 'ID' ) );
$passed = $passed && $duplicate instanceof WP_Markdown_Query_Result
	&& 'duplicate_post_id' === ( $duplicate->diagnostic()['reason'] ?? null );
echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . "generated native INSERTs scan fresh without publishing and recover after malformed posts\n";
foreach ( glob( $root . '/content/post/*.md' ) ?: array() as $file ) { @unlink( $file ); }
@unlink( $root . '/content/.mdi-native-posts.lock' );
@rmdir( $root . '/content/post' );
@rmdir( $root . '/content' );
@unlink( $storage->catalogue_path );
@rmdir( $root . '/state/_indexes' );
@rmdir( $root . '/state' );
@rmdir( $root );
exit( $passed ? 0 : 1 );
