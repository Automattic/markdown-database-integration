<?php
/**
 * Classify every query observed during the current native WordPress request.
 *
 * Usage: wp eval-file wp-content/plugins/markdown-database-integration/tests/probe-native-authoritative-request.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

global $wpdb;

$entries = is_array( $wpdb->queries ?? null ) ? $wpdb->queries : array();
$runtime = WP_Markdown_Native_Runtime_Factory::runtime(
	MARKDOWN_DB_STATE_DIR,
	$wpdb->prefix,
	$wpdb->base_prefix,
	is_multisite(),
	MARKDOWN_DB_CONTENT_DIR
);
$compatibility = array(
	'observed'      => count( $entries ),
	'supported'     => 0,
	'unsupported'   => 0,
	'first_blocker' => null,
	'blockers'      => array(),
);

foreach ( $entries as $entry ) {
	$query  = is_array( $entry ) ? (string) ( $entry[0] ?? '' ) : '';
	$result = $runtime->execute( new WP_Markdown_Query_Request( $query, $wpdb->prefix ) );
	if ( $result->succeeded() ) {
		++$compatibility['supported'];
		continue;
	}

	++$compatibility['unsupported'];
	$template = preg_replace( '/\s+/', ' ', trim( $query ) );
	$template = preg_replace( '/\'(?:[^\'\\\\]|\\\\.)*\'/s', '?', (string) $template );
	$template = preg_replace( '/(?<![A-Za-z0-9_])[0-9]+(?![A-Za-z0-9_])/', '?', (string) $template );
	$key      = hash( 'sha256', (string) $template );
	if ( ! isset( $compatibility['blockers'][ $key ] ) ) {
		$compatibility['blockers'][ $key ] = array(
			'count'          => 0,
			'query_template' => $template,
			'diagnostic'     => $result->diagnostic(),
		);
	}
	++$compatibility['blockers'][ $key ]['count'];
	$compatibility['first_blocker'] ??= $compatibility['blockers'][ $key ];
}

$compatibility['blockers'] = array_values( $compatibility['blockers'] );
echo wp_json_encode(
	array(
		'schema'        => 'mdi-native-authoritative-request/v1',
		'dropin'        => defined( 'MARKDOWN_DB_DROPIN' ),
		'wpdb'          => is_object( $wpdb ) ? get_class( $wpdb ) : null,
		'queries'       => is_object( $wpdb ) ? (int) ( $wpdb->num_queries ?? 0 ) : 0,
		'last_query'    => is_object( $wpdb ) ? (string) ( $wpdb->last_query ?? '' ) : '',
		'diagnostic'    => is_object( $wpdb ) ? ( $wpdb->last_runtime_diagnostic ?? null ) : null,
		'siteurl'       => get_option( 'siteurl' ),
		'compatibility' => $compatibility,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
