<?php
/** A loaded generic snapshot must refresh after its reader waits for a commit. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_read_isolation_runtime( string $root ): WP_Markdown_Native_Query_Runtime {
	return WP_Markdown_Native_Runtime_Factory::runtime( $root );
}

function mdi_read_isolation_query( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): WP_Markdown_Query_Result {
	return $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
}

function mdi_read_isolation_value( WP_Markdown_Native_Query_Runtime $runtime ): ?string {
	$rows = mdi_read_isolation_query( $runtime, "SELECT label FROM wp_reservations WHERE resource_key = 'identity'" )->wpdb_state()['last_result'];
	return $rows[0]->label ?? null;
}

if ( isset( $argv[1], $argv[2] ) && in_array( $argv[1], array( 'writer', 'reader' ), true ) ) {
	$root = $argv[2];
	$runtime = mdi_read_isolation_runtime( $root );
	if ( 'writer' === $argv[1] ) {
		$begin = mdi_read_isolation_query( $runtime, 'SET autocommit = 0' );
		$write = mdi_read_isolation_query( $runtime, "UPDATE wp_reservations SET label = 'committed' WHERE resource_key = 'identity'" );
		file_put_contents( $root . '/writer-ready', '1' );
		for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/commit-writer' ); ++$attempt ) { usleep( 10000 ); }
		$commit = mdi_read_isolation_query( $runtime, 'COMMIT' );
		exit( 0 === $begin->return_value() && 1 === $write->return_value() && is_file( $root . '/commit-writer' ) && 0 === $commit->return_value() ? 0 : 1 );
	}
	$before = mdi_read_isolation_value( $runtime );
	file_put_contents( $root . '/reader-loaded', '1' );
	for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/read-again' ); ++$attempt ) { usleep( 10000 ); }
	$after = mdi_read_isolation_value( $runtime );
	file_put_contents( $root . '/reader.json', json_encode( array( 'before' => $before, 'after' => $after ), JSON_THROW_ON_ERROR ) );
	exit( 'base' === $before && 'committed' === $after ? 0 : 1 );
}

$root = sys_get_temp_dir() . '/mdi-native-read-isolation-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0755, true );
$setup = mdi_read_isolation_runtime( $root );
mdi_read_isolation_query( $setup, 'CREATE TABLE wp_reservations (resource_key VARCHAR(100) NOT NULL, label VARCHAR(100) NOT NULL, PRIMARY KEY (resource_key))' );
mdi_read_isolation_query( $setup, "INSERT INTO wp_reservations (resource_key, label) VALUES ('identity', 'base')" );
$reader = proc_open( array( PHP_BINARY, __FILE__, 'reader', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $reader_pipes );
for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/reader-loaded' ); ++$attempt ) { usleep( 10000 ); }
$writer = is_file( $root . '/reader-loaded' ) ? proc_open( array( PHP_BINARY, __FILE__, 'writer', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $writer_pipes ) : false;
for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/writer-ready' ); ++$attempt ) { usleep( 10000 ); }
file_put_contents( $root . '/read-again', '1' );
usleep( 200000 );
$reader_waiting = is_resource( $reader ) && proc_get_status( $reader )['running'];
file_put_contents( $root . '/commit-writer', '1' );
$writer_status = is_resource( $writer ) ? proc_close( $writer ) : 1;
$reader_status = is_resource( $reader ) ? proc_close( $reader ) : 1;
$values = json_decode( (string) @file_get_contents( $root . '/reader.json' ), true );

mdi_read_isolation_query( $setup, "UPDATE wp_reservations SET label = 'base' WHERE resource_key = 'identity'" );
@unlink( $root . '/reader-loaded' );
@unlink( $root . '/writer-ready' );
@unlink( $root . '/read-again' );
@unlink( $root . '/commit-writer' );
@unlink( $root . '/reader.json' );
$idle_reader = proc_open( array( PHP_BINARY, __FILE__, 'reader', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $idle_reader_pipes );
for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/reader-loaded' ); ++$attempt ) { usleep( 10000 ); }
$idle_writer = is_file( $root . '/reader-loaded' ) ? proc_open( array( PHP_BINARY, __FILE__, 'writer', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $idle_writer_pipes ) : false;
for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/writer-ready' ); ++$attempt ) { usleep( 10000 ); }
file_put_contents( $root . '/commit-writer', '1' );
$idle_writer_status = is_resource( $idle_writer ) ? proc_close( $idle_writer ) : 1;
file_put_contents( $root . '/read-again', '1' );
$idle_reader_status = is_resource( $idle_reader ) ? proc_close( $idle_reader ) : 1;
$idle_values = json_decode( (string) @file_get_contents( $root . '/reader.json' ), true );

$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $entries as $entry ) { $entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() ); }
rmdir( $root );
$checks = array(
	'autocommit-off writer retains admission through commit' => $reader_waiting,
	'waiting reader reloads its generic snapshot after commit' => 0 === $writer_status && 0 === $reader_status && array( 'before' => 'base', 'after' => 'committed' ) === $values,
	'idle reader reloads its generic snapshot at its next canonical admission' => 0 === $idle_writer_status && 0 === $idle_reader_status && array( 'before' => 'base', 'after' => 'committed' ) === $idle_values,
);
$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) { fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) ); }
exit( $passed ? 0 : 1 );
