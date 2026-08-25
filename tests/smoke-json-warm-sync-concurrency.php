<?php
/**
 * Deterministic concurrency coverage for manifest-aware JSON hydration.
 *
 * Usage: php tests/smoke-json-warm-sync-concurrency.php
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

class MDI_Concurrent_Hydration_Connection {
	public function __construct( private PDO $pdo ) {}

	public function get_pdo(): PDO {
		return $this->pdo;
	}
}

class WP_MySQL_On_SQLite {
	public function __construct( private MDI_Concurrent_Hydration_Connection $connection ) {}

	public function get_connection(): MDI_Concurrent_Hydration_Connection {
		return $this->connection;
	}
}

class WP_Markdown_Storage {
	public function get_excluded_types(): array {
		return array();
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';

class MDI_Gated_Loader extends WP_Markdown_Loader {
	public function __construct( private bool $wait_for_release ) {}

	public function sync_incremental(): void {
		if ( $this->wait_for_release ) {
			echo "OWNED\n";
			flush();
			fgets( STDIN );
		}
	}
}

function mdi_concurrent_pdo( string $database ): PDO {
	$pdo = new PDO( 'sqlite:' . $database );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$pdo->exec( 'PRAGMA busy_timeout = 10000' );
	return $pdo;
}

function mdi_concurrent_loader( PDO $pdo, string $root ): array {
	$loader = new WP_Markdown_Loader(
		$root,
		new WP_MySQL_On_SQLite( new MDI_Concurrent_Hydration_Connection( $pdo ) ),
		new WP_Markdown_Storage()
	);
	return array( $loader, new ReflectionMethod( $loader, 'load_table_from_json' ) );
}

function mdi_concurrent_remove_dir( string $dir ): void {
	foreach ( glob( $dir . '/*' ) ?: array() as $path ) {
		is_dir( $path ) ? mdi_concurrent_remove_dir( $path ) : unlink( $path );
	}
	rmdir( $dir );
}

if ( 'worker' === ( $argv[1] ?? '' ) ) {
	$root = $argv[2];
	$pdo  = mdi_concurrent_pdo( $argv[3] );
	[ $loader, $load_table ] = mdi_concurrent_loader( $pdo, $root );

	echo "READY\n";
	flush();
	fgets( STDIN );
	echo "INVOKING\n";
	flush();
	$load_table->invoke(
		$loader,
		'plugin_jobs',
		static function () use ( $pdo ): void {
			$pdo->exec( 'INSERT INTO hydration_attempts DEFAULT VALUES' );
			$pdo->exec( 'DELETE FROM wp_plugin_jobs' );
		}
	);
	echo "SUCCESS\n";
	exit( 0 );
}

if ( 'gate-owner' === ( $argv[1] ?? '' ) ) {
	$loader = new MDI_Gated_Loader( true );
	$loader->sync_incremental_if_available( $argv[2] );
	echo "RELEASED\n";
	exit( 0 );
}

$failures = array();

function mdi_concurrent_assert( bool $condition, string $message ): void {
	global $failures;
	if ( $condition ) {
		echo 'PASS: ' . $message . PHP_EOL;
		return;
	}
	$failures[] = $message;
	echo 'FAIL: ' . $message . PHP_EOL;
}

$root = sys_get_temp_dir() . '/mdi-concurrent-hydration-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root . '/_tables', 0755, true );
$database = $root . '/shared.sqlite';
$file     = $root . '/_tables/plugin_jobs.json';
$snapshot = '[{"id":1,"name":"first"},{"id":2,"name":"second"}]';
file_put_contents( $file, $snapshot );

$pdo = mdi_concurrent_pdo( $database );
$pdo->exec( 'CREATE TABLE wp_plugin_jobs (id INTEGER PRIMARY KEY, name TEXT)' );
$pdo->exec( 'CREATE TABLE hydration_attempts (id INTEGER PRIMARY KEY)' );
$pdo->exec( 'CREATE TABLE _json_file_manifest (file_name TEXT PRIMARY KEY, file_mtime INTEGER NOT NULL, file_size INTEGER NOT NULL)' );
$pdo->exec( "INSERT INTO wp_plugin_jobs (id, name) VALUES (99, 'stale')" );
$pdo->exec( "INSERT INTO _json_file_manifest (file_name, file_mtime, file_size) VALUES ('_tables/plugin_jobs.json', 0, 0)" );

// Primary warm synchronization has one non-blocking process owner.
$gate_pipes = array();
$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
$gate_process = proc_open( array( PHP_BINARY, __FILE__, 'gate-owner', $database ), $descriptors, $gate_pipes );
if ( ! is_resource( $gate_process ) ) { throw new RuntimeException( 'Failed to start synchronization owner.' ); }
mdi_concurrent_assert( "OWNED\n" === fgets( $gate_pipes[1] ), 'one process acquires warm synchronization ownership' );
$gate_contender = new MDI_Gated_Loader( false );
$gate_start = microtime( true );
$gate_acquired = $gate_contender->sync_incremental_if_available( $database );
$gate_elapsed = microtime( true ) - $gate_start;
$gate_stats = $gate_contender->get_stats();
mdi_concurrent_assert( false === $gate_acquired && $gate_elapsed < 0.1, 'contending warm bootstrap immediately retains the previous index' );
mdi_concurrent_assert( 'synchronizer_active' === ( $gate_stats['sync_error'] ?? null ), 'contending warm bootstrap reports active synchronizer evidence' );
fwrite( $gate_pipes[0], "RELEASE\n" );
fflush( $gate_pipes[0] );
fclose( $gate_pipes[0] );
$gate_stdout = stream_get_contents( $gate_pipes[1] );
$gate_stderr = stream_get_contents( $gate_pipes[2] );
fclose( $gate_pipes[1] );
fclose( $gate_pipes[2] );
mdi_concurrent_assert( 0 === proc_close( $gate_process ) && "RELEASED\n" === $gate_stdout, 'synchronization owner releases cleanly' . ( '' === $gate_stderr ? '' : ': ' . trim( $gate_stderr ) ) );
mdi_concurrent_assert( true === ( new MDI_Gated_Loader( false ) )->sync_incremental_if_available( $database ), 'a later warm bootstrap can acquire released ownership' );

// An unchanged snapshot must not request write ownership from a busy database.
clearstatcache( true, $file );
$pdo->prepare( "UPDATE _json_file_manifest SET file_mtime = ?, file_size = ? WHERE file_name = '_tables/plugin_jobs.json'" )
	->execute( array( (int) filemtime( $file ), (int) filesize( $file ) ) );
$pdo->exec( 'BEGIN IMMEDIATE' );
$unchanged_pdo = mdi_concurrent_pdo( $database );
$unchanged_pdo->exec( 'PRAGMA busy_timeout = 100' );
[ $unchanged_loader, $unchanged_load_table ] = mdi_concurrent_loader( $unchanged_pdo, $root );
$unchanged_error = null;
$unchanged_start = microtime( true );
try {
	$unchanged_load_table->invoke( $unchanged_loader, 'plugin_jobs' );
} catch ( Throwable $error ) {
	$unchanged_error = $error;
}
$unchanged_elapsed = microtime( true ) - $unchanged_start;
mdi_concurrent_assert( null === $unchanged_error, 'unchanged snapshot bypasses an active SQLite writer' );
mdi_concurrent_assert( $unchanged_elapsed < 0.1, 'unchanged snapshot returns before the SQLite busy timeout' );
$pdo->exec( 'COMMIT' );
unset( $unchanged_pdo );
$pdo->exec( "UPDATE _json_file_manifest SET file_mtime = 0, file_size = 0 WHERE file_name = '_tables/plugin_jobs.json'" );

// Hold write ownership until both children are immediately ready to hydrate.
$pdo->exec( 'BEGIN IMMEDIATE' );
$workers = array();
$descriptors = array(
	0 => array( 'pipe', 'r' ),
	1 => array( 'pipe', 'w' ),
	2 => array( 'pipe', 'w' ),
);
for ( $i = 0; $i < 2; $i++ ) {
	$pipes   = array();
	$process = proc_open( array( PHP_BINARY, __FILE__, 'worker', $root, $database ), $descriptors, $pipes );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Failed to start hydration worker.' );
	}
	$workers[] = array( $process, $pipes );
}

foreach ( $workers as [ , $pipes ] ) {
	mdi_concurrent_assert( "READY\n" === fgets( $pipes[1] ), 'worker reached the manifest-aware hydration barrier' );
}
foreach ( $workers as [ , $pipes ] ) {
	fwrite( $pipes[0], "GO\n" );
	fflush( $pipes[0] );
}
foreach ( $workers as [ , $pipes ] ) {
	mdi_concurrent_assert( "INVOKING\n" === fgets( $pipes[1] ), 'worker was released to acquire hydration ownership' );
}
$pdo->exec( 'COMMIT' );

foreach ( $workers as [ $process, $pipes ] ) {
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );
	mdi_concurrent_assert( 0 === $status && "SUCCESS\n" === $stdout, 'queued hydration worker succeeds' . ( '' === $stderr ? '' : ': ' . trim( $stderr ) ) );
}

clearstatcache( true, $file );
$manifest = $pdo->query( "SELECT file_mtime, file_size FROM _json_file_manifest WHERE file_name = '_tables/plugin_jobs.json'" )->fetch( PDO::FETCH_ASSOC );
mdi_concurrent_assert( 1 === (int) $pdo->query( 'SELECT COUNT(*) FROM hydration_attempts' )->fetchColumn(), 'exactly one queued worker replaces and hydrates the stale snapshot' );
mdi_concurrent_assert( array( '1:first', '2:second' ) === $pdo->query( "SELECT id || ':' || name FROM wp_plugin_jobs ORDER BY id" )->fetchAll( PDO::FETCH_COLUMN ), 'final rows match the snapshot' );
mdi_concurrent_assert( (int) filemtime( $file ) === (int) $manifest['file_mtime'] && (int) filesize( $file ) === (int) $manifest['file_size'], 'final manifest matches the hydrated file identity' );

// Replace the canonical path after destructive hydration starts but before parsing.
$pdo->exec( "DELETE FROM wp_plugin_jobs; INSERT INTO wp_plugin_jobs (id, name) VALUES (77, 'preserved')" );
$pdo->exec( "UPDATE _json_file_manifest SET file_mtime = 7, file_size = 8 WHERE file_name = '_tables/plugin_jobs.json'" );
$replacement = $root . '/_tables/plugin_jobs.replacement';
$thrown      = null;
[ $loader, $load_table ] = mdi_concurrent_loader( $pdo, $root );
try {
	$load_table->invoke(
		$loader,
		'plugin_jobs',
		static function () use ( $pdo, $replacement, $file ): void {
			$pdo->exec( 'DELETE FROM wp_plugin_jobs' );
			file_put_contents( $replacement, '[{"id":3,"name":"changed-during-hydration-with-a-distinct-size"}]' );
			rename( $replacement, $file );
		}
	);
} catch ( RuntimeException $e ) {
	$thrown = $e;
}

mdi_concurrent_assert( $thrown instanceof RuntimeException, 'canonical file mutation during hydration fails deterministically' );
mdi_concurrent_assert( array( '77:preserved' ) === $pdo->query( "SELECT id || ':' || name FROM wp_plugin_jobs" )->fetchAll( PDO::FETCH_COLUMN ), 'file mutation rolls back replacement rows' );
mdi_concurrent_assert( '7:8' === $pdo->query( "SELECT file_mtime || ':' || file_size FROM _json_file_manifest WHERE file_name = '_tables/plugin_jobs.json'" )->fetchColumn(), 'file mutation rolls back the manifest' );

// Busy warm sync retains the complete previous index instead of amplifying the
// contention into a cold reconstruction.
$pdo->exec( 'BEGIN IMMEDIATE' );
$contended_pdo = mdi_concurrent_pdo( $database );
$contended_pdo->exec( 'PRAGMA busy_timeout = 100' );
[ $contended_loader ] = mdi_concurrent_loader( $contended_pdo, $root );
$contended_start = microtime( true );
$contended_loader->sync_incremental();
$contended_elapsed = microtime( true ) - $contended_start;
$contended_stats = $contended_loader->get_stats();
mdi_concurrent_assert( $contended_elapsed < 1.0, 'contended warm sync remains bounded' );
mdi_concurrent_assert( 'warm' === ( $contended_stats['boot_mode'] ?? null ), 'contended warm sync does not invoke cold reconstruction' );
mdi_concurrent_assert( 'retained_previous_index' === ( $contended_stats['sync_status'] ?? null ), 'contended warm sync retains the previous complete index' );
mdi_concurrent_assert( 'canonical_store_busy' === ( $contended_stats['sync_error'] ?? null ), 'contended warm sync reports typed busy evidence' );
$pdo->exec( 'COMMIT' );
unset( $contended_pdo );

unset( $pdo );
mdi_concurrent_remove_dir( $root );

if ( ! empty( $failures ) ) {
	exit( 1 );
}
