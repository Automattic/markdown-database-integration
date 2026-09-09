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
	$site_id = get_id_from_blogname( 'mdi-site-two' );
	if ( ! $site_id ) {
		$created = wpmu_create_blog( 'example.com', '/mdi-site-two/', 'MDI Site Two', 1 );
		if ( is_wp_error( $created ) ) {
			throw new RuntimeException( $created->get_error_message() );
		}
		$site_id = (int) $created;
	}
	$report['site_id'] = $site_id;
	mdi_native_multisite_probe_assert( $site_id > 1, 'site_created', $checks );

	$base_prefix = $wpdb->prefix;
	update_option( 'mdi_network_option', 'network-value' );

	// wpmu_create_blog() may temporarily switch the current blog. Return to the
	// network primary site before exercising the caller-visible switch sequence.
	while ( ms_is_switched() ) {
		restore_current_blog();
	}
	if ( 1 !== get_current_blog_id() ) {
		switch_to_blog( 1 );
	}
	$report['switch_return'] = switch_to_blog( $site_id );
	$site_prefix = $wpdb->prefix;
	$report['site_blogid'] = $wpdb->blogid;
	$report['global_blog_id'] = $GLOBALS['blog_id'] ?? null;
	mdi_native_multisite_probe_assert( $site_prefix === $wpdb->base_prefix . $site_id . '_', 'switch_sets_site_prefix', $checks );
	mdi_native_multisite_probe_assert( $wpdb->blogid === $site_id && $report['global_blog_id'] === $site_id, 'switch_sets_blog_identity', $checks );
	update_option( 'mdi_site_option', 'site-value' );
	$post_id = wp_insert_post( array( 'post_title' => 'MDI site post', 'post_content' => 'site-specific body', 'post_status' => 'publish' ), true );
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	$site_tables = mdi_native_multisite_probe_tables();
	$site_transaction = array(
		'begin' => $wpdb->query( 'START TRANSACTION' ),
		'update' => $wpdb->query( "UPDATE {$wpdb->options} SET option_value = 'rolled-back' WHERE option_name = 'mdi_site_option'" ),
		'rollback' => $wpdb->query( 'ROLLBACK' ),
	);
	$site_option_after_rollback = get_option( 'mdi_site_option' );
	$report['site_transaction'] = $site_transaction;
	mdi_native_multisite_probe_assert( ! in_array( false, $site_transaction, true ) && 'site-value' === $site_option_after_rollback, 'site_transaction_rollback', $checks );
	mdi_native_multisite_probe_assert( get_post( $post_id ) instanceof WP_Post, 'site_post', $checks );
	mdi_native_multisite_probe_assert( in_array( $site_prefix . 'options', $site_tables, true ) && in_array( $site_prefix . 'posts', $site_tables, true ), 'site_show_tables', $checks );
	mdi_native_multisite_probe_assert( false === $wpdb->query( "SELECT option_value FROM {$wpdb->base_prefix}options WHERE option_name = 'mdi_network_option'" ), 'cross_prefix_site_table_rejected', $checks );
	$foreign_id = wp_insert_attachment(
		array(
			'import_id'      => 987654321,
			'post_title'     => 'MDI foreign attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
		),
		'foreign-logo.png',
		0,
		true
	);
	if ( is_wp_error( $foreign_id ) ) {
		throw new RuntimeException( $foreign_id->get_error_message() );
	}
	$foreign_id = (int) $foreign_id;
	mdi_native_multisite_probe_assert( get_post( $foreign_id ) instanceof WP_Post, 'foreign_attachment_exists_on_site', $checks );
	restore_current_blog();

	$foreign_post = get_post( $foreign_id );
	mdi_native_multisite_probe_assert( null === $foreign_post, 'foreign_attachment_is_not_visible_after_restore', $checks );

	$base_tables = mdi_native_multisite_probe_tables();
	$network_transaction = array(
		'begin' => $wpdb->query( 'START TRANSACTION' ),
		'update' => $wpdb->query( "UPDATE {$wpdb->options} SET option_value = 'rolled-back' WHERE option_name = 'mdi_network_option'" ),
		'rollback' => $wpdb->query( 'ROLLBACK' ),
	);
	$network_option_after_rollback = get_option( 'mdi_network_option' );
	$report['network_transaction'] = $network_transaction;
	mdi_native_multisite_probe_assert( $base_prefix === $wpdb->prefix, 'restore_base_prefix', $checks );
	mdi_native_multisite_probe_assert( ! in_array( false, $network_transaction, true ) && 'network-value' === $network_option_after_rollback, 'network_transaction_rollback', $checks );
	mdi_native_multisite_probe_assert( in_array( $base_prefix . 'blogs', $base_tables, true ) && in_array( $base_prefix . 'users', $base_tables, true ), 'network_show_tables', $checks );
	mdi_native_multisite_probe_assert( false !== $wpdb->get_var( $wpdb->prepare( "SELECT blog_id FROM {$wpdb->base_prefix}blogs WHERE blog_id = %d", $site_id ) ), 'network_lists_site', $checks );

	$report['site_prefix'] = $site_prefix;
	$report['base_tables'] = $base_tables;
	$report['site_tables'] = $site_tables;
	$report['state_paths'] = array(
		'network_option' => file_exists( MARKDOWN_DB_STATE_DIR . '/_options/mdi_network_option.json' ),
		'site_option' => file_exists( MARKDOWN_DB_STATE_DIR . '/sites/' . $site_id . '/_options/mdi_site_option.json' ),
		'site_post' => is_dir( MARKDOWN_DB_CONTENT_DIR . '/sites/' . $site_id . '/post' ),
	);
	mdi_native_multisite_probe_assert( ! in_array( $site_prefix . 'options', $base_tables, true ) && ! in_array( $base_prefix . 'blogs', $site_tables, true ), 'scope_table_isolation', $checks );
	mdi_native_multisite_probe_assert( in_array( $base_prefix . 'users', $base_tables, true ) && ! in_array( $site_prefix . 'users', $site_tables, true ), 'user_table_is_network_global', $checks );
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
