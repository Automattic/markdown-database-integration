<?php
/**
 * Smoke test for MDI's storage-only content boundary.
 *
 * MDI persists WordPress DB rows to markdown/JSON files. Content conversion
 * belongs to the caller/content-format layer, so this smoke asserts MDI has
 * no BFB dependency, registers no render/REST/write conversion hooks, and
 * writes post_content bytes unchanged.
 *
 * Usage: php tests/smoke-storage-only-boundary.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$plugin_dir = dirname( __DIR__ );
$passed     = 0;
$failed     = 0;

function mdi_storage_only_assert( bool $cond, string $label, string $detail = '' ): void {
	global $passed, $failed;

	if ( $cond ) {
		echo '✓ ' . $label . PHP_EOL;
		$passed++;
		return;
	}

	echo '✗ ' . $label . ( '' !== $detail ? ' — ' . $detail : '' ) . PHP_EOL;
	$failed++;
}

function mdi_storage_only_body_from_file( string $path ): string {
	$raw = (string) file_get_contents( $path );
	if ( 1 !== preg_match( '/^---\n.+?\n---\n\n?(.*)$/s', $raw, $m ) ) {
		return '';
	}
	return rtrim( $m[1] );
}

function mdi_storage_only_rm_rf( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			mdi_storage_only_rm_rf( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

// ---------------------------------------------------------------------------
// Dependency and production-reference boundary.
// ---------------------------------------------------------------------------

$composer = json_decode( (string) file_get_contents( $plugin_dir . '/composer.json' ), true );
mdi_storage_only_assert( is_array( $composer ), 'composer.json parses as JSON' );

$composer_lock = file_exists( $plugin_dir . '/composer.lock' )
	? json_decode( (string) file_get_contents( $plugin_dir . '/composer.lock' ), true )
	: array( 'packages' => array() );

$require = is_array( $composer ) ? ( $composer['require'] ?? array() ) : array();
mdi_storage_only_assert( ! isset( $require['chubes4/block-format-bridge'] ), 'composer.json does not require chubes4/block-format-bridge' );
mdi_storage_only_assert( empty( $composer['repositories'] ?? array() ), 'composer.json has no BFB-specific repositories' );

$locked_packages = array_map(
	static function ( array $package ): string {
		return (string) ( $package['name'] ?? '' );
	},
	is_array( $composer_lock['packages'] ?? null ) ? $composer_lock['packages'] : array()
);
mdi_storage_only_assert( ! in_array( 'chubes4/block-format-bridge', $locked_packages, true ), 'composer.lock does not include chubes4/block-format-bridge' );
mdi_storage_only_assert( ! in_array( 'chubes4/html-to-blocks-converter', $locked_packages, true ), 'composer.lock does not include chubes4/html-to-blocks-converter' );

$production_php = array(
	$plugin_dir . '/markdown-database-integration.php',
	$plugin_dir . '/db.php',
	$plugin_dir . '/inc/class-wp-markdown-write-engine.php',
);
$production_blob = '';
foreach ( $production_php as $path ) {
	$production_blob .= (string) file_get_contents( $path ) . "\n";
}

foreach ( array( 'bfb_convert', 'bfb_normalize', 'bfb_skip_insert_conversion' ) as $symbol ) {
	mdi_storage_only_assert( ! str_contains( $production_blob, $symbol ), "production code does not reference {$symbol}" );
}

foreach ( array( 'markdown_db_the_content_filter', 'markdown_db_rest_prepare_filter', 'markdown_db_rest_pre_insert_filter', 'markdown_db_register_rest_filters' ) as $function_name ) {
	mdi_storage_only_assert( ! str_contains( $production_blob, $function_name ), "production code does not define {$function_name}" );
}

mdi_storage_only_assert( ! str_contains( $production_blob, "add_filter( 'the_content'" ), 'plugin does not register a the_content conversion filter' );
mdi_storage_only_assert( ! str_contains( $production_blob, 'rest_prepare_' ), 'plugin does not register REST prepare conversion filters' );
mdi_storage_only_assert( ! str_contains( $production_blob, 'rest_pre_insert_' ), 'plugin does not register REST pre-insert conversion filters' );
mdi_storage_only_assert( ! str_contains( $production_blob, 'wp_insert_post_data' ), 'plugin does not hook wp_insert_post_data conversion' );
mdi_storage_only_assert( ! str_contains( $production_blob, "'blocks', 'markdown'" ), 'write engine has no blocks-to-markdown conversion path' );

// ---------------------------------------------------------------------------
// Write engine mirrors post_content bytes unchanged.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_SQLite_Driver' ) ) {
	class WP_SQLite_Driver {}
}

require_once $plugin_dir . '/inc/class-wp-markdown-storage.php';
require_once $plugin_dir . '/inc/class-wp-markdown-write-engine.php';

class MDI_Storage_Only_Driver extends WP_SQLite_Driver {
	/** @var array<int, object> */
	public array $posts = array();
	private int $insert_id = 0;

	public function set_next_insert_id( int $id ): void {
		$this->insert_id = $id;
	}

	public function get_insert_id(): int {
		return $this->insert_id;
	}

	public function query( string $sql ): array {
		if ( 1 === preg_match( '/WHERE\s+ID\s*=\s*(\d+)/i', $sql, $m ) ) {
			$id = (int) $m[1];
			return isset( $this->posts[ $id ] ) ? array( $this->posts[ $id ] ) : array();
		}
		return array();
	}
}

function mdi_storage_only_make_post( int $id, string $slug, string $content ): object {
	return (object) array(
		'ID'                    => $id,
		'post_author'           => 1,
		'post_date'             => '2026-04-29 00:00:00',
		'post_date_gmt'         => '2026-04-29 00:00:00',
		'post_content'          => $content,
		'post_title'            => 'Storage Boundary',
		'post_excerpt'          => '',
		'post_status'           => 'publish',
		'comment_status'        => 'open',
		'ping_status'           => 'open',
		'post_password'         => '',
		'post_name'             => $slug,
		'to_ping'               => '',
		'pinged'                => '',
		'post_modified'         => '2026-04-29 00:00:00',
		'post_modified_gmt'     => '2026-04-29 00:00:00',
		'post_content_filtered' => '',
		'post_parent'           => 0,
		'guid'                  => '',
		'menu_order'            => 0,
		'post_type'             => 'wiki',
		'post_mime_type'        => '',
		'comment_count'         => 0,
	);
}

$tmp_root = sys_get_temp_dir() . '/mdi-storage-only-boundary-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp_root, 0755, true );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$driver  = new MDI_Storage_Only_Driver();
$engine  = new WP_Markdown_Write_Engine( $tmp_root, $storage, $driver, 'wp_' );

$serialized_blocks = '<!-- wp:heading {"level":2} --><h2>Stored As Sent</h2><!-- /wp:heading -->'
	. "\n\n"
	. '<!-- wp:paragraph --><p>Block markup remains block markup.</p><!-- /wp:paragraph -->';

$driver->posts[202] = mdi_storage_only_make_post( 202, 'stored-as-sent', $serialized_blocks );
$driver->set_next_insert_id( 202 );
$engine->persist_write( 'INSERT INTO `wp_posts` (`ID`) VALUES (202)', 'wp_posts', 'INSERT' );
$engine->flush_dirty();

$path = $tmp_root . '/wiki/stored-as-sent.md';
mdi_storage_only_assert( file_exists( $path ), 'write engine creates .md file for markdown-managed post type' );
mdi_storage_only_assert( mdi_storage_only_body_from_file( $path ) === $serialized_blocks, 'write engine stores serialized blocks unchanged' );
mdi_storage_only_assert( ( $storage->read_post( 202 )->post_content ?? null ) === $serialized_blocks, 'storage read returns unchanged post_content bytes' );

mdi_storage_only_rm_rf( $tmp_root );

if ( $failed > 0 ) {
	echo PHP_EOL . "Failed: {$failed}" . PHP_EOL;
	exit( 1 );
}

echo PHP_EOL . "All {$passed} assertions passed." . PHP_EOL;
