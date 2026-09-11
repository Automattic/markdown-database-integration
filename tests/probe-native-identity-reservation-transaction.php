<?php
/**
 * #377 conformance probe for reservation JSON plus its canonical post shell.
 *
 * It requires transaction admission before reservation reads and committed-only
 * visibility across the JSON reservation and its Markdown post shell.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_reservation_runtime( string $root ): WP_Markdown_Native_Query_Runtime {
	return WP_Markdown_Native_Runtime_Factory::runtime( $root );
}

function mdi_reservation_query( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): WP_Markdown_Query_Result {
	return $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
}

function mdi_reservation_remove_tree( string $root ): void {
	$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}

function mdi_reservation_value( WP_Markdown_Native_Query_Runtime $runtime, string $sql, string $column ): ?string {
	$result = mdi_reservation_query( $runtime, $sql );
	$row = $result->wpdb_state()['last_result'][0] ?? null;
	return is_object( $row ) ? ( $row->{$column} ?? null ) : null;
}

if ( isset( $argv[1], $argv[2] ) && in_array( $argv[1], array( 'crash-owner', 'read-owner', 'writer', 'observer' ), true ) ) {
	$mode = $argv[1];
	$root = $argv[2];
	$runtime = mdi_reservation_runtime( $root );
	if ( 'crash-owner' === $mode ) {
		mdi_reservation_query( $runtime, 'START TRANSACTION' );
		$reservation = mdi_reservation_query( $runtime, "UPDATE wp_identity_reservations SET owner = 'first' WHERE resource_key = 'identity' AND owner = ''" );
		$post = mdi_reservation_query( $runtime, "UPDATE wp_posts SET post_title = 'first' WHERE ID = 1" );
		file_put_contents( $root . '/first-written', '1' );
		for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/crash-first' ); ++$attempt ) { usleep( 10000 ); }
		exit( 1 === $reservation->return_value() && 1 === $post->return_value() && is_file( $root . '/crash-first' ) ? 0 : 1 );
	}
	if ( 'read-owner' === $mode ) {
		mdi_reservation_query( $runtime, 'START TRANSACTION' );
		$read = mdi_reservation_query( $runtime, "SELECT owner FROM wp_identity_reservations WHERE resource_key = 'identity' FOR UPDATE" );
		file_put_contents( $root . '/read-ready', '1' );
		for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/finish-read' ); ++$attempt ) { usleep( 10000 ); }
		exit( 1 === $read->return_value() && is_file( $root . '/finish-read' ) && 0 === mdi_reservation_query( $runtime, 'ROLLBACK' )->return_value() ? 0 : 1 );
	}
	if ( 'observer' === $mode ) {
		$owner = mdi_reservation_value( $runtime, "SELECT owner FROM wp_identity_reservations WHERE resource_key = 'identity'", 'owner' );
		$title = mdi_reservation_value( $runtime, 'SELECT post_title FROM wp_posts WHERE ID = 1', 'post_title' );
		file_put_contents( $root . '/observer.json', json_encode( array( 'owner' => $owner, 'title' => $title ), JSON_THROW_ON_ERROR ) );
		exit( 0 );
	}
	$reservation = mdi_reservation_query( $runtime, "UPDATE wp_identity_reservations SET owner = 'second' WHERE resource_key = 'identity' AND owner = ''" );
	$post = mdi_reservation_query( $runtime, "UPDATE wp_posts SET post_title = 'second' WHERE ID = 1" );
	exit( 1 === $reservation->return_value() && 1 === $post->return_value() ? 0 : 1 );
}

$root = sys_get_temp_dir() . '/mdi-native-reservation-transaction-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0755, true );
$setup = mdi_reservation_runtime( $root );
mdi_reservation_query( $setup, 'CREATE TABLE wp_identity_reservations (resource_key VARCHAR(100) NOT NULL, owner VARCHAR(100) NOT NULL, PRIMARY KEY (resource_key))' );
mdi_reservation_query( $setup, "INSERT INTO wp_identity_reservations (resource_key, owner) VALUES ('identity', '')" );
mdi_reservation_query( $setup, "INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, 1, '2026-09-10 00:00:00', '2026-09-10 00:00:00', '', 'base', '', 'publish', 'open', 'open', '', 'base', '', '', '2026-09-10 00:00:00', '2026-09-10 00:00:00', '', 0, '', 0, 'post', '', 0)" );

$crash_owner = proc_open( array( PHP_BINARY, __FILE__, 'crash-owner', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $crash_pipes );
for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/first-written' ); ++$attempt ) { usleep( 10000 ); }
$observer = is_file( $root . '/first-written' ) ? proc_open( array( PHP_BINARY, __FILE__, 'observer', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $observer_pipes ) : false;
$writer = is_file( $root . '/first-written' ) ? proc_open( array( PHP_BINARY, __FILE__, 'writer', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $writer_pipes ) : false;
usleep( 200000 );
$writer_waiting = is_resource( $writer ) && proc_get_status( $writer )['running'];
file_put_contents( $root . '/crash-first', '1' );
$crash_status = is_resource( $crash_owner ) ? proc_close( $crash_owner ) : 1;
$observer_status = is_resource( $observer ) ? proc_close( $observer ) : 1;
$writer_status = is_resource( $writer ) ? proc_close( $writer ) : 1;
$observed = json_decode( (string) @file_get_contents( $root . '/observer.json' ), true );
$final = mdi_reservation_runtime( $root );
$final_owner = mdi_reservation_value( $final, "SELECT owner FROM wp_identity_reservations WHERE resource_key = 'identity'", 'owner' );
$final_title = mdi_reservation_value( $final, 'SELECT post_title FROM wp_posts WHERE ID = 1', 'post_title' );

mdi_reservation_query( $final, "UPDATE wp_identity_reservations SET owner = '' WHERE resource_key = 'identity'" );
$read_owner = proc_open( array( PHP_BINARY, __FILE__, 'read-owner', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $read_pipes );
for ( $attempt = 0; $attempt < 1000 && ! is_file( $root . '/read-ready' ); ++$attempt ) { usleep( 10000 ); }
$read_writer = is_file( $root . '/read-ready' ) ? proc_open( array( PHP_BINARY, __FILE__, 'writer', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $read_writer_pipes ) : false;
usleep( 200000 );
$read_writer_waiting = is_resource( $read_writer ) && proc_get_status( $read_writer )['running'];
file_put_contents( $root . '/finish-read', '1' );
$read_owner_status = is_resource( $read_owner ) ? proc_close( $read_owner ) : 1;
$read_writer_status = is_resource( $read_writer ) ? proc_close( $read_writer ) : 1;

$checks = array(
	'crashed transaction writer holds the root lock before its first canonical mutation' => $writer_waiting,
	'waiting writer recovers JSON reservation and Markdown shell before admission' => 0 === $crash_status && 0 === $writer_status && 'second' === $final_owner && 'second' === $final_title,
	'independent observer sees one committed reservation and shell state after rollback' => 0 === $observer_status && ( array( '', 'base' ) === array( $observed['owner'] ?? null, $observed['title'] ?? null ) || array( 'second', 'second' ) === array( $observed['owner'] ?? null, $observed['title'] ?? null ) ),
	'START TRANSACTION before SELECT FOR UPDATE retains the root lock until rollback admits the writer' => 0 === $read_owner_status && 0 === $read_writer_status && $read_writer_waiting,
);
fwrite( STDERR, json_encode( compact( 'crash_status', 'observer_status', 'writer_status', 'observed', 'final_owner', 'final_title', 'read_owner_status', 'read_writer_status', 'writer_waiting', 'read_writer_waiting' ), JSON_THROW_ON_ERROR ) . "\n" );
mdi_reservation_remove_tree( $root );
$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) { fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) ); }
exit( $passed ? 0 : 1 );
