<?php
/** Typed loader outcome and cold reconstruction failure coverage. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/class-wp-markdown-frontmatter-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-content-layout-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';

class MDI_Loader_Outcome_Backend implements WP_Markdown_Backend_Operations {
	public ?string $failure = null;
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable { return array(); }
	public function post_rows( array $post_ids ): array { return array(); }
	public function post_status( int $post_id ): ?string { return null; }
	public function post_meta( int $post_id ): array { return array(); }
	public function post_terms( int $post_id ): array { return array(); }
	public function affected_post_ids( string $table_suffix, array $resource_ids, string $operation, array $scope = array() ): array { return array(); }
	public function options( array $names, bool $all = false ): array { return array(); }
	public function option_names(): array { return array(); }
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
	public function hydrate_options( array $rows ): void {
		if ( 'warm' === $this->failure ) { throw new RuntimeException( 'database is locked' ); }
		if ( 'warm-generic' === $this->failure ) { throw new RuntimeException( 'invalid canonical option row' ); }
	}
	public function ensure_tables( array $schemas ): void { if ( 'cold' === $this->failure ) { throw new RuntimeException( 'invalid canonical schema' ); } }
	public function ensure_reconciliation_state(): void {}
	public function mutations_for_query( string $query, array $operation ): array { return array(); }
}

$failures = array();
function mdi_loader_outcome_assert( bool $condition, string $message ): void {
	global $failures;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $message . PHP_EOL;
	if ( ! $condition ) { $failures[] = $message; }
}
function mdi_loader_outcome_remove( string $root ): void {
	foreach ( glob( $root . '/*' ) ?: array() as $path ) { is_dir( $path ) ? mdi_loader_outcome_remove( $path ) : unlink( $path ); }
	rmdir( $root );
}

$root = sys_get_temp_dir() . '/mdi-loader-outcomes-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );
$backend = new MDI_Loader_Outcome_Backend();
$loader = new WP_Markdown_Loader( $root, $backend, new WP_Markdown_Storage( $root ) );
$cold = $loader->load_all();
mdi_loader_outcome_assert( 'cold' === $cold->mode() && 'complete' === $cold->status() && null === $cold->reason(), 'successful cold reconstruction returns a complete typed outcome' );

$backend->failure = 'cold';
$cold_failure = null;
try { $loader->load_all(); } catch ( WP_Markdown_Loader_Exception $error ) { $cold_failure = $error; }
mdi_loader_outcome_assert( $cold_failure instanceof WP_Markdown_Loader_Exception, 'cold reconstruction failure propagates through the typed boundary' );
mdi_loader_outcome_assert( 'cold_reconstruction_failed' === $cold_failure?->diagnostic_code() && $cold_failure?->getPrevious() instanceof RuntimeException, 'cold failure preserves bounded diagnostics and its original cause' );
mdi_loader_outcome_assert( 'failed' === ( $loader->get_stats()['sync_status'] ?? null ), 'cold failure cannot report loader completion' );

$backend->failure = 'warm';
$warm = $loader->sync_incremental();
mdi_loader_outcome_assert( 'warm' === $warm->mode() && 'retained_previous_index' === $warm->status(), 'warm failure returns a retained-index typed outcome' );
mdi_loader_outcome_assert( 'canonical_store_busy' === $warm->reason(), 'warm contention retains its bounded typed reason' );

$backend->failure = 'warm-generic';
$warm_generic = $loader->sync_incremental();
mdi_loader_outcome_assert( 'canonical_sync_failed' === $warm_generic->reason(), 'non-contention warm failure has a distinct bounded reason' );

$backend->failure = null;
$warm_complete = $loader->sync_incremental();
mdi_loader_outcome_assert( 'complete' === $warm_complete->status() && 'complete' === ( $loader->get_stats()['sync_status'] ?? null ), 'successful warm synchronization returns completion and compatible stats' );

mdi_loader_outcome_remove( $root );
if ( $failures ) { exit( 1 ); }
