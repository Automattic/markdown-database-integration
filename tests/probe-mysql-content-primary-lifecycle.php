<?php
/**
 * Disposable real WordPress/MySQL content-primary probe.
 *
 * Run with MARKDOWN_DB_BACKEND=mysql-content and
 * MARKDOWN_DB_MANAGED_POST_TYPES=post,page:
 * wp eval-file wp-content/plugins/markdown-database-integration/tests/probe-mysql-content-primary-lifecycle.php
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_Markdown_MySQL_Content_Runtime' ) ) {
	fwrite( STDERR, "This probe requires booted WordPress with mysql-content active.\n" );
	exit( 1 );
}
function mdi_mysql_content_probe( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( "FAIL: $message" ); }
	echo "PASS: $message\n";
}

$root = MARKDOWN_DB_CONTENT_DIR;
$created = array();
try {
	global $wpdb;
	mdi_mysql_content_probe( defined( 'MARKDOWN_DB_BACKEND' ) && 'mysql-content' === MARKDOWN_DB_BACKEND, 'mysql-content backend is active' );
	mdi_mysql_content_probe( $wpdb->dbh instanceof mysqli && str_contains( (string) $wpdb->get_var( 'SELECT VERSION()' ), 'MariaDB' ), 'normal WordPress mysqli runtime uses MariaDB' );
	mdi_mysql_content_probe( ! defined( 'MARKDOWN_DB_DROPIN' ) && ! class_exists( 'WP_SQLite_DB' ), 'no SQLite drop-in or SQLite runtime class is active' );
	$parent = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'MDI lifecycle parent', 'post_name' => 'mdi-lifecycle-parent' ), true );
	if ( is_wp_error( $parent ) ) { throw new RuntimeException( $parent->get_error_message() ); }
	$created[] = $parent;
	$child = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'MDI lifecycle child', 'post_name' => 'mdi-lifecycle-child', 'post_parent' => $parent ), true );
	if ( is_wp_error( $child ) ) { throw new RuntimeException( $child->get_error_message() ); }
	$created[] = $child;
	$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'MDI lifecycle post', 'post_name' => 'mdi-lifecycle-post', 'post_content' => 'Original canonical body.' ), true );
	if ( is_wp_error( $post ) ) { throw new RuntimeException( $post->get_error_message() ); }
	$created[] = $post;
	add_post_meta( $post, 'repeated', 'one' );
	add_post_meta( $post, 'repeated', 'two' );
	add_post_meta( $post, 'structured', array( 'nested' => array( 'value' => 7 ) ) );
	wp_set_object_terms( $post, array( 'news', 'release' ), 'category' );
	wp_set_object_terms( $post, array( 'mysql-content' ), 'post_tag' );
	$receipt = WP_Markdown_MySQL_Content_Runtime::flush_now();
	$parent_path = (string) get_post_meta( $parent, '_markdown_source_path', true );
	$parent_file = $root . '/' . $parent_path;
	$child_path = (string) get_post_meta( $child, '_markdown_source_path', true );
	$child_file = $root . '/' . $child_path;
	$post_path = (string) get_post_meta( $post, '_markdown_source_path', true );
	$post_file = $root . '/' . $post_path;
	mdi_mysql_content_probe( 3 === count( $receipt['changed'] ) && empty( $receipt['pending'] ), 'coalesced post/meta/term writes into one completed canonical projection per resource: ' . wp_json_encode( $receipt ) );
	mdi_mysql_content_probe( is_file( $parent_file ), 'explicit flush returns only after the canonical parent file exists' );
	$storage = new WP_Markdown_Storage( $root );
	$round_trip = $storage->read_post( $post );
	mdi_mysql_content_probe( array( 'one', 'two' ) === $round_trip->_frontmatter_meta['repeated'] && array( 'nested' => array( 'value' => 7 ) ) === $round_trip->_frontmatter_meta['structured'] && array( 'news', 'release' ) === $round_trip->_frontmatter_terms['category'] && array( 'mysql-content' ) === $round_trip->_frontmatter_terms['post_tag'], 'storage round-trip preserves repeated structured meta and multiple taxonomies' );

	$external = str_replace( 'Original canonical body.', 'Externally reconciled body.', (string) file_get_contents( $post_file ), $replacements );
	if ( 1 !== $replacements || false === file_put_contents( $post_file, $external, LOCK_EX ) ) { throw new RuntimeException( 'Unable to stage the external canonical edit.' ); }
	WP_Markdown_MySQL_Content_Runtime::bootstrap();
	$runtime = new ReflectionMethod( WP_Markdown_MySQL_Content_Runtime::class, 'recover' );
	$instance = new ReflectionProperty( WP_Markdown_MySQL_Content_Runtime::class, 'instance' );
	$active_runtime = $instance->getValue();
	$external_plan = WP_Markdown_CLI::reconcile( array( 'dry_run' => true, 'canonical_root' => $root, 'state_root' => MARKDOWN_DB_STATE_DIR, 'managed_scope' => array( 'post', 'page' ), 'direction' => 'canonical_to_wordpress', 'deletion_policy' => 'none', 'conflict_policy' => 'prefer_canonical', 'batch_size' => 1000, 'layout_profile' => MARKDOWN_DB_CONTENT_LAYOUT_PROFILE ) );
	$reconcile = new ReflectionMethod( WP_Markdown_MySQL_Content_Runtime::class, 'reconcile' );
	$external_result = $reconcile->invoke( $active_runtime, 'canonical_to_wordpress', 'none', 'prefer_canonical' );
	$feedback = WP_Markdown_MySQL_Content_Runtime::flush_now();
	mdi_mysql_content_probe( 'Externally reconciled body.' === get_post_field( 'post_content', $post ) && empty( $feedback['changed'] ) && empty( $feedback['pending'] ), 'external canonical edit reconciles into WordPress without a write-feedback projection: plan=' . wp_json_encode( $external_plan['counts'] ) . ' conflicts=' . wp_json_encode( $external_result['categories']['conflicts'] ) . ' content=' . wp_json_encode( get_post_field( 'post_content', $post ) ) . ' receipt=' . wp_json_encode( $feedback ) );

	$wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $post ), array( '%d' ) );
	$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post ), array( '%d' ) );
	$wpdb->delete( $wpdb->posts, array( 'ID' => $post ), array( '%d' ) );
	clean_post_cache( $post );
	mdi_mysql_content_probe( null === get_post( $post ) && is_file( $post_file ), 'cold reconstruction setup removes the MariaDB post while retaining canonical markdown' );
	$runtime->invoke( $active_runtime );
	$reconstructed = get_post( $post );
	mdi_mysql_content_probe( null !== $reconstructed && 'Externally reconciled body.' === $reconstructed->post_content && array( 'one', 'two' ) === get_post_meta( $post, 'repeated', false ) && array( 'news', 'release' ) === wp_get_object_terms( $post, 'category', array( 'fields' => 'slugs' ) ), 'cold hydration reconstructs post content, repeated meta, and taxonomy state from canonical markdown' );
	mdi_mysql_content_probe( $post_path === get_post_meta( $post, '_markdown_source_path', true ), 'cold hydration restores stable canonical source identity metadata' );

	wp_update_post( array( 'ID' => $post, 'post_title' => 'MDI lifecycle retry title' ) );
	$blocked_root = $root . '/blocked-root';
	file_put_contents( $blocked_root, 'not a directory' );
	$block_roots = static fn( array $roots ): array => array( 'content_dir' => $blocked_root, 'state_dir' => $roots['state_dir'] );
	add_filter( 'markdown_db_mysql_content_roots', $block_roots );
	set_error_handler( static fn(): bool => true );
	$failed = WP_Markdown_MySQL_Content_Runtime::flush_now();
	restore_error_handler();
	remove_filter( 'markdown_db_mysql_content_roots', $block_roots );
	unlink( $blocked_root );
	$retried = WP_Markdown_MySQL_Content_Runtime::flush_now();
	$retried_post = $storage->read_post( $post );
	mdi_mysql_content_probe( ! empty( $failed['pending'] ) && in_array( $post_path, $retried['changed'], true ) && empty( $retried['pending'] ) && 'MDI lifecycle retry title' === $retried_post->post_title, 'failed canonical projection retains dirty state and succeeds on explicit retry: failed=' . wp_json_encode( $failed ) . ' retried=' . wp_json_encode( $retried ) );

	wp_delete_post( $parent, true );
	$deleted = WP_Markdown_MySQL_Content_Runtime::flush_now();
	$moved_child_path = 'page/mdi-lifecycle-child.md';
	$child_baseline = get_post_meta( $child, '_markdown_reconciliation_baseline', true );
	mdi_mysql_content_probe( ! is_file( $parent_file ) && ! is_file( $child_file ) && is_file( $root . '/' . $moved_child_path ) && in_array( $moved_child_path, $deleted['changed'], true ) && in_array( $parent_path, $deleted['deleted'], true ) && empty( $deleted['pending'] ), 'permanent parent delete moves its reparented child before canonical deletion completes: ' . wp_json_encode( $deleted ) );
	mdi_mysql_content_probe( $moved_child_path === get_post_meta( $child, '_markdown_source_path', true ) && $moved_child_path === ( $child_baseline['canonical_path'] ?? null ), 'descendant move updates source-path and reconciliation baseline metadata' );
	mdi_mysql_content_probe( null !== get_post( $child ), 'core reparented child remains available for canonical reconstruction' );
} finally {
	$teardown_receipts = array();
	foreach ( array_reverse( $created ) as $id ) {
		if ( get_post( $id ) ) {
			wp_delete_post( $id, true );
			$teardown_receipt = WP_Markdown_MySQL_Content_Runtime::flush_now();
			$teardown_receipts[] = $teardown_receipt;
			mdi_mysql_content_probe( empty( $teardown_receipt['pending'] ), 'teardown deletion completes without pending reconciliation: ' . wp_json_encode( $teardown_receipt ) );
		}
	}
	$teardown = WP_Markdown_MySQL_Content_Runtime::flush_now();
	$rows_left = array_filter( $created, static fn( int $id ): bool => null !== get_post( $id ) );
	$files_left = array_filter( $created, static fn( int $id ): bool => null !== ( new WP_Markdown_Storage( MARKDOWN_DB_CONTENT_DIR ) )->read_post( $id ) );
	mdi_mysql_content_probe( empty( $rows_left ) && empty( $files_left ) && empty( $teardown['pending'] ), 'probe teardown removes its MariaDB rows and canonical files through lifecycle projection: rows=' . wp_json_encode( $rows_left ) . ' files=' . wp_json_encode( $files_left ) . ' receipts=' . wp_json_encode( $teardown_receipts ) . ' final=' . wp_json_encode( $teardown ) );
}
