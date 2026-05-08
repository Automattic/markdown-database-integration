<?php
/**
 * Smoke test for primary-mode seed imports after fallback installs.
 *
 * Usage: php tests/smoke-primary-install-fallback.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

$tmp_root     = sys_get_temp_dir() . '/mdi-primary-install-fallback-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$content_dir  = $tmp_root . '/markdown';
$captured_hook = null;
$inserted_posts = array();

mkdir( $content_dir . '/page', 0755, true );

file_put_contents(
	$content_dir . '/page/home.md',
	<<<'MD'
---
id: 17
title: "Home"
status: publish
type: page
author: 1
date: "2026-05-08 17:45:00"
date_gmt: "2026-05-08 17:45:00"
modified: "2026-05-08 17:45:00"
modified_gmt: "2026-05-08 17:45:00"
slug: home
parent: 0
menu_order: 0
comment_status: closed
ping_status: closed
guid: ""
comment_count: 0
---

<!-- wp:paragraph -->
<p>Seeded by primary install fallback.</p>
<!-- /wp:paragraph -->
MD
);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', $tmp_root );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
define( 'MARKDOWN_DB_CONTENT_DIR', $content_dir );
define( 'MARKDOWN_DB_MODE', 'primary' );
define( 'MARKDOWN_DB_INSTALL_FALLBACK', true );

function plugin_dir_path( string $file ): string {
	return rtrim( dirname( $file ), '/\\' ) . '/';
}

function add_action( string $hook_name, string $callback, int $priority = 10 ): void {
	global $captured_hook;
	$captured_hook = array( $hook_name, $callback, $priority );
}

function get_page_by_path( string $path, string $output = OBJECT, string $post_type = 'page' ) {
	return null;
}

function wp_insert_post( array $postarr, bool $wp_error = false ) {
	global $inserted_posts;
	$inserted_posts[] = $postarr;
	return $postarr['import_id'] ?? count( $inserted_posts );
}

function mdi_primary_install_fallback_rm_rf( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			mdi_primary_install_fallback_rm_rf( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

require dirname( __DIR__ ) . '/markdown-database-integration.php';

$failures = array();

if ( array( 'wp_install', 'markdown_database_integration_import_seed_posts_after_install', 20 ) !== $captured_hook ) {
	$failures[] = 'fallback install importer was not registered on wp_install priority 20';
}

markdown_database_integration_import_seed_posts_after_install();

if ( 1 !== count( $inserted_posts ) ) {
	$failures[] = 'expected exactly one seed post import';
} else {
	$post = $inserted_posts[0];
	if ( 17 !== (int) $post['import_id'] ) {
		$failures[] = 'seed import_id did not preserve markdown ID';
	}
	if ( 'home' !== $post['post_name'] ) {
		$failures[] = 'seed post slug was not imported';
	}
	if ( 'page' !== $post['post_type'] ) {
		$failures[] = 'seed post type was not imported';
	}
	if ( ! str_contains( $post['post_content'], 'Seeded by primary install fallback' ) ) {
		$failures[] = 'seed post content was not imported';
	}
}

mdi_primary_install_fallback_rm_rf( $tmp_root );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		echo '✗ ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo '✓ primary install fallback imports seed posts' . PHP_EOL;
