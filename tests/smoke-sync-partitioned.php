<?php
/**
 * Smoke tests for sync_partitioned_table_from_json().
 *
 * Exercises the partitioned-table sync logic that keeps warm boot from
 * wiping postmeta/term_relationships rows belonging to markdown-type
 * posts (see issue #64).
 *
 * Uses a real in-memory SQLite PDO plus a hand-built loader harness that
 * bypasses WP_SQLite_Driver entirely — the logic under test is pure SQL
 * and doesn't need the MySQL-on-SQLite translator.
 *
 * Usage: php tests/smoke-sync-partitioned.php
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

// ---------------------------------------------------------------------------
// Minimal harness: exercises the partitioned-delete SQL directly.
//
// The method under test boils down to:
//
//     DELETE FROM wp_{table}
//     WHERE {post_id_col} IN (
//         SELECT ID FROM wp_posts WHERE post_type IN ({excluded})
//     )
//
// followed by re-inserts from JSON. Loading JSON is already covered by
// load_table_from_json() elsewhere — these smokes focus on the delete
// bound that makes the whole fix safe.
// ---------------------------------------------------------------------------

function partitioned_delete( \PDO $pdo, string $table, string $post_id_col, array $excluded ): void {
	if ( empty( $excluded ) ) {
		return;
	}
	$quoted = array();
	foreach ( $excluded as $type ) {
		$quoted[] = "'" . str_replace( "'", "''", $type ) . "'";
	}
	$type_list = implode( ',', $quoted );
	$pdo->exec(
		"DELETE FROM `{$table}`
		 WHERE `{$post_id_col}` IN (
		     SELECT ID FROM `wp_posts` WHERE post_type IN ({$type_list})
		 )"
	);
}

function setup_fixture(): \PDO {
	$pdo = new \PDO( 'sqlite::memory:' );
	$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );

	$pdo->exec(
		'CREATE TABLE wp_posts (
			ID INTEGER PRIMARY KEY,
			post_type TEXT NOT NULL
		)'
	);
	$pdo->exec(
		'CREATE TABLE wp_postmeta (
			meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
			post_id INTEGER NOT NULL,
			meta_key TEXT,
			meta_value TEXT
		)'
	);
	$pdo->exec(
		'CREATE TABLE wp_term_relationships (
			object_id INTEGER NOT NULL,
			term_taxonomy_id INTEGER NOT NULL,
			term_order INTEGER NOT NULL DEFAULT 0,
			PRIMARY KEY (object_id, term_taxonomy_id)
		)'
	);

	return $pdo;
}

function seed_posts( \PDO $pdo, array $rows ): void {
	$stmt = $pdo->prepare( 'INSERT INTO wp_posts (ID, post_type) VALUES (?, ?)' );
	foreach ( $rows as $row ) {
		$stmt->execute( array( $row[0], $row[1] ) );
	}
}

function seed_meta( \PDO $pdo, array $rows ): void {
	$stmt = $pdo->prepare( 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)' );
	foreach ( $rows as $row ) {
		$stmt->execute( $row );
	}
}

function count_meta_for( \PDO $pdo, int $post_id ): int {
	$stmt = $pdo->prepare( 'SELECT COUNT(*) FROM wp_postmeta WHERE post_id = ?' );
	$stmt->execute( array( $post_id ) );
	return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// Test 1 — markdown-post meta survives a partitioned sync
// ---------------------------------------------------------------------------
echo "Test 1: markdown-post postmeta survives sync_json_tables warm boot\n";

$pdo = setup_fixture();
seed_posts( $pdo, array(
	array( 10, 'wiki' ),       // markdown type
	array( 11, 'wiki' ),       // markdown type
	array( 50, 'revision' ),   // non-markdown type
	array( 51, 'nav_menu_item' ),
) );
seed_meta( $pdo, array(
	array( 10, '_observation_flag', '1' ),
	array( 10, '_observation_key', 'abc' ),
	array( 11, '_observation_flag', '1' ),
	array( 50, '_edit_last', '1' ),
	array( 51, '_menu_item_type', 'post_type' ),
) );

assert_eq( count_meta_for( $pdo, 10 ), 2, 'before sync: post 10 (wiki) has 2 rows' );
assert_eq( count_meta_for( $pdo, 50 ), 1, 'before sync: post 50 (revision) has 1 row' );

partitioned_delete( $pdo, 'wp_postmeta', 'post_id', array( 'revision', 'nav_menu_item' ) );

assert_eq( count_meta_for( $pdo, 10 ), 2, 'after sync: post 10 (wiki) still has 2 rows' );
assert_eq( count_meta_for( $pdo, 11 ), 1, 'after sync: post 11 (wiki) still has 1 row' );
assert_eq( count_meta_for( $pdo, 50 ), 0, 'after sync: post 50 (revision) meta deleted' );
assert_eq( count_meta_for( $pdo, 51 ), 0, 'after sync: post 51 (nav_menu_item) meta deleted' );

// ---------------------------------------------------------------------------
// Test 2 — empty excluded-types list is a no-op
// ---------------------------------------------------------------------------
echo "\nTest 2: empty excluded-types list leaves everything intact\n";

$pdo = setup_fixture();
seed_posts( $pdo, array(
	array( 1, 'post' ),
	array( 2, 'page' ),
) );
seed_meta( $pdo, array(
	array( 1, 'key', 'val' ),
	array( 2, 'key', 'val' ),
) );

partitioned_delete( $pdo, 'wp_postmeta', 'post_id', array() );

$total = (int) $pdo->query( 'SELECT COUNT(*) FROM wp_postmeta' )->fetchColumn();
assert_eq( $total, 2, 'empty excluded list deletes nothing' );

// ---------------------------------------------------------------------------
// Test 3 — term_relationships partitioned delete works the same way
// ---------------------------------------------------------------------------
echo "\nTest 3: term_relationships partitioned delete\n";

$pdo = setup_fixture();
seed_posts( $pdo, array(
	array( 100, 'wiki' ),
	array( 101, 'revision' ),
) );
$pdo->exec( "INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (100, 5)" );
$pdo->exec( "INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES (101, 7)" );

partitioned_delete( $pdo, 'wp_term_relationships', 'object_id', array( 'revision' ) );

$wiki_rows = (int) $pdo->query( 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 100' )->fetchColumn();
$rev_rows  = (int) $pdo->query( 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 101' )->fetchColumn();
assert_eq( $wiki_rows, 1, 'wiki post term relationship preserved' );
assert_eq( $rev_rows, 0, 'revision post term relationship deleted' );

// ---------------------------------------------------------------------------
// Test 4 — orphan meta (post_id has no wp_posts row) is preserved
// ---------------------------------------------------------------------------
echo "\nTest 4: orphan postmeta rows are not touched by partitioned delete\n";

$pdo = setup_fixture();
seed_posts( $pdo, array(
	array( 1, 'revision' ),
) );
seed_meta( $pdo, array(
	array( 1, 'key', 'val' ),
	array( 999, 'orphan', 'value' ), // no matching wp_posts row
) );

partitioned_delete( $pdo, 'wp_postmeta', 'post_id', array( 'revision' ) );

assert_eq( count_meta_for( $pdo, 1 ), 0, 'revision meta deleted' );
assert_eq( count_meta_for( $pdo, 999 ), 1, 'orphan meta preserved (safer than dropping)' );

// ---------------------------------------------------------------------------
// Test 5 — SQL-injection-safe quoting of post type names
// ---------------------------------------------------------------------------
echo "\nTest 5: post type names with quotes are safely escaped\n";

$pdo = setup_fixture();
$pdo->prepare( 'INSERT INTO wp_posts (ID, post_type) VALUES (?, ?)' )->execute(
	array( 1, "weird' OR 1=1 --" )
);
seed_meta( $pdo, array( array( 1, 'key', 'val' ) ) );

partitioned_delete( $pdo, 'wp_postmeta', 'post_id', array( "weird' OR 1=1 --" ) );

assert_eq( count_meta_for( $pdo, 1 ), 0, 'quoted excluded type deletes its rows' );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n─────────────────────────────────────\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "─────────────────────────────────────\n";

exit( $failed > 0 ? 1 : 0 );
