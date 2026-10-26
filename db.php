<?php
/**
 * WordPress db.php drop-in for Markdown Database Integration.
 *
 * Serves canonical Markdown/JSON files directly through the mdi-native PHP engine
 * before plugins load. Unknown backend identifiers fail closed.
 * Install this file as wp-content/db.php.
 *
 * @studio-keep
 * @package Markdown_Database_Integration
 */

// Process supervisors may supply the backend before wp-config.php loads.
if ( ! defined( 'MARKDOWN_DB_BACKEND' ) ) {
	$markdown_db_environment_backend = getenv( 'MARKDOWN_DB_BACKEND' );
	if ( is_string( $markdown_db_environment_backend ) && '' !== $markdown_db_environment_backend ) {
		define( 'MARKDOWN_DB_BACKEND', $markdown_db_environment_backend );
	} else {
		define( 'MARKDOWN_DB_BACKEND', 'mdi-native' );
	}
}

// Locate an MDI install able to serve canonical files during bootstrap.
$markdown_db_native_plugin_dir = null;
foreach ( array( __DIR__ . '/mu-plugins/markdown-database-integration', __DIR__ . '/plugins/markdown-database-integration' ) as $markdown_db_candidate_dir ) {
	if ( is_file( $markdown_db_candidate_dir . '/inc/class-wp-markdown-backend-capabilities.php' )
		&& is_file( $markdown_db_candidate_dir . '/inc/native/class-wp-markdown-native-query-runtime.php' )
		&& is_file( $markdown_db_candidate_dir . '/inc/native/class-wp-markdown-native-wpdb.php' )
	) {
		$markdown_db_native_plugin_dir = $markdown_db_candidate_dir;
		break;
	}
}
if ( null === $markdown_db_native_plugin_dir ) {
	throw new RuntimeException( 'mdi-native requires the MDI plugin during db.php bootstrap.' );
}
require_once $markdown_db_native_plugin_dir . '/inc/class-wp-markdown-backend-capabilities.php';
$markdown_db_native_backend = WP_Markdown_Backend_Resolver::configure_from_globals();
$markdown_db_native_backend->require( 'canonical_option_select' );
if ( ! class_exists( 'wpdb' ) ) {
	throw new RuntimeException( 'mdi-native requires stock wpdb during db.php bootstrap.' );
}
require_once $markdown_db_native_plugin_dir . '/inc/markdown-db-paths.php';
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
