<?php
/** Bounded native-shutdown canonical persistence regression coverage. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/interface-wp-markdown-backend-operations.php';

$GLOBALS['mdi_bounded_actions'] = array();
function do_action( string $hook, mixed ...$args ): void { $GLOBALS['mdi_bounded_actions'][] = array( 'hook' => $hook, 'args' => $args ); }

final class WP_Markdown_Storage {
	private $observer = null;
	public bool $fail_next_post_write = false;
	public int $post_write_attempts = 0;
	public function __construct( private string $content_dir ) {}
	public function set_file_mutation_observer( callable $observer ): void { $this->observer = $observer; }
	public function get_content_dir(): string { return $this->content_dir; }
	public function is_markdown_type( string $post_type ): bool { return 'post' === $post_type; }
	public function write_post( object $post ): string|false { $this->post_write_attempts++; if ( $this->fail_next_post_write ) { $this->fail_next_post_write = false; return false; } $path = $this->content_dir . '/post-' . (int) $post->ID . '.md'; if ( is_callable( $this->observer ) ) { ( $this->observer )( $path ); } file_put_contents( $path, (string) $post->post_content ); return $path; }
	public function delete_post( int $post_id ): bool { $path = $this->content_dir . '/post-' . $post_id . '.md'; if ( ! is_file( $path ) ) { return true; } if ( is_callable( $this->observer ) ) { ( $this->observer )( $path ); } return unlink( $path ); }
	public function path_for_post( int $post_id ): string|false { $path = $this->content_dir . '/post-' . $post_id . '.md'; return is_file( $path ) ? $path : false; }
	public function read_post( int $post_id ): ?object { return null; }
}
require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';

final class MDI_Bounded_Operations implements WP_Markdown_Backend_Operations {
	public bool $fail_options_once = false;
	public int $option_reads = 0;
	public array $rows = array( 'target' => array( 'option_id' => 7, 'option_name' => 'target', 'option_value' => 'durable', 'autoload' => 'yes' ) );
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable { return array(); }
	public function post_rows( array $post_ids ): array { return array( 42 ) === array_map( 'intval', $post_ids ) ? array( array( 'ID' => 42, 'post_type' => 'post', 'post_content' => 'durable post' ) ) : array(); }
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
$storage = new WP_Markdown_Storage( $root );
$persistence = new MDI_Bounded_Persistence( $root, $storage, $operations, 'wp_', $root );

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
$failure_thrown = false;
try { $persistence->flush_dirty( true ); } catch ( RuntimeException ) { $failure_thrown = true; }
$failure_diagnostics = $persistence->last_flush_diagnostics();
$failure_action = end( $GLOBALS['mdi_bounded_actions'] );
mdi_bounded_assert( $failure_thrown && 'retryable_failure' === $failure_diagnostics['status'] && 'canonical_persistence_failed' === $failure_diagnostics['failure'] && ! isset( $failure_diagnostics['error'] ) && 'markdown_database_integration_persistence_diagnostics' === $failure_action['hook'] && $failure_diagnostics === $failure_action['args'][0], 'failed persistence emits sanitized retryable diagnostics before rethrowing' );
$persistence->flush_dirty( true );
mdi_bounded_assert( 3 === $operations->option_reads && 'persisted' === $persistence->last_flush_diagnostics()['status'], 'failed dirty subset retries and completes durably' );

// A false markdown write is a retryable durability failure, not a successful post flush.
$post_mutation = array( 'key' => 'post:42', 'resource' => 'post:42', 'operation' => 'UPDATE', 'table' => 'wp_posts', 'context' => array( 'resource_ids' => array( '42' ) ) );
$storage->fail_next_post_write = true;
$persistence->persist_mutation( $post_mutation );
$persistence->flush_dirty();
mdi_bounded_assert( 'retryable_failure' === $persistence->last_flush_diagnostics()['status'] && array( 42 ) === $persistence->last_flush_diagnostics()['dirty_posts'] && 1 === $storage->post_write_attempts && ! is_file( $root . '/post-42.md' ), 'false markdown write retains the dirty post for retry' );
$persistence->flush_dirty( true );
mdi_bounded_assert( 2 === $storage->post_write_attempts && is_file( $root . '/post-42.md' ) && 'persisted' === $persistence->last_flush_diagnostics()['status'], 'retried markdown write publishes a durable canonical post' );

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
