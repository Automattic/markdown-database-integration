<?php
/**
 * Smoke test for the filesystem-only primary storage runtime.
 *
 * Usage: php tests/smoke-primary-storage-runtime.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-storage.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-primary-storage-runtime.php';

$passed = 0;
$failed = 0;

function mdi_primary_runtime_assert( bool $condition, string $label ): void {
	global $passed, $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	$condition ? $passed++ : $failed++;
}

function mdi_primary_runtime_remove( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( scandir( $path ) ?: array() as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			$child = $path . '/' . $entry;
			is_dir( $child ) ? mdi_primary_runtime_remove( $child ) : unlink( $child );
		}
	}
	rmdir( $path );
}

$root    = sys_get_temp_dir() . '/mdi-primary-runtime-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$content = $root . '/content';
$state   = $root . '/state';
$runtime = new WP_Markdown_Primary_Storage_Runtime( array( 'content_root' => $content, 'state_root' => $state ) );
$index   = array(
	'posts'   => array(
		(object) array(
			'ID' => 12, 'post_title' => 'Cold post', 'post_status' => 'publish', 'post_type' => 'post',
			'post_name' => 'cold-post', 'post_content' => 'Canonical body', 'post_date' => '2026-07-18 00:00:00',
			'post_modified' => '2026-07-18 00:00:00', 'post_author' => 1,
		),
	),
	'options' => array(
		array( 'option_id' => 7, 'option_name' => 'siteurl', 'option_value' => 'https://example.test', 'autoload' => 'yes' ),
	),
);

$first = $runtime->flush( $index );
$post_path = $runtime->get_content_root() . '/post/cold-post.md';
$option_path = $runtime->get_state_root() . '/_options/siteurl.json';
mdi_primary_runtime_assert( array( $post_path, $option_path ) === $first['created'], 'flush returns canonical created paths' );
mdi_primary_runtime_assert( array() === $first['changed'] && array() === $first['deleted'], 'initial flush has no changed or deleted paths' );

unset( $index ); // A fresh runtime must recover from canonical files, not an index or SQLite.
$cold_runtime = new WP_Markdown_Primary_Storage_Runtime( array( 'content_root' => $content, 'state_root' => $state ) );
$recovered = $cold_runtime->reconstruct();
mdi_primary_runtime_assert( 1 === count( $recovered['posts'] ) && 12 === $recovered['posts'][0]->ID && 'Canonical body' === $recovered['posts'][0]->post_content, 'fresh runtime reconstructs post from Markdown' );
mdi_primary_runtime_assert( array( array( 'option_id' => 7, 'option_name' => 'siteurl', 'option_value' => 'https://example.test', 'autoload' => 'yes' ) ) === $recovered['options'], 'fresh runtime reconstructs option from JSON' );
mdi_primary_runtime_assert( array( 'created' => array(), 'changed' => array(), 'deleted' => array() ) === $cold_runtime->flush( $recovered ), 're-flushing recovered index is deterministic' );

$recovered['posts'][0]->post_title = 'Changed post';
$changed = $cold_runtime->flush( $recovered );
mdi_primary_runtime_assert( array( $post_path ) === $changed['changed'], 'flush returns changed canonical Markdown path' );

$deleted = $cold_runtime->flush( array( 'posts' => array(), 'options' => array() ) );
mdi_primary_runtime_assert( array( $post_path, $option_path ) === $deleted['deleted'], 'flush returns deleted canonical paths' );

mdi_primary_runtime_remove( $root );
if ( $failed ) {
	exit( 1 );
}
echo "All {$passed} assertions passed.\n";
