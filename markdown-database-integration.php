<?php
/**
 * Plugin Name: Markdown Database Integration
 * Plugin URI: https://github.com/Automattic/markdown-database-integration
 * Description: Native pure-PHP WordPress SQL engine backed by canonical Markdown content, JSON state, and plugin schemas.
 * Version: 0.14.6
 * Author: Chris Huber
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: markdown-database-integration
 * Requires at least: 6.9
 * Requires PHP: 8.2
 *
 * mdi-native serves canonical Markdown and JSON files directly as the database.
 * The files are readable by agents, searchable, and suitable for Git sync.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MARKDOWN_DB_VERSION', '0.14.6' );
define( 'MARKDOWN_DB_PLUGIN_DIR', dirname( __FILE__ ) . '/' );

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
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/native/class-wp-markdown-native-query-runtime.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/native/class-wp-markdown-native-wpdb.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-durable-reconciliation-operations.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-reconciliation-adapters.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-reconciliation-service.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-wordpress-reconciliation-adapter.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-health.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/class-wp-markdown-cli.php';
require_once MARKDOWN_DB_PLUGIN_DIR . 'inc/markdown-db-paths.php';

/** Override in wp-config.php to choose the canonical post content directory. */
if ( ! defined( 'MARKDOWN_DB_CONTENT_DIR' ) ) {
	define( 'MARKDOWN_DB_CONTENT_DIR', markdown_db_default_content_dir() );
}

/** Non-post runtime state defaults to the content directory. */
if ( ! defined( 'MARKDOWN_DB_STATE_DIR' ) ) {
	define( 'MARKDOWN_DB_STATE_DIR', MARKDOWN_DB_CONTENT_DIR );
}

/** Post types persisted as table rows rather than Markdown files. */
if ( ! defined( 'MARKDOWN_DB_EXCLUDED_TYPES' ) ) {
	define( 'MARKDOWN_DB_EXCLUDED_TYPES', 'revision,auto-draft,nav_menu_item,customize_changeset,oembed_cache,wp_navigation,wp_global_styles,wp_template,wp_template_part' );
}

if ( ! defined( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE' ) ) {
	define( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE', 'post-type-hierarchy' );
}

/** Prepare shared reconciliation fencing for import/export-only MySQL sites. */
function markdown_database_integration_ensure_mysql_reconciliation_state(): void {
	global $wpdb;
	if ( ( defined( 'MARKDOWN_DB_BACKEND' ) && 'mdi-native' === MARKDOWN_DB_BACKEND ) || $wpdb instanceof WP_Markdown_Native_WPDB || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
		return;
	}
	$table = (string) ( $wpdb->prefix ?? 'wp_' ) . 'mdi_resource_fences';
	if ( false === $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (`resource_key` VARCHAR(191) PRIMARY KEY, `operation_id` VARCHAR(64) NOT NULL, `fence` BIGINT NOT NULL) ENGINE=InnoDB" ) ) {
		return;
	}
	update_option( '_markdown_db_mysql_reconciliation_schema', '1', false );
}

// Content-format conversion belongs to the application above this storage plugin.
add_action( 'init', 'markdown_database_integration_ensure_mysql_reconciliation_state', 0 );
add_action( 'switch_blog', 'markdown_database_integration_ensure_mysql_reconciliation_state', 0 );
add_action( 'init', array( 'WP_Markdown_CLI', 'register' ) );
add_action( 'init', array( 'WP_Markdown_Frontmatter_Migration', 'maybe_run' ), 1 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_Markdown_CLI::register_db_command_boundary();
	WP_CLI::add_command( 'markdown-db import', array( 'WP_Markdown_CLI', 'import_cli' ) );
	WP_CLI::add_command( 'markdown-db export', array( 'WP_Markdown_CLI', 'export_cli' ) );
	WP_CLI::add_command( 'markdown-db reconcile', array( 'WP_Markdown_CLI', 'reconcile_cli' ) );
	WP_CLI::add_command( 'markdown-db doctor', array( 'WP_Markdown_CLI', 'doctor_cli' ) );
	WP_CLI::add_command( 'markdown-db collect-partition-generations', array( 'WP_Markdown_CLI', 'collect_partition_generations_cli' ) );
}
