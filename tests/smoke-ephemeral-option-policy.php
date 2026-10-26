<?php
/**
 * Smoke coverage for configurable exact ephemeral option names.
 *
 * Usage: php tests/smoke-ephemeral-option-policy.php
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['mdi_ephemeral_filters'] = array();

function add_filter( string $tag, callable $callback ): void {
	$GLOBALS['mdi_ephemeral_filters'][ $tag ][] = $callback;
}

function remove_all_filters( string $tag ): void {
	unset( $GLOBALS['mdi_ephemeral_filters'][ $tag ] );
}

function apply_filters( string $tag, mixed $value ): mixed {
	foreach ( $GLOBALS['mdi_ephemeral_filters'][ $tag ] ?? array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}

require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';

require_once __DIR__ . '/stubs/stub-backend-operations.php';
require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';

$failed = 0;
function mdi_ephemeral_assert( bool $condition, string $label ): void {
	global $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $condition ) {
		$failed++;
	}
}

function mdi_ephemeral_rm( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( scandir( $path ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$child = $path . '/' . $entry;
		is_dir( $child ) ? mdi_ephemeral_rm( $child ) : unlink( $child );
	}
	rmdir( $path );
}

$timestamp = 1770000000;
$args      = array( 42, 'proof' );
$event_key = md5( serialize( $args ) );
$cron      = array(
	$timestamp => array(
		'wp_codebox_durable_cron' => array(
			$event_key => array(
				'schedule' => false,
				'args'     => $args,
			),
		),
	),
	'version' => 2,
);
$rows      = array(
	(object) array( 'option_id' => 1, 'option_name' => 'cron', 'option_value' => serialize( $cron ), 'autoload' => 'on' ),
	(object) array( 'option_id' => 2, 'option_name' => 'doing_cron', 'option_value' => 'lock', 'autoload' => 'on' ),
	(object) array( 'option_id' => 3, 'option_name' => '_transient_proof', 'option_value' => 'temporary', 'autoload' => 'off' ),
);
$root      = sys_get_temp_dir() . '/mdi-ephemeral-policy-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );
$operations = new MDI_Stub_Backend_Operations();
$operations->rows['options'] = array_column( array_map( static fn( object $row ): array => (array) $row, $rows ), null, 'option_name' );
$engine = new WP_Markdown_Canonical_Persistence( $root, new WP_Markdown_Storage( $root ), $operations, 'wp_' );
$dirty  = new ReflectionProperty( WP_Markdown_Canonical_Persistence::class, 'dirty_option_names' );
$flush  = new ReflectionMethod( WP_Markdown_Canonical_Persistence::class, 'persist_options' );
$mark_all_dirty = static function () use ( $dirty, $engine ): void {
	$dirty->setValue( $engine, array( 'cron' => true, 'doing_cron' => true, '_transient_proof' => true ) );
};

$mark_all_dirty();
$flush->invoke( $engine );
mdi_ephemeral_assert( ! file_exists( $root . '/_options/cron.json' ), 'cron remains ephemeral by default' );
mdi_ephemeral_assert( ! file_exists( $root . '/_options/doing_cron.json' ), 'doing_cron remains ephemeral by default' );
mdi_ephemeral_assert( ! file_exists( $root . '/_options/transient_proof.json' ), 'transient prefixes remain ephemeral by default' );

add_filter(
	'markdown_database_integration_ephemeral_option_names',
	static function ( array $names ): array {
		$GLOBALS['mdi_ephemeral_filter_calls'] = ( $GLOBALS['mdi_ephemeral_filter_calls'] ?? 0 ) + 1;
		return array_values( array_diff( $names, array( 'cron' ) ) );
	}
);
$GLOBALS['mdi_ephemeral_filter_calls'] = 0;
$mark_all_dirty();
$flush->invoke( $engine );
$cron_path = $root . '/_options/cron.json';
mdi_ephemeral_assert( file_exists( $cron_path ), 'durable runtime can opt cron into canonical persistence after engine construction' );
mdi_ephemeral_assert( 1 === $GLOBALS['mdi_ephemeral_filter_calls'], 'exact-name policy resolves once per option flush' );
mdi_ephemeral_assert( ! file_exists( $root . '/_options/doing_cron.json' ), 'durable cron opt-in keeps doing_cron ephemeral' );
mdi_ephemeral_assert( ! file_exists( $root . '/_options/transient_proof.json' ), 'durable cron opt-in keeps transient prefixes ephemeral' );

remove_all_filters( 'markdown_database_integration_ephemeral_option_names' );
add_filter( 'markdown_database_integration_ephemeral_option_names', static fn (): string => 'invalid' );
$mark_all_dirty();
$flush->invoke( $engine );
mdi_ephemeral_assert( ! file_exists( $cron_path ), 'malformed filter output fails closed to default cron ephemerality' );

remove_all_filters( 'markdown_database_integration_ephemeral_option_names' );
add_filter( 'markdown_database_integration_ephemeral_option_names', static fn (): array => array() );
$mark_all_dirty();
$flush->invoke( $engine );
mdi_ephemeral_assert( file_exists( $root . '/_options/doing_cron.json' ), 'an intentional empty exact-name policy is valid' );
mdi_ephemeral_assert( ! file_exists( $root . '/_options/transient_proof.json' ), 'exact-name policy cannot disable transient-prefix protection' );

mdi_ephemeral_rm( $root );
exit( $failed ? 1 : 0 );
