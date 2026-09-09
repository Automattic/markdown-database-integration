<?php
/** Split content/state roots must journal post rename, delete, rollback, and recovery. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_split_root_remove_tree( string $root ): void {
	if ( ! is_dir( $root ) ) {
		return;
	}
	$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}

function mdi_split_root_request( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): WP_Markdown_Query_Result {
	return $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
}

$root = sys_get_temp_dir() . '/mdi-native-split-root-' . bin2hex( random_bytes( 6 ) );
$state = $root . '/state';
$content = $root . '/content';
mkdir( $state . '/_options', 0755, true );
// Cache the state-only owner first, then configure a fresh split content root.
WP_Markdown_Native_Runtime_Factory::runtime( $state );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $state, 'wp_', null, false, $content );
$insert = "INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, 1, '2026-09-09 00:00:00', '2026-09-09 00:00:00', '', 'base', '', 'publish', 'open', 'open', '', 'base', '', '', '2026-09-09 00:00:00', '2026-09-09 00:00:00', '', 0, '', 0, 'post', '', 0)";
mdi_split_root_request( $runtime, $insert );
$original = $content . '/post/base.md';

mdi_split_root_request( $runtime, 'BEGIN' );
$deleted = mdi_split_root_request( $runtime, 'DELETE FROM wp_posts WHERE ID = 1' );
$removed_before_rollback = ! is_file( $original );
$rollback_delete = mdi_split_root_request( $runtime, 'ROLLBACK' );
mdi_split_root_request( $runtime, 'BEGIN' );
$renamed = mdi_split_root_request( $runtime, "UPDATE wp_posts SET post_name = 'renamed' WHERE ID = 1" );
$rollback_rename = mdi_split_root_request( $runtime, 'ROLLBACK' );

// A dead journal uses only trusted configured content roots, never journal data
// to decide where a restore may publish.
$journal = $state . '/_journal/native-transaction-deadbeefcafefeed.json';
file_put_contents( $journal, json_encode( array( array( 'path' => $original, 'existed' => true, 'contents' => base64_encode( (string) file_get_contents( $original ) ) ) ) ) );
unlink( $original );
$recovery = new WP_Markdown_Native_Transaction_Journal( $state, array( $state, $content ) );
$recovered = $recovery->recover();

// A journal entry may not use an in-root alias to escape the trusted root.
$outside = $root . '/outside';
mkdir( $outside, 0755 );
$alias = $content . '/post/alias';
$linked = @symlink( $outside, $alias );
if ( $linked ) {
	file_put_contents(
		$state . '/_journal/native-transaction-unsafealias000.json',
		json_encode( array( array( 'path' => $alias . '/escaped.json', 'existed' => true, 'contents' => base64_encode( '{"escaped":true}' ) ) ) )
	);
	$unsafe_recovery = ( new WP_Markdown_Native_Transaction_Journal( $state, array( $state, $content ) ) )->recover();
	@unlink( $state . '/_journal/native-transaction-unsafealias000.json' );
} else {
	$unsafe_recovery = null;
}

$checks = array(
	'split-root post rename is admitted' => 1 === $renamed->return_value(),
	'split-root rename rollback restores original path' => 0 === $rollback_rename->return_value() && is_file( $original ) && ! is_file( $content . '/post/renamed.md' ),
	'split-root post delete is admitted' => $removed_before_rollback,
	'split-root delete rollback restores original path' => 0 === $rollback_delete->return_value() && is_file( $original ),
	'split-root abandoned journal recovery restores content' => true === $recovered && is_file( $original ),
	'split-root recovery rejects a symlinked content alias' => ! $linked || ( false === $unsafe_recovery && ! is_file( $outside . '/escaped.json' ) ),
);
mdi_split_root_remove_tree( $root );
$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
