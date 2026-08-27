<?php
/** Activate the configured plugin corpus against mdi-native and report per-plugin outcomes. */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "FAIL: WordPress did not bootstrap.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

$result = array(
	'schema'  => 'mdi-native-plugin-corpus/v1',
	'backend' => defined( 'MARKDOWN_DB_BACKEND' ) ? MARKDOWN_DB_BACKEND : null,
	'wpdb'    => get_class( $wpdb ),
	'plugins' => array(),
);

if ( ! $wpdb instanceof WP_Markdown_Native_WPDB || 'mdi-native' !== $result['backend'] ) {
	$result['passed'] = false;
	$result['error']  = 'The runtime did not boot the native backend.';
	fwrite( STDERR, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 1 );
}

// The mounted plugin directories define the corpus under test.
$corpus = array();
foreach ( array_keys( get_plugins() ) as $candidate ) {
	$slug = strtok( $candidate, '/' );
	if ( 'markdown-database-integration' === $slug ) {
		continue;
	}
	$corpus[ $slug ] = $candidate;
}
ksort( $corpus );

// WordPress refuses a plugin whose declared dependencies are not active yet, so
// activation is retried until a pass stops making progress.
$pending = $corpus;
$attempts = array();
do {
	$progressed = false;
	foreach ( $pending as $slug => $file ) {
		$attempts[ $slug ] = mdi_native_activate_plugin( $wpdb, $slug, $file );
		if ( 'active' === $attempts[ $slug ]['status'] ) {
			unset( $pending[ $slug ] );
			$progressed = true;
		}
	}
} while ( $progressed && array() !== $pending );

foreach ( $corpus as $slug => $file ) {
	$result['plugins'][] = $attempts[ $slug ];
}

/**
 * Activate one plugin and capture the native diagnostic it produced.
 *
 * @return array<string,mixed>
 */
function mdi_native_activate_plugin( wpdb $wpdb, string $slug, string $file ): array {
	$wpdb->last_runtime_diagnostic = null;
	try {
		$activation = activate_plugin( $file );
		$error      = is_wp_error( $activation ) ? $activation->get_error_message() : null;
	} catch ( Throwable $thrown ) {
		$error = get_class( $thrown ) . ': ' . $thrown->getMessage();
	}

	$active = is_plugin_active( $file );
	return array(
		'slug'       => $slug,
		'file'       => $file,
		'status'     => $active && null === $error ? 'active' : 'failed',
		'error'      => $error,
		'diagnostic' => $wpdb->last_runtime_diagnostic,
		'last_query' => null === $wpdb->last_runtime_diagnostic ? null : $wpdb->last_query,
	);
}

$failed = array_values(
	array_filter( $result['plugins'], static fn( array $row ): bool => 'active' !== $row['status'] )
);
$result['summary'] = array(
	'total'  => count( $result['plugins'] ),
	'active' => count( $result['plugins'] ) - count( $failed ),
	'failed' => count( $failed ),
);
$result['passed'] = array() === $failed;

// The runner judges the corpus. Reporting structurally keeps a failing
// activation distinguishable from a crashed runtime.
fwrite( STDOUT, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
