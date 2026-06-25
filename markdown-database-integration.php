<?php
/**
 * Plugin Name: Markdown Database Integration
 * Plugin URI: https://github.com/chubes4/markdown-database-integration
 * Description: WordPress database integration that stores content as markdown files. SQLite for machinery, markdown for knowledge. AI-native WordPress storage layer.
 * Version: 0.8.3
 * Author: Chris Huber
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: markdown-database-integration
 * Requires at least: 6.9
 * Requires PHP: 8.1
 *
 * Requires the SQLite Database Integration plugin (sqlite-database-integration).
 *
 * Modes:
 *   - 'mirror'  (Phase 1): SQLite is primary. Markdown files are mirrored on every write.
 *                           WordPress reads from SQLite. AI agents read from markdown.
 *   - 'primary' (Phase 2): Markdown is primary. SQLite is an index rebuilt from .md files.
 *                           WordPress reads are served from the index. Writes go to markdown first.
 *
 * The markdown files are the knowledge layer:
 *   - AI agents read them directly (no API, no query, just grep)
 *   - Git syncs them across machines and people
 *   - Every post is a file, every file is searchable
 *   - WordPress keeps working normally
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MARKDOWN_DB_VERSION', '0.8.3' );
define( 'MARKDOWN_DB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$markdown_database_integration_autoload = MARKDOWN_DB_PLUGIN_DIR . 'vendor/autoload.php';
if ( function_exists( 'did_action' ) && file_exists( $markdown_database_integration_autoload ) ) {
	require_once $markdown_database_integration_autoload;
}

require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-frontmatter-profiles.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-storage.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-frontmatter-migration.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-sqlite-recovery.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-cli.php';

/**
 * The content directory where markdown files are stored.
 * Override in wp-config.php: define( 'MARKDOWN_DB_CONTENT_DIR', '/path/to/wiki' );
 */
if ( ! defined( 'MARKDOWN_DB_CONTENT_DIR' ) ) {
	define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/markdown' );
}

/**
 * Operating mode: 'mirror' or 'primary'.
 * Override in wp-config.php: define( 'MARKDOWN_DB_MODE', 'primary' );
 */
if ( ! defined( 'MARKDOWN_DB_MODE' ) ) {
	define( 'MARKDOWN_DB_MODE', 'mirror' );
}

/**
 * Post types to exclude from markdown storage. Comma-separated.
 * These go to _tables/posts.json instead of .md files.
 * Override in wp-config.php to customize.
 */
if ( ! defined( 'MARKDOWN_DB_EXCLUDED_TYPES' ) ) {
	define( 'MARKDOWN_DB_EXCLUDED_TYPES', 'revision,auto-draft,nav_menu_item,customize_changeset,oembed_cache,wp_navigation,wp_global_styles,wp_template,wp_template_part' );
}

// MDI intentionally registers no content-format conversion hooks. It persists
// the post_content bytes WordPress receives; rendering/editor conversion lives
// in the application/content-format layer above this storage plugin.

// @phpstan-ignore-next-line Runtime wp-config.php constants are intentionally dynamic.
if ( defined( 'MARKDOWN_DB_INSTALL_FALLBACK' ) && (bool) constant( 'MARKDOWN_DB_INSTALL_FALLBACK' ) ) {
	add_action( 'wp_install', 'markdown_database_integration_import_seed_posts_after_install', 20 );
}

/**
 * Import markdown seed posts after a fallback SQLite install finishes.
 *
 * A partial primary store can contain markdown posts before it contains
 * installed-site options. The db.php drop-in lets WordPress install into normal
 * SQLite for that first request, then this hook imports seed posts into the new
 * tables so the fresh environment has content immediately.
 */
function markdown_database_integration_import_seed_posts_after_install(): void {
	$excluded_types = array_filter( array_map( 'trim', explode( ',', MARKDOWN_DB_EXCLUDED_TYPES ) ) );
	$storage        = new WP_Markdown_Storage( MARKDOWN_DB_CONTENT_DIR, $excluded_types );
	$posts          = $storage->get_all_posts( false );

	foreach ( $posts as $post ) {
		$post_type = (string) ( $post->post_type ?? 'post' );
		$post_name = (string) ( $post->post_name ?? '' );
		if ( '' !== $post_name && get_page_by_path( $post_name, OBJECT, $post_type ) ) {
			continue;
		}

		wp_insert_post(
			array(
				'import_id'         => (int) ( $post->ID ?? 0 ),
				'post_author'       => (int) ( $post->post_author ?? 1 ),
				'post_date'         => (string) ( $post->post_date ?? '' ),
				'post_date_gmt'     => (string) ( $post->post_date_gmt ?? '' ),
				'post_content'      => (string) ( $post->post_content ?? '' ),
				'post_title'        => (string) ( $post->post_title ?? '' ),
				'post_excerpt'      => (string) ( $post->post_excerpt ?? '' ),
				'post_status'       => (string) ( $post->post_status ?? 'publish' ),
				'comment_status'    => (string) ( $post->comment_status ?? 'open' ),
				'ping_status'       => (string) ( $post->ping_status ?? 'open' ),
				'post_password'     => (string) ( $post->post_password ?? '' ),
				'post_name'         => $post_name,
				'post_modified'     => (string) ( $post->post_modified ?? '' ),
				'post_modified_gmt' => (string) ( $post->post_modified_gmt ?? '' ),
				'post_parent'       => (int) ( $post->post_parent ?? 0 ),
				'menu_order'        => (int) ( $post->menu_order ?? 0 ),
				'post_type'         => $post_type,
				'post_mime_type'    => (string) ( $post->post_mime_type ?? '' ),
				'comment_count'     => (int) ( $post->comment_count ?? 0 ),
			),
			true
		);
	}
}

add_action( 'init', array( 'WP_Markdown_SQLite_Recovery', 'register' ) );
add_action( 'init', array( 'WP_Markdown_CLI', 'register' ) );
add_action( 'init', array( 'WP_Markdown_Frontmatter_Migration', 'maybe_run' ), 1 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'markdown-db import', array( 'WP_Markdown_CLI', 'import_cli' ) );
	WP_CLI::add_command( 'markdown-db export', array( 'WP_Markdown_CLI', 'export_cli' ) );
	WP_CLI::add_command( 'markdown-db recover-sqlite-posts', array( 'WP_Markdown_SQLite_Recovery', 'cli' ) );
}
