<?php
/** Indeterminate canonical option reads fail closed instead of hydrating partial option sets. */

declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-frontmatter-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-content-layout-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';

class MDI_Loader_Option_Backend implements WP_Markdown_Backend_Operations {
	/** @var array<int,array<int,string>> */
	public array $hydrated_option_names = array();

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
	public function ensure_tables( array $schemas ): void {}
	public function ensure_reconciliation_state(): void {}
	public function mutations_for_query( string $query, array $operation ): array { return array(); }
	public function hydrate_options( array $rows ): void {
		$this->hydrated_option_names[] = array_map( static fn( array $row ): string => (string) ( $row['option_name'] ?? '' ), $rows );
	}
}

$failures = array();
function mdi_option_read_assert( bool $condition, string $message ): void {
	global $failures;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $message . PHP_EOL;
	if ( ! $condition ) { $failures[] = $message; }
}
function mdi_option_read_remove( string $root ): void {
	foreach ( glob( $root . '/*' ) ?: array() as $path ) { is_dir( $path ) ? mdi_option_read_remove( $path ) : unlink( $path ); }
	rmdir( $root );
}

$root = sys_get_temp_dir() . '/mdi-loader-option-reads-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );
$backend = new MDI_Loader_Option_Backend();
$loader = new WP_Markdown_Loader( $root, $backend, new WP_Markdown_Storage( $root ) );

$fresh = $loader->load_all();
mdi_option_read_assert( 'cold' === $fresh->mode() && 'complete' === $fresh->status(), 'a store without an options directory still cold-boots as a determinate empty set' );
mdi_option_read_assert( isset( $backend->hydrated_option_names[0] ) && array() === $backend->hydrated_option_names[0], 'fresh bootstrap hydrates an explicitly empty option set' );

mkdir( $root . '/_options', 0755, true );
file_put_contents( $root . '/_options/siteurl.json', '{"option_id":1,"option_name":"siteurl","option_value":"https://example.test","autoload":"yes"}' );
file_put_contents( $root . '/_options/auth_salt.json', '{"option_id":2,"option_name":"auth_salt","option_value":"first-server-only-material","autoload":"yes"}' );
$complete = $loader->load_all();
$last_batch = $backend->hydrated_option_names[ count( $backend->hydrated_option_names ) - 1 ];
sort( $last_batch );
mdi_option_read_assert( 'cold' === $complete->mode() && 'complete' === $complete->status() && array( 'auth_salt', 'siteurl' ) === $last_batch, 'healthy canonical option files hydrate every row including salts' );

$corrupt_path = $root . '/_options/auth_key.json';
file_put_contents( $corrupt_path, '{"option_id":3,"option_name":"auth_key",' );
$backend->hydrated_option_names = array();
$cold_failure = null;
try { $loader->load_all(); } catch ( WP_Markdown_Loader_Exception $error ) { $cold_failure = $error; }
$diagnostic = $cold_failure?->diagnostic();
mdi_option_read_assert( null === $cold_failure ? false : 'cold_reconstruction_failed' === $cold_failure->diagnostic_code(), 'an unreadable canonical option file fails cold reconstruction through the typed boundary' );
mdi_option_read_assert(
	'Cold reconstruction phase hydrate_options failed for canonical resource _options/*.json.' === ( $diagnostic['causes'][0]['message'] ?? null )
		&& ( 'Markdown DB: Invalid canonical option file ' . $corrupt_path . '.' ) === ( $diagnostic['causes'][1]['message'] ?? null ),
	'indeterminate option reads report the hydrate_options phase, resource, and offending file'
);
mdi_option_read_assert( str_contains( $cold_failure?->operator_message() ?? '', $corrupt_path ), 'the operator diagnostic names the corrupt option file' );
mdi_option_read_assert( array() === $backend->hydrated_option_names, 'a failed option read never hydrates a truncated or empty option set' );

file_put_contents( $corrupt_path, '{"option_id":3,"option_value":"missing-identity"}' );
$identity_failure = null;
try { $loader->load_all(); } catch ( WP_Markdown_Loader_Exception $error ) { $identity_failure = $error; }
mdi_option_read_assert(
	'cold_reconstruction_failed' === $identity_failure?->diagnostic_code()
		&& ( 'Markdown DB: Invalid canonical option file ' . $corrupt_path . '.' ) === ( $identity_failure?->diagnostic()['causes'][1]['message'] ?? null ),
	'a decoded option row without its option_name identity fails instead of being skipped'
);

$blocked_path = $root . '/_options/nonce_salt.json';
file_put_contents( $corrupt_path, '{"option_id":3,"option_name":"auth_key","option_value":"key-material","autoload":"yes"}' );
mkdir( $blocked_path );
$backend->hydrated_option_names = array();
$warm = $loader->sync_incremental();
mdi_option_read_assert(
	'warm' === $warm->mode() && 'retained_previous_index' === $warm->status() && 'canonical_sync_failed' === $warm->reason(),
	'indeterminate option reads retain the previous warm index instead of completing an empty sync'
);
mdi_option_read_assert( array() === $backend->hydrated_option_names, 'a retained warm sync hydrates no option rows from an unreadable store' );
rmdir( $blocked_path );

$backend->hydrated_option_names = array();
$repaired = $loader->sync_incremental();
$repaired_batch = $backend->hydrated_option_names[ count( $backend->hydrated_option_names ) - 1 ] ?? array();
sort( $repaired_batch );
mdi_option_read_assert( 'complete' === $repaired->status() && array( 'auth_key', 'auth_salt', 'siteurl' ) === $repaired_batch, 'repaired canonical option files sync every option again' );

mdi_option_read_remove( $root );
if ( $failures ) { exit( 1 ); }
