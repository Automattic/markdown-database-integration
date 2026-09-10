<?php
/** Verify bounded cross-process GET_LOCK exclusion, timeout, and handoff. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_advisory_process_value( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): ?string {
	$rows = $runtime->execute( new WP_Markdown_Query_Request( $sql ) )->wpdb_state()['last_result'];
	return isset( $rows[0] ) ? current( get_object_vars( $rows[0] ) ) : null;
}

if ( isset( $argv[1], $argv[2] ) && in_array( $argv[1], array( 'owner', 'waiter', 'timeout' ), true ) ) {
	$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $argv[2] );
	if ( 'owner' === $argv[1] ) {
		$acquired = mdi_advisory_process_value( $runtime, "SELECT GET_LOCK('reservation-identity', 0)" );
		file_put_contents( $argv[2] . '/owner-ready', '1' );
		for ( $attempt = 0; $attempt < 1000 && ! is_file( $argv[2] . '/release-owner' ); ++$attempt ) { usleep( 10000 ); }
		exit( '1' === $acquired && is_file( $argv[2] . '/release-owner' ) && '1' === mdi_advisory_process_value( $runtime, "SELECT RELEASE_LOCK('reservation-identity')" ) ? 0 : 1 );
	}
	$value = mdi_advisory_process_value( $runtime, "SELECT GET_LOCK('reservation-identity', " . ( 'waiter' === $argv[1] ? '2' : '0.1' ) . ')' );
	exit( ( 'waiter' === $argv[1] ? '1' : '0' ) === $value ? 0 : 1 );
}

$root = sys_get_temp_dir() . '/mdi-native-advisory-processes-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0755, true );
$owner = proc_open( array( PHP_BINARY, __FILE__, 'owner', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $owner_pipes );
for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/owner-ready' ); ++$attempt ) { usleep( 10000 ); }
$timeout = is_file( $root . '/owner-ready' ) ? proc_open( array( PHP_BINARY, __FILE__, 'timeout', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $timeout_pipes ) : false;
$timeout_status = is_resource( $timeout ) ? proc_close( $timeout ) : 1;
$waiter = is_file( $root . '/owner-ready' ) ? proc_open( array( PHP_BINARY, __FILE__, 'waiter', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $waiter_pipes ) : false;
usleep( 200000 );
$waiter_waiting = is_resource( $waiter ) && proc_get_status( $waiter )['running'];
file_put_contents( $root . '/release-owner', '1' );
$owner_status = is_resource( $owner ) ? proc_close( $owner ) : 1;
$waiter_status = is_resource( $waiter ) ? proc_close( $waiter ) : 1;

$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $entries as $entry ) { $entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() ); }
rmdir( $root );
$checks = array(
	'contending process receives zero after its bounded timeout' => 0 === $timeout_status,
	'waiter remains excluded until the owning process releases' => $waiter_waiting,
	'waiter acquires after cross-process release' => 0 === $owner_status && 0 === $waiter_status,
);
$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) { fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) ); }
exit( $passed ? 0 : 1 );
