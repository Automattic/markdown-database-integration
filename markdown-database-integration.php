<?php
/**
 * Plugin Name: Markdown Database Integration
 * Plugin URI: https://github.com/chubes4/markdown-database-integration
 * Description: WordPress database integration that stores content as markdown files. SQLite for machinery, markdown for knowledge. AI-native WordPress storage layer.
 * Version: 0.5.1
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

define( 'MARKDOWN_DB_VERSION', '0.5.1' );
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
// Substrate veto: keep post_content as raw markdown for markdown-managed
// post types.
//
// Block Format Bridge ships a `wp_insert_post_data` hook (priority 5) that
// normalises non-html source formats into serialised block markup before
// WordPress writes them. That's the right behaviour on a vanilla WordPress
// install — the editor expects blocks. It's the wrong behaviour for an
// MDI-managed CPT, where the source of truth is the .md file on disk and
// post_content should mirror it as raw markdown. Without the veto the round
// trip becomes:
//
//   markdown agent input
//     → BFB on insert: markdown → blocks
//   SQLite stores blocks
//     → MDI write engine: blocks → markdown for the .md file
//   .md file on disk
//
// The blocks→markdown hop loses fidelity (heading + list + paragraph re-
// ordering, attribution prose duplicating across sibling blocks). MDI never
// wanted blocks in post_content in the first place; the right answer is to
// short-circuit BFB's insert-time conversion for any markdown-managed type
// and let post_content stay as the raw markdown the agent sent.
//
// The veto fires regardless of mode (mirror or primary) because both modes
// mirror post_content to .md files; the round-trip is lossy in either.
//
// See GitHub issue #82 and chubes4/block-format-bridge#8.
// ---------------------------------------------------------------------------

/**
 * Whether a post type is excluded from markdown storage.
 *
 * Mirror of `WP_Markdown_Storage::is_markdown_type()` against the
 * `MARKDOWN_DB_EXCLUDED_TYPES` constant. Inlined here so the BFB skip
 * filter callback (which runs on every `wp_insert_post_data` call) can
 * answer without instantiating Storage.
 *
 * @param string $post_type The post type slug.
 * @return bool
 */
function markdown_db_is_markdown_type( string $post_type ): bool {
	if ( '' === $post_type ) {
		return false;
	}
	$excluded = defined( 'MARKDOWN_DB_EXCLUDED_TYPES' )
		? array_map( 'trim', explode( ',', (string) MARKDOWN_DB_EXCLUDED_TYPES ) )
		: array();
	return ! in_array( $post_type, $excluded, true );
}

/**
 * Veto BFB's insert-time markdown→blocks conversion for markdown-managed
 * post types.
 *
 * Lets `post_content` flow into wp_posts as the raw markdown the caller
 * sent, so MDI's write engine writes that same markdown to the .md file
 * with no lossy blocks→markdown round-trip. The frontend `the_content`
 * filter (priority 1, below) and REST prepare filter (priority 5, below)
 * still convert markdown→HTML at render time via `bfb_convert()`.
 *
 * Filter contract:
 *   apply_filters( 'bfb_skip_insert_conversion', false, $data, $postarr, $format )
 *
 * Returning true short-circuits BFB's conversion. We veto when:
 *   - the source format is `markdown` (other formats are out of MDI's
 *     scope — let html→blocks-via-h2bc continue to fire normally), AND
 *   - the post type is one MDI manages (mirrored to .md files).
 *
 * @param bool   $skip    Default skip flag.
 * @param array  $data    Sanitized post data.
 * @param array  $postarr Original wp_insert_post() array.
 * @param string $format  Resolved BFB source format slug.
 * @return bool
 */
function markdown_db_bfb_skip_insert_conversion( $skip, $data, $postarr, $format ): bool {
	if ( $skip ) {
		return true;
	}
	if ( 'markdown' !== $format ) {
		return false;
	}
	$post_type = (string) ( $data['post_type'] ?? '' );
	return markdown_db_is_markdown_type( $post_type );
}
add_filter( 'bfb_skip_insert_conversion', 'markdown_db_bfb_skip_insert_conversion', 10, 4 );

// ---------------------------------------------------------------------------
// Render-time markdown → HTML conversion.
//
// post_content stores raw markdown. Conversion to HTML happens at the edges:
//   1. the_content filter (priority 1) — frontend rendering
//   2. rest_prepare_{type} filters (priority 5) — REST API / block editor
//
// This ensures CLI, abilities, and direct post_content reads get markdown.
// Conversion is delegated to Block Format Bridge (`bfb_convert()`); see
// GitHub issues #30 and #82.
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

	// Block Format Bridge ships the markdown→HTML adapter. If it's not
	// loaded (standalone plugin not active and not bundled via composer),
	// pass through unchanged.
	if ( ! function_exists( 'bfb_convert' ) ) {
		return $content;
	}

	// CommonMark produces proper <p> tags, so disable wpautop to prevent
	// double-wrapping paragraphs. We remove it here (inside the filter) so
	// it only affects posts that actually get markdown→HTML conversion.
	remove_filter( 'the_content', 'wpautop' );

	return bfb_convert( $content, 'markdown', 'html' );
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
	if ( ! function_exists( 'bfb_convert' ) ) {
		return $response;
	}

	$data = $response->get_data();

	if ( empty( $data['content'] ) ) {
		return $response;
	}

	$context = $request->get_param( 'context' ) ?? 'view';

	// Edit context: the block editor needs HTML in content.raw so the
	// html-to-blocks-converter can parse it into blocks.
	if ( 'edit' === $context && ! empty( $data['content']['raw'] ) ) {
		$raw = $data['content']['raw'];
		if ( ! str_contains( $raw, '<!-- wp:' ) ) {
			$data['content']['raw'] = bfb_convert( $raw, 'markdown', 'html' );
		}
	}

	// All contexts: ensure content.rendered is HTML.
	if ( ! empty( $data['content']['rendered'] ) ) {
		$rendered = $data['content']['rendered'];
		// If rendered still has markdown patterns (not yet filtered), convert.
		if ( ! str_contains( $rendered, '<p>' ) && preg_match( '/^#{1,6}\s|\*\*/m', $rendered ) ) {
			$data['content']['rendered'] = bfb_convert( $rendered, 'markdown', 'html' );
		}
	}

	$response->set_data( $data );
	return $response;
}

/**
 * Convert block-editor REST writes back to markdown before storage.
 *
 * MDI owns the stored post_content shape for markdown-managed post types:
 * it should be raw markdown, not serialized block markup. The REST prepare
 * filter above gives the editor HTML, and editor saves can send serialized
 * blocks back. Normalize those blocks through BFB, reject malformed markup
 * with a structured error, then convert valid blocks to markdown before the
 * row reaches wp_posts / the write engine.
 *
 * @param object $prepared_post Prepared post object from the REST controller.
 * @param object $request       REST request.
 * @return object|WP_Error Prepared post object, or validation error.
 */
function markdown_db_rest_pre_insert_filter( $prepared_post, $request ) {
	if ( ! function_exists( 'bfb_normalize' ) || ! function_exists( 'bfb_convert' ) ) {
		return $prepared_post;
	}

	$post_type = (string) ( $prepared_post->post_type ?? '' );
	if ( '' === $post_type && is_object( $request ) && method_exists( $request, 'get_param' ) ) {
		$post_type = (string) ( $request->get_param( 'type' ) ?? '' );
	}

	if ( ! markdown_db_is_markdown_type( $post_type ) ) {
		return $prepared_post;
	}

	$content = (string) ( $prepared_post->post_content ?? '' );
	if ( '' === trim( $content ) || ! str_contains( $content, '<!-- wp:' ) ) {
		return $prepared_post;
	}

	$normalized = bfb_normalize( $content, 'blocks' );
	$is_error   = function_exists( 'is_wp_error' ) ? is_wp_error( $normalized ) : $normalized instanceof WP_Error;
	if ( $is_error ) {
		return $normalized;
	}

	$prepared_post->post_content = bfb_convert( (string) $normalized, 'blocks', 'markdown' );
	return $prepared_post;
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
		add_filter( "rest_pre_insert_{$post_type}", 'markdown_db_rest_pre_insert_filter', 5, 2 );
		add_filter( "rest_prepare_{$post_type}", 'markdown_db_rest_prepare_filter', 5, 3 );
	}
}
add_action( 'init', 'markdown_db_register_rest_filters', 25 );
