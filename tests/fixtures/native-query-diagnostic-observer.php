<?php
/** Bounded, literal-free same-process native wpdb diagnostic sink for consumer tests. */

if ( ! defined( 'MDI_NATIVE_DIAGNOSTIC_MAX' ) ) {
	define( 'MDI_NATIVE_DIAGNOSTIC_MAX', 25 );
}

$mdi_native_diagnostics = array();
$mdi_native_pending_query = null;

$mdi_native_template = static function ( string $query ): string {
	$template = preg_replace( '/\/\*.*?\*\/|--[^\r\n]*|#[^\r\n]*/s', ' ', $query );
	$template = preg_replace( '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', '?', (string) $template );
	$template = preg_replace( '/(?<![A-Za-z0-9_])[0-9]+(?![A-Za-z0-9_])/', '?', (string) $template );
	$template = trim( (string) preg_replace( '/\s+/', ' ', (string) $template ) );
	return substr( (string) preg_replace( '/[^\x20-\x7E]/', '?', $template ), 0, 500 );
};

$mdi_native_flush = static function () use ( &$mdi_native_diagnostics, &$mdi_native_pending_query, $mdi_native_template ): void {
	global $wpdb;
	if ( ! is_string( $mdi_native_pending_query ) || count( $mdi_native_diagnostics ) >= MDI_NATIVE_DIAGNOSTIC_MAX || ! isset( $wpdb->last_runtime_diagnostic ) || ! is_array( $wpdb->last_runtime_diagnostic ) ) {
		return;
	}
	$diagnostic = $wpdb->last_runtime_diagnostic;
	$mdi_native_diagnostics[] = array(
		'template' => $mdi_native_template( $mdi_native_pending_query ),
		'template_sha256' => hash( 'sha256', $mdi_native_template( $mdi_native_pending_query ) ),
		'code' => (string) ( $diagnostic['code'] ?? '' ),
		'reason' => (string) ( $diagnostic['reason'] ?? '' ),
	);
};

add_filter(
	'query',
	static function ( string $query ) use ( &$mdi_native_pending_query, $mdi_native_flush ): string {
		$mdi_native_flush();
		$mdi_native_pending_query = $query;
		return $query;
	},
	PHP_INT_MIN
);

register_shutdown_function(
	static function () use ( $mdi_native_flush, &$mdi_native_diagnostics ): void {
		$mdi_native_flush();
		fwrite( STDERR, 'MDI_NATIVE_DIAGNOSTICS:' . wp_json_encode( array( 'schema' => 'mdi-native-query-diagnostics/v1', 'records' => $mdi_native_diagnostics ) ) . PHP_EOL );
	}
);
