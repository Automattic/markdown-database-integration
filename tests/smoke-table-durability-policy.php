<?php
/** Deterministic coverage for canonical, reconstructible, and ephemeral tables. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MARKDOWN_DB_TABLE_DURABILITY_POLICY', array( 'bootstrap_jobs' => array( 'durability' => 'reconstructible', 'projection' => array( 'limit' => 3 ) ) ) );

$GLOBALS['mdi_durability_filters'] = array();

function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
	if ( 'markdown_db_table_durability_policy' === $tag ) {
		$suffix = (string) ( $args[0] ?? '' );
		return $GLOBALS['mdi_durability_filters'][ $suffix ] ?? $value;
	}
	if ( 'markdown_db_table_persistence_policy' === $tag ) {
		return $GLOBALS['mdi_legacy_persistence'] ?? $value;
	}
	if ( 'markdown_db_ephemeral_tables' === $tag ) {
		return $GLOBALS['mdi_legacy_ephemeral'] ?? $value;
	}
	return $value;
}

function has_filter( string $tag ): bool {
	unset( $tag );
	return false;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/class-wp-markdown-write-engine.php';
require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';

if ( ! class_exists( 'WP_MySQL_On_SQLite' ) ) {
	class WP_MySQL_On_SQLite {}
}
require_once __DIR__ . '/../inc/sqlite/class-wp-markdown-sqlite-runtime-adapter.php';

final class MDI_Durability_Operations implements WP_Markdown_Backend_Operations {
	public array $row_policies = array();
	public array $ensured = array();
	public array $hydrated = array();

	public function table_rows( string $table_suffix, ?array $policy = null ): iterable {
		$this->row_policies[ $table_suffix ] = $policy;
		yield array( 'id' => 1, 'kind' => $table_suffix );
	}
	public function post_rows( array $post_ids ): array { unset( $post_ids ); return array(); }
	public function post_status( int $post_id ): ?string { unset( $post_id ); return null; }
	public function post_meta( int $post_id ): array { unset( $post_id ); return array(); }
	public function post_terms( int $post_id ): array { unset( $post_id ); return array(); }
	public function affected_post_ids( string $table_suffix, array $resource_ids, string $operation, array $scope = array() ): array { unset( $table_suffix, $resource_ids, $operation, $scope ); return array(); }
	public function options( array $names, bool $all = false ): array { unset( $names, $all ); return array(); }
	public function option_names(): array { return array(); }
	public function insert_id(): int { return 0; }
	public function next_post_id( int $minimum = 1 ): int { return $minimum; }
	public function upsert_file_index( int $post_id, string $path, int $mtime, int $size ): void { unset( $post_id, $path, $mtime, $size ); }
	public function delete_file_index( int $post_id ): void { unset( $post_id ); }
	public function upsert_options_index( array $rows ): void { unset( $rows ); }
	public function delete_options_index( array $names ): void { unset( $names ); }
	public function update_manifest( string $path, int $mtime, int $size ): void { unset( $path, $mtime, $size ); }
	public function persist_schema( string $table_suffix, string $operation ): ?string { unset( $operation ); return "CREATE TABLE wp_{$table_suffix} (id INTEGER PRIMARY KEY)"; }
	public function delete_schema( string $table_suffix ): void { unset( $table_suffix ); }
	public function manifest_entries(): array { return array(); }
	public function hydrate_markdown_posts( array $posts, ?iterable $fallback_posts ): void { unset( $posts, $fallback_posts ); }
	public function hydrate_table_snapshot( string $table_suffix, callable $rows, ?array $identity = null, ?array $partition = null ): bool {
		unset( $identity, $partition );
		$this->hydrated[ $table_suffix ] = iterator_to_array( ( function () use ( $rows ): iterable { yield from $rows(); } )(), false );
		return true;
	}
	public function reconcile_markdown( array $files, callable $parse_file ): array { unset( $files, $parse_file ); return array(); }
	public function hydrate_options( array $rows ): void { unset( $rows ); }
	public function ensure_tables( array $schemas ): void { $this->ensured = array_values( array_unique( array_merge( $this->ensured, array_keys( $schemas ) ) ) ); }
	public function ensure_reconciliation_state(): void {}
	public function mutations_for_query( string $query, array $operation ): array {
		unset( $query );
		return array( array( 'stable_id' => 'mutation', 'operation' => $operation['op'], 'table' => $operation['table'], 'resource_ids' => array( '*' ), 'scope' => array() ) );
	}
}

$passed = 0;
$failed = 0;
function mdi_durability_assert( bool $condition, string $label ): void {
	global $passed, $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	$condition ? ++$passed : ++$failed;
}
function mdi_durability_remove_tree( string $root ): void {
	if ( ! is_dir( $root ) ) { return; }
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $path ) { $path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() ); }
	rmdir( $root );
}

$GLOBALS['mdi_durability_filters'] = array(
	'reconstructible_jobs' => array( 'durability' => 'reconstructible', 'projection' => array( 'limit' => 2 ) ),
	'ephemeral_jobs'       => array( 'durability' => 'ephemeral' ),
);

$canonical = WP_Markdown_Table_Durability_Policy::resolve( 'wp_canonical_jobs' );
$reconstructible = WP_Markdown_Table_Durability_Policy::resolve( 'wp_reconstructible_jobs' );
$ephemeral = WP_Markdown_Table_Durability_Policy::resolve( 'wp_ephemeral_jobs' );
$bootstrap = WP_Markdown_Table_Durability_Policy::resolve( 'wp_bootstrap_jobs' );
mdi_durability_assert( 'canonical' === $canonical['durability'], 'unconfigured tables remain canonical' );
mdi_durability_assert( 'reconstructible' === $reconstructible['durability'] && 2 === $reconstructible['projection']['limit'], 'reconstructible policy carries a bounded projection' );
mdi_durability_assert( 'ephemeral' === $ephemeral['durability'], 'ephemeral policy resolves before backend mutation capture' );
mdi_durability_assert( 'reconstructible' === $bootstrap['durability'] && 3 === $bootstrap['projection']['limit'], 'wp-config policy is available before plugin hooks load' );

$GLOBALS['mdi_legacy_persistence'] = array( 'legacy_jobs' => false, 'bounded_jobs' => array( 'limit' => 1 ) );
mdi_durability_assert( 'ephemeral' === WP_Markdown_Table_Durability_Policy::resolve( 'wp_legacy_jobs' )['durability'], 'legacy false persistence policy maps to ephemeral' );
mdi_durability_assert( 1 === WP_Markdown_Table_Durability_Policy::resolve( 'wp_bounded_jobs' )['projection']['limit'], 'legacy projection maps into the unified policy' );
unset( $GLOBALS['mdi_legacy_persistence'] );
$GLOBALS['mdi_legacy_ephemeral'] = array( 'wp_legacy_cache' );
mdi_durability_assert( 'ephemeral' === WP_Markdown_Table_Durability_Policy::resolve( 'wp_legacy_cache' )['durability'], 'legacy ephemeral table filter maps into the unified policy' );
unset( $GLOBALS['mdi_legacy_ephemeral'] );

$root = sys_get_temp_dir() . '/mdi-durability-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );
$operations = new MDI_Durability_Operations();
$engine = new WP_Markdown_Write_Engine( $root, new WP_Markdown_Storage( $root ), $operations );
foreach ( array( 'canonical_jobs', 'reconstructible_jobs', 'ephemeral_jobs' ) as $table ) {
	$engine->persist_write( 'UPDATE wp_' . $table . ' SET id = 1', 'wp_' . $table, 'UPDATE' );
	$engine->persist_schema( '', 'wp_' . $table, 'CREATE' );
}
$engine->flush_dirty( true );
mdi_durability_assert( is_file( $root . '/_tables/canonical_jobs.json' ) && is_file( $root . '/_schema/canonical_jobs.sql' ), 'canonical DML and DDL publish rows and schema' );
mdi_durability_assert( is_file( $root . '/_tables/reconstructible_jobs.json' ) && is_file( $root . '/_schema/reconstructible_jobs.sql' ), 'reconstructible DML and DDL publish their declared projection' );
mdi_durability_assert( 2 === ( $operations->row_policies['reconstructible_jobs']['limit'] ?? null ), 'reconstructible projection reaches backend-neutral snapshot reads' );
mdi_durability_assert( ! is_file( $root . '/_tables/ephemeral_jobs.json' ) && ! is_file( $root . '/_schema/ephemeral_jobs.sql' ) && ! isset( $operations->row_policies['ephemeral_jobs'] ), 'ephemeral DML and DDL produce no canonical state' );

$adapter = ( new ReflectionClass( WP_Markdown_SQLite_Runtime_Adapter::class ) )->newInstanceWithoutConstructor();
$prefix = new ReflectionProperty( $adapter, 'table_prefix' );
$prefix->setValue( $adapter, 'wp_' );
$detect = new ReflectionMethod( $adapter, 'detect_operation' );
mdi_durability_assert( null === $detect->invoke( $adapter, 'UPDATE wp_ephemeral_jobs SET id = 2' ), 'SQLite excludes ephemeral DML before mutation capture' );
mdi_durability_assert( null === $detect->invoke( $adapter, 'CREATE TABLE wp_ephemeral_jobs (id INTEGER)' ), 'SQLite excludes ephemeral DDL before mutation capture' );
mdi_durability_assert( is_array( $detect->invoke( $adapter, 'UPDATE wp_reconstructible_jobs SET id = 2' ) ), 'SQLite captures reconstructible mutations' );

file_put_contents( $root . '/_schema/ephemeral_jobs.sql', 'CREATE TABLE wp_ephemeral_jobs (id INTEGER PRIMARY KEY);' );
file_put_contents( $root . '/_tables/ephemeral_jobs.json', '[{"id":9}]' );
$cold_operations = new MDI_Durability_Operations();
$loader = new WP_Markdown_Loader( $root, $cold_operations, new WP_Markdown_Storage( $root ) );
$cold = $loader->load_all();
mdi_durability_assert( 'complete' === $cold->status(), 'empty-index cold reconstruction completes with classified plugin tables' );
mdi_durability_assert( in_array( 'canonical_jobs', $cold_operations->ensured, true ) && in_array( 'reconstructible_jobs', $cold_operations->ensured, true ), 'cold reconstruction registers canonical and reconstructible schemas' );
mdi_durability_assert( ! in_array( 'ephemeral_jobs', $cold_operations->ensured, true ) && ! isset( $cold_operations->hydrated['ephemeral_jobs'] ), 'cold reconstruction ignores stale ephemeral schema and rows' );
mdi_durability_assert( isset( $cold_operations->hydrated['canonical_jobs'], $cold_operations->hydrated['reconstructible_jobs'] ), 'cold reconstruction hydrates available canonical projections' );

$warm_operations = new MDI_Durability_Operations();
$warm_loader = new WP_Markdown_Loader( $root, $warm_operations, new WP_Markdown_Storage( $root ) );
$warm = $warm_loader->sync_incremental();
mdi_durability_assert( 'complete' === $warm->status() && isset( $warm_operations->hydrated['canonical_jobs'], $warm_operations->hydrated['reconstructible_jobs'] ), 'warm synchronization consumes the same durability decision' );
mdi_durability_assert( ! isset( $warm_operations->hydrated['ephemeral_jobs'] ), 'warm synchronization excludes ephemeral snapshots' );

$empty = $root . '-empty';
mkdir( $empty, 0755, true );
$empty_loader = new WP_Markdown_Loader( $empty, new MDI_Durability_Operations(), new WP_Markdown_Storage( $empty ) );
mdi_durability_assert( 'complete' === $empty_loader->load_all()->status(), 'missing reconstructible and ephemeral state is owner-recreatable during cold reconstruction' );

mdi_durability_remove_tree( $root );
mdi_durability_remove_tree( $empty );

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit( $failed > 0 ? 1 : 0 );
