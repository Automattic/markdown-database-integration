<?php
/** Independent post and JSON-table writers must serialize across a rollback. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_file_isolation_runtime( string $root ): WP_Markdown_Native_Query_Runtime {
	return WP_Markdown_Native_Runtime_Factory::runtime( $root );
}

function mdi_file_isolation_request( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): WP_Markdown_Query_Result {
	return $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
}

function mdi_file_isolation_sql( string $kind, string $value ): string {
	return 'post' === $kind
		? "UPDATE wp_posts SET post_title = '{$value}' WHERE ID = 1"
		: "UPDATE wp_records SET label = '{$value}' WHERE id = 1";
}

function mdi_file_isolation_remove_tree( string $root ): void {
	$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}

if ( isset( $argv[1], $argv[2] ) && in_array( $argv[1], array( 'first', 'second' ), true ) ) {
	$runtime = mdi_file_isolation_runtime( $argv[3] );
	if ( 'first' === $argv[1] ) {
		mdi_file_isolation_request( $runtime, 'BEGIN' );
		$result = mdi_file_isolation_request( $runtime, mdi_file_isolation_sql( $argv[2], 'first' ) );
		file_put_contents( $argv[3] . '/first-ready', '1' );
		for ( $attempt = 0; 1000 > $attempt && ! is_file( $argv[3] . '/release-first' ); ++$attempt ) { usleep( 10000 ); }
		exit( 1 === $result->return_value() && is_file( $argv[3] . '/release-first' ) && 0 === mdi_file_isolation_request( $runtime, 'ROLLBACK' )->return_value() ? 0 : 1 );
	}
	$result = mdi_file_isolation_request( $runtime, mdi_file_isolation_sql( $argv[2], 'second' ) );
	exit( 1 === $result->return_value() ? 0 : 1 );
}

$checks = array();
foreach ( array( 'post', 'table' ) as $kind ) {
	$root = sys_get_temp_dir() . '/mdi-native-file-isolation-' . $kind . '-' . bin2hex( random_bytes( 6 ) );
	mkdir( $root . '/_options', 0755, true );
	$setup = mdi_file_isolation_runtime( $root );
	if ( 'post' === $kind ) {
		mdi_file_isolation_request( $setup, "INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, 1, '2026-09-09 00:00:00', '2026-09-09 00:00:00', '', 'base', '', 'publish', 'open', 'open', '', 'base', '', '', '2026-09-09 00:00:00', '2026-09-09 00:00:00', '', 0, '', 0, 'post', '', 0)" );
	} else {
		mdi_file_isolation_request( $setup, 'CREATE TABLE wp_records (id BIGINT NOT NULL, label VARCHAR(20), PRIMARY KEY (id))' );
		mdi_file_isolation_request( $setup, "INSERT INTO wp_records (id, label) VALUES (1, 'base')" );
	}
	$first = proc_open( array( PHP_BINARY, __FILE__, 'first', $kind, $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $first_pipes );
	for ( $attempt = 0; 1000 > $attempt && ! is_file( $root . '/first-ready' ); ++$attempt ) { usleep( 10000 ); }
	$second = is_file( $root . '/first-ready' ) ? proc_open( array( PHP_BINARY, __FILE__, 'second', $kind, $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $second_pipes ) : false;
	usleep( 200000 );
	$waiting = is_resource( $second ) && proc_get_status( $second )['running'];
	file_put_contents( $root . '/release-first', '1' );
	$first_status = is_resource( $first ) ? proc_close( $first ) : 1;
	$second_status = is_resource( $second ) ? proc_close( $second ) : 1;
	$column = 'post' === $kind ? 'post_title' : 'label';
	$table = 'post' === $kind ? 'wp_posts' : 'wp_records';
	$final = mdi_file_isolation_request( mdi_file_isolation_runtime( $root ), "SELECT {$column} FROM {$table}" );
	$row = $final->wpdb_state()['last_result'][0] ?? null;
	$value = is_object( $row ) ? ( $row->{$column} ?? null ) : ( is_array( $row ) ? ( $row[ $column ] ?? null ) : null );
	$checks[ "{$kind} writer B waits for A rollback" ] = $waiting;
	$checks[ "{$kind} writer B survives A rollback" ] = 0 === $first_status && 0 === $second_status && 'second' === $value;
	mdi_file_isolation_remove_tree( $root );
}

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) { fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) ); }
exit( $passed ? 0 : 1 );
