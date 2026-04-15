<?php
/**
 * Plugin Name: Markdown Database Integration
 * Plugin URI: https://github.com/chubes4/markdown-database-integration
 * Description: WordPress database integration that stores content as markdown files. SQLite for machinery, markdown for knowledge. AI-native WordPress storage layer.
 * Version: 0.1.0
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
// Read-time conversion: markdown → HTML at the edges.
//
// SQLite stores raw markdown (the source of truth). Conversion to HTML
// happens on read for consumers that need it:
//   - Frontend: the_content filter → markdown to HTML
//   - REST API: rest_prepare_{type} filter → markdown to HTML in content.raw
//
// The html-to-blocks-converter plugin (if active) then converts HTML → blocks
// in the REST response for the block editor. This plugin doesn't know about
// blocks — it only converts markdown → HTML.
// ---------------------------------------------------------------------------

/**
 * Convert markdown to HTML on the frontend via the_content filter.
 *
 * Runs at priority 5 (before wpautop at 10) so markdown is converted first.
 * Only converts content that looks like markdown (no block delimiters, no
 * significant HTML tags).
 */
function markdown_db_render_content( string $content ): string {
	if ( empty( $content ) || has_blocks( $content ) ) {
		return $content;
	}

	$converter = WP_Markdown_Converter::get_instance();
	if ( ! $converter->is_markdown( $content ) ) {
		return $content;
	}

	// Since we return proper HTML, disable wpautop to prevent double-wrapping.
	remove_filter( 'the_content', 'wpautop' );

	return $converter->markdown_to_html( $content );
}

add_filter( 'the_content', 'markdown_db_render_content', 5 );

/**
 * Convert markdown to HTML in REST API responses for the block editor.
 *
 * Registers rest_prepare_{type} filters for all markdown-backed post types.
 * Converts content.raw from markdown → HTML so the html-to-blocks-converter
 * plugin (if active) can then convert HTML → blocks.
 *
 * Runs at priority 5 so it fires before html-to-blocks-converter (priority 10).
 */
function markdown_db_register_rest_filters() {
	$excluded = array_map( 'trim', explode( ',', MARKDOWN_DB_EXCLUDED_TYPES ) );
	$types    = get_post_types( [ 'show_in_rest' => true ] );

	foreach ( $types as $type ) {
		if ( in_array( $type, $excluded, true ) ) {
			continue;
		}
		add_filter( "rest_prepare_{$type}", 'markdown_db_convert_rest_content', 5, 3 );
	}
}

add_action( 'init', 'markdown_db_register_rest_filters' );

/**
 * Convert markdown to HTML in a REST API response.
 *
 * Only converts content.raw when the request context is 'edit' (block editor).
 * Other contexts (view, embed) are left for the_content filter or other renderers.
 *
 * @param WP_REST_Response $response The response object.
 * @param WP_Post          $post     The post object.
 * @param WP_REST_Request  $request  The request object.
 * @return WP_REST_Response
 */
function markdown_db_convert_rest_content( $response, $post, $request ) {
	if ( $request->get_param( 'context' ) !== 'edit' ) {
		return $response;
	}

	$data = $response->get_data();

	if ( empty( $data['content']['raw'] ) ) {
		return $response;
	}

	$raw = $data['content']['raw'];

	// Already block markup or HTML — don't convert.
	if ( strpos( $raw, '<!-- wp:' ) !== false ) {
		return $response;
	}

	$converter = WP_Markdown_Converter::get_instance();
	if ( ! $converter->is_markdown( $raw ) ) {
		return $response;
	}

	$data['content']['raw'] = $converter->markdown_to_html( $raw );
	$response->set_data( $data );

	return $response;
}
