<?php
/**
 * Plugin Name: Markdown Database Integration (Drop-in)
 * Version: 0.2.0
 * Author: Chris Huber
 *
 * WordPress db.php drop-in that replaces the SQLite database file with
 * markdown files as the sole source of truth. In-memory SQLite is used
 * as the runtime query engine.
 *
 * Supports two modes (set MARKDOWN_DB_MODE in wp-config.php):
 *   'mirror'  — Phase 1: SQLite on disk, markdown mirrored on writes
 *   'primary' — Phase 2: In-memory SQLite, markdown files are the database
 *
 * This file goes in wp-content/db.php (replacing the SQLite drop-in's version).
 *
 * @package Markdown_Database_Integration
 */

define( 'SQLITE_DB_DROPIN_VERSION', '1.8.0' );
define( 'MARKDOWN_DB_DROPIN', true );

// Find the SQLite integration plugin.
$sqlite_plugin_implementation_folder_path = realpath( __DIR__ . '/mu-plugins/sqlite-database-integration' );
if ( ! file_exists( $sqlite_plugin_implementation_folder_path ) ) {
	$sqlite_plugin_implementation_folder_path = realpath( __DIR__ . '/plugins/sqlite-database-integration' );
}

// Bail if SQLite integration is not installed.
if ( ! $sqlite_plugin_implementation_folder_path || ! file_exists( $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/db.php' ) ) {
	return;
}

// Standard SQLite constants.
if ( ! defined( 'DATABASE_TYPE' ) ) {
	define( 'DATABASE_TYPE', 'sqlite' );
}
if ( ! defined( 'DB_ENGINE' ) ) {
	define( 'DB_ENGINE', 'sqlite' );
}

// Force the v2 AST driver — required for our integration.
if ( ! defined( 'WP_SQLITE_AST_DRIVER' ) ) {
	define( 'WP_SQLITE_AST_DRIVER', true );
}

// Load the SQLite integration's version and constants.
require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/database/version.php';
require_once $sqlite_plugin_implementation_folder_path . '/constants.php';

// Check PDO extensions.
if ( ! extension_loaded( 'pdo' ) || ! extension_loaded( 'pdo_sqlite' ) ) {
	return;
}

// Load the SQLite v2 driver stack (parser, lexer, connection, driver).
require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/database/load.php';

// Load the SQLite DB class.
require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/class-wp-sqlite-db.php';
require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/install-functions.php';

// Load our markdown classes.
$markdown_plugin_dir = null;

// Look in mu-plugins first, then plugins.
$possible_paths = array(
	__DIR__ . '/mu-plugins/markdown-database-integration',
	__DIR__ . '/plugins/markdown-database-integration',
);

foreach ( $possible_paths as $path ) {
	if ( is_dir( $path ) && file_exists( $path . '/inc/class-wp-markdown-storage.php' ) ) {
		$markdown_plugin_dir = $path;
		break;
	}
}

// If the markdown plugin isn't installed yet, fall back to standard SQLite.
if ( ! $markdown_plugin_dir ) {
	// Fallback: standard SQLite behavior.
	if ( defined( 'DB_NAME' ) && '' !== DB_NAME ) {
		$db_name = DB_NAME;
	} else {
		$db_name = 'database_name_here';
	}
	$GLOBALS['wpdb'] = new WP_SQLite_DB( $db_name );
	return;
}

// Load composer autoloader (for league/commonmark, league/html-to-markdown).
$composer_autoload = $markdown_plugin_dir . '/vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
	require_once $composer_autoload;
}

// Load markdown integration classes.
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-converter.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-storage.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-driver.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-search.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-write-engine.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-loader.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-db.php';

// Load plugin constants (if not already loaded via the plugin file).
if ( ! defined( 'MARKDOWN_DB_VERSION' ) ) {
	require_once $markdown_plugin_dir . '/markdown-database-integration.php';
}

// Create the database connection — our WP_Markdown_DB extends WP_SQLite_DB.
if ( defined( 'DB_NAME' ) && '' !== DB_NAME ) {
	$db_name = DB_NAME;
} else {
	$db_name = 'database_name_here';
}

$GLOBALS['wpdb'] = new WP_Markdown_DB( $db_name );

// Boot Query Monitor integration if present.
$qm_boot = $sqlite_plugin_implementation_folder_path . '/integrations/query-monitor/boot.php';
if ( file_exists( $qm_boot ) ) {
	require_once $qm_boot;
}
