<?php
/**
 * Plugin Name: Markdown Database Integration (Drop-in)
 * Version: 0.2.0
 * Author: Chris Huber
 * Text Domain: markdown-database-integration
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

// Find the SQLite integration plugin. Probe order:
//   1. wp-content/mu-plugins/sqlite-database-integration  (typical install)
//   2. wp-content/plugins/sqlite-database-integration     (regular plugin install)
//   3. /internal/shared/sqlite-database-integration       (WordPress Playground)
//
// The Playground location is the bundled SDI install used by
// @wp-playground/cli — required so MDI works under the homeboy-extensions
// wordpress backend (test runner, bench dispatcher) without a separate
// drop-in. Playground exposes SDI via VFS at this absolute path; the host
// filesystem doesn't have it, so the realpath() probe is unsafe — file_exists
// suffices because the file is either there or it's not.
$sqlite_plugin_implementation_folder_path = realpath( __DIR__ . '/mu-plugins/sqlite-database-integration' );
if ( ! $sqlite_plugin_implementation_folder_path || ! file_exists( $sqlite_plugin_implementation_folder_path ) ) {
	$sqlite_plugin_implementation_folder_path = realpath( __DIR__ . '/plugins/sqlite-database-integration' );
}
if ( ! $sqlite_plugin_implementation_folder_path || ! file_exists( $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/db.php' ) ) {
	$playground_sqlite = '/internal/shared/sqlite-database-integration';
	if ( file_exists( $playground_sqlite . '/wp-includes/sqlite/db.php' ) ) {
		$sqlite_plugin_implementation_folder_path = $playground_sqlite;
	}
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

// Primary mode uses markdown-index.sqlite as the active query engine. The
// SQLite Integration install shim opens FQDB directly during wp_install(), so
// FQDB must point at the same file MDI's wpdb instance is using.
if ( defined( 'MARKDOWN_DB_MODE' ) && 'primary' === MARKDOWN_DB_MODE ) {
	$markdown_db_content_dir = defined( 'MARKDOWN_DB_CONTENT_DIR' )
		? MARKDOWN_DB_CONTENT_DIR
		: WP_CONTENT_DIR . '/markdown';
	$markdown_db_index_path = dirname( rtrim( $markdown_db_content_dir, '/\\' ) ) . '/markdown-index.sqlite';

	if ( ! defined( 'MARKDOWN_DB_INDEX_PATH' ) ) {
		define( 'MARKDOWN_DB_INDEX_PATH', $markdown_db_index_path );
	}
	if ( ! defined( 'FQDBDIR' ) ) {
		define( 'FQDBDIR', rtrim( dirname( MARKDOWN_DB_INDEX_PATH ), '/\\' ) . '/' );
	}
	if ( ! defined( 'FQDB' ) ) {
		define( 'FQDB', MARKDOWN_DB_INDEX_PATH );
	}
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

// Load composer autoloader if present. MDI is storage-only; content-format
// dependencies belong to the application layer above this drop-in.
$composer_autoload = $markdown_plugin_dir . '/vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
	require_once $composer_autoload;
}

// Load markdown integration classes.
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
