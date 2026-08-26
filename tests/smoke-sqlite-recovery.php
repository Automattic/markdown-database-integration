<?php
/**
 * Smoke tests for recovering stranded SQLite posts into markdown files.
 *
 * Usage: php tests/smoke-sqlite-recovery.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	echo "SKIP: pdo_sqlite extension is not available.\n";
	exit( 0 );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$test_filters = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $callback ): void {
		global $test_filters;
		$test_filters[ $tag ][] = $callback;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value ) {
		global $test_filters;
		$args = func_get_args();
		array_shift( $args );
		foreach ( $test_filters[ $tag ] ?? array() as $callback ) {
			$args[0] = $callback( ...$args );
		}
		return $args[0];
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}
}

define( 'MARKDOWN_DB_CONTENT_DIR', sys_get_temp_dir() . '/mdi-sqlite-recovery-' . bin2hex( random_bytes( 4 ) ) );
define( 'MARKDOWN_DB_EXCLUDED_TYPES', 'revision,auto-draft' );

require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/class-wp-markdown-sqlite-recovery.php';

$passed = 0;
$failed = 0;

function assert_eq( $actual, $expected, string $label ): void {
	global $passed, $failed;
	if ( $actual === $expected ) {
		echo "  ✓ {$label}\n";
		$passed++;
		return;
	}

	echo "  ✗ {$label}\n";
	echo '    expected: ' . var_export( $expected, true ) . "\n";
	echo '    actual:   ' . var_export( $actual, true ) . "\n";
	$failed++;
}

function assert_true( $actual, string $label ): void {
	assert_eq( (bool) $actual, true, $label );
}

function rm_rf( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $item ) {
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

function seed_sqlite( string $db ): void {
	$pdo = new PDO( 'sqlite:' . $db );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$pdo->exec(
		'CREATE TABLE wp_posts (
			ID INTEGER PRIMARY KEY,
			post_author INTEGER,
			post_date TEXT,
			post_date_gmt TEXT,
			post_content TEXT,
			post_title TEXT,
			post_excerpt TEXT,
			post_status TEXT,
			comment_status TEXT,
			ping_status TEXT,
			post_password TEXT,
			post_name TEXT,
			to_ping TEXT,
			pinged TEXT,
			post_modified TEXT,
			post_modified_gmt TEXT,
			post_content_filtered TEXT,
			post_parent INTEGER,
			guid TEXT,
			menu_order INTEGER,
			post_type TEXT,
			post_mime_type TEXT,
			comment_count INTEGER
		)'
	);
	$pdo->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );

	$insert = $pdo->prepare(
		'INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (?, 1, "2026-05-09 00:00:00", "2026-05-09 00:00:00", ?, ?, "", "publish", "closed", "closed", "", ?, "", "", "2026-05-09 00:00:00", "2026-05-09 00:00:00", "", ?, ?, 0, "wiki", "", 0)'
	);

	$insert->execute( array( 20, "# Child\n\nRecovered body.", 'Child', 'child', 10, 'http://example.test/?p=20' ) );
	$insert->execute( array( 21, "# Grandchild\n\nRecovered body.", 'Grandchild', 'grandchild', 20, 'http://example.test/?p=21' ) );
	$insert->execute( array( 22, "# Other\n\nOutside root.", 'Other', 'other', 0, 'http://example.test/?p=22' ) );

	$meta = $pdo->prepare( 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)' );
	$meta->execute( array( 20, '_datamachine_content_hash', 'abc123' ) );
	$meta->execute( array( 20, '_ignored_internal', 'nope' ) );
}

$source_db = MARKDOWN_DB_CONTENT_DIR . '-source.sqlite';
mkdir( MARKDOWN_DB_CONTENT_DIR . '/wiki/wordpress-com', 0755, true );
file_put_contents(
	MARKDOWN_DB_CONTENT_DIR . '/wiki/wordpress-com/index.md',
	"---\nid: 10\ntitle: WordPress.com\nstatus: publish\ntype: wiki\nauthor: 1\ndate: \"2026-05-09 00:00:00\"\ndate_gmt: \"2026-05-09 00:00:00\"\nmodified: \"2026-05-09 00:00:00\"\nmodified_gmt: \"2026-05-09 00:00:00\"\nslug: wordpress-com\nparent: 0\nmenu_order: 0\ncomment_status: closed\nping_status: closed\nguid: \"http://example.test/?p=10\"\ncomment_count: 0\n---\n\n# WordPress.com\n"
);
seed_sqlite( $source_db );

echo "Test 1: dry-run reports recoverable descendants without writing\n";
$dry_run = WP_Markdown_SQLite_Recovery::recover(
	array(
		'source_db' => $source_db,
		'post_type' => 'wiki',
		'root_slug' => 'wordpress-com',
		'min_id'    => 20,
	)
);

assert_true( $dry_run['success'], 'dry-run succeeds' );
assert_eq( $dry_run['mode'], 'dry-run', 'dry-run mode reported' );
assert_eq( $dry_run['candidate_count'], 2, 'only descendants under root are candidates' );
assert_eq( file_exists( MARKDOWN_DB_CONTENT_DIR . '/wiki/wordpress-com/child.md' ), false, 'dry-run does not write child file' );

echo "\nTest 2: apply writes markdown through storage and preserves meta\n";
$apply = WP_Markdown_SQLite_Recovery::recover(
	array(
		'source_db' => $source_db,
		'post_type' => 'wiki',
		'root_slug' => 'wordpress-com',
		'min_id'    => 20,
		'apply'     => true,
	)
);

$child_file      = MARKDOWN_DB_CONTENT_DIR . '/wiki/wordpress-com/child/index.md';
$grandchild_file = MARKDOWN_DB_CONTENT_DIR . '/wiki/wordpress-com/child/grandchild.md';
$child_markdown  = file_get_contents( $child_file );

assert_true( $apply['success'], 'apply succeeds' );
assert_eq( $apply['written_count'], 2, 'two posts written' );
assert_true( file_exists( $child_file ), 'child promoted to index.md after grandchild write' );
assert_true( file_exists( $grandchild_file ), 'grandchild written below child directory' );
assert_true( str_contains( $child_markdown, "  meta:\n    _datamachine_content_hash: abc123" ), 'allowlisted recovery meta is preserved under wordpress.meta' );
assert_eq( str_contains( $child_markdown, '_ignored_internal' ), false, 'unallowlisted internal meta is not preserved' );

@unlink( $source_db );
rm_rf( MARKDOWN_DB_CONTENT_DIR );

echo "\n";
if ( $failed > 0 ) {
	echo "FAILURES: {$failed}\n";
	exit( 1 );
}

echo "All tests passed ({$passed} assertions).\n";
