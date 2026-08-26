<?php
/**
 * Stub WP_Markdown_Driver for smoke tests.
 *
 * Returns a fixed file index without extending the real SQLite driver
 * (which requires a live SQLite connection to instantiate).
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stub_WP_Markdown_Driver {
	/** @var array<int, string> */
	private array $file_index;

	/**
	 * @param array<int, string> $file_index post_id => relative path
	 */
	public function __construct( array $file_index ) {
		$this->file_index = $file_index;
	}

	public function get_file_index_cache(): array {
		return $this->file_index;
	}
}

// Alias so the type hint on WP_Markdown_Search::__construct() matches.
class_alias( Stub_WP_Markdown_Driver::class, 'WP_Markdown_Driver' );
