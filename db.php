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

// mysql-content is a plugin-level MySQL runtime. Leave normal wpdb bootstrap
// intact so the plugin can report the explicit db.php migration diagnostic.
if ( defined( 'MARKDOWN_DB_BACKEND' ) && 'mysql-content' === MARKDOWN_DB_BACKEND ) {
	define( 'MARKDOWN_DB_RETAINED_DROPIN', true );
	return;
}

// mysql-full must own this boundary because plugins load after wpdb bootstrap.
if ( defined( 'MARKDOWN_DB_BACKEND' ) && 'mysql-full' === MARKDOWN_DB_BACKEND ) {
	$markdown_db_mysql_plugin_dir = null;
	foreach ( array( __DIR__ . '/mu-plugins/markdown-database-integration', __DIR__ . '/plugins/markdown-database-integration' ) as $path ) {
		if ( is_file( $path . '/inc/class-wp-markdown-backend-capabilities.php' ) && is_file( $path . '/inc/class-wp-markdown-sql-classifier.php' ) && is_file( $path . '/inc/class-wp-markdown-mysql-wpdb.php' ) ) {
			$markdown_db_mysql_plugin_dir = $path;
			break;
		}
	}
	if ( null === $markdown_db_mysql_plugin_dir || ! class_exists( 'wpdb' ) || ! is_callable( $GLOBALS['markdown_db_mysql_mutation_sink'] ?? null ) ) {
		$GLOBALS['markdown_db_mysql_full_diagnostic'] = array( 'code' => null === $markdown_db_mysql_plugin_dir || ! class_exists( 'wpdb' ) ? 'markdown_db_mysql_full_bootstrap_unavailable' : 'markdown_db_mysql_full_sink_unavailable', 'message' => 'mysql-full requires a compatible MDI db.php bootstrap, stock wpdb, and an injected mutation sink.' );
		return;
	}
	try {
		require_once $markdown_db_mysql_plugin_dir . '/inc/class-wp-markdown-backend-capabilities.php';
		$markdown_db_mysql_backend = WP_Markdown_Backend_Resolver::configure_from_globals();
		$markdown_db_mysql_backend->require( 'table_mutation_capture' );
		require_once $markdown_db_mysql_plugin_dir . '/inc/class-wp-markdown-mysql-wpdb.php';
		if ( 1 !== WP_Markdown_MySQL_WPDB::BOOTSTRAP_ABI ) {
			throw new RuntimeException( 'Incompatible mysql-full bootstrap ABI.' );
		}
		$GLOBALS['wpdb'] = new WP_Markdown_MySQL_WPDB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST, $GLOBALS['markdown_db_mysql_mutation_sink'] );
		define( 'MARKDOWN_DB_DROPIN', true );
	} catch ( Throwable $error ) {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['markdown_db_mysql_full_diagnostic'] = array( 'code' => 'markdown_db_mysql_full_bootstrap_incompatible', 'message' => $error->getMessage() );
	}
	return;
}

define( 'SQLITE_DB_DROPIN_VERSION', '1.8.0' );
define( 'MARKDOWN_DB_DROPIN', true );

if ( ! function_exists( 'markdown_database_integration_store_has_siteurl' ) ) {
	/**
	 * Whether the state store already contains an installed-site siteurl.
	 *
	 * @param string $state_dir Runtime state directory.
	 * @return bool True when siteurl is persisted in per-option or legacy form.
	 */
	function markdown_database_integration_store_has_siteurl( string $state_dir ): bool {
		$siteurl_file = rtrim( $state_dir, '/\\' ) . '/_options/siteurl.json';
		if ( file_exists( $siteurl_file ) ) {
			return true;
		}

		$legacy_file = rtrim( $state_dir, '/\\' ) . '/options.json';
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

if ( ! function_exists( 'markdown_database_integration_primary_index_path' ) ) {
	/**
	 * Resolve the default primary index path for the configured roots.
	 *
	 * @param string $content_dir Markdown post content directory.
	 * @param string $state_dir   Runtime state directory.
	 * @return string Primary SQLite index path.
	 */
	function markdown_database_integration_primary_index_path( string $content_dir, string $state_dir ): string {
		if ( rtrim( $state_dir, '/\\' ) !== rtrim( $content_dir, '/\\' ) ) {
			return rtrim( $state_dir, '/\\' ) . '/markdown-index.sqlite';
		}

		return dirname( rtrim( $content_dir, '/\\' ) ) . '/markdown-index.sqlite';
	}
}

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
	if ( @file_exists( $playground_sqlite . '/wp-includes/sqlite/db.php' ) ) {
		$sqlite_plugin_implementation_folder_path = $playground_sqlite;
	}
}

if ( ! defined( 'MARKDOWN_DB_PLAYGROUND_RUNTIME' ) ) {
	define( 'MARKDOWN_DB_PLAYGROUND_RUNTIME', isset( $playground_sqlite ) && $playground_sqlite === $sqlite_plugin_implementation_folder_path );
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
// SQLite Integration install shim opens FQDB directly during wp_install(). Only
// point FQDB at the markdown index when the markdown store already represents an
// installed site. Partial seed stores fall back to the existing SQLite database
// so already-installed Playground sites do not reset to the install screen.
if ( defined( 'MARKDOWN_DB_MODE' ) && 'primary' === MARKDOWN_DB_MODE ) {
	$markdown_db_content_dir = defined( 'MARKDOWN_DB_CONTENT_DIR' )
		? MARKDOWN_DB_CONTENT_DIR
		: WP_CONTENT_DIR . '/markdown';
	$markdown_db_state_dir = defined( 'MARKDOWN_DB_STATE_DIR' )
		? MARKDOWN_DB_STATE_DIR
		: $markdown_db_content_dir;
	if ( markdown_database_integration_store_has_siteurl( $markdown_db_state_dir ) ) {
		$markdown_db_index_path = markdown_database_integration_primary_index_path( $markdown_db_content_dir, $markdown_db_state_dir );
		if ( MARKDOWN_DB_PLAYGROUND_RUNTIME ) {
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

// SQLite Integration renamed the canonical PDO driver after its latest release.
if ( ! class_exists( 'WP_MySQL_On_SQLite', false ) ) {
	if ( class_exists( 'WP_PDO_MySQL_On_SQLite', false ) ) {
		if ( ! defined( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API' ) ) {
			define( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API', true );
		}
		class_alias( 'WP_PDO_MySQL_On_SQLite', 'WP_MySQL_On_SQLite' );
	} else {
		throw new RuntimeException(
			'Markdown Database Integration requires SQLite Integration with the PDO-compatible WP_MySQL_On_SQLite or WP_PDO_MySQL_On_SQLite driver.'
		);
	}
}

// Load the SQLite DB class.
require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/class-wp-sqlite-db.php';
require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/install-functions.php';

if ( defined( 'MARKDOWN_DB_MODE' ) && 'primary' === MARKDOWN_DB_MODE ) {
	$markdown_db_content_dir = defined( 'MARKDOWN_DB_CONTENT_DIR' )
		? MARKDOWN_DB_CONTENT_DIR
		: WP_CONTENT_DIR . '/markdown';
	$markdown_db_state_dir = defined( 'MARKDOWN_DB_STATE_DIR' )
		? MARKDOWN_DB_STATE_DIR
		: $markdown_db_content_dir;

	if ( ! markdown_database_integration_store_has_siteurl( $markdown_db_state_dir ) ) {
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
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-backend-capabilities.php';
$markdown_db_backend = WP_Markdown_Backend_Resolver::configure_from_globals();
WP_Markdown_Backend_Resolver::require_runtime_capabilities(
	$markdown_db_backend,
	defined( 'MARKDOWN_DB_MODE' ) ? (string) MARKDOWN_DB_MODE : 'mirror'
);
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-frontmatter-profiles.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-content-layout-profiles.php';
if ( defined( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE_BOOTSTRAP' ) && is_file( MARKDOWN_DB_CONTENT_LAYOUT_PROFILE_BOOTSTRAP ) ) {
	require_once MARKDOWN_DB_CONTENT_LAYOUT_PROFILE_BOOTSTRAP;
}
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-storage.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-driver.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-search.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-write-engine.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-reconciliation-adapters.php';
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
