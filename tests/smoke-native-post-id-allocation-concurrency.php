<?php
/** Generated native post IDs are serialized across independent PHP processes. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_allocation_runtime( string $root ): array {
	$storage = new WP_Markdown_Storage( $root . '/content' );
	$schema = WP_Markdown_Native_Runtime_Factory::posts_schema();
	$registry = new WP_Markdown_Native_Table_Registry();
	$registry->register( 'wp_posts', $schema, new WP_Markdown_Native_Post_Provider( $root . '/content', $schema, $storage, $root . '/state' ) );
	return array(
		$storage,
		new WP_Markdown_Native_Query_Runtime(
			$registry,
			new WP_Markdown_Native_Query_Parser(),
			null,
			null,
			null,
			null,
			new WP_Markdown_Native_Post_Mutation_Runtime( $registry, new WP_Markdown_Native_Table_Insert_Parser(), $storage )
		),
	);
}

function mdi_allocation_insert( WP_Markdown_Native_Query_Runtime $runtime, string $slug ): int {
	$result = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (1, '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', '{$slug}', '', 'publish', 'open', 'open', '', '{$slug}', '', '', '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', 0, '', 0, 'post', '', 0)", 'wp_' ) );
	return (int) ( $result->wpdb_state()['insert_id'] ?? 0 );
}

function mdi_allocation_explicit_insert( WP_Markdown_Native_Query_Runtime $runtime, string $slug, int $id ): int {
	$result = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES ({$id}, 1, '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', '{$slug}', '', 'publish', 'open', 'open', '', '{$slug}', '', '', '2026-09-06 00:00:00', '2026-09-06 00:00:00', '', 0, '', 0, 'post', '', 0)", 'wp_' ) );
	return (int) ( $result->wpdb_state()['insert_id'] ?? 0 );
}

if ( 'holder' === ( $argv[1] ?? '' ) ) {
	list( $storage, $runtime ) = mdi_allocation_runtime( $argv[2] );
	$storage->synchronize_native_post_write(
		static function () use ( $runtime ): void {
			fwrite( STDOUT, "LOCKED\n" );
			fgets( STDIN );
			fwrite( STDOUT, 'ID:' . mdi_allocation_explicit_insert( $runtime, 'holder', 1 ) . "\n" );
		}
	);
	exit( 0 );
}
if ( 'writer' === ( $argv[1] ?? '' ) ) {
	list( , $runtime ) = mdi_allocation_runtime( $argv[2] );
	fwrite( STDOUT, "READY\n" );
	fgets( STDIN );
	fwrite( STDOUT, "STARTED\n" );
	fwrite( STDOUT, 'ID:' . mdi_allocation_insert( $runtime, 'writer' ) . "\n" );
	exit( 0 );
}

$root = sys_get_temp_dir() . '/mdi-native-post-allocation-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/state/_options', 0777, true );
mkdir( $root . '/content/post', 0777, true );
$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
$holder = proc_open( array( PHP_BINARY, __FILE__, 'holder', $root ), $descriptors, $holder_pipes );
$holder_locked = is_resource( $holder ) && "LOCKED\n" === fgets( $holder_pipes[1] );
$writer = proc_open( array( PHP_BINARY, __FILE__, 'writer', $root ), $descriptors, $writer_pipes );
$writer_ready = is_resource( $writer ) && "READY\n" === fgets( $writer_pipes[1] );
$writer_started = false;
if ( $writer_ready ) {
	fwrite( $writer_pipes[0], "GO\n" );
	$writer_started = "STARTED\n" === fgets( $writer_pipes[1] );
}
fwrite( $holder_pipes[0], "GO\n" );
fclose( $holder_pipes[0] );
$holder_result = trim( (string) fgets( $holder_pipes[1] ) );
$writer_result = trim( (string) fgets( $writer_pipes[1] ) );
foreach ( array( $holder_pipes, $writer_pipes ) as $pipes ) {
	foreach ( $pipes as $pipe ) {
		if ( is_resource( $pipe ) ) {
			fclose( $pipe );
		}
	}
}
$holder_exit = is_resource( $holder ) ? proc_close( $holder ) : -1;
$writer_exit = is_resource( $writer ) ? proc_close( $writer ) : -1;
$ids = array_filter( array_map( 'intval', array( substr( $holder_result, 3 ), substr( $writer_result, 3 ) ) ) );
$files = glob( $root . '/content/post/*.md' ) ?: array();
$passed = $holder_locked && $writer_ready && $writer_started && 0 === $holder_exit && 0 === $writer_exit && array( 1, 2 ) === $ids && 2 === count( $files );
echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . "an explicit native post INSERT serializes with a generated post ID allocation\n";
foreach ( $files as $file ) { @unlink( $file ); }
@unlink( $root . '/content/.mdi-native-posts.lock' );
@rmdir( $root . '/content/post' );
@rmdir( $root . '/content' );
@rmdir( $root . '/state/_options' );
@rmdir( $root . '/state' );
@rmdir( $root );
exit( $passed ? 0 : 1 );
