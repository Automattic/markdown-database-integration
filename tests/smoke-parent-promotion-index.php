<?php
/**
 * Smoke tests for the parent-promotion index-writer callback.
 *
 * Exercises the hook that WP_Markdown_Storage invokes when
 * resolve_parent_dir() renames a parent's leaf file to `index.md`
 * because the parent gained a child. Without this hook the
 * `_markdown_file_index` SQLite row still points at the old path
 * and warm boot churns the promoted post through a full delete +
 * reinsert on every sync. See issue #68.
 *
 * Uses a real filesystem temp dir + a capture-to-array callback
 * stand-in for the production driver's update_file_index call. The
 * storage's public set_index_writer() accepts any callable, so we
 * don't need a real PDO here.
 *
 * Usage: php tests/smoke-parent-promotion-index.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Load the real storage class — we're testing its public surface.
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';

// ---------------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function assert_eq( $actual, $expected, string $label ): void {
	global $passed, $failed;
	if ( $actual === $expected ) {
		echo "  ✓ {$label}\n";
		$passed++;
	} else {
		echo "  ✗ {$label}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
		$failed++;
	}
}

function assert_true( $actual, string $label ): void {
	assert_eq( (bool) $actual, true, $label );
}

function rm_rf( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = scandir( $dir );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			rm_rf( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

// Writes a minimal .md leaf file with YAML frontmatter the storage engine
// can parse (at least an `id:` so extract_id_from_file returns it).
function write_leaf( string $path, int $id, string $slug, int $parent = 0 ): void {
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0755, true );
	}
	$fm = "---\n";
	$fm .= "id: {$id}\n";
	$fm .= "title: \"Post {$id}\"\n";
	$fm .= "status: publish\n";
	$fm .= "type: wiki\n";
	$fm .= "slug: {$slug}\n";
	$fm .= "parent: {$parent}\n";
	$fm .= "---\n\nbody\n";
	file_put_contents( $path, $fm );
}

// ---------------------------------------------------------------------------
// Shared fixture setup
// ---------------------------------------------------------------------------

$tmp_root = sys_get_temp_dir() . '/mdi-parent-promotion-' . bin2hex( random_bytes( 4 ) );

// Post_resolver stand-in. Real resolver queries wp_posts; ours walks a
// hand-built map. Returns an object shaped like get_row( "SELECT post_name,
// post_parent, post_type FROM wp_posts WHERE ID = ?" ).
$posts = array(
	10 => (object) array( 'post_name' => 'root-parent', 'post_parent' => 0, 'post_type' => 'wiki' ),
	11 => (object) array( 'post_name' => 'mid-parent',  'post_parent' => 10, 'post_type' => 'wiki' ),
	12 => (object) array( 'post_name' => 'leaf-child',  'post_parent' => 11, 'post_type' => 'wiki' ),
);

// ---------------------------------------------------------------------------
// Test 1 — rename during promotion fires the index-writer callback
// ---------------------------------------------------------------------------
echo "Test 1: single-level promotion invokes index_writer with the new path\n";

rm_rf( $tmp_root );
mkdir( $tmp_root . '/wiki', 0755, true );

// Seed: parent exists as a leaf file. No child on disk yet.
write_leaf( $tmp_root . '/wiki/root-parent.md', 10, 'root-parent', 0 );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$storage->set_post_resolver( function ( int $id ) use ( $posts ) {
	return $posts[ $id ] ?? null;
} );

$captured = array();
$storage->set_index_writer( function ( int $post_id, string $relative_path, int $mtime, int $size ) use ( &$captured ) {
	$captured[] = array(
		'post_id'       => $post_id,
		'relative_path' => $relative_path,
		'mtime'         => $mtime,
		'size'          => $size,
	);
} );

// Write a child of the parent. This triggers resolve_parent_dir to
// promote /wiki/root-parent.md → /wiki/root-parent/index.md.
$child = (object) array(
	'ID'           => 11,
	'post_type'    => 'wiki',
	'post_name'    => 'mid-parent',
	'post_parent'  => 10,
	'post_status'  => 'publish',
	'post_title'   => 'Mid parent',
	'post_content' => 'body',
);
$written_path = $storage->write_post( $child );

assert_true( false !== $written_path, 'write_post returned a path' );
assert_true( file_exists( $tmp_root . '/wiki/root-parent/index.md' ), 'parent promoted to index.md on disk' );
assert_true( ! file_exists( $tmp_root . '/wiki/root-parent.md' ), 'old leaf path removed by rename' );
assert_eq( count( $captured ), 1, 'index_writer invoked exactly once' );
assert_eq( $captured[0]['post_id'], 10, 'callback received the promoted parent ID (10)' );
assert_eq( $captured[0]['relative_path'], 'wiki/root-parent/index.md', 'callback received relative path to new location' );
assert_true( $captured[0]['mtime'] > 0, 'callback received a real mtime' );
assert_true( $captured[0]['size'] > 0, 'callback received a real size' );

// ---------------------------------------------------------------------------
// Test 2 — two sequential child writes each promote a different ancestor
// ---------------------------------------------------------------------------
echo "\nTest 2: each child write promotes its immediate ancestor exactly once\n";

rm_rf( $tmp_root );
mkdir( $tmp_root . '/wiki', 0755, true );

// Start with just the root as a leaf. The mid and leaf posts will be written
// through write_post — matching the order real calendar-parent creation takes.
write_leaf( $tmp_root . '/wiki/root-parent.md', 10, 'root-parent', 0 );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$storage->set_post_resolver( function ( int $id ) use ( $posts ) {
	return $posts[ $id ] ?? null;
} );

$captured = array();
$storage->set_index_writer( function ( int $post_id, string $relative_path ) use ( &$captured ) {
	$captured[] = array( 'post_id' => $post_id, 'relative_path' => $relative_path );
} );

// Write mid-parent. Promotes root-parent from leaf to index.md.
$mid = (object) array(
	'ID'           => 11,
	'post_type'    => 'wiki',
	'post_name'    => 'mid-parent',
	'post_parent'  => 10,
	'post_status'  => 'publish',
	'post_title'   => 'Mid',
	'post_content' => 'body',
);
$storage->write_post( $mid );

assert_eq( count( $captured ), 1, 'mid-parent write promoted 1 ancestor' );
assert_eq( $captured[0]['post_id'], 10, 'root-parent (10) promoted first' );
assert_eq( $captured[0]['relative_path'], 'wiki/root-parent/index.md', 'root-parent at new path' );

// Write leaf-child. Promotes mid-parent (now under root-parent/) from leaf to index.md.
// root-parent was already promoted so it does not fire again.
$captured = array();
$leaf = (object) array(
	'ID'           => 12,
	'post_type'    => 'wiki',
	'post_name'    => 'leaf-child',
	'post_parent'  => 11,
	'post_status'  => 'publish',
	'post_title'   => 'Leaf',
	'post_content' => 'body',
);
$storage->write_post( $leaf );

assert_eq( count( $captured ), 1, 'leaf-child write promoted 1 ancestor' );
assert_eq( $captured[0]['post_id'], 11, 'mid-parent (11) promoted on second write' );
assert_eq( $captured[0]['relative_path'], 'wiki/root-parent/mid-parent/index.md', 'mid-parent at new path' );

// ---------------------------------------------------------------------------
// Test 3 — no promotion, no callback
// ---------------------------------------------------------------------------
echo "\nTest 3: writing a child whose parent directory already exists fires no callback\n";

rm_rf( $tmp_root );
mkdir( $tmp_root . '/wiki/root-parent', 0755, true );

// Seed: parent already promoted. index.md exists.
write_leaf( $tmp_root . '/wiki/root-parent/index.md', 10, 'root-parent', 0 );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$storage->set_post_resolver( function ( int $id ) use ( $posts ) {
	return $posts[ $id ] ?? null;
} );

$captured = array();
$storage->set_index_writer( function ( int $post_id, string $relative_path ) use ( &$captured ) {
	$captured[] = array( 'post_id' => $post_id, 'relative_path' => $relative_path );
} );

$child = (object) array(
	'ID'           => 11,
	'post_type'    => 'wiki',
	'post_name'    => 'mid-parent',
	'post_parent'  => 10,
	'post_status'  => 'publish',
	'post_title'   => 'Mid',
	'post_content' => 'body',
);
$storage->write_post( $child );

assert_eq( count( $captured ), 0, 'no promotion → no callback' );

// ---------------------------------------------------------------------------
// Test 4 — index_writer not set: no fatal, in-memory index still correct
// ---------------------------------------------------------------------------
echo "\nTest 4: no index_writer registered — promotion still works, no fatal\n";

rm_rf( $tmp_root );
mkdir( $tmp_root . '/wiki', 0755, true );
write_leaf( $tmp_root . '/wiki/root-parent.md', 10, 'root-parent', 0 );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$storage->set_post_resolver( function ( int $id ) use ( $posts ) {
	return $posts[ $id ] ?? null;
} );
// Intentionally do NOT call set_index_writer.

$child = (object) array(
	'ID'           => 11,
	'post_type'    => 'wiki',
	'post_name'    => 'mid-parent',
	'post_parent'  => 10,
	'post_status'  => 'publish',
	'post_title'   => 'Mid',
	'post_content' => 'body',
);
$written_path = $storage->write_post( $child );

assert_true( false !== $written_path, 'write_post succeeded with no writer' );
assert_true( file_exists( $tmp_root . '/wiki/root-parent/index.md' ), 'parent still promoted on disk' );

// ---------------------------------------------------------------------------
// Test 5 — rename onto an existing dir-without-index.md path (second branch)
// ---------------------------------------------------------------------------
echo "\nTest 5: orphaned leaf moved into existing directory fires callback\n";

rm_rf( $tmp_root );

// Seed: a directory already exists for the slug (from past children), AND a
// sibling leaf file for the parent — inconsistent state that triggers the
// second branch of resolve_parent_dir.
mkdir( $tmp_root . '/wiki/root-parent/existing-child', 0755, true );
write_leaf( $tmp_root . '/wiki/root-parent.md', 10, 'root-parent', 0 );
write_leaf( $tmp_root . '/wiki/root-parent/existing-child/index.md', 99, 'existing-child', 10 );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$storage->set_post_resolver( function ( int $id ) use ( $posts ) {
	return $posts[ $id ] ?? null;
} );

$captured = array();
$storage->set_index_writer( function ( int $post_id, string $relative_path ) use ( &$captured ) {
	$captured[ $post_id ] = $relative_path;
} );

// Write another child of root-parent. The second branch of resolve_parent_dir
// (directory exists, index.md doesn't) should move the leaf into index.md
// and fire the callback.
$new_child = (object) array(
	'ID'           => 11,
	'post_type'    => 'wiki',
	'post_name'    => 'mid-parent',
	'post_parent'  => 10,
	'post_status'  => 'publish',
	'post_title'   => 'Mid',
	'post_content' => 'body',
);
$storage->write_post( $new_child );

assert_true( file_exists( $tmp_root . '/wiki/root-parent/index.md' ), 'leaf moved into dir as index.md' );
assert_true( ! file_exists( $tmp_root . '/wiki/root-parent.md' ), 'sibling leaf file removed' );
assert_eq( $captured[10] ?? null, 'wiki/root-parent/index.md', 'callback fired with new index.md path' );

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
rm_rf( $tmp_root );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n─────────────────────────────────────\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "─────────────────────────────────────\n";

exit( $failed > 0 ? 1 : 0 );
