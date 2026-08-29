<?php
/** A post looked up by identity resolves to its file and still sees the disk. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_native_identity_remove_tree( string $root ): void {
	if ( ! is_dir( $root ) ) {
		return;
	}
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}

function mdi_native_identity_write( string $root, int $id, string $slug, string $title, string $body = 'Body.' ): void {
	$dir = $root . '/post';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents(
		$dir . '/' . $slug . '.md',
		"---\ntype: document\ntitle: \"{$title}\"\nwordpress:\n  id: {$id}\n  status: publish\n  type: post\n  author: 1\n  date: \"2026-01-01 00:00:00\"\n  date_gmt: \"2026-01-01 00:00:00\"\n---\n\n{$body}\n"
	);
}

/** @return array<int,array<string,mixed>> */
function mdi_native_identity_rows( WP_Markdown_Query_Result $result ): array {
	$rows = $result->wpdb_state()['last_result'] ?? array();
	return is_array( $rows ) ? array_map( static fn( $row ): array => (array) $row, $rows ) : array();
}

$root = sys_get_temp_dir() . '/mdi-native-identity-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
mkdir( $root . '/_options', 0755 );
mdi_native_identity_write( $root, 41, 'first-post', 'First Post', 'BODY-MUST-NOT-BE-CATALOGUED' );
mdi_native_identity_write( $root, 42, 'second-post', 'Second Post' );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$title = static fn( WP_Markdown_Query_Result $r ): ?string => mdi_native_identity_rows( $r )[0]['post_title'] ?? null;

// An identity never scanned is still found, because the read falls back.
$cold = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title FROM wp_posts WHERE ID = 41', 'wp_' ) );

// A scan teaches identity where each post lives; the lookup then resolves.
$runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts', 'wp_' ) );
$warm = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title FROM wp_posts WHERE ID = 42', 'wp_' ) );
$catalogue_path = $root . '/_indexes/posts.json';
$catalogue = (string) file_get_contents( $catalogue_path );

// A new runtime can use the persisted catalogue without first teaching it identity.
$persisted_runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$persisted = $persisted_runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title FROM wp_posts WHERE ID = 42', 'wp_' ) );

// Same-size, same-mtime atomic replacement changes inode/ctime and must not validate.
$original_path = $root . '/post/second-post.md';
$original_stat = stat( $original_path );
mdi_native_identity_write( $root, 42, 'replacement', 'Renamd Post' );
$replacement_path = $root . '/post/replacement.md';
touch( $replacement_path, (int) $original_stat['mtime'] );
rename( $replacement_path, $original_path );
$replacement_stat = stat( $original_path );
$replacement_runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$replaced = $replacement_runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title FROM wp_posts WHERE ID = 42', 'wp_' ) );

// A corrupt artifact is never trusted; the canonical corpus remains readable.
file_put_contents( $catalogue_path, '{' );
$corrupt_runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$after_corruption = $corrupt_runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title FROM wp_posts WHERE ID = 41', 'wp_' ) );

// A file rewritten outside this process is still read as it now stands.
sleep( 1 );
mdi_native_identity_write( $root, 42, 'second-post', 'Renamed Outside' );
$edited = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title FROM wp_posts WHERE ID = 42', 'wp_' ) );

// A file removed outside this process stops being found.
unlink( $root . '/post/second-post.md' );
$removed = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title FROM wp_posts WHERE ID = 42', 'wp_' ) );

// A post added outside this process is found by the identity that never existed.
mdi_native_identity_write( $root, 43, 'third-post', 'Third Post' );
$added = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title FROM wp_posts WHERE ID = 43', 'wp_' ) );

$checks = array(
	'an identity never scanned is still found' => 'First Post' === $title( $cold ),
	'a scanned identity resolves to its post' => 'Second Post' === $title( $warm ),
	'the persisted catalogue resolves identity in a new runtime' => 'Second Post' === $title( $persisted ),
	'the persisted metadata projection excludes post bodies' => ! str_contains( $catalogue, 'BODY-MUST-NOT-BE-CATALOGUED' ) && ! str_contains( $catalogue, '"post_content":' ),
	'same-size same-mtime atomic replacement preserves the intended witness challenge' => $original_stat['size'] === $replacement_stat['size'] && $original_stat['mtime'] === $replacement_stat['mtime'] && $original_stat['ino'] !== $replacement_stat['ino'],
	'same-size same-mtime atomic replacement fails catalogue verification' => 'Renamd Post' === $title( $replaced ),
	'a corrupt persisted catalogue falls back to the canonical corpus' => 'First Post' === $title( $after_corruption ),
	'a post rewritten on disk is read as it now stands' => 'Renamed Outside' === $title( $edited ),
	'a post removed on disk stops being found' => array() === mdi_native_identity_rows( $removed ),
	'a post added on disk is found' => 'Third Post' === $title( $added ),
);

mdi_native_identity_remove_tree( $root );

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
