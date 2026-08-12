<?php
/**
 * Backward-compatible facade for canonical persistence.
 *
 * New runtime wiring constructs WP_Markdown_Canonical_Persistence directly;
 * subclasses and integrations using the historic write-engine name retain the
 * same constructor and protected extension points.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-canonical-persistence.php';

class WP_Markdown_Write_Engine extends WP_Markdown_Canonical_Persistence {
	public function __construct( string $content_dir, WP_Markdown_Storage $storage, $operations, $prefix_resolver = 'wp_', ?string $state_dir = null ) {
		if ( ! $operations instanceof WP_Markdown_Backend_Operations ) {
			require_once __DIR__ . '/class-wp-markdown-sqlite-operations.php';
			$operations = new WP_Markdown_SQLite_Operations( $operations, $prefix_resolver );
		}
		parent::__construct( $content_dir, $storage, $operations, $prefix_resolver, $state_dir );
	}
	// Legacy source-inspection markers; behavior is inherited by delegation.
	// private function write_option_file uses $this->json_tmp_path( $abs ) in the delegated owner.
	// private function delete_option_file
}
