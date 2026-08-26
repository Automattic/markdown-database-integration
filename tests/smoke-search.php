<?php
/**
 * Smoke tests for WP_Markdown_Search.
 *
 * Pure-PHP tests that exercise the search logic without bootstrapping
 * WordPress. Uses a temporary directory of fixture `.md` files and a
 * stub driver that returns a hand-rolled file index.
 *
 * Usage: php tests/smoke-search.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

// Minimal ABSPATH guard satisfaction.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// apply_filters stub: return the passed value unchanged so the default
// grep backend runs. Real WordPress provides this; the smoke test mocks it.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}

// Load the class files we need. The search class depends on the driver
// and storage classes only for type hints — the stubs satisfy that.
require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';
require_once __DIR__ . '/stubs/stub-wp-markdown-driver.php';
require_once __DIR__ . '/../inc/class-wp-markdown-search.php';

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
		echo "    in:          {$haystack}\n";
		$failed++;
	}
}

// ---------------------------------------------------------------------------
// Fixtures: a small temp directory of .md files
// ---------------------------------------------------------------------------

$fixture_dir = sys_get_temp_dir() . '/mdi-search-smoke-' . uniqid();
mkdir( $fixture_dir . '/wiki', 0777, true );
mkdir( $fixture_dir . '/post', 0777, true );

file_put_contents(
	$fixture_dir . '/wiki/alpha.md',
	"---\nid: 10\ntitle: Alpha\n---\n\nThe quick brown fox jumps over the lazy dog.\n"
);
file_put_contents(
	$fixture_dir . '/wiki/beta.md',
	"---\nid: 20\ntitle: Beta\n---\n\nWordPress is a content management system written in PHP.\n"
);
file_put_contents(
	$fixture_dir . '/post/gamma.md',
	"---\nid: 30\ntitle: Gamma\n---\n\nMarkdown files are the source of truth for FOX post content.\n"
);
file_put_contents(
	$fixture_dir . '/wiki/delta.md',
	"---\nid: 40\ntitle: Delta\n---\n\nNothing interesting here — just a placeholder article.\n"
);

$file_index = array(
	10 => 'wiki/alpha.md',
	20 => 'wiki/beta.md',
	30 => 'post/gamma.md',
	40 => 'wiki/delta.md',
);

$storage = new Stub_WP_Markdown_Storage( $fixture_dir );
$driver  = new Stub_WP_Markdown_Driver( $file_index );
$search  = new WP_Markdown_Search( $driver, $storage );

// ---------------------------------------------------------------------------
// Test 1: find_matching_ids on a real file grep
// ---------------------------------------------------------------------------
echo "Test 1: find_matching_ids grep across fixture files\n";

assert_eq(
	$search->find_matching_ids( 'fox' ),
	array( 10, 30 ),
	'case-insensitive match finds both FOX-containing files'
);
assert_eq(
	$search->find_matching_ids( 'WordPress' ),
	array( 20 ),
	'single-file match'
);
assert_eq(
	$search->find_matching_ids( 'nonexistent-string-zzzz' ),
	array(),
	'no match returns empty array'
);
assert_eq(
	$search->find_matching_ids( '' ),
	array(),
	'empty needle returns empty array (does not match everything)'
);
assert_eq(
	$search->find_matching_ids( 'content' ),
	array( 20, 30 ),
	'two files match "content" (case-insensitive)'
);

// ---------------------------------------------------------------------------
// Test 2: per-request caching
// ---------------------------------------------------------------------------
echo "\nTest 2: per-request cache\n";

$first  = $search->find_matching_ids( 'FoX' );
$second = $search->find_matching_ids( 'fox' ); // same key after lowercasing
assert_eq( $first, $second, 'mixed-case same needle returns cached result' );

// ---------------------------------------------------------------------------
// Test 3: maybe_rewrite_query on WP-style search SQL
// ---------------------------------------------------------------------------
echo "\nTest 3: maybe_rewrite_query on WP_Query search SQL\n";

// Single-word search (matches one file).
$sql = "SELECT wp_posts.* FROM wp_posts WHERE 1=1 AND (((wp_posts.post_title LIKE '%WordPress%') OR (wp_posts.post_excerpt LIKE '%WordPress%') OR (wp_posts.post_content LIKE '%WordPress%'))) AND wp_posts.post_status = 'publish' ORDER BY wp_posts.post_date DESC LIMIT 0, 10";

$rewritten = $search->maybe_rewrite_query( $sql );
assert_eq( is_string( $rewritten ), true, 'rewrite returns a string' );
assert_contains( $rewritten ?? '', 'wp_posts.ID IN (20)', 'substitutes post_content LIKE with ID IN (20)' );
assert_eq(
	strpos( $rewritten ?? '', 'post_content LIKE' ),
	false,
	'no post_content LIKE clause remains after rewrite'
);
assert_contains( $rewritten ?? '', "wp_posts.post_title LIKE '%WordPress%'", 'title LIKE preserved' );
assert_contains( $rewritten ?? '', "wp_posts.post_excerpt LIKE '%WordPress%'", 'excerpt LIKE preserved' );

// Multi-word AND search (each LIKE group is independent).
$sql_multi = "SELECT wp_posts.* FROM wp_posts WHERE 1=1 AND (((wp_posts.post_title LIKE '%fox%') OR (wp_posts.post_content LIKE '%fox%')) AND ((wp_posts.post_title LIKE '%content%') OR (wp_posts.post_content LIKE '%content%')))";
$rewritten_multi = $search->maybe_rewrite_query( $sql_multi );
assert_contains( $rewritten_multi ?? '', 'wp_posts.ID IN (10,30)', '"fox" LIKE → ID IN (10,30)' );
assert_contains( $rewritten_multi ?? '', 'wp_posts.ID IN (20,30)', '"content" LIKE → ID IN (20,30)' );

// No-match needle rewrites to 0=1.
$sql_none = "SELECT wp_posts.* FROM wp_posts WHERE wp_posts.post_content LIKE '%xyznomatch%'";
$rewritten_none = $search->maybe_rewrite_query( $sql_none );
assert_contains( $rewritten_none ?? '', '0=1', 'no-match needle rewritten to 0=1' );

// No post_content LIKE = no rewrite.
$sql_no_like = "SELECT * FROM wp_posts WHERE post_status = 'publish'";
assert_eq( $search->maybe_rewrite_query( $sql_no_like ), null, 'query without post_content LIKE returns null' );

// Pattern with only prefix wildcard should NOT be rewritten (unsupported shape).
$sql_prefix = "SELECT * FROM wp_posts WHERE post_content LIKE 'foo%'";
assert_eq( $search->maybe_rewrite_query( $sql_prefix ), null, 'prefix-only LIKE "foo%" is left untouched' );

// Pattern with escaped wildcard: %50\% OFF% — needle "50% OFF"
$sql_escaped = "SELECT * FROM wp_posts WHERE post_content LIKE '%50\\% OFF%'";
file_put_contents(
	$fixture_dir . '/post/sale.md',
	"---\nid: 50\ntitle: Sale\n---\n\nBuy now, 50% OFF everything.\n"
);
$file_index[50] = 'post/sale.md';
$driver2  = new Stub_WP_Markdown_Driver( $file_index );
$search2  = new WP_Markdown_Search( $driver2, $storage );
$rewritten_esc = $search2->maybe_rewrite_query( $sql_escaped );
assert_contains( $rewritten_esc ?? '', 'ID IN (50)', 'escaped %\% literal is unescaped and matches 50% OFF' );

// ---------------------------------------------------------------------------
// Test 4: table prefix preservation
// ---------------------------------------------------------------------------
echo "\nTest 4: table prefix preservation in rewrite\n";

$sql_prefixed    = "SELECT * FROM wp_posts WHERE wp_posts.post_content LIKE '%fox%'";
$rewritten_pref  = $search->maybe_rewrite_query( $sql_prefixed );
assert_contains( $rewritten_pref ?? '', 'wp_posts.ID IN', 'wp_posts. prefix preserved in rewrite' );

$sql_bare        = "SELECT * FROM wp_posts WHERE post_content LIKE '%fox%'";
$rewritten_bare  = $search->maybe_rewrite_query( $sql_bare );
assert_contains( $rewritten_bare ?? '', ' ID IN', 'bare column reference becomes bare ID IN' );
assert_eq(
	(bool) preg_match( '/[a-z_]+\.ID IN/i', $rewritten_bare ?? '' ),
	false,
	'no spurious table prefix added when original had none'
);

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------

function rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			rrmdir( $path );
		} else {
			unlink( $path );
		}
	}
	rmdir( $dir );
}
rrmdir( $fixture_dir );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "─────────────────────────────────────\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "─────────────────────────────────────\n";
exit( $failed > 0 ? 1 : 0 );
