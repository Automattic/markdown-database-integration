<?php
/**
 * Smoke test for MySQL-compatible import/export abilities.
 *
 * Usage: php tests/smoke-mysql-import-export-abilities.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

$tmp_root    = sys_get_temp_dir() . '/mdi-mysql-import-export-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$content_dir = $tmp_root . '/markdown';
$export_dir  = $tmp_root . '/export';

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
meta:
  color: blue
terms:
  category:
    - docs
---

Original body.
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
define( 'MARKDOWN_DB_EXCLUDED_TYPES', 'revision,auto-draft' );

$GLOBALS['mdi_test_actions']    = array();
$GLOBALS['mdi_test_categories'] = array();
$GLOBALS['mdi_test_abilities']  = array();
$GLOBALS['mdi_test_filters']    = array();
$GLOBALS['mdi_test_posts']      = array();
$GLOBALS['mdi_test_meta']       = array();
$GLOBALS['mdi_test_terms']      = array();
$GLOBALS['mdi_test_contexts']   = array();
$GLOBALS['mdi_next_post_id']    = 100;
$GLOBALS['mdi_test_did_action'] = array();

function add_action( string $hook_name, callable $callback, int $priority = 10 ): void {
	$GLOBALS['mdi_test_actions'][ $hook_name ][] = array( $callback, $priority );
}

function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['mdi_test_filters'][ $hook_name ][] = array( $callback, $priority, $accepted_args );
}

function did_action( string $hook_name ): int {
	return (int) ( $GLOBALS['mdi_test_did_action'][ $hook_name ] ?? 0 );
}

function doing_action( string $hook_name ): bool {
	return false;
}

function wp_has_ability_category( string $name ): bool {
	return isset( $GLOBALS['mdi_test_categories'][ $name ] );
}

function wp_register_ability_category( string $name, array $args ): void {
	$GLOBALS['mdi_test_categories'][ $name ] = $args;
}

function wp_has_ability( string $name ): bool {
	return isset( $GLOBALS['mdi_test_abilities'][ $name ] );
}

function wp_register_ability( string $name, array $args ): void {
	$GLOBALS['mdi_test_abilities'][ $name ] = $args;
}

function current_user_can( string $capability ): bool {
	return 'manage_options' === $capability;
}

function apply_filters( string $hook_name, $value, ...$args ) {
	$callbacks = $GLOBALS['mdi_test_filters'][ $hook_name ] ?? array();
	usort(
		$callbacks,
		static fn( array $a, array $b ): int => $a[1] <=> $b[1]
	);

	foreach ( $callbacks as $callback ) {
		$accepted = max( 1, (int) $callback[2] );
		$value    = call_user_func_array( $callback[0], array_slice( array_merge( array( $value ), $args ), 0, $accepted ) );
	}

	return $value;
}

function mdi_mysql_import_export_do_action( string $hook_name ): void {
	$GLOBALS['mdi_test_did_action'][ $hook_name ] = (int) ( $GLOBALS['mdi_test_did_action'][ $hook_name ] ?? 0 ) + 1;
	foreach ( $GLOBALS['mdi_test_actions'][ $hook_name ] ?? array() as $action ) {
		call_user_func( $action[0] );
	}
}

function get_posts( array $args = array() ): array {
	$posts = $GLOBALS['mdi_test_posts'];

	if ( isset( $args['meta_key'] ) ) {
		$ids = array();
		foreach ( $GLOBALS['mdi_test_meta'] as $post_id => $meta ) {
			if ( isset( $meta[ $args['meta_key'] ] ) ) {
				$ids[] = (int) $post_id;
			}
		}
		return 'ids' === ( $args['fields'] ?? '' ) ? $ids : array_values( array_intersect_key( $posts, array_flip( $ids ) ) );
	}

	$post_type = $args['post_type'] ?? 'any';
	if ( 'any' !== $post_type ) {
		$types = is_array( $post_type ) ? $post_type : array( $post_type );
		$posts = array_filter(
			$posts,
			static fn( $post ): bool => in_array( $post->post_type ?? '', $types, true )
		);
	}

	ksort( $posts );
	return 'ids' === ( $args['fields'] ?? '' ) ? array_map( 'intval', array_keys( $posts ) ) : array_values( $posts );
}

function get_post( int $post_id ) {
	return $GLOBALS['mdi_test_posts'][ $post_id ] ?? null;
}

function get_post_type( int $post_id ): string {
	$post = get_post( $post_id );
	return $post ? (string) $post->post_type : '';
}

function get_page_by_path( string $path, string $output = OBJECT, string $post_type = 'page' ) {
	foreach ( $GLOBALS['mdi_test_posts'] as $post ) {
		if ( $path === ( $post->post_name ?? '' ) && $post_type === ( $post->post_type ?? '' ) ) {
			return $post;
		}
	}
	return null;
}

function wp_insert_post( array $postarr, bool $wp_error = false ) {
	$id = (int) ( $postarr['ID'] ?? $postarr['import_id'] ?? 0 );
	if ( $id <= 0 ) {
		$id = $GLOBALS['mdi_next_post_id']++;
	}

	$postarr['ID']               = $id;
	$GLOBALS['mdi_test_posts'][ $id ] = (object) $postarr;
	return $id;
}

function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
	$meta = $GLOBALS['mdi_test_meta'][ $post_id ] ?? array();
	if ( '' === $key ) {
		return array_map( static fn( $value ) => is_array( $value ) ? $value : array( $value ), $meta );
	}
	$value = $meta[ $key ] ?? ( $single ? '' : array() );
	return $single && is_array( $value ) ? reset( $value ) : $value;
}

function update_post_meta( int $post_id, string $key, $value ): void {
	$GLOBALS['mdi_test_meta'][ $post_id ][ $key ] = $value;
}

function delete_post_meta( int $post_id, string $key ): void {
	unset( $GLOBALS['mdi_test_meta'][ $post_id ][ $key ] );
}

function add_post_meta( int $post_id, string $key, $value ): void {
	if ( ! isset( $GLOBALS['mdi_test_meta'][ $post_id ][ $key ] ) ) {
		$GLOBALS['mdi_test_meta'][ $post_id ][ $key ] = array();
	}
	$GLOBALS['mdi_test_meta'][ $post_id ][ $key ][] = $value;
}

function wp_set_object_terms( int $post_id, array $terms, string $taxonomy, bool $append = false ): void {
	$GLOBALS['mdi_test_terms'][ $post_id ][ $taxonomy ] = $terms;
}

function get_object_taxonomies( string $post_type ): array {
	return 'page' === $post_type ? array( 'category' ) : array();
}

function wp_get_object_terms( int $post_id, string $taxonomy, array $args = array() ): array {
	$terms = $GLOBALS['mdi_test_terms'][ $post_id ][ $taxonomy ] ?? array();
	return array_map( static fn( string $slug ): object => (object) array( 'slug' => $slug ), $terms );
}

function maybe_serialize( $value ) {
	return $value;
}

function mdi_mysql_import_export_rm_rf( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			mdi_mysql_import_export_rm_rf( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

require dirname( __DIR__ ) . '/inc/class-wp-markdown-storage.php';
require dirname( __DIR__ ) . '/inc/class-wp-markdown-cli.php';

$failures = array();

WP_Markdown_CLI::register();
mdi_mysql_import_export_do_action( 'wp_abilities_api_categories_init' );
mdi_mysql_import_export_do_action( 'wp_abilities_api_init' );

add_filter(
	'datamachine_content_format_convert',
	static fn( $converted, string $content, string $from, string $to ): string => "[{$from}:{$to}]{$content}",
	10,
	5
);

if ( ! isset( $GLOBALS['mdi_test_abilities']['markdown-db/import'] ) ) {
	$failures[] = 'markdown-db/import ability was not registered';
}
if ( ! isset( $GLOBALS['mdi_test_abilities']['markdown-db/export'] ) ) {
	$failures[] = 'markdown-db/export ability was not registered';
}
if ( ! call_user_func( $GLOBALS['mdi_test_abilities']['markdown-db/import']['permission_callback'] ) ) {
	$failures[] = 'import ability permission callback did not allow manage_options';
}

add_filter(
	'markdown_db_import_post_content',
	static function ( string $content, array $context ): string {
		$GLOBALS['mdi_test_contexts']['import_content'] = $context;
		return $content . "\n\nImported transform.";
	},
	10,
	2
);

add_filter(
	'markdown_db_export_post_content',
	static function ( string $content, array $context ): string {
		$GLOBALS['mdi_test_contexts']['export_content'] = $context;
		return str_replace( 'Imported transform.', 'Exported transform.', $content );
	},
	10,
	2
);

$import = call_user_func( $GLOBALS['mdi_test_abilities']['markdown-db/import']['execute_callback'], array( 'path' => $content_dir ) );
if ( 1 !== ( $import['create_count'] ?? 0 ) || 1 !== count( $GLOBALS['mdi_test_posts'] ) || ! isset( $GLOBALS['mdi_test_posts'][17] ) ) {
	$failures[] = 'import ability did not create the markdown post with its source ID';
}

if ( ! str_contains( (string) ( $GLOBALS['mdi_test_posts'][17]->post_content ?? '' ), 'Imported transform.' ) ) {
	$failures[] = 'import content transform filter did not modify post_content before insert';
}

$import_context = $GLOBALS['mdi_test_contexts']['import_content'] ?? array();
if ( 'import' !== ( $import_context['operation'] ?? '' ) || 'page' !== ( $import_context['post_type'] ?? '' ) || 'page/home.md' !== ( $import_context['source_path'] ?? '' ) || ! isset( $import_context['frontmatter']['title'] ) ) {
	$failures[] = 'import content transform filter did not receive expected context';
}

if ( 'page/home.md' !== ( $GLOBALS['mdi_test_meta'][17]['_markdown_source_path'] ?? '' ) ) {
	$failures[] = 'import ability did not store stable source path meta';
}
if ( array( 'docs' ) !== ( $GLOBALS['mdi_test_terms'][17]['category'] ?? array() ) ) {
	$failures[] = 'import ability did not preserve frontmatter terms';
}

file_put_contents(
	$content_dir . '/page/home.md',
	str_replace( array( 'title: "Home"', 'Original body.' ), array( 'title: "Home Updated"', 'Updated body.' ), file_get_contents( $content_dir . '/page/home.md' ) )
);

$second_import = call_user_func( $GLOBALS['mdi_test_abilities']['markdown-db/import']['execute_callback'], array( 'path' => $content_dir ) );
if ( 1 !== ( $second_import['update_count'] ?? 0 ) || 1 !== count( $GLOBALS['mdi_test_posts'] ) ) {
	$failures[] = 'second import did not update the existing source-path post without duplicating it';
}
if ( 'Home Updated' !== ( $GLOBALS['mdi_test_posts'][17]->post_title ?? '' ) ) {
	$failures[] = 'second import did not update post title';
}

$export = call_user_func( $GLOBALS['mdi_test_abilities']['markdown-db/export']['execute_callback'], array( 'path' => $export_dir, 'post_types' => 'page' ) );
if ( 1 !== ( $export['written_count'] ?? 0 ) || ! is_file( $export_dir . '/page/home.md' ) ) {
	$failures[] = 'export ability did not write the current page to markdown';
}
if ( is_file( $export_dir . '/page/home.md' ) && false === strpos( file_get_contents( $export_dir . '/page/home.md' ), 'Updated body.' ) ) {
	$failures[] = 'export ability did not preserve post_content bytes';
}
if ( is_file( $export_dir . '/page/home.md' ) && false === strpos( file_get_contents( $export_dir . '/page/home.md' ), 'Exported transform.' ) ) {
	$failures[] = 'export content transform filter did not modify markdown body before write';
}

$export_context = $GLOBALS['mdi_test_contexts']['export_content'] ?? array();
if ( 'export' !== ( $export_context['operation'] ?? '' ) || 'page' !== ( $export_context['post_type'] ?? '' ) || 'page/home.md' !== ( $export_context['source_path'] ?? '' ) ) {
	$failures[] = 'export content transform filter did not receive expected context';
}

mdi_mysql_import_export_rm_rf( $tmp_root );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo 'PASS: MySQL-compatible import/export abilities round-trip markdown posts' . PHP_EOL;
