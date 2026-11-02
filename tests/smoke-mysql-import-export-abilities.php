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
  pattern: '\d+\s'
terms:
  category:
    - docs
---

Original body.

`C:\docs\file.md` and `\d+`.
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
$GLOBALS['mdi_test_cli_output'] = array();

class WP_CLI {

	public static function error( string $message ): void {
		throw new RuntimeException( $message );
	}

	public static function line( string $message ): void {
		$GLOBALS['mdi_test_cli_output'][] = $message;
	}
}

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

// Model the slashed-input contract of WordPress's post and metadata APIs.
function wp_slash( $value ) {
	return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}

function wp_insert_post( array $postarr, bool $wp_error = false ) {
	$GLOBALS['mdi_insert_calls'] = ( $GLOBALS['mdi_insert_calls'] ?? 0 ) + 1;
	$postarr = wp_unslash( $postarr );
	$id = (int) ( $postarr['ID'] ?? $postarr['import_id'] ?? 0 );
	if ( $id <= 0 ) {
		$id = $GLOBALS['mdi_next_post_id']++;
	}

	$GLOBALS['mdi_last_postarr'] = $postarr;
	// Model WordPress merging omitted fields on update and filling empty publish dates.
	$postarr = array_merge( (array) ( $GLOBALS['mdi_test_posts'][ $id ] ?? array() ), $postarr );
	foreach ( array( 'post_date', 'post_date_gmt' ) as $field ) {
		if ( empty( $postarr[ $field ] ) || '0000-00-00 00:00:00' === $postarr[ $field ] ) {
			$postarr[ $field ] = '2026-11-02 01:45:00';
		}
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
	$GLOBALS['mdi_test_meta'][ $post_id ][ $key ] = wp_unslash( $value );
}

function delete_post_meta( int $post_id, string $key ): void {
	unset( $GLOBALS['mdi_test_meta'][ $post_id ][ $key ] );
}

function add_post_meta( int $post_id, string $key, $value ): void {
	if ( ! isset( $GLOBALS['mdi_test_meta'][ $post_id ][ $key ] ) ) {
		$GLOBALS['mdi_test_meta'][ $post_id ][ $key ] = array();
	}
	$GLOBALS['mdi_test_meta'][ $post_id ][ $key ][] = wp_unslash( $value );
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

function wp_json_encode( $value, int $flags = 0 ): string {
	return (string) json_encode( $value, $flags );
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
	'markdown_db_content_format_convert',
	static function ( $converted, string $content, string $from, string $to ): string {
		if ( 'markdown' === $from ) {
			// Representative serialized block attributes from an HTML-link conversion.
			$content = '<!-- wp:list-item ' . json_encode( array( 'content' => '<a href="https://example.com">Join</a>' ), JSON_HEX_TAG | JSON_HEX_QUOT ) . ' -->' . "\n" . $content;
		}
		$result = "[{$from}:{$to}]{$content}";
		if ( 'markdown' === $from ) {
			$GLOBALS['mdi_expected_import_content'] = $result;
		}
		return $result;
	},
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

if ( $GLOBALS['mdi_expected_import_content'] !== ( $GLOBALS['mdi_test_posts'][17]->post_content ?? null ) ) {
	$failures[] = 'import did not preserve converter output, block JSON escapes, and literal backslashes exactly';
}

$import_context = $GLOBALS['mdi_test_contexts']['import_content'] ?? array();
if ( 'import' !== ( $import_context['operation'] ?? '' ) || 'page' !== ( $import_context['post_type'] ?? '' ) || 'page/home.md' !== ( $import_context['source_path'] ?? '' ) || ! isset( $import_context['frontmatter']['title'] ) ) {
	$failures[] = 'import content transform filter did not receive expected context';
}

if ( 'page/home.md' !== ( $GLOBALS['mdi_test_meta'][17]['_markdown_source_path'] ?? '' ) ) {
	$failures[] = 'import ability did not store stable source path meta';
}
if ( 'page/home.md' !== ( $GLOBALS['mdi_test_meta'][17]['_markdown_source_identity'] ?? '' ) ) {
	$failures[] = 'import ability did not store immutable source identity meta';
}
if ( array( '\\d+\\s' ) !== ( $GLOBALS['mdi_test_meta'][17]['pattern'] ?? null ) ) {
	$failures[] = 'import did not preserve frontmatter metadata backslashes';
}
if ( array( 'docs' ) !== ( $GLOBALS['mdi_test_terms'][17]['category'] ?? array() ) ) {
	$failures[] = 'import ability did not preserve frontmatter terms';
}

$calls_before = $GLOBALS['mdi_insert_calls'];
foreach ( array( true, false ) as $dry_run ) {
	$repeat = WP_Markdown_CLI::import( array( 'path' => $content_dir, 'dry_run' => $dry_run ) );
	if ( 0 !== $repeat['update_count'] || 1 !== $repeat['unchanged_count'] || array( array( 'id' => 17, 'path' => 'page/home.md' ) ) !== $repeat['unchanged'] || $calls_before !== $GLOBALS['mdi_insert_calls'] ) {
		$failures[] = 'unchanged dated re-import wrote a post or was not reported unchanged';
	}
}
// A final post-data filter must participate in change detection.
add_filter( 'markdown_db_import_post_data', static function ( array $postarr ): array {
	$postarr['post_status'] = 'draft';
	return $postarr;
} );
foreach ( array( true, false ) as $dry_run ) {
	$status_import = WP_Markdown_CLI::import( array( 'path' => $content_dir, 'dry_run' => $dry_run ) );
	if ( 1 !== $status_import['update_count'] || 0 !== $status_import['unchanged_count'] ) {
		$failures[] = 'post-data status filter was not counted as a change';
	}
}
if ( $calls_before + 1 !== $GLOBALS['mdi_insert_calls'] || 'draft' !== $GLOBALS['mdi_test_posts'][17]->post_status ) {
	$failures[] = 'status filter did not write exactly once in apply';
}
$GLOBALS['mdi_test_filters']['markdown_db_import_post_data'] = array();

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

if ( $GLOBALS['mdi_expected_import_content'] !== ( $GLOBALS['mdi_test_posts'][17]->post_content ?? null ) ) {
	$failures[] = 'update import did not preserve converted content exactly';
}

WP_Markdown_CLI::import_cli( array(), array( 'content-dir' => $content_dir, 'dry-run' => true, 'format' => 'json' ) );
$cli_import = json_decode( (string) end( $GLOBALS['mdi_test_cli_output'] ), true );
if ( $content_dir !== ( $cli_import['path'] ?? null ) || 'dry-run' !== ( $cli_import['mode'] ?? null ) ) {
	$failures[] = 'import CLI did not route --content-dir to the markdown content root';
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
if ( is_file( $export_dir . '/page/home.md' ) && false === strpos( file_get_contents( $export_dir . '/page/home.md' ), 'source_identity: page/home.md' ) ) {
	$failures[] = 'export ability did not preserve immutable source identity';
}

$export_context = $GLOBALS['mdi_test_contexts']['export_content'] ?? array();
if ( 'export' !== ( $export_context['operation'] ?? '' ) || 'page' !== ( $export_context['post_type'] ?? '' ) || 'page/home.md' !== ( $export_context['source_path'] ?? '' ) ) {
	$failures[] = 'export content transform filter did not receive expected context';
}

WP_Markdown_CLI::export_cli( array(), array( 'content-dir' => $export_dir, 'post-type' => 'page', 'dry-run' => true, 'format' => 'json' ) );
$cli_export = json_decode( (string) end( $GLOBALS['mdi_test_cli_output'] ), true );
if ( $export_dir !== ( $cli_export['path'] ?? null ) || array( 'page' ) !== ( $cli_export['post_types'] ?? null ) ) {
	$failures[] = 'export CLI did not route --content-dir to the markdown content root';
}

require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-content-layout-profiles.php';
markdown_db_register_content_layout_profile(
	'nested-export-fixture',
	array(
		'enumerate' => static fn(): array => array( 'plugins/foo/docs/user/home.md' ),
		'map_source' => static fn(): array => array( 'post_type' => 'page', 'post_name' => 'home' ),
		'path_for_post' => static fn(): string => 'plugins/foo/docs/user/home.md',
	)
);
$nested_options = array( 'path' => $tmp_root . '/nested-export', 'post_types' => 'page', 'layout_profile' => 'nested-export-fixture' );
$nested_export = WP_Markdown_CLI::export( $nested_options );
if ( true !== $nested_export['success'] || 1 !== $nested_export['written_count'] || 0 !== $nested_export['skipped_count'] || ! is_file( $nested_options['path'] . '/plugins/foo/docs/user/home.md' ) ) {
	$failures[] = 'nested layout export did not write into a fresh root';
}
// A conflicting route must be accounted for rather than silently disappearing.
file_put_contents( $nested_options['path'] . '/plugins/foo/docs/user/home.md', "---\nid: 999\n---\n\nOther post\n" );
$failed_export = WP_Markdown_CLI::export( $nested_options );
if ( false !== $failed_export['success'] || 1 !== $failed_export['candidate_count'] || 0 !== $failed_export['written_count'] || 1 !== $failed_export['skipped_count'] || 17 !== ( $failed_export['skipped'][0]['id'] ?? null ) || 'plugins/foo/docs/user/home.md' !== ( $failed_export['skipped'][0]['path'] ?? null ) || empty( $failed_export['skipped'][0]['reason'] ) ) {
	$failures[] = 'failed export write was not reported as a skip with unsuccessful status';
}
$dry_export = WP_Markdown_CLI::export( array_merge( $nested_options, array( 'dry_run' => true ) ) );
if ( true !== $dry_export['success'] || 0 !== $dry_export['written_count'] || 0 !== $dry_export['skipped_count'] ) {
	$failures[] = 'dry-run export was incorrectly reported as a failed write';
}

// Exercise the actual import result and source-meta update matching for plain docs.
$GLOBALS['mdi_test_filters']['markdown_db_import_post_content'] = array();
$GLOBALS['mdi_test_filters']['markdown_db_content_format_convert'] = array();
$GLOBALS['mdi_test_posts'] = array();
$GLOBALS['mdi_test_meta'] = array();
$plain_root = $tmp_root . '/plain';
mkdir( $plain_root . '/plugins/foo/docs/user', 0777, true );
$plain_path = $plain_root . '/plugins/foo/docs/user/home.md';
file_put_contents( $plain_path, "# Plain home\n\nPlain body.\n" );
markdown_db_register_content_layout_profile( 'plain-import-fixture', array(
	'enumerate' => static fn(): array => array( 'plugins/foo/docs/user/home.md' ),
	'map_source' => static fn( string $path ): array => array( 'post_type' => 'page', 'post_name' => 'plain-home', 'source_identity' => $path ),
	'path_for_post' => static fn(): string => 'plugins/foo/docs/user/home.md',
) );
$plain_options = array( 'path' => $plain_root, 'layout_profile' => 'plain-import-fixture', 'profile' => 'markdown-h1-title' );
$required_import = WP_Markdown_CLI::import( array_merge( $plain_options, array( 'profile' => 'native' ) ) );
if ( 1 !== $required_import['skipped_count'] || 'missing_frontmatter' !== ( $required_import['skipped'][0]['reason'] ?? '' ) || 'plugins/foo/docs/user/home.md' !== ( $required_import['skipped'][0]['path'] ?? '' ) ) {
	$failures[] = 'frontmatter-required CLI import silently dropped a plain file';
}
$plain_import = WP_Markdown_CLI::import( $plain_options );
$plain_id = $plain_import['created'][0]['id'] ?? 0;
$before = $GLOBALS['mdi_test_posts'][ $plain_id ] ?? null;
$repeat = WP_Markdown_CLI::import( $plain_options );
if ( 1 !== $plain_import['create_count'] || 0 !== $repeat['update_count'] || 1 !== $repeat['unchanged_count'] || 0 !== $repeat['create_count'] || ! $before || 'Plain home' !== $before->post_title || 'plain-home' !== $before->post_name || 'Plain body.' !== $before->post_content || $before != $GLOBALS['mdi_test_posts'][ $plain_id ] ) {
	$failures[] = 'plain docs import/re-import did not update the same source without content changes';
}
$fresh_plain_storage = new WP_Markdown_Storage( $plain_root );
$fresh_plain_storage->set_content_layout_profile( 'plain-import-fixture' );
$fresh_plain_storage->set_frontmatter_profile( 'markdown-h1-title' );
if ( $plain_path !== $fresh_plain_storage->path_for_post( $plain_id ) ) {
	$failures[] = 'fresh layout storage could not find a frontmatter-free imported post by ID';
}
$plain_export = WP_Markdown_CLI::export( array_merge( $plain_options, array( 'post_types' => 'page' ) ) );
if ( 1 !== $plain_export['written_count'] || "# Plain home\n\nPlain body.\n" !== file_get_contents( $plain_path ) ) {
	$failures[] = 'plain docs CLI export did not reproduce the source bytes';
}
// Regression #473: dateless updates keep publish dates and delegate modified dates to WordPress.
if ( '2026-11-02 01:45:00' !== ( $before->post_date ?? '' ) || '2026-11-02 01:45:00' !== ( $before->post_date_gmt ?? '' ) ) {
	$failures[] = 'a new dateless post did not receive a publish date';
}
$stored_date = '2026-07-30 12:34:56';
$GLOBALS['mdi_test_posts'][ $plain_id ]->post_date = $stored_date;
$GLOBALS['mdi_test_posts'][ $plain_id ]->post_date_gmt = $stored_date;
file_put_contents( $plain_path, "# Plain home\n\nUpdated body.\n" );
$calls_before = $GLOBALS['mdi_insert_calls'];
$changed_import = WP_Markdown_CLI::import( $plain_options );
if ( 1 !== $changed_import['update_count'] || 0 !== $changed_import['unchanged_count'] || $calls_before + 1 !== $GLOBALS['mdi_insert_calls'] ) {
	$failures[] = 'one changed file did not produce exactly one write';
}
$updated = $GLOBALS['mdi_test_posts'][ $plain_id ];
if ( $stored_date !== $updated->post_date || $stored_date !== $updated->post_date_gmt || 'Updated body.' !== $updated->post_content ) {
	$failures[] = 'dateless import reset the existing publish date or did not update content';
}
foreach ( array( 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ) as $field ) {
	if ( array_key_exists( $field, $GLOBALS['mdi_last_postarr'] ) ) {
		$failures[] = 'dateless update explicitly supplied ' . $field;
	}
}

// Native frontmatter uses zero dates for absent dates; dated sources must still override dates.
file_put_contents( $plain_path, "---\ntitle: Plain home\ntype: page\nslug: plain-home\n---\n\nNative updated body.\n" );
$native_options = array_merge( $plain_options, array( 'profile' => 'native' ) );
WP_Markdown_CLI::import( $native_options );
if ( $stored_date !== $GLOBALS['mdi_test_posts'][ $plain_id ]->post_date || 'Native updated body.' !== $GLOBALS['mdi_test_posts'][ $plain_id ]->post_content ) {
	$failures[] = 'dateless native frontmatter reset the existing date or did not update content';
}
$source_date = '2026-08-15 10:20:30';
file_put_contents( $plain_path, "---\ntitle: Plain home\ntype: page\nslug: plain-home\ndate: '$source_date'\n---\n\nDated body.\n" );
WP_Markdown_CLI::import( $native_options );
foreach ( array( 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ) as $field ) {
	if ( $source_date !== ( $GLOBALS['mdi_last_postarr'][ $field ] ?? null ) ) {
		$failures[] = 'dated source no longer supplies ' . $field;
	}
}

// Reconciliation has its own write-array builder; keep the same update/create contract there.
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-wordpress-reconciliation-adapter.php';
$adapter_class = new ReflectionClass( WP_Markdown_WordPress_Reconciliation_Adapter::class );
$adapter = $adapter_class->newInstanceWithoutConstructor();
$builder = $adapter_class->getMethod( 'post_array' );
$builder->setAccessible( true );
foreach ( array( '', '0000-00-00 00:00:00', $source_date ) as $date ) {
	foreach ( array( 0, $plain_id ) as $existing_id ) {
		$source = (object) array( 'post_date' => $date, 'post_date_gmt' => $date, 'post_content' => 'Reconciled body.' );
		$postarr = $builder->invoke( $adapter, $source, $existing_id );
		$expect_dates = 0 === $existing_id || $source_date === $date;
		if ( $expect_dates !== array_key_exists( 'post_date', $postarr ) || $expect_dates !== array_key_exists( 'post_date_gmt', $postarr ) || 'Reconciled body.' !== $postarr['post_content'] ) {
			$failures[] = 'reconciliation did not preserve the dateless update/create contract';
		}
	}
}
// Batch regression: changing one of two sources must leave the other untouched.
$GLOBALS['mdi_test_posts'] = array();
$GLOBALS['mdi_test_meta'] = array();
$batch_root = $tmp_root . '/batch';
mkdir( $batch_root . '/page', 0755, true );
foreach ( array( 201, 202 ) as $id ) {
	file_put_contents( $batch_root . '/page/item-' . $id . '.md', "---\nid: $id\ntitle: Item $id\ntype: page\nslug: item-$id\n---\n\nBody $id.\n" );
}
$batch_options = array( 'path' => $batch_root, 'no_convert' => true );
WP_Markdown_CLI::import( $batch_options );
// WordPress returns numeric columns as strings from the database.
foreach ( $GLOBALS['mdi_test_posts'] as $stored ) {
	foreach ( array( 'post_author', 'post_parent', 'menu_order', 'comment_count' ) as $field ) {
		$stored->$field = (string) $stored->$field;
	}
}
$calls_before = $GLOBALS['mdi_insert_calls'];
foreach ( array( true, false ) as $dry_run ) {
	$batch = WP_Markdown_CLI::import( array_merge( $batch_options, array( 'dry_run' => $dry_run ) ) );
	if ( 0 !== $batch['update_count'] || 2 !== $batch['unchanged_count'] || $calls_before !== $GLOBALS['mdi_insert_calls'] ) {
		$failures[] = 'unchanged batch did not avoid all writes';
	}
}
file_put_contents( $batch_root . '/page/item-201.md', "---\nid: 201\ntitle: Changed\ntype: page\nslug: item-201\n---\n\nChanged body.\n" );
foreach ( array( true, false ) as $dry_run ) {
	$batch = WP_Markdown_CLI::import( array_merge( $batch_options, array( 'dry_run' => $dry_run ) ) );
	if ( 1 !== $batch['update_count'] || 1 !== $batch['unchanged_count'] ) {
		$failures[] = 'one changed source did not isolate the batch update';
	}
}
if ( $calls_before + 1 !== $GLOBALS['mdi_insert_calls'] ) {
	$failures[] = 'one changed source did not write exactly once';
}
// Managed metadata drift must still trigger a write even when fields match.
$GLOBALS['mdi_test_meta'][202]['_markdown_source_hash'] = 'stale';
$batch = WP_Markdown_CLI::import( $batch_options );
if ( 1 !== $batch['update_count'] || 1 !== $batch['unchanged_count'] ) {
	$failures[] = 'managed source metadata drift was ignored';
}
mdi_mysql_import_export_rm_rf( $tmp_root );

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo 'PASS: MySQL-compatible import/export abilities round-trip markdown posts' . PHP_EOL;
