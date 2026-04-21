<?php
/**
 * Smoke tests for WP_Markdown_Storage::inject_id_into_frontmatter().
 *
 * Pure-PHP tests that exercise surgical frontmatter rewrites without
 * bootstrapping WordPress. Covers the file formats produced by the
 * plugin itself, by AI agents dropping files on disk, and by users
 * who hand-author markdown.
 *
 * Usage: php tests/smoke-inject-id.php
 *
 * See GitHub issue #42.
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Minimal stubs for methods the class references but our tests don't hit.
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
		$passed++;
	} else {
		echo "  ✗ {$label}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
		$failed++;
	}
}

function assert_contains( string $haystack, string $needle, string $label ): void {
	global $passed, $failed;
	if ( false !== strpos( $haystack, $needle ) ) {
		echo "  ✓ {$label}\n";
		$passed++;
	} else {
		echo "  ✗ {$label}\n";
		echo "    looking for: {$needle}\n";
		echo "    in:\n{$haystack}\n";
		$failed++;
	}
}

function tmpfile_with( string $content ): string {
	$path = sys_get_temp_dir() . '/mdi-inject-' . uniqid() . '.md';
	file_put_contents( $path, $content );
	return $path;
}

// The storage class has a private constructor dependency on composer-loaded
// converters; we only need inject_id_into_frontmatter + atomic_write, both
// of which are self-contained. Instantiate with a dummy content dir.
$storage = new WP_Markdown_Storage( sys_get_temp_dir() );

// ---------------------------------------------------------------------------
// Test 1: Replace an existing `id:` line
// ---------------------------------------------------------------------------
echo "Test 1: replace existing id: line\n";

$file = tmpfile_with(
	"---\nid: 7\ntitle: Alpha\nstatus: publish\n---\n\nBody content here.\n"
);
$ok = $storage->inject_id_into_frontmatter( $file, 42 );
$after = file_get_contents( $file );

assert_eq( $ok, true, 'returns true on success' );
assert_contains( $after, "id: 42\n", 'new id present' );
assert_eq(
	(bool) preg_match( '/^id:\s*7\s*$/m', $after ),
	false,
	'old id: 7 no longer present'
);
assert_contains( $after, 'title: Alpha', 'other frontmatter preserved (title)' );
assert_contains( $after, 'status: publish', 'other frontmatter preserved (status)' );
assert_contains( $after, 'Body content here.', 'body preserved' );

// Verify the id: is still at the top (where it was originally).
$first_frontmatter_line = '';
if ( preg_match( "/\\A---\\n(.*?)\\n/s", $after, $m ) ) {
	$first_frontmatter_line = $m[1];
}
assert_eq(
	$first_frontmatter_line,
	'id: 42',
	'id: line remains in its original position (first)'
);
unlink( $file );

// ---------------------------------------------------------------------------
// Test 2: Prepend `id:` when frontmatter has none (AI-dropped file)
// ---------------------------------------------------------------------------
echo "\nTest 2: prepend id: to frontmatter that has none\n";

$file = tmpfile_with(
	"---\ntitle: Orphan\nstatus: draft\n---\n\nContent from an AI agent.\n"
);
$ok = $storage->inject_id_into_frontmatter( $file, 100 );
$after = file_get_contents( $file );

assert_eq( $ok, true, 'returns true on success' );
assert_contains( $after, "---\nid: 100\ntitle: Orphan\n", 'id: prepended before title' );
assert_contains( $after, 'Content from an AI agent.', 'body preserved' );
unlink( $file );

// ---------------------------------------------------------------------------
// Test 3: Add frontmatter block when file has none at all
// ---------------------------------------------------------------------------
echo "\nTest 3: add frontmatter block to bare markdown file\n";

$file = tmpfile_with( "# Just a heading\n\nAnd a paragraph.\n" );
$ok = $storage->inject_id_into_frontmatter( $file, 55 );
$after = file_get_contents( $file );

assert_eq( $ok, true, 'returns true on success' );
assert_contains( $after, "---\nid: 55\n---\n", 'minimal frontmatter block created' );
assert_contains( $after, '# Just a heading', 'original body preserved' );
unlink( $file );

// ---------------------------------------------------------------------------
// Test 4: Idempotency — inject same ID twice
// ---------------------------------------------------------------------------
echo "\nTest 4: idempotency\n";

$file = tmpfile_with( "---\ntitle: Beta\n---\n\nBody.\n" );
$storage->inject_id_into_frontmatter( $file, 77 );
$first = file_get_contents( $file );
$storage->inject_id_into_frontmatter( $file, 77 );
$second = file_get_contents( $file );

assert_eq( $first, $second, 'same ID twice produces identical output' );
unlink( $file );

// ---------------------------------------------------------------------------
// Test 5: Guards — invalid ID or missing file
// ---------------------------------------------------------------------------
echo "\nTest 5: input guards\n";

$file = tmpfile_with( "---\ntitle: Safe\n---\n\nBody.\n" );
$before = file_get_contents( $file );

assert_eq( $storage->inject_id_into_frontmatter( $file, 0 ), false, 'id=0 returns false' );
assert_eq( $storage->inject_id_into_frontmatter( $file, -5 ), false, 'negative id returns false' );
assert_eq( file_get_contents( $file ), $before, 'file untouched after guard rejections' );

assert_eq(
	$storage->inject_id_into_frontmatter( '/nonexistent/path/to/ghost.md', 42 ),
	false,
	'missing file returns false'
);
unlink( $file );

// ---------------------------------------------------------------------------
// Test 6: Frontmatter key variations — case-insensitive, whitespace
// ---------------------------------------------------------------------------
echo "\nTest 6: key variations\n";

$file = tmpfile_with( "---\nID: 99\ntitle: Case\n---\n\nBody.\n" );
$storage->inject_id_into_frontmatter( $file, 100 );
$after = file_get_contents( $file );
assert_contains( $after, 'id: 100', 'replaces uppercase ID key' );
assert_eq( (bool) preg_match( '/^ID:\s*99\s*$/m', $after ), false, 'old ID: 99 replaced' );
unlink( $file );

$file = tmpfile_with( "---\nid :  7\ntitle: Spaced\n---\n\nBody.\n" );
$storage->inject_id_into_frontmatter( $file, 101 );
$after = file_get_contents( $file );
assert_contains( $after, 'id: 101', 'replaces key with irregular whitespace' );
unlink( $file );

// ---------------------------------------------------------------------------
// Test 7: Atomic write — no partial state on failure
// ---------------------------------------------------------------------------
echo "\nTest 7: atomic write (no .tmp files remain after successful write)\n";

$file = tmpfile_with( "---\nid: 1\n---\n\nBody.\n" );
$storage->inject_id_into_frontmatter( $file, 2 );
$dir = dirname( $file );
$tmp_leftovers = glob( $dir . '/' . basename( $file ) . '.*.tmp' );
assert_eq( is_array( $tmp_leftovers ), true, 'glob returns array' );
assert_eq( count( $tmp_leftovers ), 0, 'no .tmp leftovers from successful rename' );
unlink( $file );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "─────────────────────────────────────\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "─────────────────────────────────────\n";
exit( $failed > 0 ? 1 : 0 );
