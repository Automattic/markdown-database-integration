<?php
/**
 * Smoke tests for parent promotion in WP_Markdown_Storage.
 *
 * When a parent gains its first child, resolve_parent_dir() renames the
 * parent's leaf file to `<slug>/index.md`. The in-memory index must follow
 * the rename so same-request lookups resolve the parent at its new path.
 *
 * Usage: php tests/smoke-parent-promotion.php
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
// Test 1 — single-level promotion
// ---------------------------------------------------------------------------
echo "Test 1: single-level promotion moves the parent and its index entry\n";

rm_rf( $tmp_root );
mkdir( $tmp_root . '/wiki', 0755, true );

// Seed: parent exists as a leaf file. No child on disk yet.
write_leaf( $tmp_root . '/wiki/root-parent.md', 10, 'root-parent', 0 );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$storage->set_post_resolver( function ( int $id ) use ( $posts ) {
	return $posts[ $id ] ?? null;
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
assert_eq( ( $storage->indexed_post_file( 10 )['absolute'] ?? null ), $tmp_root . '/wiki/root-parent/index.md', 'in-memory index follows the promoted parent' );

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

assert_eq( ( $storage->indexed_post_file( 10 )['absolute'] ?? null ), $tmp_root . '/wiki/root-parent/index.md', 'root-parent (10) promoted first' );

// Write leaf-child. Promotes mid-parent (now under root-parent/) from leaf to index.md.
// root-parent was already promoted, so it stays put.
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

assert_eq( ( $storage->indexed_post_file( 11 )['absolute'] ?? null ), $tmp_root . '/wiki/root-parent/mid-parent/index.md', 'mid-parent (11) promoted on second write' );

// ---------------------------------------------------------------------------
// Test 3 — no promotion
// ---------------------------------------------------------------------------
echo "\nTest 3: writing a child whose parent directory already exists keeps the parent path\n";

rm_rf( $tmp_root );
mkdir( $tmp_root . '/wiki/root-parent', 0755, true );

// Seed: parent already promoted. index.md exists.
write_leaf( $tmp_root . '/wiki/root-parent/index.md', 10, 'root-parent', 0 );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$storage->set_post_resolver( function ( int $id ) use ( $posts ) {
	return $posts[ $id ] ?? null;
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

assert_eq( ( $storage->indexed_post_file( 10 )['absolute'] ?? null ), $tmp_root . '/wiki/root-parent/index.md', 'already-promoted parent keeps its index.md path' );

// ---------------------------------------------------------------------------
// Test 4 — rename onto an existing dir-without-index.md path (second branch)
// ---------------------------------------------------------------------------
echo "\nTest 4: orphaned leaf moved into existing directory\n";

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


// Write another child of root-parent. The second branch of resolve_parent_dir
// (directory exists, index.md doesn't) should move the leaf into index.md
// and update the index.
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
assert_eq( ( $storage->indexed_post_file( 10 )['absolute'] ?? null ), $tmp_root . '/wiki/root-parent/index.md', 'in-memory index follows the moved index.md' );

// ---------------------------------------------------------------------------
// Test 5 — deleting a parent then writing its core-reparented child
// ---------------------------------------------------------------------------
echo "\nTest 5: parent deletion allows its child to move to the type root\n";

rm_rf( $tmp_root );
write_leaf( $tmp_root . '/wiki/root-parent/index.md', 10, 'root-parent', 0 );
write_leaf( $tmp_root . '/wiki/root-parent/mid-parent.md', 11, 'mid-parent', 10 );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$storage->set_post_resolver( function ( int $id ) use ( &$posts ) {
	return $posts[ $id ] ?? null;
} );

assert_true( $storage->delete_post( 10 ), 'parent index.md deleted' );
unset( $posts[10] );
$posts[11]->post_parent = 0;
$reparented = (object) array(
	'ID'           => 11,
	'post_type'    => 'wiki',
	'post_name'    => 'mid-parent',
	'post_parent'  => 0,
	'post_status'  => 'publish',
	'post_title'   => 'Mid',
	'post_content' => 'body',
);
$written_path = $storage->write_post( $reparented );

assert_eq( $written_path, $tmp_root . '/wiki/mid-parent.md', 'reparented child written at the type root' );
assert_true( file_exists( $tmp_root . '/wiki/mid-parent.md' ), 'new child path exists' );
assert_true( ! file_exists( $tmp_root . '/wiki/root-parent/mid-parent.md' ), 'old child path removed' );
assert_true( ! is_dir( $tmp_root . '/wiki/root-parent' ), 'empty parent directory removed' );

// ---------------------------------------------------------------------------
// Test 6 — a warmed index must not delete a reused cached path
// ---------------------------------------------------------------------------
echo "\nTest 6: stale cached paths preserve a different post that reused them\n";

rm_rf( $tmp_root );
write_leaf( $tmp_root . '/wiki/original.md', 10, 'original' );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
assert_true( null !== $storage->read_post( 10 ), 'initial read completes the index' );

rename( $tmp_root . '/wiki/original.md', $tmp_root . '/wiki/moved.md' );
write_leaf( $tmp_root . '/wiki/original.md', 20, 'replacement' );

$updated = (object) array(
	'ID'           => 10,
	'post_type'    => 'wiki',
	'post_name'    => 'updated',
	'post_parent'  => 0,
	'post_status'  => 'publish',
	'post_title'   => 'Updated',
	'post_content' => 'updated body',
);
$written_path = $storage->write_post( $updated );

assert_eq( $written_path, $tmp_root . '/wiki/updated.md', 'updated post is written to its new path' );
assert_true( file_exists( $tmp_root . '/wiki/original.md' ), 'reused cached path is preserved' );
assert_eq( $storage->read_file( $tmp_root . '/wiki/original.md', true )->ID ?? null, 20, 'reused path retains its new owner' );

// ---------------------------------------------------------------------------
// Test 7 — an externally removed cached path does not become a read warning
// ---------------------------------------------------------------------------
echo "\nTest 7: stale missing cached paths are ignored without parsing them\n";

rm_rf( $tmp_root );
write_leaf( $tmp_root . '/wiki/original.md', 10, 'original' );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
assert_true( null !== $storage->read_post( 10 ), 'initial read completes the index for a removable path' );
unlink( $tmp_root . '/wiki/original.md' );

set_error_handler( static function ( int $severity, string $message ): never {
	throw new ErrorException( $message, 0, $severity );
} );
try {
	$written_path = $storage->write_post( (object) array(
		'ID'           => 10,
		'post_type'    => 'wiki',
		'post_name'    => 'replacement',
		'post_parent'  => 0,
		'post_status'  => 'publish',
		'post_title'   => 'Replacement',
		'post_content' => 'replacement body',
	) );
	assert_eq( $written_path, $tmp_root . '/wiki/replacement.md', 'write ignores the stale missing path without a warning' );
} catch ( ErrorException $exception ) {
	assert_true( false, 'stale missing cached path emitted a warning: ' . $exception->getMessage() );
} finally {
	restore_error_handler();
}

// ---------------------------------------------------------------------------
// Test 8 — a fresh write still wins over a stale duplicate during first scan
// ---------------------------------------------------------------------------
echo "\nTest 8: fresh writes remain canonical during initial duplicate cleanup\n";

rm_rf( $tmp_root );
write_leaf( $tmp_root . '/wiki/stale/fresh.md', 30, 'fresh' );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$fresh = (object) array(
	'ID'           => 30,
	'post_type'    => 'wiki',
	'post_name'    => 'fresh',
	'post_parent'  => 0,
	'post_status'  => 'publish',
	'post_title'   => 'Fresh',
	'post_content' => 'fresh body',
);
$written_path = $storage->write_post( $fresh );

assert_eq( $written_path, $tmp_root . '/wiki/fresh.md', 'fresh write returns its canonical path' );
assert_true( file_exists( $tmp_root . '/wiki/fresh.md' ), 'fresh write is preserved after duplicate cleanup' );
assert_true( ! file_exists( $tmp_root . '/wiki/stale/fresh.md' ), 'stale duplicate is removed' );

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
