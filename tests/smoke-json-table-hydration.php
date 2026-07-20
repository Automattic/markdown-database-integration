<?php
/**
 * Smoke coverage for bounded and atomic JSON table hydration.
 *
 * Usage: php tests/smoke-json-table-hydration.php
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
require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';

class MDI_Json_Hydration_Connection {
	public function __construct( private PDO $pdo ) {}

	public function get_pdo(): PDO {
		return $this->pdo;
	}
}

class WP_SQLite_Driver {
	public function __construct( private MDI_Json_Hydration_Connection $connection ) {}

	public function get_connection(): MDI_Json_Hydration_Connection {
		return $this->connection;
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';

$failures = array();

function mdi_json_hydration_assert( bool $condition, string $message ): void {
	global $failures;
	if ( $condition ) {
		echo 'PASS: ' . $message . PHP_EOL;
		return;
	}
	$failures[] = $message;
	echo 'FAIL: ' . $message . PHP_EOL;
}

function mdi_json_hydration_remove_dir( string $dir ): void {
	foreach ( glob( $dir . '/*' ) ?: array() as $path ) {
		is_dir( $path ) ? mdi_json_hydration_remove_dir( $path ) : unlink( $path );
	}
	rmdir( $dir );
}

$root = sys_get_temp_dir() . '/mdi-json-hydration-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root . '/_tables', 0755, true );

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE wp_streamed_jobs (id INTEGER PRIMARY KEY, payload TEXT, state TEXT)' );
$pdo->exec( 'CREATE TABLE wp_truncated_jobs (id INTEGER PRIMARY KEY, payload TEXT)' );
$pdo->exec( 'CREATE TABLE wp_empty_jobs (id INTEGER PRIMARY KEY)' );
$pdo->exec( 'CREATE TABLE wp_oversized_jobs (id INTEGER PRIMARY KEY, payload TEXT)' );
$pdo->exec( "INSERT INTO wp_truncated_jobs (id, payload) VALUES (999, 'existing')" );
$pdo->exec( "INSERT INTO wp_oversized_jobs (id, payload) VALUES (999, 'existing')" );

$loader = new WP_Markdown_Loader(
	$root,
	new WP_SQLite_Driver( new MDI_Json_Hydration_Connection( $pdo ) ),
	new WP_Markdown_Storage( $root )
);
$load_table = new ReflectionMethod( $loader, 'load_table_from_json' );

$load_table->invoke( $loader, 'missing_jobs' );
file_put_contents( $root . '/_tables/empty_jobs.json', '' );
$load_table->invoke( $loader, 'empty_jobs' );
mdi_json_hydration_assert( 0 === (int) $pdo->query( 'SELECT COUNT(*) FROM wp_empty_jobs' )->fetchColumn(), 'missing and empty snapshots preserve no-op hydration behavior' );

// Generate a 24 MiB snapshot without retaining its rows in the test process.
$streamed_file = $root . '/_tables/streamed_jobs.json';
$stream        = fopen( $streamed_file, 'wb' );
fwrite( $stream, '[' );
for ( $id = 1; $id <= 12000; $id++ ) {
	if ( 1 !== $id ) {
		fwrite( $stream, ',' );
	}
	fwrite( $stream, json_encode( array( 'id' => $id, 'payload' => str_pad( (string) $id, 2048, 'x' ), 'state' => 'pending' ), JSON_THROW_ON_ERROR ) );
}
fwrite( $stream, ',{"id":1,"payload":"ignored","state":"duplicate"}]' );
fclose( $stream );

$memory_before = memory_get_usage( true );
$load_table->invoke( $loader, 'streamed_jobs' );
$memory_delta = memory_get_peak_usage( true ) - $memory_before;

mdi_json_hydration_assert( 12000 === (int) $pdo->query( 'SELECT COUNT(*) FROM wp_streamed_jobs' )->fetchColumn(), 'large snapshots hydrate all rows and preserve INSERT OR IGNORE' );
mdi_json_hydration_assert( 'pending' === $pdo->query( 'SELECT state FROM wp_streamed_jobs WHERE id = 1' )->fetchColumn(), 'duplicate rows do not replace the first row' );
mdi_json_hydration_assert( $memory_delta < 8 * 1024 * 1024, 'peak hydration memory stays bounded below 8 MiB above baseline' );

file_put_contents( $root . '/_tables/truncated_jobs.json', '[{"id":1,"payload":"first"},{"id":2,"payload":"second"}' );
$thrown = null;
try {
	$load_table->invoke( $loader, 'truncated_jobs' );
} catch ( RuntimeException $e ) {
	$thrown = $e;
}

mdi_json_hydration_assert( $thrown instanceof RuntimeException, 'truncated snapshots fail deterministically' );
mdi_json_hydration_assert( 1 === (int) $pdo->query( 'SELECT COUNT(*) FROM wp_truncated_jobs' )->fetchColumn(), 'truncated snapshots roll back inserted rows' );
mdi_json_hydration_assert( 'existing' === $pdo->query( 'SELECT payload FROM wp_truncated_jobs WHERE id = 999' )->fetchColumn(), 'rollback preserves existing table state' );

// JSON Machine retains one decoded row, so valid rows larger than 1 MiB remain supported.
$oversized_file = $root . '/_tables/oversized_jobs.json';
$stream         = fopen( $oversized_file, 'wb' );
fwrite( $stream, '[{"id":1,"payload":"' );
for ( $i = 0; $i < 256; $i++ ) {
	fwrite( $stream, str_repeat( 'x', 8192 ) );
}
fwrite( $stream, '"}]' );
fclose( $stream );
$load_table->invoke( $loader, 'oversized_jobs' );

mdi_json_hydration_assert( 2 === (int) $pdo->query( 'SELECT COUNT(*) FROM wp_oversized_jobs' )->fetchColumn(), 'rows larger than 1 MiB hydrate successfully' );
mdi_json_hydration_assert( 2097152 === (int) $pdo->query( 'SELECT LENGTH(payload) FROM wp_oversized_jobs WHERE id = 1' )->fetchColumn(), 'large row payload remains intact' );

mdi_json_hydration_remove_dir( $root );

if ( ! empty( $failures ) ) {
	exit( 1 );
}
