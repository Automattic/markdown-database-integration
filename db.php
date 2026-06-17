<?php
/**
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
 * @studio-keep
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

if ( ! function_exists( 'markdown_database_integration_store_has_siteurl' ) ) {
	/**
	 * Whether the markdown store already contains an installed-site siteurl.
	 *
	 * @param string $content_dir Markdown content directory.
	 * @return bool True when siteurl is persisted in per-option or legacy form.
	 */
	function markdown_database_integration_store_has_siteurl( string $content_dir ): bool {
		$siteurl_file = rtrim( $content_dir, '/\\' ) . '/_options/siteurl.json';
		if ( file_exists( $siteurl_file ) ) {
			return true;
		}

		$legacy_file = rtrim( $content_dir, '/\\' ) . '/options.json';
		if ( ! file_exists( $legacy_file ) ) {
			return false;
		}

		$decoded = json_decode( (string) file_get_contents( $legacy_file ), true );
		if ( ! is_array( $decoded ) ) {
			return false;
		}

		foreach ( $decoded as $row ) {
			if ( is_array( $row ) && isset( $row['option_name'] ) && 'siteurl' === $row['option_name'] ) {
				return true;
			}
		}

		return false;
	}
}

// Primary mode uses markdown-index.sqlite as the active query engine. The
// SQLite Integration install shim opens FQDB directly during wp_install(). Only
// point FQDB at the markdown index when the markdown store already represents an
// installed site. Partial seed stores fall back to the existing SQLite database
// so already-installed Playground sites do not reset to the install screen.
if ( defined( 'MARKDOWN_DB_MODE' ) && 'primary' === MARKDOWN_DB_MODE ) {
	$markdown_db_content_dir = defined( 'MARKDOWN_DB_CONTENT_DIR' )
		? MARKDOWN_DB_CONTENT_DIR
		: WP_CONTENT_DIR . '/markdown';
	if ( markdown_database_integration_store_has_siteurl( $markdown_db_content_dir ) ) {
		$markdown_db_index_path = dirname( rtrim( $markdown_db_content_dir, '/\\' ) ) . '/markdown-index.sqlite';
		if ( file_exists( '/internal/shared/sqlite-database-integration/wp-includes/sqlite/db.php' ) ) {
			$markdown_db_index_path = rtrim( sys_get_temp_dir(), '/\\' ) . '/markdown-index-' . substr( md5( $markdown_db_index_path ), 0, 12 ) . '.sqlite';
		}

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

if ( defined( 'MARKDOWN_DB_MODE' ) && 'primary' === MARKDOWN_DB_MODE ) {
	$markdown_db_content_dir = defined( 'MARKDOWN_DB_CONTENT_DIR' )
		? MARKDOWN_DB_CONTENT_DIR
		: WP_CONTENT_DIR . '/markdown';

	if ( ! markdown_database_integration_store_has_siteurl( $markdown_db_content_dir ) ) {
		if ( ! defined( 'MARKDOWN_DB_INSTALL_FALLBACK' ) ) {
			define( 'MARKDOWN_DB_INSTALL_FALLBACK', true );
		}
		$db_name          = defined( 'DB_NAME' ) && '' !== DB_NAME ? DB_NAME : 'database_name_here';
		$GLOBALS['wpdb'] = new WP_SQLite_DB( $db_name );
		return;
	}
}

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
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-frontmatter-profiles.php';
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
