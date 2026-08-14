<?php
/** Multisite fence provisioning regression. Usage: php tests/smoke-mysql-content-switch-blog-fences.php */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
define( 'MARKDOWN_DB_BACKEND', 'mysql-content' );
$GLOBALS['mdi_fence_hooks'] = array();
$GLOBALS['mdi_fence_options'] = array();

function plugin_dir_path( string $file ): string { return dirname( $file ) . '/'; }
function did_action( string $hook ): int { unset( $hook ); return 0; }
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['mdi_fence_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args ); }
function update_option( string $name, mixed $value, bool $autoload = true ): bool { unset( $autoload ); $GLOBALS['mdi_fence_options'][ $name ] = $value; return true; }
function do_action( string $hook, mixed ...$args ): void {
	$callbacks = $GLOBALS['mdi_fence_hooks'][ $hook ] ?? array(); ksort( $callbacks, SORT_NUMERIC );
	foreach ( $callbacks as $group ) { foreach ( $group as $registered ) { list( $callback, $accepted_args ) = $registered; $callback( ...array_slice( $args, 0, $accepted_args ) ); } }
}

final class MDI_Fence_WPDB {
	public string $prefix = 'wp_';
	public array $queries = array();
	public function query( string $sql ): int { $this->queries[] = $sql; return 1; }
}

global $wpdb;
$wpdb = new MDI_Fence_WPDB();
require_once __DIR__ . '/../markdown-database-integration.php';

$wpdb->prefix = 'wp_23_';
do_action( 'switch_blog', 23, 1, 'switch' );
$passed = isset( $GLOBALS['mdi_fence_hooks']['switch_blog'][0] )
	&& 1 === count( $wpdb->queries )
	&& str_contains( $wpdb->queries[0], '`wp_23_mdi_resource_fences`' )
	&& str_contains( $wpdb->queries[0], 'ENGINE=InnoDB' )
	&& '1' === ( $GLOBALS['mdi_fence_options']['_markdown_db_mysql_reconciliation_schema'] ?? '' );
if ( ! $passed ) { fwrite( STDERR, "FAIL: switch_blog provisions the active blog's prefixed reconciliation fence table\n" ); exit( 1 ); }
echo "PASS: switch_blog provisions the active blog's prefixed reconciliation fence table\n";
