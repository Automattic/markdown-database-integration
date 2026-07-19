<?php
/**
 * End-to-end warm-sync regression coverage for atomic JSON replacement.
 *
 * A malformed changed snapshot must retain every row that its replacement
 * delete would otherwise remove. This is the loader contract for core,
 * plugin, and markdown-partitioned tables.
 *
 * Usage: php tests/smoke-json-warm-sync-atomic.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	echo "SKIP: pdo_sqlite extension is not available.\n";
	exit( 0 );
}

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../vendor/autoload.php';

class MDI_Warm_Sync_Connection {
	public function __construct( private PDO $pdo ) {}

	public function get_pdo(): PDO {
		return $this->pdo;
	}
}

class WP_SQLite_Driver {
	public function __construct( private MDI_Warm_Sync_Connection $connection ) {}

	public function get_connection(): MDI_Warm_Sync_Connection {
		return $this->connection;
	}
}

class WP_Markdown_Storage {
	public function __construct( private array $excluded_types ) {}

	public function get_excluded_types(): array {
		return $this->excluded_types;
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';

$failures = array();

function mdi_warm_sync_assert( bool $condition, string $message ): void {
	global $failures;
	if ( $condition ) {
		echo 'PASS: ' . $message . PHP_EOL;
		return;
	}
	$failures[] = $message;
	echo 'FAIL: ' . $message . PHP_EOL;
}

function mdi_warm_sync_remove_dir( string $dir ): void {
	foreach ( glob( $dir . '/*' ) ?: array() as $path ) {
		is_dir( $path ) ? mdi_warm_sync_remove_dir( $path ) : unlink( $path );
	}
	rmdir( $dir );
}

function mdi_warm_sync_loader( PDO $pdo, string $root ): array {
	$loader = new WP_Markdown_Loader(
		$root,
		new WP_SQLite_Driver( new MDI_Warm_Sync_Connection( $pdo ) ),
		new WP_Markdown_Storage( array( 'revision' ) )
	);
	$create_manifest = new ReflectionMethod( $loader, 'create_json_manifest_table' );
	$create_manifest->invoke( $loader );
	return array( $loader, new ReflectionMethod( $loader, 'sync_json_tables' ) );
}

function mdi_warm_sync_changed_snapshot( PDO $pdo, string $root, string $name ): void {
	file_put_contents( $root . '/_tables/' . $name . '.json', '[{"id":1}' );
	$pdo->prepare( 'INSERT INTO _json_file_manifest (file_name, file_mtime, file_size) VALUES (?, ?, ?)' )
		->execute( array( '_tables/' . $name . '.json', 0, 0 ) );
}

function mdi_warm_sync_run( ReflectionMethod $sync, WP_Markdown_Loader $loader ): bool {
	try {
		$sync->invoke( $loader );
	} catch ( RuntimeException ) {
		return true;
	}
	return false;
}

function mdi_warm_sync_fixture(): array {
	$root = sys_get_temp_dir() . '/mdi-warm-sync-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	mkdir( $root . '/_tables', 0755, true );
	$pdo = new PDO( 'sqlite::memory:' );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	return array( $root, $pdo );
}

// Core tables use a full replacement delete.
[ $root, $pdo ] = mdi_warm_sync_fixture();
$pdo->exec( 'CREATE TABLE wp_users (id INTEGER PRIMARY KEY, name TEXT)' );
$pdo->exec( "INSERT INTO wp_users (id, name) VALUES (1, 'before')" );
[ $loader, $sync ] = mdi_warm_sync_loader( $pdo, $root );
mdi_warm_sync_changed_snapshot( $pdo, $root, 'users' );
mdi_warm_sync_assert( mdi_warm_sync_run( $sync, $loader ), 'malformed changed core snapshot fails warm sync' );
mdi_warm_sync_assert( 'before' === $pdo->query( 'SELECT name FROM wp_users WHERE id = 1' )->fetchColumn(), 'core replacement rollback preserves existing rows' );
mdi_warm_sync_remove_dir( $root );

// Plugin tables use the same full replacement contract.
[ $root, $pdo ] = mdi_warm_sync_fixture();
$pdo->exec( 'CREATE TABLE wp_plugin_jobs (id INTEGER PRIMARY KEY, name TEXT)' );
$pdo->exec( "INSERT INTO wp_plugin_jobs (id, name) VALUES (1, 'before')" );
[ $loader, $sync ] = mdi_warm_sync_loader( $pdo, $root );
mdi_warm_sync_changed_snapshot( $pdo, $root, 'plugin_jobs' );
mdi_warm_sync_assert( mdi_warm_sync_run( $sync, $loader ), 'malformed changed plugin snapshot fails warm sync' );
mdi_warm_sync_assert( 'before' === $pdo->query( 'SELECT name FROM wp_plugin_jobs WHERE id = 1' )->fetchColumn(), 'plugin replacement rollback preserves existing rows' );
mdi_warm_sync_remove_dir( $root );

// Posts deletes only non-markdown rows, but that partition must also roll back.
[ $root, $pdo ] = mdi_warm_sync_fixture();
$pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_title TEXT)' );
$pdo->exec( "INSERT INTO wp_posts (ID, post_type, post_title) VALUES (1, 'wiki', 'markdown')" );
$pdo->exec( "INSERT INTO wp_posts (ID, post_type, post_title) VALUES (2, 'revision', 'before')" );
[ $loader, $sync ] = mdi_warm_sync_loader( $pdo, $root );
mdi_warm_sync_changed_snapshot( $pdo, $root, 'posts' );
mdi_warm_sync_assert( mdi_warm_sync_run( $sync, $loader ), 'malformed changed posts partition snapshot fails warm sync' );
mdi_warm_sync_assert( 2 === (int) $pdo->query( 'SELECT COUNT(*) FROM wp_posts' )->fetchColumn(), 'posts partition rollback preserves markdown and non-markdown rows' );
mdi_warm_sync_remove_dir( $root );

// Postmeta deletes rows through the non-markdown wp_posts partition.
[ $root, $pdo ] = mdi_warm_sync_fixture();
$pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT)' );
$pdo->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_value TEXT)' );
$pdo->exec( "INSERT INTO wp_posts (ID, post_type) VALUES (1, 'wiki'), (2, 'revision')" );
$pdo->exec( "INSERT INTO wp_postmeta (meta_id, post_id, meta_value) VALUES (1, 1, 'markdown'), (2, 2, 'before')" );
[ $loader, $sync ] = mdi_warm_sync_loader( $pdo, $root );
file_put_contents( $root . '/_tables/postmeta.json', '[{"meta_id":3,"post_id":2,"meta_value":"new"}' );
$pdo->prepare( 'INSERT INTO _json_file_manifest (file_name, file_mtime, file_size) VALUES (?, ?, ?)' )
	->execute( array( '_tables/postmeta.json', 0, 0 ) );
mdi_warm_sync_assert( mdi_warm_sync_run( $sync, $loader ), 'malformed changed postmeta partition snapshot fails warm sync' );
mdi_warm_sync_assert( 2 === (int) $pdo->query( 'SELECT COUNT(*) FROM wp_postmeta' )->fetchColumn(), 'postmeta partition rollback preserves all existing rows' );
mdi_warm_sync_remove_dir( $root );

if ( ! empty( $failures ) ) {
	exit( 1 );
}
