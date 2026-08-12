<?php
/** Row-partitioned canonical table persistence coverage. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['mdi_partition_filters'] = array();

function add_filter( string $tag, callable $callback ): void { $GLOBALS['mdi_partition_filters'][ $tag ][] = $callback; }
function has_filter( string $tag ): bool { return ! empty( $GLOBALS['mdi_partition_filters'][ $tag ] ); }
function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed { foreach ( $GLOBALS['mdi_partition_filters'][ $tag ] ?? array() as $callback ) { $value = $callback( $value, ...$args ); } return $value; }
function do_action(): void {}

require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';

class MDI_Partition_Driver {
	public PDO $pdo;
	public array $queries = array();
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_runtime_events (event_id INTEGER PRIMARY KEY, payload TEXT)' );
		$this->pdo->exec( "INSERT INTO wp_runtime_events VALUES (1, 'one'), (2, 'two'), (3, 'three')" );
	}
	public function query( string $sql ): PDOStatement|false { $this->queries[] = $sql; return $this->pdo->query( $sql ); }
	public function query_cursor( string $sql ): PDOStatement|false { return $this->query( $sql ); }
	public function get_insert_id(): int { return (int) $this->pdo->lastInsertId(); }
	public function get_connection(): object { return new class( $this->pdo ) { public function __construct( private PDO $pdo ) {} public function get_pdo(): PDO { return $this->pdo; } }; }
}

require_once __DIR__ . '/../inc/class-wp-markdown-write-engine.php';
require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';

$failed = 0;
function mdi_partition_assert( bool $condition, string $label ): void { global $failed; echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL; if ( ! $condition ) { $failed++; } }
function mdi_partition_rm( string $path ): void { if ( ! is_dir( $path ) ) { return; } foreach ( scandir( $path ) ?: array() as $entry ) { if ( '.' === $entry || '..' === $entry ) { continue; } $child = $path . '/' . $entry; is_dir( $child ) ? mdi_partition_rm( $child ) : unlink( $child ); } rmdir( $path ); }

add_filter( 'markdown_db_table_persistence_policy', static function ( array $policy ): array { $policy['runtime_events'] = array( 'partition_by' => 'event_id' ); return $policy; } );

$root = sys_get_temp_dir() . '/mdi-partition-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );
$driver = new MDI_Partition_Driver();
$engine = new WP_Markdown_Write_Engine( $root, new WP_Markdown_Storage( $root ), $driver, 'wp_' );

// The first mutation migrates the legacy logical table into complete partitions.
$engine->persist_write( "UPDATE wp_runtime_events SET payload = 'two' WHERE event_id = 2", 'wp_runtime_events', 'UPDATE' );
$engine->flush_dirty( true );
$directory = $root . '/_tables/runtime_events';
$marker = json_decode( (string) file_get_contents( $directory . '/.mdi-partition.json' ), true );
$generation_directory = $directory . '/' . $marker['generation'];
$paths = array(
	1 => $generation_directory . '/' . hash( 'sha256', '1' ) . '.json',
	2 => $generation_directory . '/' . hash( 'sha256', '2' ) . '.json',
	3 => $generation_directory . '/' . hash( 'sha256', '3' ) . '.json',
);
mdi_partition_assert( is_file( $directory . '/.mdi-partition.json' ) && is_file( $paths[1] ) && is_file( $paths[2] ) && is_file( $paths[3] ), 'first mutation creates a complete partitioned table' );

$before = array_map( 'hash_file', array_fill( 0, 3, 'sha256' ), array_values( $paths ) );
$driver->queries = array();
$driver->pdo->exec( "UPDATE wp_runtime_events SET payload = 'changed' WHERE event_id = 2" );
$engine->persist_write( "UPDATE wp_runtime_events SET payload = 'changed' WHERE event_id = 2", 'wp_runtime_events', 'UPDATE' );
$engine->flush_dirty( true );
$after = array_map( 'hash_file', array_fill( 0, 3, 'sha256' ), array_values( $paths ) );
mdi_partition_assert( 1 === count( $driver->queries ) && str_contains( $driver->queries[0], "WHERE `event_id` IN ('2')" ), 'one-row mutation reads only its declared identity' );
mdi_partition_assert( $before[0] === $after[0] && $before[1] !== $after[1] && $before[2] === $after[2], 'one-row mutation rewrites no unrelated partition' );

$driver->queries = array();
$driver->pdo->exec( 'DELETE FROM wp_runtime_events WHERE event_id = 2' );
$engine->persist_write( 'DELETE FROM wp_runtime_events WHERE event_id = 2', 'wp_runtime_events', 'DELETE' );
$engine->flush_dirty( true );
mdi_partition_assert( ! file_exists( $paths[2] ) && file_exists( $paths[1] ) && file_exists( $paths[3] ), 'one-row delete removes only its partition' );
mdi_partition_assert( 1 === count( $driver->queries ) && str_contains( $driver->queries[0], "WHERE `event_id` IN ('2')" ), 'one-row delete avoids full-table enumeration' );

$rows = array();
foreach ( glob( $generation_directory . '/*.json' ) ?: array() as $file ) { $data = json_decode( (string) file_get_contents( $file ), true ); $rows[] = $data['row']['event_id'] ?? null; }
sort( $rows );
mdi_partition_assert( array( 1, 3 ) === $rows, 'partition directory reconstructs the remaining logical rows' );

$mutations = ( new WP_Markdown_SQLite_Operations( $driver ) )->mutations_for_query( "INSERT INTO wp_runtime_events (event_id, payload) VALUES (4, 'four'), (5, 'five')", array( 'table' => 'wp_runtime_events', 'op' => 'INSERT', 'type' => 'DML' ) );
mdi_partition_assert( empty( $mutations[0]['scope']['resource_ids_by_column'] ?? array() ), 'ambiguous multi-row insert fails closed to full reconciliation' );

$operations = new WP_Markdown_SQLite_Operations( $driver );
$mutations = $operations->mutations_for_query( 'UPDATE wp_runtime_events SET event_id = event_id + 1', array( 'table' => 'wp_runtime_events', 'op' => 'UPDATE', 'type' => 'DML' ) );
mdi_partition_assert( empty( $mutations[0]['scope']['resource_ids_by_column'] ?? array() ), 'update expression without an identity predicate fails closed to full reconciliation' );
$mutations = $operations->mutations_for_query( "UPDATE wp_runtime_events SET payload = 'changed' WHERE event_id IN (1, 3)", array( 'table' => 'wp_runtime_events', 'op' => 'UPDATE', 'type' => 'DML' ) );
mdi_partition_assert( array( '1', '3' ) === ( $mutations[0]['scope']['resource_ids_by_column']['event_id'] ?? array() ), 'scope extraction reads identity literals only from the WHERE predicate' );
$mutations = $operations->mutations_for_query( 'UPDATE wp_runtime_events SET event_id = 99 WHERE event_id = 1', array( 'table' => 'wp_runtime_events', 'op' => 'UPDATE', 'type' => 'DML' ) );
mdi_partition_assert( array( 'event_id' ) === ( $mutations[0]['scope']['assigned_columns'] ?? array() ), 'identity-changing updates are declared for full reconciliation' );
$mutations = $operations->mutations_for_query( "UPDATE wp_runtime_events SET payload = 'changed' WHERE event_id = 1 OR payload = 'three'", array( 'table' => 'wp_runtime_events', 'op' => 'UPDATE', 'type' => 'DML' ) );
mdi_partition_assert( empty( $mutations[0]['scope']['resource_ids_by_column'] ?? array() ), 'OR predicate fails closed to full reconciliation' );
$mutations = $operations->mutations_for_query( "REPLACE INTO wp_runtime_events (event_id, payload) VALUES (1, 'changed')", array( 'table' => 'wp_runtime_events', 'op' => 'REPLACE', 'type' => 'DML' ) );
mdi_partition_assert( empty( $mutations[0]['scope']['resource_ids_by_column'] ?? array() ), 'REPLACE fails closed because another unique key may remove a different row' );
$mutations = $operations->mutations_for_query( "INSERT INTO wp_runtime_events (event_id, payload) VALUES (1, 'changed') ON DUPLICATE KEY UPDATE payload = 'changed'", array( 'table' => 'wp_runtime_events', 'op' => 'INSERT', 'type' => 'DML' ) );
mdi_partition_assert( empty( $mutations[0]['scope']['resource_ids_by_column'] ?? array() ), 'upsert fails closed because another unique key may update a different row' );
$mutations = $operations->mutations_for_query( "INSERT INTO wp_runtime_events (event_id, payload) VALUES (1, 'changed') ON CONFLICT(payload) DO UPDATE SET payload = 'changed'", array( 'table' => 'wp_runtime_events', 'op' => 'INSERT', 'type' => 'DML' ) );
mdi_partition_assert( empty( $mutations[0]['scope']['resource_ids_by_column'] ?? array() ), 'SQLite upsert fails closed because another unique key may update a different row' );
$mutations = $operations->mutations_for_query( "INSERT INTO wp_runtime_events (event_id, payload) VALUES (NULL, 'changed')", array( 'table' => 'wp_runtime_events', 'op' => 'INSERT', 'type' => 'DML' ) );
mdi_partition_assert( empty( $mutations[0]['scope']['resource_ids_by_column']['event_id'] ?? array() ), 'generated NULL identity is not persisted as a literal partition key' );
$mutations = $operations->mutations_for_query( "UPDATE wp_runtime_events SET payload = 'changed' WHERE EVENT_ID = 1", array( 'table' => 'wp_runtime_events', 'op' => 'UPDATE', 'type' => 'DML' ) );
mdi_partition_assert( array( '1' ) === ( $mutations[0]['scope']['resource_ids_by_column']['event_id'] ?? array() ), 'SQL identifiers are normalized case-insensitively' );

add_filter( 'markdown_db_persistent_table_rows', static fn( array $rows ): array => $rows );
$driver->queries = array();
$driver->pdo->exec( "UPDATE wp_runtime_events SET payload = 'filtered' WHERE event_id = 1" );
$engine->persist_write( "UPDATE wp_runtime_events SET payload = 'filtered' WHERE event_id = 1", 'wp_runtime_events', 'UPDATE' );
$engine->flush_dirty( true );
mdi_partition_assert( 1 === count( $driver->queries ) && ! str_contains( $driver->queries[0], ' WHERE ' ), 'legacy row filter retains its complete-table input contract' );
$current_marker = json_decode( (string) file_get_contents( $directory . '/.mdi-partition.json' ), true );
mdi_partition_assert( $marker['generation'] !== $current_marker['generation'] && ! is_dir( $generation_directory ), 'full reconciliation atomically rolls to a complete generation and reclaims the old one' );

$rehydrated = new MDI_Partition_Driver();
$rehydrated->pdo->exec( 'DELETE FROM wp_runtime_events' );
$loader = new WP_Markdown_Loader( $root, new WP_Markdown_SQLite_Operations( $rehydrated ), new WP_Markdown_Storage( $root ), 'wp_' );
$method = new ReflectionMethod( $loader, 'load_plugin_tables' );
$method->invoke( $loader );
$rows = $rehydrated->pdo->query( 'SELECT event_id, payload FROM wp_runtime_events ORDER BY event_id' )->fetchAll( PDO::FETCH_ASSOC );
mdi_partition_assert( array( 1, 3 ) === array_map( static fn( array $row ): int => (int) $row['event_id'], $rows ), 'cold loader reconstructs the logical table from row partitions' );

mdi_partition_rm( $root );
exit( $failed ? 1 : 0 );
