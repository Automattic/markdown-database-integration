<?php
/** A canonical write surrenders what it restated, and nothing else. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_native_catalogue_remove_tree( string $root ): void {
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

function mdi_native_catalogue_write( string $root, string $relative, int $id, string $title ): void {
	$path = $root . '/' . $relative;
	$dir  = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents(
		$path,
		"---\ntype: document\ntitle: \"{$title}\"\nwordpress:\n  id: {$id}\n  status: publish\n  type: post\n  author: 1\n  date: \"2026-01-01 00:00:00\"\n  date_gmt: \"2026-01-01 00:00:00\"\n---\n\nBody.\n"
	);
}

/** @return array<int,array<string,mixed>> */
function mdi_native_catalogue_rows( WP_Markdown_Query_Result $result ): array {
	$rows = $result->wpdb_state()['last_result'] ?? array();
	return is_array( $rows ) ? array_map( static fn( $row ): array => (array) $row, $rows ) : array();
}

/** The parent a post derives from where it sits, or null when it is not found. */
function mdi_native_catalogue_parent_of( WP_Markdown_Native_Query_Runtime $runtime, int $id ): ?int {
	$result = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_parent FROM wp_posts WHERE ID = ' . $id, 'wp_' ) );
	$rows   = mdi_native_catalogue_rows( $result );
	return array() === $rows ? null : (int) $rows[0]['post_parent'];
}

function mdi_native_catalogue_title_of( WP_Markdown_Native_Query_Runtime $runtime, int $id ): ?string {
	$result = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title FROM wp_posts WHERE ID = ' . $id, 'wp_' ) );
	$rows   = mdi_native_catalogue_rows( $result );
	return array() === $rows ? null : (string) $rows[0]['post_title'];
}

$root = sys_get_temp_dir() . '/mdi-native-catalogue-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
mkdir( $root . '/_options', 0755 );

// A parent that owns a directory, two posts that derive their parent from it,
// and one unrelated post that derives nothing from anybody.
mdi_native_catalogue_write( $root, 'post/parent/index.md', 10, 'Parent' );
mdi_native_catalogue_write( $root, 'post/parent/first-child.md', 11, 'First Child' );
mdi_native_catalogue_write( $root, 'post/parent/second-child.md', 12, 'Second Child' );
mdi_native_catalogue_write( $root, 'post/unrelated.md', 13, 'Unrelated' );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

// Teach the catalogue the whole corpus, so every later read has something
// remembered that it could wrongly believe.
$runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts', 'wp_' ) );

$child_before   = mdi_native_catalogue_parent_of( $runtime, 11 );
$sibling_before = mdi_native_catalogue_parent_of( $runtime, 12 );

// A write to a post that is nobody's index restates nothing about the others.
$runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_posts SET post_title = 'Unrelated Renamed' WHERE ID = 13", 'wp_' ) );
$unrelated_after     = mdi_native_catalogue_title_of( $runtime, 13 );
$child_after_neighbour = mdi_native_catalogue_parent_of( $runtime, 11 );
$parent_after_neighbour = mdi_native_catalogue_title_of( $runtime, 10 );

// Removing the index.md a directory is named by restates the parent of
// everything under it. The children's own bytes never change, so no witness
// can catch this and the catalogue must have surrendered them with it.
$runtime->execute( new WP_Markdown_Query_Request( 'DELETE FROM wp_posts WHERE ID = 10', 'wp_' ) );
$index_removed = ! file_exists( $root . '/post/parent/index.md' );
$children_kept = file_exists( $root . '/post/parent/first-child.md' ) && file_exists( $root . '/post/parent/second-child.md' );

$child_after   = mdi_native_catalogue_parent_of( $runtime, 11 );
$sibling_after = mdi_native_catalogue_parent_of( $runtime, 12 );
$parent_after  = mdi_native_catalogue_parent_of( $runtime, 10 );
$unrelated_end = mdi_native_catalogue_title_of( $runtime, 13 );

$checks = array(
	'a post derives its parent from the index beside it'
		=> 10 === $child_before && 10 === $sibling_before,
	'a write to an unrelated post leaves it as it was written'
		=> 'Unrelated Renamed' === $unrelated_after,
	'a write to an unrelated post restates no other parent'
		=> 10 === $child_after_neighbour,
	'a write to an unrelated post leaves untouched posts readable'
		=> 'Parent' === $parent_after_neighbour,
	'removing a parent keeps the children it was named for'
		=> $index_removed && $children_kept,
	'removing an index restates the parent of every post under it'
		=> 0 === $child_after && 0 === $sibling_after,
	'a removed parent stops being found'
		=> null === $parent_after,
	'a post outside the restated directory is untouched by the removal'
		=> 'Unrelated Renamed' === $unrelated_end,
);

mdi_native_catalogue_remove_tree( $root );

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
