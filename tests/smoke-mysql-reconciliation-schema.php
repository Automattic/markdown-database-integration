<?php
/** Usage: php tests/smoke-mysql-reconciliation-schema.php */
declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
$actions = array();
$options = array();
$writes = array();
function add_action( string $hook, callable $callback, int $priority = 10 ): void {
	$GLOBALS['actions'][ $hook ][ $priority ][] = $callback;
}
function do_action( string $hook ): void {
	$callbacks = $GLOBALS['actions'][ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) { foreach ( $group as $callback ) { $callback(); } }
}
function get_option( string $key, mixed $default = false ): mixed {
	// The unrelated frontmatter migration has already run on these sites.
	if ( 'markdown_db_frontmatter_portable_migrated' === $key ) { return 'done'; }
	return $GLOBALS['options'][ $GLOBALS['wpdb']->prefix ][ $key ] ?? $default;
}
function update_option( string $key, mixed $value, bool $autoload = true ): bool {
	$GLOBALS['writes'][] = array( $GLOBALS['wpdb']->prefix, $key, $value, $autoload );
	$GLOBALS['options'][ $GLOBALS['wpdb']->prefix ][ $key ] = $value;
	return true;
}
function check( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
	echo "PASS: $message\n";
}
final class MDI_Schema_WPDB {
	public string $prefix = 'wp_';
	public array $queries = array();
	public array $owner = array( 'operation_id' => 'operation', 'fence' => 1 );
	public bool $fail_ddl = false;
	public int $in_transaction = 0;
	public function query( string $sql ): bool {
		$this->queries[] = $sql;
		return ! ( $this->fail_ddl && str_starts_with( $sql, 'CREATE TABLE' ) );
	}
	public function prepare( string $sql, mixed ...$args ): string { return $sql; }
	public function get_row( string $sql, mixed $format ): array { return $this->owner; }
	public function get_var( string $sql ): int { return $this->in_transaction; }
	public function ddl_count(): int {
		return count( array_filter( $this->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'CREATE TABLE' ) ) );
	}
}
$wpdb = new MDI_Schema_WPDB();
require dirname( __DIR__ ) . '/markdown-database-integration.php';
do_action( 'init' );
$wpdb->prefix = 'wp_2_';
do_action( 'switch_blog' );
$wpdb->prefix = 'wp_';
do_action( 'switch_blog' );
check( array() === $wpdb->queries && array() === $writes, 'plugin load, init and blog switches perform no DDL or option writes' );
$operation = array( 'id' => 'operation', 'fence' => 1, 'binding' => array( 'canonical_root' => '/content', 'resource' => array( 'type' => 'post', 'id' => '1' ) ) );
$mutations = 0;
$make_adapter = static function () use ( $wpdb, &$mutations ): WP_Markdown_WPDB_Reconciliation_Adapter {
	return new WP_Markdown_WPDB_Reconciliation_Adapter( $wpdb, static fn() => array(), static function () use ( &$mutations ): void { ++$mutations; } );
};
$adapter = $make_adapter();
$adapter->observe( $operation );
check( 0 === $wpdb->ddl_count(), 'observation does not require schema writes' );
$adapter->fence( $operation );
check( 1 === $wpdb->ddl_count() && 1 === count( $writes ), 'first fence creates schema and stores version once' );
check( str_starts_with( $wpdb->queries[0], 'CREATE TABLE' ) && 'START TRANSACTION' === $wpdb->queries[1], 'DDL runs before the fencing transaction' );
// Remove the persisted marker to prove the request cache, not just the option guard.
unset( $options['wp_']['_markdown_db_mysql_reconciliation_schema'] );
$adapter->apply( $operation );
$make_adapter()->fence( $operation );
check( 1 === $wpdb->ddl_count() && 1 === count( $writes ) && 1 === $mutations, 'apply and new adapter instances reuse the ensured schema' );
$wpdb->prefix = 'wp_2_';
$adapter->fence( $operation );
check( 2 === $wpdb->ddl_count() && 'wp_2_' === $writes[1][0], 'explicit fencing ensures the switched blog independently' );
$wpdb->prefix = 'wp_';
$adapter->fence( $operation );
check( 2 === $wpdb->ddl_count(), 'switching back reuses the original prefix cache' );
$wpdb->prefix = 'wp_3_';
$options['wp_3_']['_markdown_db_mysql_reconciliation_schema'] = '1';
$adapter->fence( $operation );
check( 2 === $wpdb->ddl_count() && 2 === count( $writes ), 'stored current version skips DDL and option updates on an uncached blog' );
$wpdb->prefix = 'wp_4_';
$wpdb->fail_ddl = true;
try { $adapter->fence( $operation ); check( false, 'failed DDL must throw' ); } catch ( RuntimeException $error ) {}
check( ! isset( $options['wp_4_'] ), 'failed DDL does not store schema version' );
$wpdb->fail_ddl = false;
$adapter->fence( $operation );
check( 4 === $wpdb->ddl_count() && isset( $options['wp_4_'] ), 'failed schema setup is not cached and can be retried' );
$wpdb->prefix = 'wp_5_';
$wpdb->in_transaction = 1;
try { $adapter->fence( $operation ); check( false, 'existing transaction must be rejected' ); } catch ( WP_Markdown_Reconciliation_Store_Conflict $error ) {}
check( 4 === $wpdb->ddl_count(), 'existing transactions are rejected before schema DDL' );
