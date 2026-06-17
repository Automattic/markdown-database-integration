<?php
/**
 * Smoke tests for sync_posts_partition_from_json() and the shared
 * quote_excluded_types_for_in_clause() helper.
 *
 * Exercises the wp_posts partitioned-sync logic that keeps warm boot
 * from wiping markdown-type post rows when _tables/posts.json changes
 * (see issue #66).
 *
 * Uses a real in-memory SQLite PDO and hand-built fixtures so the
 * logic under test runs against actual SQLite SQL — no WP_SQLite_Driver
 * or MySQL-to-SQLite translator needed.
 *
 * Usage: php tests/smoke-sync-posts-partition.php
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

/**
 * Mirror of WP_Markdown_Loader::quote_excluded_types_for_in_clause() used by
 * both sync_partitioned_table_from_json() and sync_posts_partition_from_json().
 *
 * Pure function — exercised here without the surrounding class.
 */
function quote_excluded_types_for_in_clause( array $types ): string {
	$quoted = array();
	foreach ( $types as $type ) {
		$quoted[] = "'" . str_replace( "'", "''", $type ) . "'";
	}
	return implode( ',', $quoted );
}

/**
 * Mirror of WP_Markdown_Loader::sync_posts_partition_from_json() delete step.
 *
 * The reload step is plain load_table_from_json() which is covered elsewhere;
 * these smokes focus on the delete bound that makes the whole fix safe.
 */
function posts_partition_delete( \PDO $pdo, array $excluded ): void {
	if ( empty( $excluded ) ) {
		return;
	}
	$type_list = quote_excluded_types_for_in_clause( $excluded );
	$pdo->exec( "DELETE FROM `wp_posts` WHERE post_type IN ({$type_list})" );
}

function setup_fixture(): \PDO {
	$pdo = new \PDO( 'sqlite::memory:' );
	$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );

	$pdo->exec(
		'CREATE TABLE wp_posts (
			ID INTEGER PRIMARY KEY,
			post_type TEXT NOT NULL,
			post_title TEXT
		)'
	);

	return $pdo;
}

function seed_posts( \PDO $pdo, array $rows ): void {
	$stmt = $pdo->prepare( 'INSERT INTO wp_posts (ID, post_type, post_title) VALUES (?, ?, ?)' );
	foreach ( $rows as $row ) {
		$stmt->execute( $row );
	}
}

function count_by_type( \PDO $pdo, string $post_type ): int {
	$stmt = $pdo->prepare( 'SELECT COUNT(*) FROM wp_posts WHERE post_type = ?' );
	$stmt->execute( array( $post_type ) );
	return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// Test 1 — markdown-type wp_posts rows survive a partitioned sync
// ---------------------------------------------------------------------------
echo "Test 1: markdown-type wp_posts rows survive sync_posts_partition_from_json\n";

$pdo = setup_fixture();
seed_posts( $pdo, array(
	array( 10, 'wiki', 'wiki post a' ),
	array( 11, 'wiki', 'wiki post b' ),
	array( 12, 'post', 'blog post a' ),
	array( 13, 'page', 'page a' ),
	array( 50, 'revision', 'rev of 10' ),
	array( 51, 'nav_menu_item', 'menu item' ),
	array( 52, 'customize_changeset', 'theme draft' ),
) );

assert_eq( count_by_type( $pdo, 'wiki' ), 2, 'before sync: 2 wiki rows' );
assert_eq( count_by_type( $pdo, 'post' ), 1, 'before sync: 1 post row' );
assert_eq( count_by_type( $pdo, 'revision' ), 1, 'before sync: 1 revision row' );

posts_partition_delete( $pdo, array( 'revision', 'nav_menu_item', 'customize_changeset' ) );

assert_eq( count_by_type( $pdo, 'wiki' ), 2, 'after sync: wiki rows preserved' );
assert_eq( count_by_type( $pdo, 'post' ), 1, 'after sync: post rows preserved' );
assert_eq( count_by_type( $pdo, 'page' ), 1, 'after sync: page rows preserved' );
assert_eq( count_by_type( $pdo, 'revision' ), 0, 'after sync: revisions deleted' );
assert_eq( count_by_type( $pdo, 'nav_menu_item' ), 0, 'after sync: nav items deleted' );
assert_eq( count_by_type( $pdo, 'customize_changeset' ), 0, 'after sync: changesets deleted' );

// ---------------------------------------------------------------------------
// Test 2 — empty excluded-types list is a no-op
// ---------------------------------------------------------------------------
echo "\nTest 2: empty excluded-types list leaves everything intact\n";

$pdo = setup_fixture();
seed_posts( $pdo, array(
	array( 1, 'post', 'a' ),
	array( 2, 'page', 'b' ),
) );

posts_partition_delete( $pdo, array() );

$total = (int) $pdo->query( 'SELECT COUNT(*) FROM wp_posts' )->fetchColumn();
assert_eq( $total, 2, 'empty excluded list deletes nothing' );

// ---------------------------------------------------------------------------
// Test 3 — only the exact matching post types are deleted
// ---------------------------------------------------------------------------
echo "\nTest 3: post_type match is exact, not prefix\n";

$pdo = setup_fixture();
seed_posts( $pdo, array(
	array( 1, 'revision', 'a' ),
	array( 2, 'revision_draft', 'custom type with revision prefix' ),
	array( 3, 'wp_navigation', 'navigation block' ),
) );

posts_partition_delete( $pdo, array( 'revision' ) );

assert_eq( count_by_type( $pdo, 'revision' ), 0, 'revision deleted' );
assert_eq( count_by_type( $pdo, 'revision_draft' ), 1, 'revision_draft NOT deleted (exact match only)' );
assert_eq( count_by_type( $pdo, 'wp_navigation' ), 1, 'wp_navigation NOT deleted' );

// ---------------------------------------------------------------------------
// Test 4 — SQL-injection-safe quoting of post type names
// ---------------------------------------------------------------------------
echo "\nTest 4: post type names with quotes are safely escaped\n";

$pdo = setup_fixture();
seed_posts( $pdo, array(
	array( 1, "weird' OR 1=1 --", 'hostile post type' ),
	array( 2, 'wiki', 'should survive' ),
) );

posts_partition_delete( $pdo, array( "weird' OR 1=1 --" ) );

assert_eq( count_by_type( $pdo, 'wiki' ), 1, 'wiki preserved despite neighboring injection attempt' );
assert_eq( count_by_type( $pdo, "weird' OR 1=1 --" ), 0, 'exact quoted type deleted' );

// ---------------------------------------------------------------------------
// Test 5 — quote_excluded_types_for_in_clause helper shape
// ---------------------------------------------------------------------------
echo "\nTest 5: quote_excluded_types_for_in_clause shape\n";

assert_eq(
	quote_excluded_types_for_in_clause( array( 'revision' ) ),
	"'revision'",
	'single type quoted correctly'
);
assert_eq(
	quote_excluded_types_for_in_clause( array( 'revision', 'nav_menu_item' ) ),
	"'revision','nav_menu_item'",
	'multiple types comma-joined'
);
assert_eq(
	quote_excluded_types_for_in_clause( array( "O'Brien" ) ),
	"'O''Brien'",
	"single quote in type escaped to '' (SQL standard)"
);
assert_eq(
	quote_excluded_types_for_in_clause( array() ),
	'',
	'empty array returns empty string'
);

// ---------------------------------------------------------------------------
// Test 6 — simulates the repro from issue #66 end-to-end in SQL
// ---------------------------------------------------------------------------
echo "\nTest 6: issue #66 live repro (141 wiki rows → survives → recovers on heal)\n";

$pdo = setup_fixture();

// Seed 141 wiki posts + a handful of non-markdown posts mimicking the site.
$stmt = $pdo->prepare( 'INSERT INTO wp_posts (ID, post_type, post_title) VALUES (?, ?, ?)' );
for ( $i = 1; $i <= 141; $i++ ) {
	$stmt->execute( array( $i, 'wiki', "wiki {$i}" ) );
}
$stmt->execute( array( 500, 'revision', 'rev a' ) );
$stmt->execute( array( 501, 'nav_menu_item', 'menu' ) );

assert_eq( count_by_type( $pdo, 'wiki' ), 141, 'seeded 141 wiki posts' );

// A non-markdown post update triggered posts.json rewrite. The warm-boot
// sync would have wiped everything before the fix. With the fix:
posts_partition_delete( $pdo, array( 'revision', 'nav_menu_item', 'customize_changeset' ) );

assert_eq( count_by_type( $pdo, 'wiki' ), 141, 'all 141 wiki posts preserved across warm-boot sync' );
assert_eq( count_by_type( $pdo, 'revision' ), 0, 'revisions cleared for JSON reload' );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n─────────────────────────────────────\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "─────────────────────────────────────\n";

exit( $failed > 0 ? 1 : 0 );
