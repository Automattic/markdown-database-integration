<?php
/** Backward-compatible public name for the SQLite runtime adapter. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-sqlite-runtime-adapter.php';

class_alias( WP_Markdown_SQLite_Runtime_Adapter::class, 'WP_Markdown_Driver' );
