<?php
/** Row-partitioned canonical persistence, independent of query adapters. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';
require_once __DIR__ . '/stubs/stub-backend-operations.php';
require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';

$GLOBALS['mdi_partition_full_rows'] = false;
function has_filter( string $tag ): bool { return 'markdown_db_persistent_table_rows' === $tag && $GLOBALS['mdi_partition_full_rows']; }
function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
	if ( 'markdown_db_table_persistence_policy' === $tag ) { $value['runtime_events'] = array( 'partition_by' => 'event_id', 'resource_ids' => array( '999' ) ); }
	return $value;
}
function do_action(): void {}
class MDI_Partition_Operations extends MDI_Stub_Backend_Operations {
	public array $reads = array();
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable {
		$this->reads[] = $policy;
		$rows = $this->rows[ $table_suffix ] ?? array();
		if ( isset( $policy['resource_ids'] ) ) { $rows = array_intersect_key( $rows, array_flip( $policy['resource_ids'] ) ); }
		yield from array_values( $rows );
	}
}
$root = sys_get_temp_dir() . '/mdi-partition-' . getmypid();
mkdir( $root . '/_tables', 0755, true );
register_shutdown_function( static function () use ( $root ): void {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) { $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); }
	rmdir( $root );
} );
$operations = new MDI_Partition_Operations();
$operations->rows['runtime_events'] = array( 1 => array( 'event_id' => 1, 'payload' => 'one' ), 2 => array( 'event_id' => 2, 'payload' => 'two' ), 3 => array( 'event_id' => 3, 'payload' => 'three' ) );
$engine = new WP_Markdown_Canonical_Persistence( $root, new WP_Markdown_Storage( $root ), $operations );
$write = static function ( string $operation, int $id ) use ( $engine ): array {
	$engine->persist_mutation( array( 'key' => 'event:' . $id, 'resource' => 'event:' . $id, 'operation' => $operation, 'table' => 'wp_runtime_events', 'context' => array( 'resource_ids' => array( (string) $id ), 'scope' => array( 'resource_ids_by_column' => array( 'event_id' => array( (string) $id ) ) ) ) ) );
	return $engine->flush_dirty( true );
};
$check = static function ( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( $label ); } echo 'PASS: ' . $label . "\n"; };
$legacy = $root . '/_tables/runtime_events.json';
file_put_contents( $legacy, '[]' );
$changes = $write( 'UPDATE', 2 );
$directory = $root . '/_tables/runtime_events';
$marker = json_decode( file_get_contents( $directory . '/.mdi-partition.json' ), true );
$generation = $directory . '/' . $marker['generation'];
$path = static fn( int $id ): string => $generation . '/' . hash( 'sha256', (string) $id ) . '.json';
$check( is_file( $path( 1 ) ) && is_file( $path( 2 ) ) && is_file( $path( 3 ) ), 'first mutation publishes a complete generation' );
$check( ! is_file( $legacy ) && in_array( '_tables/runtime_events.json', $changes['deleted'], true ), 'migration reports legacy snapshot deletion' );
$check( ! isset( $operations->reads[0]['resource_ids'] ), 'external resource IDs cannot truncate generation creation' );
$before = array_map( static fn( int $id ): string => hash_file( 'sha256', $path( $id ) ), array( 1, 2, 3 ) );
$operations->reads = array();
$operations->rows['runtime_events'][2]['payload'] = 'changed';
$write( 'UPDATE', 2 );
$after = array_map( static fn( int $id ): string => hash_file( 'sha256', $path( $id ) ), array( 1, 2, 3 ) );
$check( 1 === count( $operations->reads ) && array( '2' ) === $operations->reads[0]['resource_ids'], 'one-row mutation reads only its identity' );
$check( $before[0] === $after[0] && $before[1] !== $after[1] && $before[2] === $after[2], 'scoped mutation leaves unrelated partitions unchanged' );
unset( $operations->rows['runtime_events'][2] );
$operations->reads = array();
$write( 'DELETE', 2 );
$check( ! is_file( $path( 2 ) ) && is_file( $path( 1 ) ) && is_file( $path( 3 ) ), 'scoped deletion removes only its partition' );
$check( array( '2' ) === $operations->reads[0]['resource_ids'], 'scoped deletion avoids table enumeration' );
$GLOBALS['mdi_partition_full_rows'] = true;
$operations->reads = array();
$operations->rows['runtime_events'][1]['payload'] = 'filtered';
$write( 'UPDATE', 1 );
$current = json_decode( file_get_contents( $directory . '/.mdi-partition.json' ), true );
$check( ! isset( $operations->reads[0]['resource_ids'] ), 'row filter receives complete-table input' );
$check( $marker['generation'] !== $current['generation'] && ! is_dir( $generation ), 'full reconciliation rolls generations atomically and reclaims old rows' );
