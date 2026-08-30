<?php
/**
 * Stub WP_Markdown_Storage for smoke tests.
 *
 * Provides the minimum surface the search class needs: a content dir.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stub_WP_Markdown_Storage {
	private string $content_dir;

	public function __construct( string $content_dir ) {
		$this->content_dir = $content_dir;
	}

	public function get_content_dir(): string {
		return $this->content_dir;
	}

	public function get_all_posts_iterator( bool $include_source = false ): iterable {
		unset( $include_source );
		return array();
	}

	public function get_markdown_file_manifest_iterator(): iterable {
		return array();
	}

	public function get_excluded_types(): array {
		return array();
	}
}

// Alias so the type hint on WP_Markdown_Search::__construct() matches.
class_alias( Stub_WP_Markdown_Storage::class, 'WP_Markdown_Storage' );
