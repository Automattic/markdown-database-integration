<?php
/** Exercise a real WordPress multisite lifecycle through mdi-native. */

if ( ! defined( 'ABSPATH' ) || ! is_multisite() ) {
	fwrite( STDERR, "FAIL: WordPress multisite did not bootstrap.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

function mdi_native_multisite_probe_assert( bool $condition, string $label, array &$checks ): void {
	$checks[ $label ] = $condition;
}

function mdi_native_multisite_probe_tables(): array {
	global $wpdb;
	return array_map(
		static fn( object $row ): string => (string) array_values( get_object_vars( $row ) )[0],
		$wpdb->get_results( "SHOW TABLES LIKE '" . esc_sql( $wpdb->prefix ) . "%'" )
	);
}

$checks = array();
$report = array(
	'schema' => 'mdi-native-multisite-wordpress/v1',
	'backend' => defined( 'MARKDOWN_DB_BACKEND' ) ? MARKDOWN_DB_BACKEND : null,
	'wpdb' => is_object( $wpdb ) ? get_class( $wpdb ) : null,
	'base_prefix' => $wpdb->base_prefix,
	'initial_prefix' => $wpdb->prefix,
);

try {
	mdi_native_multisite_probe_assert( $wpdb instanceof WP_Markdown_Native_WPDB, 'native_wpdb', $checks );
	mdi_native_multisite_probe_assert( 'mdi-native' === $report['backend'], 'native_backend', $checks );

	// WordPress creates the network tables and the second site's tables through
	// its ordinary dbDelta/populate paths, not synthetic canonical snapshots.
	if ( ! get_network( 1 ) ) {
		populate_network( 1, 'example.com', '/' );
	}
	$blog_id = get_id_from_blogname( 'mdi-site-two' );
	if ( ! $blog_id ) {
		$created = wpmu_create_blog( 'example.com', '/mdi-site-two/', 'MDI Site Two', 1 );
		if ( is_wp_error( $created ) ) {
			throw new RuntimeException( $created->get_error_message() );
		}
		$blog_id = (int) $created;
	}
	$report['blog_id'] = $blog_id;
	mdi_native_multisite_probe_assert( $blog_id > 1, 'site_created', $checks );

	$base_prefix = $wpdb->prefix;
	update_option( 'mdi_network_option', 'network-value' );
	$network_user_id = 1;
	$report['network_user_id'] = $network_user_id;

	// wpmu_create_blog() may temporarily switch the current blog. Return to the
	// network primary site before exercising the caller-visible switch sequence.
	while ( ms_is_switched() ) {
		restore_current_blog();
	}
	if ( 1 !== get_current_blog_id() ) {
		switch_to_blog( 1 );
	}
	$report['switch_return'] = switch_to_blog( $blog_id );
	// WordPress's switch API owns the caller-visible context. Reapply the same
	// selected scope to the replacement wpdb so its table properties cannot lag
	// a switch that occurred while db.php was loading.
	$wpdb->set_blog_id( $blog_id );
	$GLOBALS['blog_id'] = $blog_id;
	$GLOBALS['table_prefix'] = $wpdb->prefix;
	$site_prefix = $wpdb->prefix;
	$report['site_blogid'] = $wpdb->blogid;
	$report['global_blog_id'] = $GLOBALS['blog_id'] ?? null;
	update_option( 'mdi_site_option', 'site-value' );
	$post_id = wp_insert_post( array( 'post_title' => 'MDI site post', 'post_content' => 'site-specific body', 'post_status' => 'publish' ), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	$site_tables = mdi_native_multisite_probe_tables();
	$site_transaction = $wpdb->query( 'START TRANSACTION' ) && $wpdb->query( "UPDATE {$wpdb->options} SET option_value = 'rolled-back' WHERE option_name = 'mdi_site_option'" ) && $wpdb->query( 'ROLLBACK' );
	mdi_native_multisite_probe_assert( $site_transaction && 'site-value' === get_option( 'mdi_site_option' ), 'site_transaction_rollback', $checks );
	mdi_native_multisite_probe_assert( get_post( $post_id ) instanceof WP_Post, 'site_post', $checks );
	mdi_native_multisite_probe_assert( in_array( $site_prefix . 'options', $site_tables, true ) && in_array( $site_prefix . 'posts', $site_tables, true ), 'site_show_tables', $checks );
	mdi_native_multisite_probe_assert( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->base_prefix}users WHERE ID = " . (int) $network_user_id ) === '1', 'site_reads_shared_users', $checks );
	mdi_native_multisite_probe_assert( false === $wpdb->query( "SELECT option_value FROM {$wpdb->base_prefix}options WHERE option_name = 'mdi_network_option'" ), 'cross_prefix_site_table_rejected', $checks );
	restore_current_blog();

	$base_tables = mdi_native_multisite_probe_tables();
	$network_transaction = $wpdb->query( 'START TRANSACTION' ) && $wpdb->query( "UPDATE {$wpdb->options} SET option_value = 'rolled-back' WHERE option_name = 'mdi_network_option'" ) && $wpdb->query( 'ROLLBACK' );
	mdi_native_multisite_probe_assert( $base_prefix === $wpdb->prefix, 'restore_base_prefix', $checks );
	mdi_native_multisite_probe_assert( $network_transaction && 'network-value' === get_option( 'mdi_network_option' ), 'network_transaction_rollback', $checks );
	mdi_native_multisite_probe_assert( in_array( $base_prefix . 'blogs', $base_tables, true ) && in_array( $base_prefix . 'users', $base_tables, true ), 'network_show_tables', $checks );
	mdi_native_multisite_probe_assert( false !== $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->base_prefix}blogs WHERE blog_id = %d", $blog_id ) ), 'network_lists_site', $checks );

	$report['site_prefix'] = $site_prefix;
	$report['base_tables'] = $base_tables;
	$report['site_tables'] = $site_tables;
	$report['state_paths'] = array(
		'network_option' => file_exists( MARKDOWN_DB_STATE_DIR . '/_options/mdi_network_option.json' ),
		'site_option' => file_exists( MARKDOWN_DB_STATE_DIR . '/sites/' . $blog_id . '/_options/mdi_site_option.json' ),
		'site_post' => is_dir( MARKDOWN_DB_CONTENT_DIR . '/sites/' . $blog_id . '/post' ),
	);
	mdi_native_multisite_probe_assert( ! in_array( $site_prefix . 'options', $base_tables, true ) && in_array( $base_prefix . 'blogs', $site_tables, true ), 'scope_table_isolation', $checks );
	mdi_native_multisite_probe_assert( ! in_array( false, $report['state_paths'], true ), 'canonical_scope_paths', $checks );
} catch ( Throwable $error ) {
	$report['error'] = get_class( $error );
	$report['message'] = $error->getMessage();
	$checks['exception'] = false;
}

$report['checks'] = $checks;
$report['passed'] = ! in_array( false, $checks, true );
fwrite( $report['passed'] ? STDOUT : STDERR, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
exit( $report['passed'] ? 0 : 1 );
