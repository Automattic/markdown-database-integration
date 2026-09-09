<?php
/** Independent native-runtime writers must serialize across a rollback. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_isolation_runtime( string $root ): WP_Markdown_Native_Query_Runtime {
	return WP_Markdown_Native_Runtime_Factory::runtime( $root );
}

function mdi_isolation_request( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): WP_Markdown_Query_Result {
	return $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
}

function mdi_isolation_remove_tree( string $root ): void {
	$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}

if ( isset( $argv[1] ) && in_array( $argv[1], array( 'first', 'second' ), true ) ) {
	$root = $argv[2];
	$runtime = mdi_isolation_runtime( $root );
	if ( 'first' === $argv[1] ) {
		mdi_isolation_request( $runtime, 'BEGIN' );
		mdi_isolation_request( $runtime, "UPDATE wp_options SET option_value = 'first' WHERE option_name = 'isolation'" );
		file_put_contents( $root . '/first-ready', '1' );
		for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/release-first' ); ++$attempt ) {
			usleep( 10000 );
		}
		exit( is_file( $root . '/release-first' ) && 0 === mdi_isolation_request( $runtime, 'ROLLBACK' )->return_value() ? 0 : 1 );
	}
	$result = mdi_isolation_request( $runtime, "UPDATE wp_options SET option_value = 'second' WHERE option_name = 'isolation'" );
	exit( 1 === $result->return_value() ? 0 : 1 );
}

$root = sys_get_temp_dir() . '/mdi-native-write-isolation-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
$setup = mdi_isolation_runtime( $root );
mdi_isolation_request( $setup, "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('isolation', 'base', 'yes')" );
$first = proc_open( array( PHP_BINARY, __FILE__, 'first', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $first_pipes );
for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/first-ready' ); ++$attempt ) {
	usleep( 10000 );
}
$second = is_file( $root . '/first-ready' ) ? proc_open( array( PHP_BINARY, __FILE__, 'second', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $second_pipes ) : false;
usleep( 200000 );
$second_waiting = is_resource( $second ) && proc_get_status( $second )['running'];
file_put_contents( $root . '/release-first', '1' );
$first_status = is_resource( $first ) ? proc_close( $first ) : 1;
$second_status = is_resource( $second ) ? proc_close( $second ) : 1;
$final = mdi_isolation_request( mdi_isolation_runtime( $root ), "SELECT option_value FROM wp_options WHERE option_name = 'isolation'" );
$rows = $final->wpdb_state()['last_result'] ?? array();
$value = is_array( $rows ) ? ( (array) ( $rows[0] ?? array() ) )['option_value'] ?? null : null;
$checks = array(
	'writer B waits while writer A owns a rollback journal' => $second_waiting,
	'writer A rolls back successfully' => 0 === $first_status,
	'writer B completes after writer A releases ownership' => 0 === $second_status,
	'writer B committed value survives writer A rollback' => 'second' === $value,
);
mdi_isolation_remove_tree( $root );
$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
