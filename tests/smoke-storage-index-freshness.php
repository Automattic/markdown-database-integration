<?php
/** A warm storage index must not treat external canonical absence as durable. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';

function mdi_freshness_post( int $id, string $slug, string $title ): object {
	return (object) array( 'ID' => $id, 'post_type' => 'post', 'post_name' => $slug, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '' );
}

$root = sys_get_temp_dir() . '/mdi-storage-freshness-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/post', 0777, true );
$storage = new WP_Markdown_Storage( $root );
$storage->write_post( mdi_freshness_post( 1, 'first', 'First' ) );
$storage->read_post( 1 );
file_put_contents( $root . '/post/external.md', "---\nid: 2\ntitle: External\nstatus: publish\ntype: post\nauthor: 1\ndate: 2026-09-06 00:00:00\nmodified: 2026-09-06 00:00:00\nslug: external\ncomment_status: open\nping_status: open\n---\n\n" );
$written = $storage->write_post( mdi_freshness_post( 2, 'updated', 'Updated' ) );
$post_files = glob( $root . '/post/*.md' ) ?: array();
$passed = $root . '/post/updated.md' === $written && ! file_exists( $root . '/post/external.md' ) && 2 === count( $post_files );
echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . "a warmed index discovers an externally introduced post identity before writing it\n";
foreach ( $post_files as $file ) { @unlink( $file ); }
@rmdir( $root . '/post' );
@rmdir( $root );
exit( $passed ? 0 : 1 );
