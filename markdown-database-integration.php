<?php
/**
 * Plugin Name: Markdown Database Integration
 * Plugin URI: https://github.com/chubes4/markdown-database-integration
 * Description: Pure-PHP WordPress database runtime backed by canonical Markdown and JSON files.
 * Version: 0.11.5
 * Author: Chris Huber
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: markdown-database-integration
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * The markdown files are the knowledge layer:
 *   - AI agents read them directly (no API, no query, just grep)
 *   - Git syncs them across machines and people
 *   - Every post is a file, every file is searchable
 *   - WordPress keeps working normally
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MARKDOWN_DB_VERSION', '0.11.5' );
define( 'MARKDOWN_DB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$markdown_database_integration_autoload = MARKDOWN_DB_PLUGIN_DIR . 'vendor/autoload.php';
if ( function_exists( 'did_action' ) && file_exists( $markdown_database_integration_autoload ) ) {
	require_once $markdown_database_integration_autoload;
}

require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-frontmatter-profiles.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-content-layout-profiles.php';
if ( defined( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE_BOOTSTRAP' ) && is_file( MARKDOWN_DB_CONTENT_LAYOUT_PROFILE_BOOTSTRAP ) ) {
	require_once MARKDOWN_DB_CONTENT_LAYOUT_PROFILE_BOOTSTRAP;
}
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-storage.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-frontmatter-migration.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-backend-capabilities.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-native-query-runtime.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-native-wpdb.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-native-shadow-verifier.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-durable-reconciliation-operations.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-reconciliation-adapters.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-reconciliation-service.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-wordpress-reconciliation-adapter.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-mysql-content-runtime.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-mysql-outbox.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-mysql-operations.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-mysql-canonical-publisher.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-mysql-full-runtime.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-health.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-cli.php';

function markdown_database_integration_ensure_mysql_reconciliation_state(): void {
	global $wpdb;
	if ( ! defined( 'MARKDOWN_DB_BACKEND' ) || 'mysql-content' !== MARKDOWN_DB_BACKEND ) {
		return;
	}
	if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
		return;
	}
	$table = (string) ( $wpdb->prefix ?? 'wp_' ) . 'mdi_resource_fences';
	if ( false === $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (`resource_key` VARCHAR(191) PRIMARY KEY, `operation_id` VARCHAR(64) NOT NULL, `fence` BIGINT NOT NULL) ENGINE=InnoDB" ) ) {
		return;
	}
	update_option( '_markdown_db_mysql_reconciliation_schema', '1', false );
}

/**
 * The content directory where markdown files are stored.
 * Override in wp-config.php: define( 'MARKDOWN_DB_CONTENT_DIR', '/path/to/wiki' );
 */
if ( ! defined( 'MARKDOWN_DB_CONTENT_DIR' ) ) {
	define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/markdown' );
}

/**
 * The directory where non-post runtime state is stored.
 * Defaults to the content directory for layout compatibility.
 * Override in wp-config.php: define( 'MARKDOWN_DB_STATE_DIR', WP_CONTENT_DIR . '/markdown-state' );
 */
if ( ! defined( 'MARKDOWN_DB_STATE_DIR' ) ) {
	define( 'MARKDOWN_DB_STATE_DIR', MARKDOWN_DB_CONTENT_DIR );
}

/**
 * Post types to exclude from markdown storage. Comma-separated.
 * These go to _tables/posts.json instead of .md files.
 * Override in wp-config.php to customize.
 */
if ( ! defined( 'MARKDOWN_DB_EXCLUDED_TYPES' ) ) {
	define( 'MARKDOWN_DB_EXCLUDED_TYPES', 'revision,auto-draft,nav_menu_item,customize_changeset,oembed_cache,wp_navigation,wp_global_styles,wp_template,wp_template_part' );
}

if ( ! defined( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE' ) ) {
	define( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE', 'post-type-hierarchy' );
}

// MDI intentionally registers no content-format conversion hooks. It persists
// the post_content bytes WordPress receives; rendering/editor conversion lives
// in the application/content-format layer above this storage plugin.

add_action( 'init', 'markdown_database_integration_ensure_mysql_reconciliation_state', 0 );
add_action( 'switch_blog', 'markdown_database_integration_ensure_mysql_reconciliation_state', 0 );
add_action( 'plugins_loaded', array( 'WP_Markdown_MySQL_Content_Runtime', 'bootstrap' ), 20 );
add_action( 'plugins_loaded', array( 'WP_Markdown_MySQL_Full_Runtime', 'bootstrap' ), 20 );
add_action( 'init', array( 'WP_Markdown_CLI', 'register' ) );
add_action( 'init', array( 'WP_Markdown_Frontmatter_Migration', 'maybe_run' ), 1 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_Markdown_CLI::register_db_command_boundary();
	WP_CLI::add_command( 'markdown-db import', array( 'WP_Markdown_CLI', 'import_cli' ) );
	WP_CLI::add_command( 'markdown-db export', array( 'WP_Markdown_CLI', 'export_cli' ) );
	WP_CLI::add_command( 'markdown-db reconcile', array( 'WP_Markdown_CLI', 'reconcile_cli' ) );
	WP_CLI::add_command( 'markdown-db doctor', array( 'WP_Markdown_CLI', 'doctor_cli' ) );
}
