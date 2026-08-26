<?php
/** WordPress and WooCommerce lifecycle probe for the pure-PHP native backend. */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "FAIL: WordPress did not bootstrap.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

$result = array(
	'schema'     => 'mdi-native-wordpress-lifecycle/v1',
	'backend'    => defined( 'MARKDOWN_DB_BACKEND' ) ? MARKDOWN_DB_BACKEND : null,
	'wpdb'       => is_object( $wpdb ) ? get_class( $wpdb ) : null,
	'extensions' => array(
		'mysqli'     => extension_loaded( 'mysqli' ),
		'pdo_sqlite' => extension_loaded( 'pdo_sqlite' ),
		'sqlite3'    => extension_loaded( 'sqlite3' ),
	),
	'wordpress'   => array(
		'installed' => is_blog_installed(),
		'siteurl'   => get_option( 'siteurl' ),
	),
	'woocommerce' => array(),
);

if ( ! $wpdb instanceof WP_Markdown_Native_WPDB || 'mdi-native' !== $result['backend'] || ! $result['wordpress']['installed'] ) {
	$result['diagnostic'] = 'native_wordpress_boot_failed';
	fwrite( STDERR, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

try {
	$result['woocommerce']['before_activation'] = array(
		'active'     => is_plugin_active( 'woocommerce/woocommerce.php' ),
		'version'    => get_option( 'woocommerce_version', null ),
		'db_version' => get_option( 'woocommerce_db_version', null ),
	);
	$activation = activate_plugin( 'woocommerce/woocommerce.php' );
	$result['woocommerce']['activation_error'] = is_wp_error( $activation ) ? $activation->get_error_message() : null;
	$result['woocommerce']['active'] = is_plugin_active( 'woocommerce/woocommerce.php' );
	$result['woocommerce']['version'] = get_option( 'woocommerce_version', null );
	$result['woocommerce']['db_version'] = get_option( 'woocommerce_db_version', null );
	$result['woocommerce']['last_query'] = $wpdb->last_query;
	$result['woocommerce']['last_error'] = $wpdb->last_error;
	$result['woocommerce']['last_runtime_diagnostic'] = $wpdb->last_runtime_diagnostic;
} catch ( Throwable $error ) {
	$result['woocommerce']['active'] = is_plugin_active( 'woocommerce/woocommerce.php' );
	$result['woocommerce']['exception'] = array(
		'class'   => get_class( $error ),
		'message' => $error->getMessage(),
	);
	$result['woocommerce']['last_query'] = $wpdb->last_query;
	$result['woocommerce']['last_error'] = $wpdb->last_error;
	$result['woocommerce']['last_runtime_diagnostic'] = $wpdb->last_runtime_diagnostic;
}

$passed = true === ( $result['woocommerce']['active'] ?? false )
	&& null === ( $result['woocommerce']['activation_error'] ?? null )
	&& ! isset( $result['woocommerce']['exception'] )
	&& is_string( $result['woocommerce']['version'] ?? null )
	&& is_string( $result['woocommerce']['db_version'] ?? null );

if ( defined( 'MDI_NATIVE_LIFECYCLE_EXPECT_PERSISTED' ) && true === MDI_NATIVE_LIFECYCLE_EXPECT_PERSISTED ) {
	$passed = $passed
		&& true === ( $result['woocommerce']['before_activation']['active'] ?? false )
		&& ( $result['woocommerce']['version'] ?? null ) === ( $result['woocommerce']['before_activation']['version'] ?? null )
		&& ( $result['woocommerce']['db_version'] ?? null ) === ( $result['woocommerce']['before_activation']['db_version'] ?? null );
}

$result['passed'] = $passed;
fwrite( $passed ? STDOUT : STDERR, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
exit( $passed ? 0 : 1 );
