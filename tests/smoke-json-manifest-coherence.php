<?php
/**
 * Smoke tests for write-engine and warm-sync JSON manifest coherence.
 *
 * Usage: php tests/smoke-json-manifest-coherence.php
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

class MDI_Manifest_Connection {
	public function __construct( private PDO $pdo ) {}

	public function get_pdo(): PDO {
		return $this->pdo;
	}
}

class WP_SQLite_Driver {
	public function __construct( private MDI_Manifest_Connection $connection ) {}

	public function get_connection(): MDI_Manifest_Connection {
		return $this->connection;
	}
}

class WP_Markdown_Storage {
	public function __construct( private string $content_dir ) {}

	public function get_excluded_types(): array {
		return array();
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-write-engine.php';
require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';

$failures = array();

function mdi_manifest_assert( bool $condition, string $message ): void {
	global $failures;
	if ( $condition ) {
		echo 'PASS: ' . $message . PHP_EOL;
		return;
	}
	$failures[] = $message;
	echo 'FAIL: ' . $message . PHP_EOL;
}

function mdi_manifest_remove_dir( string $dir ): void {
	foreach ( glob( $dir . '/*' ) ?: array() as $path ) {
		is_dir( $path ) ? mdi_manifest_remove_dir( $path ) : unlink( $path );
	}
	rmdir( $dir );
}

$root = sys_get_temp_dir() . '/mdi-json-manifest-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root . '/_tables', 0755, true );

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE _json_file_manifest (file_name TEXT PRIMARY KEY, file_mtime INTEGER NOT NULL, file_size INTEGER NOT NULL)' );
$pdo->exec( 'CREATE TABLE wp_plugin_jobs (id INTEGER PRIMARY KEY, name TEXT)' );
$pdo->exec( "INSERT INTO wp_plugin_jobs (id, name) VALUES (1, 'own')" );
$pdo->exec( 'CREATE TABLE hydration_audit (count INTEGER NOT NULL)' );
$pdo->exec( 'INSERT INTO hydration_audit (count) VALUES (0)' );
$pdo->exec( 'CREATE TRIGGER plugin_jobs_hydrated AFTER DELETE ON wp_plugin_jobs BEGIN UPDATE hydration_audit SET count = count + 1; END' );

$driver  = new WP_SQLite_Driver( new MDI_Manifest_Connection( $pdo ) );
$storage = new WP_Markdown_Storage( $root );
$engine  = new WP_Markdown_Write_Engine( $root, $storage, $driver, 'wp_' );
$write   = new ReflectionMethod( $engine, 'write_json' );
$path    = $root . '/_tables/plugin_jobs.json';

$write->invoke( $engine, $path, array( array( 'id' => 1, 'name' => 'own' ) ) );
clearstatcache( true, $path );
$manifest = $pdo->query( "SELECT file_mtime, file_size FROM _json_file_manifest WHERE file_name = '_tables/plugin_jobs.json'" )->fetch( PDO::FETCH_ASSOC );
mdi_manifest_assert( (int) $manifest['file_mtime'] === (int) filemtime( $path ) && (int) $manifest['file_size'] === (int) filesize( $path ), 'successful table snapshot records its final mtime and size' );

$loader = new WP_Markdown_Loader( $root, $driver, $storage, 'wp_' );
$sync   = new ReflectionMethod( $loader, 'sync_json_tables' );
$sync->invoke( $loader );
mdi_manifest_assert( 0 === (int) $pdo->query( 'SELECT count FROM hydration_audit' )->fetchColumn(), 'warm sync skips hydration after an own snapshot write' );

$own_identity = $manifest;
file_put_contents( $path, "[\n    {\"id\": 2, \"name\": \"external\"}\n]\n" );
clearstatcache( true, $path );
$external_identity = array( 'file_mtime' => (int) filemtime( $path ), 'file_size' => (int) filesize( $path ) );
$update_manifest   = new ReflectionMethod( $engine, 'update_json_manifest' );
$update_manifest->invoke( $engine, $path, (int) $own_identity['file_mtime'], (int) $own_identity['file_size'] );
$manifest = $pdo->query( "SELECT file_mtime, file_size FROM _json_file_manifest WHERE file_name = '_tables/plugin_jobs.json'" )->fetch( PDO::FETCH_ASSOC );
mdi_manifest_assert(
	(int) $manifest['file_mtime'] === (int) $own_identity['file_mtime']
	&& (int) $manifest['file_size'] === (int) $own_identity['file_size']
	&& (int) $manifest['file_size'] !== $external_identity['file_size'],
	'manifest records the supplied own snapshot identity after destination replacement'
);
$sync->invoke( $loader );
mdi_manifest_assert( 'external' === $pdo->query( 'SELECT name FROM wp_plugin_jobs WHERE id = 2' )->fetchColumn(), 'warm sync hydrates an external snapshot modification' );

$pdo->prepare( 'INSERT OR REPLACE INTO _json_file_manifest (file_name, file_mtime, file_size) VALUES (?, ?, ?)' )
	->execute( array( '_tables/failed.json', 123, 456 ) );
mkdir( $root . '/_tables/failed.json' );
try {
	$write->invoke( $engine, $root . '/_tables/failed.json', array( array( 'id' => 3 ) ) );
} catch ( Throwable ) {
	// The failed rename is the expected atomic-write failure.
}
$failed_manifest = $pdo->query( "SELECT file_mtime, file_size FROM _json_file_manifest WHERE file_name = '_tables/failed.json'" )->fetch( PDO::FETCH_ASSOC );
mdi_manifest_assert( 123 === (int) $failed_manifest['file_mtime'] && 456 === (int) $failed_manifest['file_size'], 'failed atomic snapshot write preserves the previous manifest entry' );

mdi_manifest_remove_dir( $root );

if ( ! empty( $failures ) ) {
	exit( 1 );
}
