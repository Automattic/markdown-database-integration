<?php
/**
 * Executable backend capability contract and SQLite reference compatibility test.
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

$sqlite = WP_Markdown_Backend_Capabilities::sqlite();
$expected = array(
	'content_mutation_capture',
	'table_mutation_capture',
	'schema_persistence',
	'cold_reconstruction',
	'disposable_index_operation',
	'lazy_post_content_resolution',
	'explicit_flush',
	'changed_path_receipts',
);

mdi_backend_assert( 'sqlite' === $sqlite->get_backend(), 'SQLite reference backend identifier is stable' );
mdi_backend_assert( $expected === array_keys( $sqlite->report()['capabilities'] ), 'SQLite reference contract declares the complete compatibility matrix in stable order' );
foreach ( $expected as $capability ) {
	mdi_backend_assert( $sqlite->supports( $capability ), 'SQLite supports ' . $capability );
	$sqlite->require( $capability );
}

$mysql_content = WP_Markdown_Backend_Capabilities::mysql_content();
mdi_backend_assert( 'mysql-content' === $mysql_content->get_backend(), 'MySQL content-primary backend identifier is stable' );
foreach ( array( 'content_mutation_capture', 'cold_reconstruction', 'explicit_flush', 'changed_path_receipts' ) as $capability ) {
	mdi_backend_assert( $mysql_content->supports( $capability ), 'MySQL content-primary supports ' . $capability );
}

$mysql_full = WP_Markdown_Backend_Capabilities::mysql_full();
mdi_backend_assert( 'mysql-full' === $mysql_full->get_backend(), 'MySQL full-primary backend identifier is stable' );
foreach ( array( 'content_mutation_capture', 'table_mutation_capture', 'schema_persistence', 'explicit_flush', 'changed_path_receipts' ) as $capability ) {
	mdi_backend_assert( $mysql_full->supports( $capability ), 'MySQL full-primary declares ' . $capability );
}
foreach ( array( 'cold_reconstruction', 'disposable_index_operation', 'lazy_post_content_resolution' ) as $capability ) {
	mdi_backend_assert( ! $mysql_full->supports( $capability ), 'MySQL full-primary does not claim an unimplemented reconstruction capability: ' . $capability );
}
foreach ( array( 'table_mutation_capture', 'schema_persistence', 'disposable_index_operation', 'lazy_post_content_resolution' ) as $capability ) {
	mdi_backend_assert( ! $mysql_content->supports( $capability ), 'MySQL content-primary fails closed for ' . $capability );
}

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
