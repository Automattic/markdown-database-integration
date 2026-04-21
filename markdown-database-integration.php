<?php
/**
 * Plugin Name: Markdown Database Integration
 * Plugin URI: https://github.com/chubes4/markdown-database-integration
 * Description: WordPress database integration that stores content as markdown files. SQLite for machinery, markdown for knowledge. AI-native WordPress storage layer.
 * Version: 0.2.0
 * Author: Chris Huber
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: markdown-database-integration
 * Requires at least: 6.9
 * Requires PHP: 7.4
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

define( 'MARKDOWN_DB_VERSION', '0.2.0' );
define( 'MARKDOWN_DB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

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

// ---------------------------------------------------------------------------
// Render-time markdown → HTML conversion.
//
// post_content stores raw markdown. Conversion to HTML happens at the edges:
//   1. the_content filter (priority 1) — frontend rendering
//   2. rest_prepare_{type} filters (priority 5) — REST API / block editor
//
// This ensures CLI, abilities, and direct post_content reads get markdown.
// See GitHub issue #30.
// ---------------------------------------------------------------------------

/**
 * Convert markdown post_content to HTML for frontend rendering.
 *
 * Runs at priority 1 (before wpautop at 10, shortcodes at 11, etc.)
 * so downstream filters see clean HTML, not raw markdown.
 *
 * Only converts content from markdown-managed post types.
 *
 * @param string $content The post content.
 * @return string HTML content.
 */
function markdown_db_the_content_filter( string $content ): string {
	if ( empty( trim( $content ) ) ) {
		return $content;
	}

	// Already block markup — skip.
	if ( str_contains( $content, '<!-- wp:' ) ) {
		return $content;
	}

	// Already HTML (no markdown indicators) — skip.
	// Check for common markdown patterns: headings, bold, links, lists.
	if ( str_contains( $content, '<p>' ) && ! preg_match( '/^#{1,6}\s|\*\*|^\-\s|^\d+\.\s|\[.*\]\(.*\)/m', $content ) ) {
		return $content;
	}

	// Load the converter (autoloaded via db.php at boot).
	if ( ! class_exists( 'WP_Markdown_Converter' ) ) {
		return $content;
	}

	// CommonMark produces proper <p> tags, so disable wpautop to prevent
	// double-wrapping paragraphs. We remove it here (inside the filter) so
	// it only affects posts that actually get markdown→HTML conversion.
	remove_filter( 'the_content', 'wpautop' );

	$converter = WP_Markdown_Converter::get_instance();
	return $converter->markdown_to_html( $content );
}
add_filter( 'the_content', 'markdown_db_the_content_filter', 1 );

/**
 * Convert markdown to HTML in REST API responses.
 *
 * Runs at priority 5 — before the html-to-blocks-converter REST filter
 * (priority 10) which converts HTML → blocks for the block editor.
 *
 * For edit context: converts content.raw so the h2b converter can parse it.
 * For view context: converts content.rendered so the frontend API gets HTML.
 */
function markdown_db_rest_prepare_filter( $response, $post, $request ) {
	if ( ! class_exists( 'WP_Markdown_Converter' ) ) {
		return $response;
	}

	$data = $response->get_data();

	if ( empty( $data['content'] ) ) {
		return $response;
	}

	$converter = WP_Markdown_Converter::get_instance();
	$context   = $request->get_param( 'context' ) ?? 'view';

	// Edit context: the block editor needs HTML in content.raw so the
	// html-to-blocks-converter can parse it into blocks.
	if ( 'edit' === $context && ! empty( $data['content']['raw'] ) ) {
		$raw = $data['content']['raw'];
		if ( ! str_contains( $raw, '<!-- wp:' ) ) {
			$data['content']['raw'] = $converter->markdown_to_html( $raw );
		}
	}

	// All contexts: ensure content.rendered is HTML.
	if ( ! empty( $data['content']['rendered'] ) ) {
		$rendered = $data['content']['rendered'];
		// If rendered still has markdown patterns (not yet filtered), convert.
		if ( ! str_contains( $rendered, '<p>' ) && preg_match( '/^#{1,6}\s|\*\*/m', $rendered ) ) {
			$data['content']['rendered'] = $converter->markdown_to_html( $rendered );
		}
	}

	$response->set_data( $data );
	return $response;
}

/**
 * Register REST filters for all markdown-managed post types.
 *
 * Runs at init priority 25 — after custom post types are registered (10)
 * and after html-to-blocks-converter registers its filters (20).
 */
function markdown_db_register_rest_filters() {
	$post_types = array_keys( get_post_types( [ 'show_in_rest' => true, 'public' => true ] ) );

	foreach ( $post_types as $post_type ) {
		add_filter( "rest_prepare_{$post_type}", 'markdown_db_rest_prepare_filter', 5, 3 );
	}
}
add_action( 'init', 'markdown_db_register_rest_filters', 25 );
