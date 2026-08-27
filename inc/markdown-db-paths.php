<?php
/** Default canonical roots for the file-backed database. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'markdown_db_default_content_dir' ) ) {
	function markdown_db_default_content_dir(): string {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return '';
		}
		$db     = WP_CONTENT_DIR . '/db';
		$legacy = WP_CONTENT_DIR . '/markdown';
		if ( ! is_dir( $db ) && is_dir( $legacy ) ) {
			return $legacy;
		}
		return $db;
	}
}
