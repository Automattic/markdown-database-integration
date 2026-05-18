<?php
/**
 * Smoke test for the array-key collision bug between auto-incremented
 * zero-ID posts and explicit-ID posts in WP_Markdown_Storage::get_all_posts().
 *
 * Reproduces issue #103. The bug: zero-ID posts (no `id:` frontmatter)
 * were appended to $posts_by_id with PHP's auto-increment keys. If the
 * auto-incremented integer collided with an explicit `id:` value from
 * another file, the duplicate-resolution branch fired but $paths_by_id
 * had no matching key, producing "Undefined array key" warnings and
 * fatally a TypeError when relative_path() received null.
 *
 * The fix: keep zero-ID posts in a separate $unkeyed_posts list so the
 * invariant "every key in $posts_by_id has a matching $paths_by_id
 * entry" is preserved.
 *
 * Usage: php tests/smoke-zero-id-key-collision.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}

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
		++$passed;
	} else {
		echo "  ✗ {$label}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
		++$failed;
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

/**
 * Write a leaf .md file with the given id and slug. Pass id=0 to omit
 * the `id:` frontmatter entirely (the bug's trigger).
 */
function write_post( string $path, int $id, string $slug ): void {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	$fm  = "---\n";
	if ( $id > 0 ) {
		$fm .= "id: {$id}\n";
	}
	$fm .= "title: \"Post {$slug}\"\n";
	$fm .= "status: publish\n";
	$fm .= "type: post\n";
	$fm .= "slug: {$slug}\n";
	$fm .= "---\n\nbody\n";
	file_put_contents( $path, $fm );
}

// ---------------------------------------------------------------------------
// Fixture: enough zero-ID files that PHP's auto-increment would land on a
// value that ALSO appears as an explicit `id:` in another file.
//
// Iteration is alphabetical (glob/scandir order). Layout:
//   content/post/a-zero.md       (no id — auto would land at 0)
//   content/post/b-explicit-1.md (id: 1)
//   content/post/c-explicit-2.md (id: 2)
//   content/post/d-zero.md       (no id — auto would land at 3)
//   content/post/e-explicit-3.md (id: 3)  ← collision: auto picked 3
//
// Pre-fix: hitting e-explicit-3.md, isset($posts_by_id[3]) returns true
// because d-zero.md landed there. Duplicate-resolution runs.
// $paths_by_id[3] is undefined → null → TypeError.
//
// Post-fix: zero-ID posts go to $unkeyed_posts. e-explicit-3.md inserts
// cleanly at $posts_by_id[3] with no collision.
// ---------------------------------------------------------------------------

$tmp_root = sys_get_temp_dir() . '/mdi-zero-id-collision-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp_root . '/post', 0755, true );

write_post( $tmp_root . '/post/a-zero.md', 0, 'a-zero' );
write_post( $tmp_root . '/post/b-explicit-1.md', 1, 'b-explicit-1' );
write_post( $tmp_root . '/post/c-explicit-2.md', 2, 'c-explicit-2' );
write_post( $tmp_root . '/post/d-zero.md', 0, 'd-zero' );
write_post( $tmp_root . '/post/e-explicit-3.md', 3, 'e-explicit-3' );

register_shutdown_function( 'rm_rf', $tmp_root );

echo "Fixture in: {$tmp_root}\n";
echo "---\n";

// ---------------------------------------------------------------------------
// Test 1: get_all_posts() does not warn or fatal.
//
// Pre-fix: PHP emits "Undefined array key 3" and ultimately throws
// TypeError. Post-fix: clean execution.
// ---------------------------------------------------------------------------

echo "Test: get_all_posts() returns without warnings or fatals\n";

$storage = new WP_Markdown_Storage( $tmp_root );

// Capture any error_log output so the conflict-log path doesn't pollute
// stderr if it accidentally triggers.
$prev_error_log = ini_set( 'error_log', '/dev/null' );

// Promote warnings/notices to exceptions so the test fails loudly if
// the bug ever returns. Pre-fix this throws "Undefined array key 3".
set_error_handler(
	static function ( int $errno, string $errstr, string $errfile, int $errline ): bool {
		if ( str_contains( $errstr, 'Undefined array key' ) ) {
			throw new RuntimeException( "Undefined array key warning: {$errstr} at {$errfile}:{$errline}" );
		}
		// Let other notices through to the default handler.
		return false;
	}
);

$got_typeerror = null;
$got_warning   = null;
$posts         = null;

try {
	$posts = $storage->get_all_posts();
} catch ( TypeError $e ) {
	$got_typeerror = $e->getMessage();
} catch ( RuntimeException $e ) {
	$got_warning = $e->getMessage();
}

restore_error_handler();
ini_set( 'error_log', $prev_error_log );

assert_eq( $got_typeerror, null, 'no TypeError from relative_path( null )' );
assert_eq( $got_warning, null, 'no "Undefined array key" warning during duplicate resolution' );
assert_true( is_array( $posts ), 'get_all_posts() returned an array' );

// ---------------------------------------------------------------------------
// Test 2: all posts are present in the returned list.
//
// The pre-fix array_values($posts_by_id) silently dropped any post that
// landed on a colliding auto-increment key (overwritten by the explicit-
// ID post that came later). The fix preserves zero-ID posts in their own
// $unkeyed_posts list so the merged return value carries every file.
// ---------------------------------------------------------------------------

echo "\nTest: every fixture file is present in the result\n";

if ( is_array( $posts ) ) {
	$slugs = array();
	foreach ( $posts as $post ) {
		$slug = $post->post_name ?? '';
		if ( '' !== $slug ) {
			$slugs[] = $slug;
		}
	}
	sort( $slugs );

	assert_eq(
		$slugs,
		array( 'a-zero', 'b-explicit-1', 'c-explicit-2', 'd-zero', 'e-explicit-3' ),
		'all 5 fixture files (3 explicit-ID + 2 zero-ID) returned'
	);
} else {
	assert_true( false, 'posts result was not an array (pre-condition failed)' );
}

// ---------------------------------------------------------------------------
// Test 3: explicit IDs survive intact, zero-ID posts have ID 0.
// ---------------------------------------------------------------------------

echo "\nTest: explicit IDs preserved, zero-ID posts unchanged\n";

if ( is_array( $posts ) ) {
	$by_slug = array();
	foreach ( $posts as $post ) {
		$by_slug[ $post->post_name ?? '' ] = (int) $post->ID;
	}

	assert_eq( $by_slug['a-zero'] ?? null, 0, 'a-zero retained ID 0' );
	assert_eq( $by_slug['b-explicit-1'] ?? null, 1, 'b-explicit-1 retained ID 1' );
	assert_eq( $by_slug['c-explicit-2'] ?? null, 2, 'c-explicit-2 retained ID 2' );
	assert_eq( $by_slug['d-zero'] ?? null, 0, 'd-zero retained ID 0' );
	assert_eq(
		$by_slug['e-explicit-3'] ?? null,
		3,
		'e-explicit-3 retained ID 3 (would have been clobbered by collision pre-fix)'
	);
}

echo "\n---\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

exit( $failed > 0 ? 1 : 0 );
