<?php
/**
 * Content Converter — Transforms between markdown, HTML, and block markup.
 *
 * This is the conversion layer that makes .md files store clean markdown
 * instead of raw Gutenberg block markup. HTML is the intermediary format:
 *
 *   WRITE: Blocks → serialize_blocks() → Block HTML → strip comments → HTML → markdown
 *   READ:  Markdown → commonmark → HTML (→ optionally html_to_blocks for block markup)
 *
 * Ref: GitHub issue #11
 *
 * @package Markdown_Database_Integration
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\HTMLToMarkdown\HtmlConverter;

class WP_Markdown_Converter {

	/**
	 * The markdown-to-HTML converter (league/commonmark).
	 *
	 * @var GithubFlavoredMarkdownConverter
	 */
	private $md_to_html;

	/**
	 * The HTML-to-markdown converter (league/html-to-markdown).
	 *
	 * @var HtmlConverter
	 */
	private $html_to_md;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — initializes both converters.
	 */
	private function __construct() {
		$this->md_to_html = new GithubFlavoredMarkdownConverter( [
			'html_input'         => 'allow',
			'allow_unsafe_links' => false,
		] );

		$this->html_to_md = new HtmlConverter( [
			'strip_tags'         => false,
			'hard_break'         => true,
			'preserve_comments'  => false,
			'remove_nodes'       => '',
		] );
	}

	/**
	 * Convert block HTML (from post_content) to clean markdown for .md files.
	 *
	 * Pipeline: Block HTML → strip block comments → clean HTML → markdown
	 *
	 * @param string $block_html The block-commented HTML from post_content.
	 * @return string Clean markdown.
	 */
	public function blocks_to_markdown( string $block_html ): string {
		if ( empty( trim( $block_html ) ) ) {
			return '';
		}

		// Step 1: Strip Gutenberg block comment delimiters.
		$html = $this->strip_block_comments( $block_html );

		// Step 2: Clean up whitespace from removed comments.
		$html = $this->normalize_html( $html );

		// Step 3: Convert HTML to markdown.
		$markdown = $this->html_to_markdown( $html );

		return trim( $markdown );
	}

	/**
	 * Convert clean markdown (from .md files) to HTML for SQLite.
	 *
	 * The returned HTML is clean (no block comments). WordPress and the
	 * block editor can handle this — the editor converts to blocks via
	 * rawHandler when opened.
	 *
	 * @param string $markdown The markdown content.
	 * @return string HTML content.
	 */
	public function markdown_to_html( string $markdown ): string {
		if ( empty( trim( $markdown ) ) ) {
			return '';
		}

		return trim( $this->md_to_html->convert( $markdown )->getContent() );
	}

	/**
	 * Convert clean markdown to block markup for SQLite.
	 *
	 * Uses html_to_blocks_raw_handler() if the HTML-to-Blocks plugin is
	 * active. Falls back to clean HTML (which WordPress handles fine).
	 *
	 * @param string $markdown The markdown content.
	 * @return string Block-commented HTML, or clean HTML as fallback.
	 */
	public function markdown_to_blocks( string $markdown ): string {
		$html = $this->markdown_to_html( $markdown );

		if ( empty( $html ) ) {
			return '';
		}

		// Use the HTML-to-Blocks converter if available.
		// Note: At boot time (Loader), block types aren't registered yet so
		// html_to_blocks_raw_handler cannot produce valid blocks. In that case,
		// we return HTML and rely on the wp_insert_post_data filter to convert
		// HTML → blocks when a post is first saved through WordPress.
		if ( function_exists( 'html_to_blocks_raw_handler' ) ) {
			$blocks = html_to_blocks_raw_handler( [ 'HTML' => $html ] );
			if ( ! empty( $blocks ) && function_exists( 'serialize_blocks' ) ) {
				return serialize_blocks( $blocks );
			}
		}

		// Fallback: return clean HTML. The html-to-blocks-converter plugin
		// converts this to blocks via the wp_insert_post_data filter on save.
		return $html;
	}

	/**
	 * Strip Gutenberg block comment delimiters from HTML.
	 *
	 * Removes both opening (<!-- wp:blockname {...} -->) and closing
	 * (<!-- /wp:blockname -->) comments, plus self-closing void blocks
	 * (<!-- wp:separator /-->).
	 *
	 * @param string $html HTML with block comments.
	 * @return string HTML without block comments.
	 */
	public function strip_block_comments( string $html ): string {
		// Self-closing blocks: <!-- wp:separator /-->
		$html = preg_replace( '/<!--\s*wp:[a-z][a-z0-9-]*\/[a-z0-9-]*\s+(?:\{[^}]*\}\s*)?\/-->\s*/s', '', $html );

		// Opening blocks: <!-- wp:paragraph --> or <!-- wp:heading {"level":2} -->
		$html = preg_replace( '/<!--\s*wp:[a-z][a-z0-9-]*(?:\/[a-z0-9-]*)?\s*(?:\{[^}]*\})?\s*-->\s*/s', '', $html );

		// Closing blocks: <!-- /wp:paragraph -->
		$html = preg_replace( '/<!--\s*\/wp:[a-z][a-z0-9-]*(?:\/[a-z0-9-]*)?\s*-->\s*/s', '', $html );

		return $html;
	}

	/**
	 * Detect if content is markdown (vs HTML or block markup).
	 *
	 * Heuristic: if it contains block comments or significant HTML tags,
	 * it's not markdown.
	 *
	 * @param string $content The content to check.
	 * @return bool True if the content appears to be markdown.
	 */
	public function is_markdown( string $content ): bool {
		// Block comments = definitely not markdown.
		if ( str_contains( $content, '<!-- wp:' ) ) {
			return false;
		}

		// Check for significant HTML block-level tags.
		// Inline tags like <strong>, <em>, <a>, <code> are fine in markdown.
		$block_tags = [ '<div', '<p>', '<h1', '<h2', '<h3', '<h4', '<h5', '<h6',
			'<table', '<ul>', '<ol>', '<blockquote', '<figure', '<section' ];

		$html_tag_count = 0;
		foreach ( $block_tags as $tag ) {
			$html_tag_count += substr_count( strtolower( $content ), $tag );
		}

		// If there are many block-level HTML tags, it's probably HTML.
		// A few is OK — markdown files can contain some inline HTML.
		return $html_tag_count < 3;
	}

	/**
	 * Convert HTML to markdown using league/html-to-markdown.
	 *
	 * @param string $html Clean HTML (no block comments).
	 * @return string Markdown content.
	 */
	private function html_to_markdown( string $html ): string {
		return $this->html_to_md->convert( $html );
	}

	/**
	 * Normalize HTML after stripping block comments.
	 *
	 * Cleans up extra whitespace, empty lines, and formatting artifacts
	 * left behind after removing block comment delimiters.
	 *
	 * @param string $html HTML with comments already stripped.
	 * @return string Cleaned HTML.
	 */
	private function normalize_html( string $html ): string {
		// Collapse multiple blank lines to a single one.
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );

		// Remove leading/trailing whitespace on each line.
		$lines = explode( "\n", $html );
		$lines = array_map( 'rtrim', $lines );
		$html  = implode( "\n", $lines );

		return trim( $html );
	}
}
