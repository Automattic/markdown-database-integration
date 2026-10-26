<?php
/**
 * Executable native backend capability contract.
 *
 * Usage: php tests/smoke-backend-capabilities.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-backend-capabilities.php';

$failures = array();
function mdi_backend_assert( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$native = WP_Markdown_Backend_Capabilities::mdi_native();
mdi_backend_assert( 'mdi-native' === $native->get_backend(), 'Native backend identifier is stable' );
mdi_backend_assert( $native->supports( 'canonical_option_select' ), 'Native backend declares its bounded option query guarantee' );
foreach ( array( 'schema_persistence', 'table_mutation_capture', 'content_mutation_capture', 'lazy_post_content_resolution' ) as $capability ) {
	mdi_backend_assert( $native->supports( $capability ), 'Native backend declares ' . $capability );
}
foreach ( array( 'cold_reconstruction', 'disposable_index_operation', 'explicit_flush', 'changed_path_receipts' ) as $capability ) {
	mdi_backend_assert( ! $native->supports( $capability ), 'Native backend fails closed for ' . $capability );
}
mdi_backend_assert( 'mdi-native' === WP_Markdown_Backend_Resolver::configure_from_globals()->get_backend(), 'default backend is native' );
mdi_backend_assert( 'mdi-native' === WP_Markdown_Backend_Resolver::resolve()->get_backend(), 'runtime resolution is native' );
$incomplete = new WP_Markdown_Backend_Capabilities( 'test', array( 'content_mutation_capture' => true ) );
mdi_backend_assert( ! $incomplete->supports( 'explicit_flush' ), 'undeclared capabilities fail closed' );
try {
	$incomplete->require( 'explicit_flush' );
	mdi_backend_assert( false, 'unsupported capability throws a structured diagnostic' );
} catch ( WP_Markdown_Unsupported_Backend_Capability $error ) {
	$diagnostic = $error->get_diagnostic();
	mdi_backend_assert( 'markdown_db_unsupported_backend_capability' === $diagnostic['code'], 'unsupported diagnostic has a stable code' );
	mdi_backend_assert( 'test' === $diagnostic['backend'] && 'explicit_flush' === $diagnostic['capability'], 'unsupported diagnostic identifies backend and capability' );
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo "All backend capability checks passed.\n";
