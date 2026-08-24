<?php
/** Bounded native-shutdown canonical persistence regression coverage. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/interface-wp-markdown-backend-operations.php';
require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';

final class MDI_Bounded_Operations implements WP_Markdown_Backend_Operations {
	public bool $fail_options_once = false;
	public int $option_reads = 0;
	public array $rows = array( 'target' => array( 'option_id' => 7, 'option_name' => 'target', 'option_value' => 'durable', 'autoload' => 'yes' ) );
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable { return array(); }
	public function post_rows( array $post_ids ): array { return array(); }
	public function post_status( int $post_id ): ?string { return null; }
	public function post_meta( int $post_id ): array { return array(); }
	public function post_terms( int $post_id ): array { return array(); }
	public function affected_post_ids( string $table_suffix, array $resource_ids, string $operation, array $scope = array() ): array { return array(); }
	public function options( array $names, bool $all = false ): array { $this->option_reads++; if ( $this->fail_options_once ) { $this->fail_options_once = false; throw new RuntimeException( 'transient options read failure' ); } return array_intersect_key( $this->rows, array_flip( $names ) ); }
	public function option_names(): array { return array_keys( $this->rows ); }
	public function insert_id(): int { return 0; }
	public function next_post_id( int $minimum = 1 ): int { return $minimum; }
	public function upsert_file_index( int $post_id, string $path, int $mtime, int $size ): void {}
	public function delete_file_index( int $post_id ): void {}
	public function upsert_options_index( array $rows ): void {}
	public function delete_options_index( array $names ): void {}
	public function update_manifest( string $path, int $mtime, int $size ): void {}
	public function persist_schema( string $table_suffix, string $operation ): ?string { return null; }
	public function delete_schema( string $table_suffix ): void {}
	public function manifest_entries(): array { return array(); }
	public function hydrate_markdown_posts( array $posts, ?iterable $fallback_posts ): void {}
	public function hydrate_table_snapshot( string $table_suffix, callable $rows, ?array $identity = null, ?array $partition = null ): bool { return false; }
	public function reconcile_markdown( array $files, callable $parse_file ): array { return array(); }
	public function hydrate_options( array $rows ): void {}
	public function ensure_tables( array $schemas ): void {}
	public function ensure_reconciliation_state(): void {}
	public function mutations_for_query( string $query, array $operation ): array { return array(); }
}

final class MDI_Bounded_Persistence extends WP_Markdown_Canonical_Persistence {
	public int $hash_reads = 0;
	protected function canonical_file_hash( string $path ): string { $this->hash_reads++; return parent::canonical_file_hash( $path ); }
}

$failed = 0;
function mdi_bounded_assert( bool $condition, string $label ): void { global $failed; echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL; if ( ! $condition ) { $failed++; } }
function mdi_bounded_remove( string $path ): void { if ( ! is_dir( $path ) ) { @unlink( $path ); return; } foreach ( scandir( $path ) ?: array() as $entry ) { if ( '.' !== $entry && '..' !== $entry ) { mdi_bounded_remove( $path . '/' . $entry ); } } rmdir( $path ); }

$root = sys_get_temp_dir() . '/mdi-bounded-persistence-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0700, true );
$operations = new MDI_Bounded_Operations();
$persistence = new MDI_Bounded_Persistence( $root, new WP_Markdown_Storage( $root ), $operations, 'wp_', $root );

// A read-only request performs no persistence work and no content hashing.
$persistence->flush_dirty( true );
$clean = $persistence->last_flush_diagnostics();
mdi_bounded_assert( 'clean' === $clean['status'] && empty( $clean['canonical_paths'] ) && 0 === $persistence->hash_reads, 'clean request has zero canonical shutdown work' );

// Duplicate marks collapse to one dirty resource and the successful rename is durable.
$mutation = array( 'key' => 'option:target', 'resource' => 'option:target', 'operation' => 'UPDATE', 'table' => 'wp_options', 'context' => array( 'resource_ids' => array( 'target' ) ) );
$persistence->persist_mutation( $mutation );
$persistence->persist_mutation( $mutation );
$changes = $persistence->flush_dirty( true );
$diagnostics = $persistence->last_flush_diagnostics();
$target = $root . '/_options/target.json';
mdi_bounded_assert( 1 === $operations->option_reads && is_file( $target ) && 'durable' === ( json_decode( (string) file_get_contents( $target ), true )['option_value'] ?? null ), 'duplicate dirty marks produce one durable bounded write' );
mdi_bounded_assert( array( 'options' ) === $diagnostics['dirty_tables'] && array( '_options/target.json' ) === $changes['created'] && 0 === $persistence->hash_reads, 'dirty diagnostics attribute only the persisted subset without hashing files' );

// A failed flush retains its dirty fact so the next request can retry safely.
$operations->fail_options_once = true;
$persistence->persist_mutation( $mutation );
$persistence->flush_dirty();
mdi_bounded_assert( 'retryable_failure' === $persistence->last_flush_diagnostics()['status'], 'failed persistence records retryable diagnostics' );
$persistence->flush_dirty( true );
mdi_bounded_assert( 3 === $operations->option_reads && 'persisted' === $persistence->last_flush_diagnostics()['status'], 'failed dirty subset retries and completes durably' );

// Replacing a large canonical snapshot never rereads it through hash_file at shutdown.
mkdir( $root . '/_tables', 0700, true );
$large = $root . '/_tables/large.json';
file_put_contents( $large, str_repeat( 'old', 3 * 1024 * 1024 ) );
$write = new ReflectionMethod( $persistence, 'write_json' );
$write->invoke( $persistence, $large, array( array( 'payload' => str_repeat( 'new', 1024 * 1024 ) ) ) );
$persistence->flush_dirty( true );
mdi_bounded_assert( 0 === $persistence->hash_reads && in_array( '_tables/large.json', $persistence->last_flush_diagnostics()['canonical_paths'], true ), 'large canonical replacement is attributed without full-file hashing' );

mdi_bounded_remove( $root );
exit( $failed ? 1 : 0 );
