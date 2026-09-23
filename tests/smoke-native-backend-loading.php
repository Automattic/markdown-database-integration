<?php
/**
 * mdi-native loads no MySQL runtime and provisions no MySQL fence table.
 *
 * The MySQL runtimes, their bootstrap hooks, and the per-request
 * `CREATE TABLE … mdi_resource_fences` plus option write only apply when a
 * MySQL backend is configured. Native requests paid for all of it. The
 * mysql-content counterpart stays covered by
 * smoke-mysql-content-switch-blog-fences.php.
 *
 * Usage: php tests/smoke-native-backend-loading.php
 */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
define( 'MARKDOWN_DB_BACKEND', 'mdi-native' );
$GLOBALS['mdi_native_hooks'] = array();
$GLOBALS['mdi_native_options'] = array();

function plugin_dir_path( string $file ): string { return dirname( $file ) . '/'; }
function did_action( string $hook ): int { unset( $hook ); return 0; }
function add_action( string $hook, callable|array $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['mdi_native_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args ); }
function update_option( string $name, mixed $value, bool $autoload = true ): bool { unset( $autoload ); $GLOBALS['mdi_native_options'][ $name ] = $value; return true; }
function do_action( string $hook, mixed ...$args ): void {
	$callbacks = $GLOBALS['mdi_native_hooks'][ $hook ] ?? array(); ksort( $callbacks, SORT_NUMERIC );
	foreach ( $callbacks as $group ) { foreach ( $group as $registered ) { list( $callback, $accepted_args ) = $registered; if ( is_callable( $callback ) ) { $callback( ...array_slice( $args, 0, $accepted_args ) ); } } }
}

final class MDI_Native_Loading_WPDB {
	public string $prefix = 'wp_';
	public array $queries = array();
	public function query( string $sql ): int { $this->queries[] = $sql; return 1; }
}

global $wpdb;
$wpdb = new MDI_Native_Loading_WPDB();
require_once __DIR__ . '/../markdown-database-integration.php';

$mysql_loaded = array_values( array_filter( get_included_files(), static fn( string $file ): bool => str_contains( $file, '/inc/mysql/' ) ) );
$bootstraps   = array();
foreach ( $GLOBALS['mdi_native_hooks']['plugins_loaded'] ?? array() as $group ) {
	foreach ( $group as $registered ) {
		if ( is_array( $registered[0] ) && is_string( $registered[0][0] ) && str_starts_with( $registered[0][0], 'WP_Markdown_MySQL_' ) ) {
			$bootstraps[] = $registered[0][0];
		}
	}
}
markdown_database_integration_ensure_mysql_reconciliation_state();
do_action( 'switch_blog', 2, 1, 'switch' );

$checks = array(
	'no MySQL runtime class is loaded'                   => array() === $mysql_loaded && ! class_exists( 'WP_Markdown_MySQL_Outbox', false ),
	'no MySQL runtime bootstrap is hooked'                => array() === $bootstraps,
	'no fence table DDL runs on init or switch_blog'      => array() === $wpdb->queries,
	'no MySQL reconciliation schema option is written'    => ! isset( $GLOBALS['mdi_native_options']['_markdown_db_mysql_reconciliation_schema'] ),
	'the SQLite recovery migration tool remains available' => class_exists( 'WP_Markdown_SQLite_Recovery', false ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}
if ( $failed ) {
	echo 'mysql files: ', implode( ', ', array_map( 'basename', $mysql_loaded ) ), "\nqueries: ", implode( ' | ', $wpdb->queries ), "\n";
}
exit( $failed ? 1 : 0 );
