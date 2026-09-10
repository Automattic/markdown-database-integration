<?php
/** Mixed equality and pattern alternatives retain their complete SQL predicate. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-mixed-like-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$checks = array();
try {
	$created = $runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_event_dates (post_id bigint unsigned NOT NULL, start_datetime datetime NOT NULL, end_datetime datetime NULL, PRIMARY KEY (post_id))' ) );
	$inserted = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_event_dates (post_id, start_datetime, end_datetime) VALUES (1, '0000-00-00 00:00:00', NULL), (2, '2026-07-15 19:30:00', NULL), (3, '2026-07-16 19:30:00', NULL)" ) );
	$checks['fixture persists zero and ordinary dates'] = $created->succeeded() && 3 === $inserted->return_value();
	$queries = array(
		"start_datetime = '0000-00-00 00:00:00' OR start_datetime LIKE '0000-%'" => array( '1' ),
		"start_datetime LIKE '0000-%' OR start_datetime = '2026-07-15 19:30:00'" => array( '1', '2' ),
		"(start_datetime LIKE '0000-%' OR start_datetime = '2026-07-15 19:30:00') AND post_id = 2" => array( '2' ),
		"start_datetime = '2026-07-15 19:30:00' OR end_datetime LIKE '0000-%'" => array( '2' ),
	);
	foreach ( $queries as $predicate => $ids ) {
		$result = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_id FROM wp_event_dates WHERE ' . $predicate . ' ORDER BY post_id' ) );
		$actual = array_map( static fn( object $row ): string => (string) $row->post_id, $result->wpdb_state()['last_result'] );
		$checks[ $predicate ] = $result->succeeded() && $ids === $actual;
	}
} finally {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
		$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}
foreach ( $checks as $label => $passed ) {
	fwrite( $passed ? STDOUT : STDERR, ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n" );
}
exit( in_array( false, $checks, true ) ? 1 : 0 );
