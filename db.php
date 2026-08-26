<?php
/**
 * WordPress db.php drop-in for MDI native canonical storage and MySQL capture.
 *
 * @studio-keep
 * @package Markdown_Database_Integration
 */

if ( ! function_exists( 'markdown_database_integration_enable_native_shadow' ) ) {
	function markdown_database_integration_enable_native_shadow( object $database, string $plugin_dir ): void {
		if ( ! defined( 'MARKDOWN_DB_NATIVE_SHADOW' ) || true !== MARKDOWN_DB_NATIVE_SHADOW || ! method_exists( $database, 'set_native_shadow_verifier' ) ) {
			return;
		}

		try {
			require_once $plugin_dir . '/inc/class-wp-markdown-native-shadow-verifier.php';
			$verifier = WP_Markdown_Native_Shadow_Factory::from_globals( $database );
			$database->set_native_shadow_verifier( $verifier );
			$GLOBALS['markdown_db_native_shadow_verifier'] = $verifier;
		} catch ( Throwable $error ) {
			$GLOBALS['markdown_db_native_shadow_diagnostic'] = array(
				'code'  => 'markdown_db_native_shadow_bootstrap_failed',
				'class' => get_class( $error ),
			);
		}
	}
}

$markdown_db_backend = defined( 'MARKDOWN_DB_BACKEND' ) ? (string) MARKDOWN_DB_BACKEND : 'mdi-native';

// mysql-content is a plugin-level MySQL runtime. Leave stock wpdb bootstrap intact.
if ( 'mysql-content' === $markdown_db_backend ) {
	define( 'MARKDOWN_DB_RETAINED_DROPIN', true );
	return;
}

// mysql-full must own this boundary because plugins load after wpdb bootstrap.
if ( 'mysql-full' === $markdown_db_backend ) {
	$markdown_db_mysql_plugin_dir = null;
	foreach ( array( __DIR__ . '/mu-plugins/markdown-database-integration', __DIR__ . '/plugins/markdown-database-integration' ) as $path ) {
		if ( is_file( $path . '/inc/class-wp-markdown-backend-capabilities.php' ) && is_file( $path . '/inc/class-wp-markdown-mysql-wpdb.php' ) && is_file( $path . '/inc/class-wp-markdown-mysql-outbox.php' ) ) {
			$markdown_db_mysql_plugin_dir = $path;
			break;
		}
	}
	if ( null === $markdown_db_mysql_plugin_dir || ! class_exists( 'wpdb' ) ) {
		$GLOBALS['markdown_db_mysql_full_diagnostic'] = array( 'code' => 'markdown_db_mysql_full_bootstrap_unavailable', 'message' => 'mysql-full requires a compatible MDI db.php bootstrap and stock wpdb.' );
		return;
	}
	try {
		require_once $markdown_db_mysql_plugin_dir . '/inc/class-wp-markdown-backend-capabilities.php';
		WP_Markdown_Backend_Resolver::configure_from_globals()->require( 'table_mutation_capture' );
		require_once $markdown_db_mysql_plugin_dir . '/inc/class-wp-markdown-mysql-outbox.php';
		require_once $markdown_db_mysql_plugin_dir . '/inc/class-wp-markdown-mysql-impact-adapter.php';
		require_once $markdown_db_mysql_plugin_dir . '/inc/class-wp-markdown-mysql-semantic-drain.php';
		require_once $markdown_db_mysql_plugin_dir . '/inc/class-wp-markdown-mysql-wpdb.php';
		if ( 2 !== WP_Markdown_MySQL_WPDB::BOOTSTRAP_ABI ) {
			throw new RuntimeException( 'Incompatible mysql-full bootstrap ABI.' );
		}
		$database = new WP_Markdown_MySQL_WPDB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$prefix = (string) ( $GLOBALS['table_prefix'] ?? 'wp_' );
		$impact = new WP_Markdown_MySQL_Impact_Adapter( $database->markdown_db_mysql_connection() );
		$outbox = new WP_Markdown_MySQL_Outbox( $database->markdown_db_mysql_connection(), $prefix . 'mdi_mysql_outbox', array( $impact, 'intents' ) );
		$database->set_mutation_sink( $outbox );
		markdown_database_integration_enable_native_shadow( $database, $markdown_db_mysql_plugin_dir );
		$GLOBALS['markdown_db_mysql_outbox'] = $outbox;
		$GLOBALS['markdown_db_mysql_semantic_drain'] = new WP_Markdown_MySQL_Semantic_Drain( $outbox, $impact );
		$GLOBALS['wpdb'] = $database;
		define( 'MARKDOWN_DB_DROPIN', true );
	} catch ( Throwable $error ) {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['markdown_db_mysql_full_diagnostic'] = array( 'code' => 'markdown_db_mysql_full_bootstrap_incompatible', 'message' => $error->getMessage() );
	}
	return;
}

if ( 'mdi-native' !== $markdown_db_backend ) {
	throw new RuntimeException( sprintf( 'Markdown Database Integration has no %s backend.', $markdown_db_backend ) );
}

$markdown_db_native_plugin_dir = null;
foreach ( array( __DIR__ . '/mu-plugins/markdown-database-integration', __DIR__ . '/plugins/markdown-database-integration' ) as $path ) {
	if ( is_file( $path . '/inc/class-wp-markdown-backend-capabilities.php' ) && is_file( $path . '/inc/class-wp-markdown-native-query-runtime.php' ) && is_file( $path . '/inc/class-wp-markdown-native-wpdb.php' ) ) {
		$markdown_db_native_plugin_dir = $path;
		break;
	}
}
if ( null === $markdown_db_native_plugin_dir || ! class_exists( 'wpdb' ) ) {
	throw new RuntimeException( 'mdi-native requires the MDI plugin and stock wpdb during db.php bootstrap.' );
}

require_once $markdown_db_native_plugin_dir . '/inc/class-wp-markdown-backend-capabilities.php';
WP_Markdown_Backend_Resolver::configure_from_globals()->require( 'canonical_option_select' );
require_once $markdown_db_native_plugin_dir . '/inc/class-wp-markdown-native-query-runtime.php';
require_once $markdown_db_native_plugin_dir . '/inc/class-wp-markdown-native-wpdb.php';

$state_dir = defined( 'MARKDOWN_DB_STATE_DIR' ) ? MARKDOWN_DB_STATE_DIR : WP_CONTENT_DIR . '/markdown';
$content_dir = defined( 'MARKDOWN_DB_CONTENT_DIR' ) ? MARKDOWN_DB_CONTENT_DIR : $state_dir;
$prefix = (string) ( $GLOBALS['table_prefix'] ?? 'wp_' );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $state_dir, $prefix, $prefix, function_exists( 'is_multisite' ) && is_multisite(), $content_dir );
$GLOBALS['wpdb'] = new WP_Markdown_Native_WPDB( $runtime, $prefix );
define( 'MARKDOWN_DB_DROPIN', true );
