<?php
/**
 * WordPress db.php drop-in for Markdown Database Integration.
 *
 * Selects the backend before plugins load (see markdown_database_integration_configured_backend):
 *   mdi-native    — default: a pure-PHP engine serves canonical Markdown/JSON
 *                   files directly; no SQLite or MySQL is involved.
 *   sqlite        — SQLite Database Integration with markdown persistence.
 *                   MARKDOWN_DB_MODE applies only here:
 *                     'mirror'  — SQLite on disk, markdown mirrored on writes
 *                     'primary' — markdown files are the database, indexed in SQLite
 *
 * This file goes in wp-content/db.php (replacing the SQLite drop-in's version).
 *
 * @studio-keep
 * @package Markdown_Database_Integration
 */

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

if ( ! function_exists( 'markdown_database_integration_enable_native_shadow' ) ) {
	function markdown_database_integration_enable_native_shadow( object $database, string $plugin_dir ): void {
		if ( ! defined( 'MARKDOWN_DB_NATIVE_SHADOW' ) || true !== MARKDOWN_DB_NATIVE_SHADOW || ! method_exists( $database, 'set_native_shadow_verifier' ) ) {
			return;
		}

		try {
			require_once $plugin_dir . '/inc/native/class-wp-markdown-native-shadow-verifier.php';
			$verifier = WP_Markdown_Native_Shadow_Factory::from_globals( $database );
			$database->set_native_shadow_verifier( $verifier );
			$GLOBALS['markdown_db_native_shadow_verifier'] = $verifier;
			$report_path = defined( 'MARKDOWN_DB_NATIVE_SHADOW_REPORT_PATH' ) ? MARKDOWN_DB_NATIVE_SHADOW_REPORT_PATH : '';
			if ( is_string( $report_path ) && '' !== $report_path ) {
				register_shutdown_function(
					static function () use ( $verifier, $report_path ): void {
						$report = $verifier->report();
						file_put_contents( $report_path, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n", LOCK_EX );
					}
				);
			}
		} catch ( Throwable $error ) {
			$GLOBALS['markdown_db_native_shadow_diagnostic'] = array(
				'code'  => 'markdown_db_native_shadow_bootstrap_failed',
				'class' => get_class( $error ),
			);
			if ( defined( 'MARKDOWN_DB_NATIVE_SHADOW_REPORT_PATH' ) && '' !== MARKDOWN_DB_NATIVE_SHADOW_REPORT_PATH ) {
				file_put_contents( MARKDOWN_DB_NATIVE_SHADOW_REPORT_PATH, json_encode( array( 'schema' => 'mdi-native-shadow-report/v1', 'observed' => 0, 'counts' => array(), 'bootstrap_diagnostic' => $GLOBALS['markdown_db_native_shadow_diagnostic'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n", LOCK_EX );
			}
		}
	}
}

if ( ! function_exists( 'markdown_database_integration_path_is_sqlite' ) ) {
	/**
	 * Whether a path holds a SQLite database, judged by its file header.
	 *
	 * Opening a SQLite path creates an empty file, so presence alone does not
	 * prove a database exists. Reads only the 16-byte header; plugin classes
	 * are not loaded yet when the drop-in runs.
	 */
	function markdown_database_integration_path_is_sqlite( string $path ): bool {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$header = fread( $handle, 16 );
		fclose( $handle );
		return "SQLite format 3\0" === $header;
	}
}

if ( ! function_exists( 'markdown_database_integration_configured_backend' ) ) {
	/**
	 * Resolve the backend that owns this bootstrap.
	 *
	 * An explicit MARKDOWN_DB_BACKEND always wins. Otherwise the canonical
	 * file store is authoritative and mdi-native serves it directly, which is
	 * why SQLite Database Integration is optional rather than required.
	 *
	 * Inferred database paths are accepted only when they are readable regular
	 * files with the SQLite format 3 header. An install that already holds a
	 * SQLite database keeps using it, so upgrading never moves a running site
	 * onto a different query engine without the operator asking for it.
	 */
	function markdown_database_integration_configured_backend(): string {
		if ( defined( 'MARKDOWN_DB_BACKEND' ) ) {
			return (string) MARKDOWN_DB_BACKEND;
		}
		if ( defined( 'DB_PATH' ) || defined( 'FQDB' ) ) {
			$path = defined( 'DB_PATH' ) ? DB_PATH : FQDB;
			return is_string( $path ) && markdown_database_integration_path_is_sqlite( $path ) ? 'sqlite' : 'mdi-native';
		}

		$directory = markdown_database_integration_sqlite_directory();
		if ( defined( 'DB_FILE' ) ) {
			return markdown_database_integration_path_is_sqlite( $directory . DB_FILE ) ? 'sqlite' : 'mdi-native';
		}

		// db-path.php records a randomized database path as executable PHP.
		// Detection never runs it, so its presence alone selects SQLite.
		if ( is_file( $directory . 'db-path.php' ) ) {
			return 'sqlite';
		}
		// .ht.sqlite.php is the integration's legacy name for the same raw
		// SQLite file; it renames it to .ht.sqlite on first connection.
		return markdown_database_integration_path_is_sqlite( $directory . '.ht.sqlite' )
			|| markdown_database_integration_path_is_sqlite( $directory . '.ht.sqlite.php' ) ? 'sqlite' : 'mdi-native';
	}
}

if ( ! function_exists( 'markdown_database_integration_sqlite_directory' ) ) {
	/** Resolve the SQLite storage directory before integration constants load. */
	function markdown_database_integration_sqlite_directory(): string {
		if ( defined( 'FQDBDIR' ) ) {
			$directory = FQDBDIR;
		} elseif ( defined( 'DB_DIR' ) ) {
			$directory = DB_DIR;
		} else {
			$directory  = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : __DIR__;
			$directory .= '/database';
		}
		return rtrim( $directory, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'markdown_database_integration_native_plugin_dir' ) ) {
	/** Locate an MDI install able to serve canonical files during bootstrap. */
	function markdown_database_integration_native_plugin_dir( string $content_dir ): ?string {
		foreach ( array( $content_dir . '/mu-plugins/markdown-database-integration', $content_dir . '/plugins/markdown-database-integration' ) as $path ) {
			if ( is_file( $path . '/inc/class-wp-markdown-backend-capabilities.php' )
				&& is_file( $path . '/inc/native/class-wp-markdown-native-query-runtime.php' )
				&& is_file( $path . '/inc/native/class-wp-markdown-native-wpdb.php' )
			) {
				return $path;
			}
		}
		return null;
	}
}

// A process supervisor may provide these early-bootstrap settings through its
// environment before WordPress loads wp-config.php in disposable runtimes.
if ( ! defined( 'MARKDOWN_DB_BACKEND' ) ) {
	$markdown_db_environment_backend = getenv( 'MARKDOWN_DB_BACKEND' );
	if ( is_string( $markdown_db_environment_backend ) && in_array( $markdown_db_environment_backend, array( 'sqlite', 'mdi-native' ), true ) ) {
		define( 'MARKDOWN_DB_BACKEND', $markdown_db_environment_backend );
	}
}
if ( ! defined( 'MARKDOWN_DB_NATIVE_SHADOW' ) && 'true' === getenv( 'MARKDOWN_DB_NATIVE_SHADOW' ) ) {
	define( 'MARKDOWN_DB_NATIVE_SHADOW', true );
}
if ( ! defined( 'MARKDOWN_DB_NATIVE_SHADOW_MAX' ) && ctype_digit( (string) getenv( 'MARKDOWN_DB_NATIVE_SHADOW_MAX' ) ) ) {
	define( 'MARKDOWN_DB_NATIVE_SHADOW_MAX', (int) getenv( 'MARKDOWN_DB_NATIVE_SHADOW_MAX' ) );
}
if ( ! defined( 'MARKDOWN_DB_NATIVE_SHADOW_INPUT_MODE' ) ) {
	$markdown_db_shadow_input_mode = getenv( 'MARKDOWN_DB_NATIVE_SHADOW_INPUT_MODE' );
	if ( false !== $markdown_db_shadow_input_mode && ! in_array( $markdown_db_shadow_input_mode, array( 'canonical', 'sql_snapshot' ), true ) ) {
		throw new InvalidArgumentException( 'The native shadow input mode must be canonical or sql_snapshot.' );
	}
	if ( is_string( $markdown_db_shadow_input_mode ) && '' !== $markdown_db_shadow_input_mode ) {
		define( 'MARKDOWN_DB_NATIVE_SHADOW_INPUT_MODE', $markdown_db_shadow_input_mode );
	}
}
if ( ! defined( 'MARKDOWN_DB_NATIVE_SHADOW_REPORT_PATH' ) ) {
	$markdown_db_shadow_report_path = getenv( 'MARKDOWN_DB_NATIVE_SHADOW_REPORT_PATH' );
	if ( is_string( $markdown_db_shadow_report_path ) && '' !== $markdown_db_shadow_report_path ) {
		define( 'MARKDOWN_DB_NATIVE_SHADOW_REPORT_PATH', $markdown_db_shadow_report_path );
	}
}
if ( ! defined( 'MARKDOWN_DB_NATIVE_SHADOW_TRACE_PATH' ) ) {
	$markdown_db_shadow_trace_path = getenv( 'MARKDOWN_DB_NATIVE_SHADOW_TRACE_PATH' );
	if ( is_string( $markdown_db_shadow_trace_path ) && '' !== $markdown_db_shadow_trace_path ) {
		define( 'MARKDOWN_DB_NATIVE_SHADOW_TRACE_PATH', $markdown_db_shadow_trace_path );
	}
}

// Downstream capability resolution, health, and CLI read the same identifier,
// so the backend is settled before it is published. An operator who named a
// backend is told when its runtime is unavailable; an inferred backend simply
// stays on the SQLite path the site was already able to boot.
$markdown_db_backend_is_explicit = defined( 'MARKDOWN_DB_BACKEND' );
$markdown_db_native_plugin_dir   = markdown_database_integration_native_plugin_dir( __DIR__ );
if ( ! $markdown_db_backend_is_explicit ) {
	$markdown_db_resolved_backend = markdown_database_integration_configured_backend();
	if ( 'mdi-native' === $markdown_db_resolved_backend
		&& ( null === $markdown_db_native_plugin_dir || ! class_exists( 'wpdb' ) )
	) {
		$markdown_db_resolved_backend = 'sqlite';
	}
	define( 'MARKDOWN_DB_BACKEND', $markdown_db_resolved_backend );
}

// mdi-native serves bounded reads directly from canonical Markdown and JSON.
// It must replace wpdb here because regular plugins load after database boot.
if ( 'mdi-native' === MARKDOWN_DB_BACKEND ) {
	if ( null === $markdown_db_native_plugin_dir || ! class_exists( 'wpdb' ) ) {
		throw new RuntimeException( 'mdi-native requires the MDI plugin and stock wpdb during db.php bootstrap.' );
	}

	require_once $markdown_db_native_plugin_dir . '/inc/class-wp-markdown-backend-capabilities.php';
	$markdown_db_native_backend = WP_Markdown_Backend_Resolver::configure_from_globals();
	$markdown_db_native_backend->require( 'canonical_option_select' );
	require_once $markdown_db_native_plugin_dir . '/inc/native/class-wp-markdown-native-query-runtime.php';
	require_once $markdown_db_native_plugin_dir . '/inc/native/class-wp-markdown-native-wpdb.php';

	$markdown_db_native_content_dir = defined( 'MARKDOWN_DB_CONTENT_DIR' ) ? MARKDOWN_DB_CONTENT_DIR : markdown_db_default_content_dir();
	$markdown_db_native_state_dir = defined( 'MARKDOWN_DB_STATE_DIR' ) ? MARKDOWN_DB_STATE_DIR : $markdown_db_native_content_dir;
	$markdown_db_native_prefix = (string) ( $GLOBALS['table_prefix'] ?? 'wp_' );
	$markdown_db_native_runtime = WP_Markdown_Native_Runtime_Factory::wordpress_runtime( $markdown_db_native_state_dir, $markdown_db_native_prefix, $markdown_db_native_content_dir );
	$GLOBALS['wpdb'] = new WP_Markdown_Native_WPDB(
		$markdown_db_native_runtime,
		$markdown_db_native_prefix,
		static fn(): WP_Markdown_Query_Runtime => WP_Markdown_Native_Runtime_Factory::wordpress_runtime( $markdown_db_native_state_dir, $markdown_db_native_prefix, $markdown_db_native_content_dir )
	);
	define( 'MARKDOWN_DB_DROPIN', true );
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

// Primary mode uses markdown-index.sqlite as the active query engine.
// Only point the SQLite installation shim at the index when the store represents an
// installed site. Partial seed stores fall back to the existing SQLite database
// so already-installed Playground sites do not reset to the install screen.
$markdown_db_primary = defined( 'MARKDOWN_DB_MODE' ) && 'primary' === MARKDOWN_DB_MODE;
$markdown_db_install_fallback = false;
if ( $markdown_db_primary ) {
	$markdown_db_content_dir = defined( 'MARKDOWN_DB_CONTENT_DIR' )
		? MARKDOWN_DB_CONTENT_DIR
		: markdown_db_default_content_dir();
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
	} else {
		$markdown_db_install_fallback = true;
		if ( ! defined( 'MARKDOWN_DB_INSTALL_FALLBACK' ) ) {
			define( 'MARKDOWN_DB_INSTALL_FALLBACK', true );
		}
	}
}

// Select configured paths before older integrations define FQDB by default.
$markdown_db_sqlite_storage_file = $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/class-wp-sqlite-storage.php';
$markdown_db_sqlite_path = null;
if ( defined( 'DB_PATH' ) ) {
	$markdown_db_sqlite_path = DB_PATH;
} elseif ( defined( 'FQDB' ) ) {
	$markdown_db_sqlite_path = FQDB;
} elseif ( defined( 'DB_FILE' ) ) {
	$markdown_db_sqlite_path = markdown_database_integration_sqlite_directory() . DB_FILE;
} elseif ( ! is_file( $markdown_db_sqlite_storage_file ) ) {
	$markdown_db_sqlite_path = markdown_database_integration_sqlite_directory() . '.ht.sqlite';
	if ( ! is_file( $markdown_db_sqlite_path ) && is_file( $markdown_db_sqlite_path . '.php' ) ) {
		$markdown_db_sqlite_path .= '.php';
	}
}

// Older integrations read the legacy constants instead of DB_PATH.
if ( null !== $markdown_db_sqlite_path ) {
	if ( ! defined( 'FQDB' ) ) {
		define( 'FQDB', $markdown_db_sqlite_path );
	}
	if ( ! defined( 'FQDBDIR' ) && ':memory:' !== $markdown_db_sqlite_path ) {
		define( 'FQDBDIR', rtrim( dirname( $markdown_db_sqlite_path ), '/\\' ) . '/' );
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

// Load SQLite storage support when available.
if ( is_file( $markdown_db_sqlite_storage_file ) ) {
	require_once $markdown_db_sqlite_storage_file;
}

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

$markdown_db_sqlite_fallback = $markdown_db_install_fallback || ! $markdown_plugin_dir;

if ( ! $markdown_db_sqlite_fallback ) {
	// MDI is storage-only; content-format dependencies belong to the application.
	$composer_autoload = $markdown_plugin_dir . '/vendor/autoload.php';
	if ( file_exists( $composer_autoload ) ) {
		require_once $composer_autoload;
	}

	require_once $markdown_plugin_dir . '/inc/class-wp-markdown-backend-capabilities.php';
	$markdown_db_backend = WP_Markdown_Backend_Resolver::configure_from_globals();
	WP_Markdown_Backend_Resolver::require_runtime_capabilities(
		$markdown_db_backend,
		defined( 'MARKDOWN_DB_MODE' ) ? (string) MARKDOWN_DB_MODE : 'mirror'
	);
}

// Primary mode creates its index during reconstruction, after storage is loaded.
if ( class_exists( 'WP_SQLite_Storage' ) && ( ! $markdown_db_primary || $markdown_db_sqlite_fallback ) ) {
	$storage = null !== $markdown_db_sqlite_path
		? WP_SQLite_Storage::with_explicit_path( $markdown_db_sqlite_path )
		: WP_SQLite_Storage::with_secret_path( FQDBDIR );
	$markdown_db_sqlite_path = $storage->initialize();
	if ( ! defined( 'FQDB' ) ) {
		define( 'FQDB', $markdown_db_sqlite_path );
	}
}
if ( ! defined( 'DB_PATH' ) ) {
	define( 'DB_PATH', $markdown_db_sqlite_path );
}

$db_name = defined( 'DB_NAME' ) && '' !== DB_NAME ? DB_NAME : 'database_name_here';

// Use standard SQLite for installation or when the markdown plugin is missing.
if ( $markdown_db_sqlite_fallback ) {
	$GLOBALS['wpdb'] = new WP_SQLite_DB( $db_name );
	return;
}

// Load markdown integration classes.
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
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-primary-index-health.php';
require_once $markdown_plugin_dir . '/inc/class-wp-markdown-db.php';

// Load plugin constants (if not already loaded via the plugin file).
if ( ! defined( 'MARKDOWN_DB_VERSION' ) ) {
	require_once $markdown_plugin_dir . '/markdown-database-integration.php';
}

// Create the database connection — our WP_Markdown_DB extends WP_SQLite_DB.
$GLOBALS['wpdb'] = new WP_Markdown_DB( $db_name );
markdown_database_integration_enable_native_shadow( $GLOBALS['wpdb'], $markdown_plugin_dir );

// Boot Query Monitor integration if present.
$qm_boot = $sqlite_plugin_implementation_folder_path . '/integrations/query-monitor/boot.php';
if ( file_exists( $qm_boot ) ) {
	require_once $qm_boot;
}
