<?php
/** Standalone smoke test for bounded three-way reconciliation. Usage: php tests/smoke-reconciliation.php */
declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['mdi_reconcile_actions'] = array();
$GLOBALS['mdi_reconcile_categories'] = array();
$GLOBALS['mdi_reconcile_abilities'] = array();
$GLOBALS['mdi_reconcile_post_meta'] = array();
$GLOBALS['mdi_reconcile_options'] = array();
$GLOBALS['mdi_reconcile_posts'] = array();

function add_action( string $hook, callable $callback ): void {
	$GLOBALS['mdi_reconcile_actions'][ $hook ][] = $callback;
}
function doing_action( string $hook ): bool { unset( $hook ); return false; }
function did_action( string $hook ): int { unset( $hook ); return 0; }
function wp_register_ability_category( string $name, array $definition ): void { $GLOBALS['mdi_reconcile_categories'][ $name ] = $definition; }
function wp_has_ability_category( string $name ): bool { return isset( $GLOBALS['mdi_reconcile_categories'][ $name ] ); }
function wp_register_ability( string $name, array $definition ): void { $GLOBALS['mdi_reconcile_abilities'][ $name ] = $definition; }
function wp_has_ability( string $name ): bool { return isset( $GLOBALS['mdi_reconcile_abilities'][ $name ] ); }
function current_user_can( string $capability ): bool { return 'manage_options' === $capability; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { unset( $hook, $args ); return $value; }
function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
	$meta = $GLOBALS['mdi_reconcile_post_meta'][ $post_id ] ?? array();
	if ( '' === $key ) { return $meta; }
	$values = $meta[ $key ] ?? array();
	return $single ? ( $values[0] ?? '' ) : $values;
}
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['mdi_reconcile_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = true ): bool { unset( $autoload ); $GLOBALS['mdi_reconcile_options'][ $key ] = $value; return true; }
function get_post( int $post_id ): ?object { return $GLOBALS['mdi_reconcile_posts'][ $post_id ] ?? null; }
function get_posts( array $query = array() ): array { unset( $query ); return array(); }
function get_object_taxonomies( string $post_type ): array { unset( $post_type ); return array(); }
function maybe_unserialize( mixed $value ): mixed {
	if ( ! is_string( $value ) || ! preg_match( '/^(?:a|O|s|i|b|d|N):/', $value ) ) { return $value; }
	$result = @unserialize( $value, array( 'allowed_classes' => false ) );
	return false === $result && 'b:0;' !== $value ? $value : $result;
}

require_once __DIR__ . '/../inc/class-wp-markdown-reconciliation-service.php';
require_once __DIR__ . '/../inc/class-wp-markdown-wordpress-reconciliation-adapter.php';
require_once __DIR__ . '/../inc/class-wp-markdown-cli.php';

$passed = 0;
$failed = 0;
function mdi_reconcile_check( bool $condition, string $message ): void {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "PASS: $message\n"; return; }
	++$failed;
	echo "FAIL: $message\n";
}
function mdi_reconcile_throws( callable $callback, string $class, string $message ): void {
	$thrown = false;
	try { $callback(); } catch ( Throwable $error ) { $thrown = $error instanceof $class; }
	mdi_reconcile_check( $thrown, $message );
}
function mdi_reconcile_remove( string $path ): void {
	if ( is_link( $path ) || is_file( $path ) ) { @unlink( $path ); return; }
	if ( ! is_dir( $path ) ) { return; }
	foreach ( scandir( $path ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) { continue; }
		mdi_reconcile_remove( $path . '/' . $entry );
	}
	@rmdir( $path );
}
function mdi_reconcile_identity( mixed $value ): array { return WP_Markdown_Reconciliation_Identity::exact( $value ); }
function mdi_reconcile_baseline( string $root, string $path, mixed $value ): array {
	return array( 'canonical_root' => $root, 'canonical_path' => $path, 'identity' => mdi_reconcile_identity( $value ) );
}
function mdi_reconcile_request( string $root, int $batch = 100 ): array {
	return array( 'canonical_root' => $root, 'managed_scope' => array( 'post' ), 'direction' => 'bidirectional', 'deletion_policy' => 'managed', 'conflict_policy' => 'none', 'batch_size' => $batch );
}
function mdi_reconcile_store( string $directory, string $root ): WP_Markdown_Filesystem_Reconciliation_Operation_Store {
	return new WP_Markdown_Filesystem_Reconciliation_Operation_Store( $directory, str_repeat( 'test-authentication-key-', 2 ), array( $root ), 1000, 1048576 );
}
function mdi_reconcile_entry_ids( array $result ): array {
	$ids = array();
	foreach ( $result['categories'] as $entries ) { foreach ( $entries as $entry ) { $ids[] = $entry['resource_id']; } }
	sort( $ids, SORT_STRING );
	return $ids;
}

final class MDI_Reconciliation_Delete_Storage extends WP_Markdown_Storage {
	public string $delete_result = 'deleted';
	public int $delete_attempts = 0;
	public function delete_post_result( int $post_id ): string { unset( $post_id ); ++$this->delete_attempts; return $this->delete_result; }
}

final class MDI_Reconciliation_Content_Adapter implements WP_Markdown_Reconciliation_Content_Adapter {
	public array $snapshots;
	public array $effect_state = array();
	public array $after_state = array();
	public array $mutation_calls = array();
	public array $mutation_order = array();
	public int $adapter_calls = 0;
	public string $source_identity;
	public bool $change_source_on_mutation = false;
	public array $last_scope = array();
	private string $fences;
	private string $files;
	private PDO $pdo;

	public function __construct( array $snapshots, string $runtime, PDO $pdo, string $source_seed = 'stable-source' ) {
		$this->snapshots = $snapshots;
		$this->fences = $runtime . '/fences';
		$this->files = $runtime . '/effects';
		$this->pdo = $pdo;
		mkdir( $this->files, 0700, true );
		$this->set_source( $source_seed );
		foreach ( $snapshots as $snapshot ) {
			$id = $snapshot['resource_id'];
			$before = $snapshot['_durable_before'] ?? array( 'canonical' => $snapshot['canonical'], 'wordpress' => $snapshot['wordpress'] );
			$after = $snapshot['_durable_after'] ?? $this->target_state( $snapshot );
			$this->effect_state[ $id ] = $before;
			$this->after_state[ $id ] = $after;
			$this->mutation_calls[ $id ] = 0;
			if ( null !== $snapshot['canonical'] ) { file_put_contents( $this->file( $id ), 'original' ); }
		}
	}

	public function set_source( string $seed ): void { $this->source_identity = hash( 'sha256', $seed ); }
	public function enumerate( array $scope, ?string $continuation, int $limit ): array {
		$this->last_scope = $scope;
		$items = $this->snapshots;
		usort( $items, static fn( array $a, array $b ): int => $a['resource_id'] <=> $b['resource_id'] );
		$offset = 0;
		if ( null !== $continuation ) {
			while ( $offset < count( $items ) && strcmp( $items[ $offset ]['resource_id'], $continuation ) <= 0 ) { ++$offset; }
		}
		$page = array_slice( $items, $offset, $limit );
		foreach ( $page as &$snapshot ) { unset( $snapshot['_durable_before'], $snapshot['_durable_after'] ); }
		unset( $snapshot );
		$next = $offset + count( $page ) < count( $items ) ? (string) end( $page )['resource_id'] : null;
		return array( 'source_identity' => $this->source_identity, 'snapshots' => $page, 'continuation' => $next );
	}

	public function adapter_for( array $operation, ?array $plan_entry = null ): WP_Markdown_Reconciliation_Adapter {
		unset( $plan_entry );
		++$this->adapter_calls;
		$binding = $operation['binding'] ?? $operation;
		$id = (string) $binding['resource']['id'];
		$observe = fn(): array => $this->effect_state[ $id ];
		$mutate = function () use ( $id, $binding ): void {
			++$this->mutation_calls[ $id ];
			$this->mutation_order[] = $id;
			$this->effect_state[ $id ] = $this->after_state[ $id ];
			if ( $this->change_source_on_mutation ) { $this->set_source( 'mutation-' . array_sum( $this->mutation_calls ) ); }
			if ( 'wordpress_to_canonical' === $binding['direction'] ) {
				if ( 'deleted_from_wordpress' === $binding['kind'] ) { @unlink( $this->file( $id ) ); }
				else { file_put_contents( $this->file( $id ), 'mutation-' . $this->mutation_calls[ $id ] ); }
			}
		};
		return 'wordpress_to_canonical' === $binding['direction']
			? new WP_Markdown_Filesystem_Reconciliation_Adapter( $this->fences, $observe, $mutate )
			: new WP_Markdown_PDO_Reconciliation_Adapter( $this->pdo, static fn() => $observe(), static fn() => $mutate() );
	}

	public function file_mtime( string $id ): int { clearstatcache( true, $this->file( $id ) ); return (int) filemtime( $this->file( $id ) ); }
	private function file( string $id ): string { return $this->files . '/' . hash( 'sha256', $id ) . '.md'; }
	private function target_state( array $snapshot ): array {
		$c = $snapshot['canonical']; $w = $snapshot['wordpress']; $b = $snapshot['baseline']['identity'] ?? null;
		$bi = null === $b ? null : $b;
		$ci = null === $c ? null : mdi_reconcile_identity( $c ); $wi = null === $w ? null : mdi_reconcile_identity( $w );
		if ( null === $bi ) { $target = null !== $c ? $c : $w; }
		elseif ( null === $c || WP_Markdown_Reconciliation_Identity::equal( $wi, $bi ) ) { $target = $c; }
		else { $target = $w; }
		return array( 'canonical' => $target, 'wordpress' => $target );
	}
}

final class MDI_Reconciliation_MySQL_PDO extends PDO {
	public array $sql = array(); public array $fences = array(); private bool $transaction = false;
	public function __construct() {}
	public function exec( string $statement ): int|false { $this->sql[] = $statement; return 0; }
	public function prepare( string $query, array $options = array() ): PDOStatement|false { unset( $options ); $this->sql[] = $query; return new MDI_Reconciliation_MySQL_Statement( $this, $query ); }
	public function beginTransaction(): bool { $this->sql[] = 'BEGIN'; $this->transaction = true; return true; }
	public function commit(): bool { $this->sql[] = 'COMMIT'; $this->transaction = false; return true; }
	public function rollBack(): bool { $this->sql[] = 'ROLLBACK'; $this->transaction = false; return true; }
	public function inTransaction(): bool { return $this->transaction; }
}
final class MDI_Reconciliation_MySQL_Statement extends PDOStatement {
	private array $row = array();
	public function __construct( private MDI_Reconciliation_MySQL_PDO $pdo, private string $sql ) {}
	public function execute( ?array $params = null ): bool {
		$params ??= array();
		if ( str_starts_with( $this->sql, 'SELECT operation_id' ) ) { $this->row = $this->pdo->fences[ $params[0] ] ?? array(); }
		if ( str_starts_with( $this->sql, 'DELETE FROM' ) ) { unset( $this->pdo->fences[ $params[0] ] ); }
		if ( str_starts_with( $this->sql, 'INSERT INTO' ) ) { $this->pdo->fences[ $params[0] ] = array( 'operation_id' => $params[1], 'fence' => (int) $params[2] ); }
		return true;
	}
	public function fetchColumn( int $column = 0 ): mixed { unset( $column ); return $this->row['fence'] ?? false; }
	public function fetch( int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0 ): mixed { unset( $mode, $cursorOrientation, $cursorOffset ); return $this->row ?: false; }
}

$runtime = tempnam( sys_get_temp_dir(), 'mdi-reconciliation-' );
if ( false === $runtime || ! unlink( $runtime ) || ! mkdir( $runtime, 0700 ) ) { throw new RuntimeException( 'Unable to create reconciliation runtime.' ); }
register_shutdown_function( static fn() => mdi_reconcile_remove( $runtime ) );
$canonical = $runtime . '/canonical';
mkdir( $canonical, 0700 );
$pdo = new PDO( 'sqlite:' . $runtime . '/fixture.sqlite' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE `_mdi_resource_fences` (`resource_key` VARCHAR(191) PRIMARY KEY, `operation_id` VARCHAR(64) NOT NULL, `fence` BIGINT NOT NULL)' );

$base_a = array( 'body' => 'baseline-a-private-secret' );
$base_b = array( 'body' => 'baseline-b-private-secret' );
$same = array( 'body' => 'identical-private-secret' );
$moved_raw_before = array( 'canonical' => array( 'path' => 'post/old.md', 'value' => $same ), 'wordpress' => $same );
$moved_raw_after = array( 'canonical' => array( 'path' => 'post/new.md', 'value' => $same ), 'wordpress' => $same );
$snapshots = array(
	array( 'resource_id' => '01-file-only', 'resource_type' => 'post', 'canonical_path' => 'post/file.md', 'expected_canonical_path' => 'post/file.md', 'canonical' => array( 'body' => 'file-only-private-secret' ), 'wordpress' => null, 'baseline' => null ),
	array( 'resource_id' => '02-wordpress-only', 'resource_type' => 'post', 'canonical_path' => null, 'expected_canonical_path' => 'post/wp.md', 'canonical' => null, 'wordpress' => array( 'body' => 'wordpress-only-private-secret' ), 'baseline' => null ),
	array( 'resource_id' => '03-identical', 'resource_type' => 'post', 'canonical_path' => 'post/same.md', 'expected_canonical_path' => 'post/same.md', 'canonical' => $same, 'wordpress' => $same, 'baseline' => mdi_reconcile_baseline( $canonical, 'post/same.md', $same ) ),
	array( 'resource_id' => '04-moved', 'resource_type' => 'post', 'canonical_path' => 'post/old.md', 'expected_canonical_path' => 'post/new.md', 'canonical' => $same, 'wordpress' => $same, 'baseline' => mdi_reconcile_baseline( $canonical, 'post/old.md', $same ), 'move_direction' => 'wordpress_to_canonical', 'durable_before' => array_map( 'mdi_reconcile_identity', $moved_raw_before ), 'durable_after' => array_map( 'mdi_reconcile_identity', $moved_raw_after ), '_durable_before' => $moved_raw_before, '_durable_after' => $moved_raw_after ),
	array( 'resource_id' => '05-deleted-file', 'resource_type' => 'post', 'canonical_path' => null, 'expected_canonical_path' => 'post/deleted-file.md', 'canonical' => null, 'wordpress' => $base_a, 'baseline' => mdi_reconcile_baseline( $canonical, 'post/deleted-file.md', $base_a ) ),
	array( 'resource_id' => '06-deleted-wordpress', 'resource_type' => 'post', 'canonical_path' => 'post/deleted-wp.md', 'expected_canonical_path' => 'post/deleted-wp.md', 'canonical' => $base_b, 'wordpress' => null, 'baseline' => mdi_reconcile_baseline( $canonical, 'post/deleted-wp.md', $base_b ) ),
	array( 'resource_id' => '07-divergent', 'resource_type' => 'post', 'canonical_path' => 'post/conflict.md', 'expected_canonical_path' => 'post/conflict.md', 'canonical' => array( 'body' => 'canonical-private-secret' ), 'wordpress' => array( 'body' => 'wordpress-private-secret' ), 'baseline' => mdi_reconcile_baseline( $canonical, 'post/conflict.md', array( 'body' => 'conflict-baseline-private-secret' ) ) ),
);

$adapter = new MDI_Reconciliation_Content_Adapter( $snapshots, $runtime . '/classification', $pdo );
$store = mdi_reconcile_store( $runtime . '/classification-journal', $canonical );
$service = new WP_Markdown_Reconciliation_Service( new WP_Markdown_Durable_Reconciliation_Coordinator( $store, 'classification-owner', 30 ), $adapter );
$request = mdi_reconcile_request( $canonical );
$plan = $service->plan( $request );
$top_keys = array( 'schema_version', 'plan_id', 'source_identity', 'options', 'categories', 'counts', 'operation_ids', 'continuation' );
$category_keys = array( 'created', 'updated_from_file', 'written_from_wordpress', 'deleted_from_file', 'deleted_from_wordpress', 'moved', 'unchanged', 'conflicts' );
mdi_reconcile_check( $top_keys === array_keys( $plan ), 'response has the exact top-level key set and order' );
mdi_reconcile_check( $category_keys === array_keys( $plan['categories'] ) && $category_keys === array_keys( $plan['counts'] ), 'categories and counts have the exact category key set' );
$expected_counts = array( 'created' => 1, 'updated_from_file' => 0, 'written_from_wordpress' => 1, 'deleted_from_file' => 1, 'deleted_from_wordpress' => 1, 'moved' => 1, 'unchanged' => 1, 'conflicts' => 1 );
mdi_reconcile_check( $expected_counts === $plan['counts'], 'file-only, WordPress-only, identical, moved, both deletions, and divergent conflict classify exactly' );
$conflict = $plan['categories']['conflicts'][0] ?? array();
$entry_required = array( 'canonical_path', 'expected_canonical_path', 'resource_id', 'canonical_identity', 'wordpress_identity', 'baseline_identity' );
mdi_reconcile_check( array() === array_diff( $entry_required, array_keys( $conflict ) ) && 'post/conflict.md' === ( $conflict['canonical_path'] ?? null ) && '07-divergent' === ( $conflict['resource_id'] ?? null ), 'entry exposes exact path and resource identity fields' );
$identity_shape = static fn( mixed $identity ): bool => is_array( $identity ) && array( 'algorithm', 'digest' ) === array_keys( $identity ) && 'sha256' === $identity['algorithm'] && 1 === preg_match( '/^[a-f0-9]{64}$/', $identity['digest'] );
mdi_reconcile_check( $identity_shape( $conflict['canonical_identity'] ?? null ) && $identity_shape( $conflict['wordpress_identity'] ?? null ) && $identity_shape( $conflict['baseline_identity'] ?? null ), 'divergent conflict has all three exact identity schemas' );
$encoded_plan = json_encode( $plan, JSON_THROW_ON_ERROR );
mdi_reconcile_check( ! str_contains( $encoded_plan, 'private-secret' ), 'public response contains no raw content secrets' );
mdi_reconcile_check( 0 === $adapter->adapter_calls && array_sum( $adapter->mutation_calls ) === 0 && null === $store->get( str_repeat( '0', 64 ) ), 'dry-run planning does not request ownership or mutate durable state' );

$unchanged_mtime = $adapter->file_mtime( '03-identical' );
$apply_request = $request + array( 'plan_id' => $plan['plan_id'], 'source_identity' => $plan['source_identity'] );
$applied = $service->apply( $apply_request );
mdi_reconcile_check( 5 === count( $applied['operation_ids'] ) && 5 === array_sum( $adapter->mutation_calls ), 'apply uses the #190 coordinator for every actionable non-conflict entry' );
mdi_reconcile_check( 0 === $adapter->mutation_calls['03-identical'] && $unchanged_mtime === $adapter->file_mtime( '03-identical' ), 'unchanged canonical file is not rewritten' );
$first_ids = $applied['operation_ids']; $first_mutations = $adapter->mutation_calls;
$repeated = $service->apply( $apply_request );
mdi_reconcile_check( $first_ids === $repeated['operation_ids'] && $first_mutations === $adapter->mutation_calls, 'repeated apply is idempotent with stable operation IDs and no duplicate mutation' );

// A scoped child receives its clean parent only as hierarchy context, never as a mutation target.
$parent_snapshot = array( 'resource_id' => 'post:00000000000000000010', 'resource_type' => 'post', 'canonical_path' => 'page/parent.md', 'expected_canonical_path' => 'page/parent.md', 'canonical' => array( 'post_parent' => 0, 'body' => 'parent' ), 'wordpress' => array( 'post_parent' => 0, 'body' => 'parent' ), 'baseline' => mdi_reconcile_baseline( $canonical, 'page/parent.md', array( 'post_parent' => 0, 'body' => 'parent' ) ) );
$child_snapshot = array( 'resource_id' => 'post:00000000000000000011', 'resource_type' => 'post', 'canonical_path' => 'page/parent/child.md', 'expected_canonical_path' => 'page/parent/child.md', 'canonical' => array( 'post_parent' => 10, 'body' => 'old-child' ), 'wordpress' => array( 'post_parent' => 10, 'body' => 'child' ), 'baseline' => mdi_reconcile_baseline( $canonical, 'page/parent/child.md', array( 'post_parent' => 10, 'body' => 'old-child' ) ) );
$scoped_adapter = new MDI_Reconciliation_Content_Adapter( array( $parent_snapshot, $child_snapshot ), $runtime . '/scoped-child', $pdo );
$scoped_service = new WP_Markdown_Reconciliation_Service( new WP_Markdown_Durable_Reconciliation_Coordinator( mdi_reconcile_store( $runtime . '/scoped-child-journal', $canonical ) ), $scoped_adapter );
$scoped_request = mdi_reconcile_request( $canonical ) + array( 'resource_ids' => array( 'post:00000000000000000011' ) );
$scoped_plan = $scoped_service->plan( $scoped_request );
$scoped_apply = $scoped_service->apply( $scoped_request + array( 'plan_id' => $scoped_plan['plan_id'], 'source_identity' => $scoped_plan['source_identity'] ) );
mdi_reconcile_check( 1 === count( $scoped_apply['operation_ids'] ) && 0 === $scoped_adapter->mutation_calls['post:00000000000000000010'] && 1 === $scoped_adapter->mutation_calls['post:00000000000000000011'], 'scoped child apply validates clean parent context without mutating it' );

// A core-reparented child must move out before its deleted parent's index disappears.
$deleted_parent = array( 'resource_id' => 'post:00000000000000000020', 'resource_type' => 'post', 'canonical_path' => 'page/parent/index.md', 'expected_canonical_path' => null, 'canonical' => array( 'post_parent' => 0, 'body' => 'parent' ), 'wordpress' => null, 'baseline' => mdi_reconcile_baseline( $canonical, 'page/parent/index.md', array( 'post_parent' => 0, 'body' => 'parent' ) ) );
$child_canonical = array( 'post_parent' => 20, 'body' => 'child' );
$child_wordpress = array( 'post_parent' => 0, 'body' => 'child' );
$child_move_before = array( 'canonical' => array( 'path' => 'page/parent/child.md', 'value' => $child_canonical ), 'wordpress' => $child_wordpress );
$child_move_after = array( 'canonical' => array( 'path' => 'page/child.md', 'value' => $child_wordpress ), 'wordpress' => $child_wordpress );
$reparented_child = array( 'resource_id' => 'post:00000000000000000021', 'resource_type' => 'post', 'canonical_path' => 'page/parent/child.md', 'expected_canonical_path' => 'page/child.md', 'canonical' => $child_canonical, 'wordpress' => $child_wordpress, 'baseline' => mdi_reconcile_baseline( $canonical, 'page/parent/child.md', $child_canonical ), 'move_direction' => 'wordpress_to_canonical', 'durable_before' => array_map( 'mdi_reconcile_identity', $child_move_before ), 'durable_after' => array_map( 'mdi_reconcile_identity', $child_move_after ), '_durable_before' => $child_move_before, '_durable_after' => $child_move_after );
$delete_adapter = new MDI_Reconciliation_Content_Adapter( array( $deleted_parent, $reparented_child ), $runtime . '/parent-delete', $pdo );
$delete_service = new WP_Markdown_Reconciliation_Service( new WP_Markdown_Durable_Reconciliation_Coordinator( mdi_reconcile_store( $runtime . '/parent-delete-journal', $canonical ) ), $delete_adapter );
$delete_request = array_replace( mdi_reconcile_request( $canonical ), array( 'direction' => 'wordpress_to_canonical', 'deletion_policy' => 'managed', 'conflict_policy' => 'prefer_wordpress' ) );
$delete_plan = $delete_service->plan( $delete_request );
$delete_service->apply( $delete_request + array( 'plan_id' => $delete_plan['plan_id'], 'source_identity' => $delete_plan['source_identity'] ) );
mdi_reconcile_check( array( $reparented_child['resource_id'], $deleted_parent['resource_id'] ) === $delete_adapter->mutation_order, 'reparented descendant moves before its former parent canonical file is deleted: ' . json_encode( $delete_adapter->mutation_order ) );

$path_observer_adapter = new WP_Markdown_WordPress_Reconciliation_Adapter();
$observe_effect = new ReflectionMethod( $path_observer_adapter, 'observe_effect' );
$path_change_binding = array( 'binding' => array( 'kind' => 'written_from_wordpress', 'resource' => array( 'id' => $reparented_child['resource_id'] ), 'before' => array( 'canonical' => mdi_reconcile_identity( $child_move_before['canonical'] ) ), 'after' => array( 'canonical' => mdi_reconcile_identity( $child_move_after['canonical'] ) ) ) );
$path_change_observed = $observe_effect->invoke( $path_observer_adapter, $path_change_binding, array( 'root' => $canonical, 'current_path' => 'page/parent/child.md', 'expected_path' => 'page/child.md', 'post_id' => 0, 'layout_profile' => '' ) );
mdi_reconcile_check( array_key_exists( 'path', $path_change_observed['canonical'] ), 'production observer preserves path-aware durability for a content write that also moves canonical route' );
$create_observed = $observe_effect->invoke( $path_observer_adapter, $path_change_binding, array( 'root' => $canonical, 'current_path' => null, 'expected_path' => 'page/child.md', 'post_id' => 0, 'layout_profile' => '' ) );
mdi_reconcile_check( ! array_key_exists( 'path', (array) $create_observed['canonical'] ), 'production observer keeps initial canonical creation on the content-only durability contract' );

$adapter->set_source( 'changed-source' );
mdi_reconcile_throws( fn() => $service->apply( $apply_request ), WP_Markdown_Reconciliation_Store_Conflict::class, 'stale source plan is rejected before new mutation' );

// Freshness is checked before recovery, so stale apply cannot execute pending intent.
$stale_adapter = new MDI_Reconciliation_Content_Adapter( array( $snapshots[1] ), $runtime . '/stale-recovery', $pdo, 'stale-before' );
$stale_store = mdi_reconcile_store( $runtime . '/stale-recovery-journal', $canonical );
$stale_service = new WP_Markdown_Reconciliation_Service( new WP_Markdown_Durable_Reconciliation_Coordinator( $stale_store, 'stale-owner', 1 ), $stale_adapter );
$stale_request = mdi_reconcile_request( $canonical, 1 );
$stale_plan = $stale_service->plan( $stale_request );
$stale_intent = array( 'plan_id' => $stale_plan['plan_id'], 'continuation' => array( 'service_schema' => 1, 'source_identity' => $stale_plan['source_identity'], 'cursor' => null, 'resource_id' => $snapshots[1]['resource_id'], 'canonical_path' => null, 'expected_canonical_path' => 'post/wp.md' ), 'canonical_root' => $canonical, 'resource' => array( 'type' => 'post', 'id' => $snapshots[1]['resource_id'] ), 'kind' => 'written_from_wordpress', 'direction' => 'wordpress_to_canonical', 'before' => array( 'canonical' => mdi_reconcile_identity( null ), 'wordpress' => mdi_reconcile_identity( $snapshots[1]['wordpress'] ) ), 'after' => array( 'canonical' => mdi_reconcile_identity( $snapshots[1]['wordpress'] ), 'wordpress' => mdi_reconcile_identity( $snapshots[1]['wordpress'] ) ) );
$pending = $stale_store->plan( $stale_intent );
$stale_adapter->set_source( 'stale-after' );
mdi_reconcile_throws( fn() => $stale_service->apply( $stale_request + array( 'plan_id' => $stale_plan['plan_id'], 'source_identity' => $stale_plan['source_identity'] ) ), WP_Markdown_Reconciliation_Store_Conflict::class, 'stale apply rejects before recovering an incomplete operation' );
mdi_reconcile_check( 'planned' === $stale_store->get( $pending['id'] )['state'] && 0 === array_sum( $stale_adapter->mutation_calls ), 'stale apply leaves incomplete operation and content untouched' );

$wrong_root_snapshot = $snapshots[4];
$wrong_root_snapshot['baseline']['canonical_root'] = $runtime . '/other-root';
$proof_adapter = new MDI_Reconciliation_Content_Adapter( array( $wrong_root_snapshot ), $runtime . '/proof', $pdo, 'proof' );
$proof_service = new WP_Markdown_Reconciliation_Service( new WP_Markdown_Durable_Reconciliation_Coordinator( mdi_reconcile_store( $runtime . '/proof-journal', $canonical ) ), $proof_adapter );
$proof = $proof_service->plan( $request );
mdi_reconcile_check( 1 === $proof['counts']['conflicts'] && 'deletion_not_proven' === $proof['categories']['conflicts'][0]['reason'], 'deletion requires managed scope and baseline proof for the exact canonical root' );
$no_delete = $service->plan( array_replace( $request, array( 'deletion_policy' => 'none' ) ) );
mdi_reconcile_check( 2 <= $no_delete['counts']['conflicts'], 'deletion policy none blocks otherwise proven deletions' );

$paging_adapter = new MDI_Reconciliation_Content_Adapter( $snapshots, $runtime . '/paging', $pdo, 'paging' );
$paging_service = new WP_Markdown_Reconciliation_Service( new WP_Markdown_Durable_Reconciliation_Coordinator( mdi_reconcile_store( $runtime . '/paging-journal', $canonical ) ), $paging_adapter );
$page_request = mdi_reconcile_request( $canonical, 2 );
$page = $paging_service->plan( $page_request ); $seen = mdi_reconcile_entry_ids( $page );
$same_page = $paging_service->plan( $page_request );
mdi_reconcile_check( $page === $same_page && null !== $page['continuation'], 'bounded first page and continuation identity are stable' );
while ( null !== $page['continuation'] ) {
	$page = $paging_service->plan( $page_request + array( 'continuation' => $page['continuation'], 'plan_id' => $page['plan_id'], 'source_identity' => $page['source_identity'] ) );
	$seen = array_merge( $seen, mdi_reconcile_entry_ids( $page ) );
}
sort( $seen, SORT_STRING );
mdi_reconcile_check( array_column( $snapshots, 'resource_id' ) === $seen && count( $seen ) === count( array_unique( $seen ) ), 'bounded continuation covers a stable snapshot exactly once' );

$bounded_pdo = new PDO( 'sqlite::memory:' );
$bounded_pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$bounded_pdo->exec( 'CREATE TABLE `_mdi_resource_fences` (`resource_key` VARCHAR(191) PRIMARY KEY, `operation_id` VARCHAR(64) NOT NULL, `fence` BIGINT NOT NULL)' );
$bounded_apply_adapter = new MDI_Reconciliation_Content_Adapter( array_slice( $snapshots, 0, 4 ), $runtime . '/bounded-apply', $bounded_pdo, 'bounded-apply' );
$bounded_apply_adapter->change_source_on_mutation = true;
$bounded_apply_service = new WP_Markdown_Reconciliation_Service( new WP_Markdown_Durable_Reconciliation_Coordinator( mdi_reconcile_store( $runtime . '/bounded-apply-journal', $canonical ) ), $bounded_apply_adapter );
$bounded_request = mdi_reconcile_request( $canonical, 1 );
$bounded_plan = $bounded_apply_service->plan( $bounded_request );
$bounded_apply_request = $bounded_request + array( 'plan_id' => $bounded_plan['plan_id'], 'source_identity' => $bounded_plan['source_identity'] );
$bounded_page = $bounded_apply_service->apply( $bounded_apply_request );
$bounded_operation_ids = $bounded_page['operation_ids'];
while ( null !== $bounded_page['continuation'] ) {
	$bounded_page = $bounded_apply_service->apply( $bounded_apply_request + array( 'continuation' => $bounded_page['continuation'] ) );
	$bounded_operation_ids = array_merge( $bounded_operation_ids, $bounded_page['operation_ids'] );
}
mdi_reconcile_check( 3 === count( array_unique( $bounded_operation_ids ) ) && 3 === array_sum( $bounded_apply_adapter->mutation_calls ) && 1 === max( $bounded_apply_adapter->mutation_calls ), 'bounded apply keeps the reviewed identity across mutating pages without replay' );

$production_adapter = new WP_Markdown_WordPress_Reconciliation_Adapter();
$authorize = new ReflectionMethod( $production_adapter, 'authorize_post_mutation' );
mdi_reconcile_throws( fn() => $authorize->invoke( $production_adapter, 'edit', 42, 'page' ), WP_Markdown_Reconciliation_Store_Conflict::class, 'production adapter retains normal WordPress capability checks by default' );
$trusted_adapter = new WP_Markdown_WordPress_Reconciliation_Adapter( null, null, static fn( string $operation, int $post_id, string $post_type ): bool => 'edit' === $operation && 42 === $post_id && 'page' === $post_type );
$trusted_authorize = new ReflectionMethod( $trusted_adapter, 'authorize_post_mutation' );
$trusted_authorize->invoke( $trusted_adapter, 'edit', 42, 'page' );
mdi_reconcile_check( true, 'trusted runtime may provide an explicit WordPress mutation authorizer' );

// Canonical deletion must retain its baseline if storage reports an unlink failure.
$delete_storage = new MDI_Reconciliation_Delete_Storage( $canonical );
$delete_adapter = new WP_Markdown_WordPress_Reconciliation_Adapter( $delete_storage );
$delete_method = new ReflectionMethod( $delete_adapter, 'wordpress_to_canonical' );
$delete_resource = 'post:00000000000000000042';
$delete_baseline_option = '_markdown_reconciliation_baselines_' . hash( 'sha256', $canonical );
$baseline_record = array( 'canonical_root' => $canonical, 'canonical_path' => 'page/delete.md', 'identity' => mdi_reconcile_identity( array( 'ID' => 42 ) ), 'resource_id' => $delete_resource, 'resource_type' => 'post' );
$delete_operation = array( 'binding' => array( 'resource' => array( 'id' => $delete_resource ) ) );
$delete_context = array( 'root' => $canonical, 'current_path' => 'page/delete.md', 'expected_path' => 'page/delete.md', 'post_id' => 42, 'kind' => 'deleted_from_wordpress', 'layout_profile' => '' );
$GLOBALS['mdi_reconcile_options'][ $delete_baseline_option ] = array( $delete_resource => $baseline_record );
$delete_storage->delete_result = 'failed';
mdi_reconcile_throws( fn() => $delete_method->invoke( $delete_adapter, $delete_operation, $delete_context ), RuntimeException::class, 'failed canonical unlink fails closed before baseline deletion' );
mdi_reconcile_check( isset( $GLOBALS['mdi_reconcile_options'][ $delete_baseline_option ][ $delete_resource ] ) && 1 === $delete_storage->delete_attempts, 'failed canonical unlink retains baseline retry evidence' );
$delete_storage->delete_result = 'deleted';
$delete_method->invoke( $delete_adapter, $delete_operation, $delete_context );
mdi_reconcile_check( ! isset( $GLOBALS['mdi_reconcile_options'][ $delete_baseline_option ][ $delete_resource ] ) && 2 === $delete_storage->delete_attempts, 'successful canonical unlink clears baseline' );
$GLOBALS['mdi_reconcile_options'][ $delete_baseline_option ] = array( $delete_resource => $baseline_record );
$delete_storage->delete_result = 'absent';
$delete_method->invoke( $delete_adapter, $delete_operation, $delete_context );
mdi_reconcile_check( ! isset( $GLOBALS['mdi_reconcile_options'][ $delete_baseline_option ][ $delete_resource ] ) && 3 === $delete_storage->delete_attempts, 'absent canonical file clears the completed deletion baseline' );
$parse_cursor = new ReflectionMethod( $production_adapter, 'parse_continuation' );
$cursor_hash = str_repeat( 'a', 64 );
mdi_reconcile_check( array( 'post:00000000000000000042', $cursor_hash ) === $parse_cursor->invoke( $production_adapter, 'v2:post%3A00000000000000000042:' . $cursor_hash ), 'production continuation parses its percent-encoded resource key' );
mdi_reconcile_throws( fn() => $parse_cursor->invoke( $production_adapter, 'v2:post:00000000000000000042:' . $cursor_hash ), InvalidArgumentException::class, 'production continuation rejects a non-canonical resource-key encoding' );

$state_entry = new ReflectionMethod( $production_adapter, 'state_entry' );
$source_identity = new ReflectionMethod( $production_adapter, 'source_identity' );
$child_file = array( 'receipt' => array( 'ID' => 42, 'post_name' => 'child' ), 'hash' => str_repeat( 'b', 64 ) );
$child_before = $state_entry->invoke( $production_adapter, 'post:00000000000000000042', $canonical, $child_file, null, 'page/parent/child.md', 'page/parent/child.md', null );
$child_after = $state_entry->invoke( $production_adapter, 'post:00000000000000000042', $canonical, $child_file, null, 'page/parent/child.md', 'page/renamed-parent/child.md', null );
$child_key = 'post:00000000000000000042';
mdi_reconcile_check( $source_identity->invoke( $production_adapter, array( $child_key => $child_before ), array( $child_key ) ) !== $source_identity->invoke( $production_adapter, array( $child_key => $child_after ), array( $child_key ) ), 'unprocessed suffix authentication covers a descendant target path changed by its parent' );
$managed_post = (object) array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'child' );
$GLOBALS['mdi_reconcile_posts'][42] = $managed_post;
$managed_entry = $state_entry->invoke( $production_adapter, $child_key, $canonical, null, $managed_post, null, 'page/parent/child.md', null );
mdi_reconcile_check(
	isset( $managed_entry['snapshot']['durable_before_extra']['management'], $managed_entry['snapshot']['durable_after_extra_by_category']['written_from_wordpress']['management'] )
	&& ! WP_Markdown_Reconciliation_Identity::equal( $managed_entry['snapshot']['durable_before_extra']['management'], $managed_entry['snapshot']['durable_after_extra_by_category']['written_from_wordpress']['management'] ),
	'production operations require source-path management metadata before WordPress-to-canonical completion'
);
$managed_receipt = $managed_entry['snapshot']['wordpress'];
$initialized_snapshot = $state_entry->invoke( $production_adapter, $child_key, $canonical, array( 'post' => $managed_post, 'receipt' => $managed_receipt, 'hash' => str_repeat( 'c', 64 ) ), $managed_post, 'page/parent/child.md', 'page/parent/child.md', null )['snapshot'];
$initialized_adapter = new MDI_Reconciliation_Content_Adapter( array( $initialized_snapshot ), $runtime . '/management-init', $pdo, 'management-init' );
$initialized_service = new WP_Markdown_Reconciliation_Service( new WP_Markdown_Durable_Reconciliation_Coordinator( mdi_reconcile_store( $runtime . '/management-init-journal', $canonical ) ), $initialized_adapter );
$initialized_plan = $initialized_service->plan( array_replace( mdi_reconcile_request( $canonical ), array( 'direction' => 'wordpress_to_canonical' ) ) );
mdi_reconcile_check( 1 === $initialized_plan['counts']['written_from_wordpress'], 'equal pre-existing content initializes management metadata in WordPress-to-canonical recovery: flag=' . json_encode( $initialized_snapshot['management_uninitialized'] ?? null ) . ' counts=' . json_encode( $initialized_plan['counts'] ) );

markdown_db_register_content_layout_profile( 'reconciliation-flat-fixture', array(
	'enumerate' => static fn(): array => array(),
	'map_source' => static fn(): array => array(),
	'path_for_post' => static fn( object $post ): string => 'flat/' . $post->post_name . '.md',
) );
$profile_storage = new WP_Markdown_Storage( $canonical );
$profile_storage->set_content_layout_profile( 'reconciliation-flat-fixture' );
$expected_path = new ReflectionMethod( $production_adapter, 'expected_path' );
$profile_post = (object) array( 'ID' => 42, 'post_type' => 'page', 'post_name' => 'profiled', 'post_parent' => 0 );
mdi_reconcile_check( 'flat/profiled.md' === $expected_path->invoke( $production_adapter, $profile_post, null, null, array( $profile_post ), $profile_storage, 'reconciliation-flat-fixture', $canonical ), 'production reconciliation plans the route selected by a non-legacy layout profile' );
$profile_plan_adapter = new MDI_Reconciliation_Content_Adapter( array( $snapshots[0] ), $runtime . '/profile-plan', $pdo, 'profile-plan' );
$profile_service = new WP_Markdown_Reconciliation_Service( new WP_Markdown_Durable_Reconciliation_Coordinator( mdi_reconcile_store( $runtime . '/profile-plan-journal', $canonical ) ), $profile_plan_adapter );
$profile_service->plan( mdi_reconcile_request( $canonical ) + array( 'layout_profile' => 'reconciliation-flat-fixture' ) );
mdi_reconcile_check( 'reconciliation-flat-fixture' === ( $profile_plan_adapter->last_scope['layout_profile'] ?? null ), 'reconciliation passes the reviewed layout profile to production enumeration' );

// Simulate interruption after a real fenced filesystem effect but before operation-store completion.
$resume_snapshot = $snapshots[1];
$resume_adapter = new MDI_Reconciliation_Content_Adapter( array( $resume_snapshot ), $runtime . '/resume', $pdo, 'resume' );
$resume_adapter->change_source_on_mutation = true;
$resume_store = mdi_reconcile_store( $runtime . '/resume-journal', $canonical );
$resume_coordinator = new WP_Markdown_Durable_Reconciliation_Coordinator( $resume_store, 'resume-owner', 1 );
$resume_service = new WP_Markdown_Reconciliation_Service( $resume_coordinator, $resume_adapter );
$resume_request = mdi_reconcile_request( $canonical, 1 ); $resume_plan = $resume_service->plan( $resume_request );
$before = array( 'canonical' => mdi_reconcile_identity( null ), 'wordpress' => mdi_reconcile_identity( $resume_snapshot['wordpress'] ) );
$after = array( 'canonical' => mdi_reconcile_identity( $resume_snapshot['wordpress'] ), 'wordpress' => mdi_reconcile_identity( $resume_snapshot['wordpress'] ) );
$intent = array( 'plan_id' => $resume_plan['plan_id'], 'continuation' => array( 'service_schema' => 1, 'source_identity' => $resume_plan['source_identity'], 'cursor' => null, 'resource_id' => $resume_snapshot['resource_id'], 'canonical_path' => null, 'expected_canonical_path' => 'post/wp.md', 'layout_profile' => '' ), 'canonical_root' => $canonical, 'resource' => array( 'type' => 'post', 'id' => $resume_snapshot['resource_id'] ), 'kind' => 'written_from_wordpress', 'direction' => 'wordpress_to_canonical', 'before' => $before, 'after' => $after );
$interrupted = $resume_store->plan( $intent );
$claimed = $resume_store->claim( $interrupted['id'], $interrupted['revision'], 'crashed-owner', time() - 2, 1 );
$owning = $resume_adapter->adapter_for( $claimed, null ); $owning->fence( $claimed ); $owning->apply( $claimed );
$resume_mutations = $resume_adapter->mutation_calls;
$other_cursor_intent = $intent;
$other_cursor_intent['continuation']['cursor'] = 'other-page';
$other_cursor_intent['resource']['id'] = 'other-page-resource';
$other_cursor = $resume_store->plan( $other_cursor_intent );
$other_cursor = $resume_store->claim( $other_cursor['id'], $other_cursor['revision'], 'other-page-owner', time() - 2, 1 );
$resumed = $resume_service->apply( $resume_request + array( 'plan_id' => $resume_plan['plan_id'], 'source_identity' => $resume_plan['source_identity'] ) );
mdi_reconcile_check( array( $interrupted['id'] ) === $resumed['operation_ids'] && $resume_mutations === $resume_adapter->mutation_calls && 'completed' === $resume_store->get( $interrupted['id'] )['state'], 'interrupted bounded apply resumes original #190 operation ID without duplicate mutation' );
mdi_reconcile_check( 'claimed' === $resume_store->get( $other_cursor['id'] )['state'], 'recovery leaves an operation from another continuation page untouched' );

$receipt_method = new ReflectionMethod( $production_adapter, 'post_receipt' );
$full_post = (object) array( 'post_author' => 7, 'post_date' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00', 'post_content' => 'body', 'post_title' => 'title', 'post_excerpt' => 'excerpt', 'post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => 'password', 'post_name' => 'slug', 'post_modified' => '2026-01-02 00:00:00', 'post_modified_gmt' => '2026-01-02 00:00:00', 'post_parent' => 3, 'guid' => 'guid', 'menu_order' => 4, 'post_type' => 'page', 'post_mime_type' => 'text/plain', 'comment_count' => 5 );
$full_receipt = $receipt_method->invoke( $production_adapter, $full_post, array(), array() );
mdi_reconcile_check( 7 === $full_receipt['post_author'] && 'excerpt' === $full_receipt['post_excerpt'] && 4 === $full_receipt['menu_order'] && 'text/plain' === $full_receipt['post_mime_type'] && array_key_exists( 'post_content_filtered', $full_receipt ) && array_key_exists( 'to_ping', $full_receipt ) && array_key_exists( 'pinged', $full_receipt ) && ! array_key_exists( 'post_modified', $full_receipt ) && ! array_key_exists( 'comment_count', $full_receipt ) && ! array_key_exists( 'guid', $full_receipt ), 'reconciliation identity covers writable post fields and excludes WordPress-managed values' );

$coordinator_root = $canonical . '/coordinator-bootstrap';
mkdir( $coordinator_root, 0755, true );
mdi_reconcile_check( wp_markdown_durable_reconciliation_coordinator( array( $coordinator_root ) ) instanceof WP_Markdown_Durable_Reconciliation_Coordinator, 'production coordinator creates its private authentication key without hard-link support' );

$wordpress_meta_method = new ReflectionMethod( $production_adapter, 'wordpress_meta' );
$GLOBALS['mdi_reconcile_post_meta'][42] = array( 'structured' => array( serialize( array( 'nested' => array( 'value' => 7 ) ) ) ) );
mdi_reconcile_check( array( 'nested' => array( 'value' => 7 ) ) === $wordpress_meta_method->invoke( $production_adapter, 42 )['structured'][0], 'WordPress serialized meta is normalized before canonical storage serialization' );
$normalized_meta_method = new ReflectionMethod( $production_adapter, 'normalize_meta' );
mdi_reconcile_check( array( array( 'nested' => array( 'value' => 7 ) ) ) === $normalized_meta_method->invoke( $production_adapter, array( 'structured' => array( 'nested' => array( 'value' => 7 ) ) ) )['structured'], 'canonical associative meta remains one structured value' );

// Store lease ownership and the actual filesystem ownership adapter both fence stale workers.
$ownership_store = mdi_reconcile_store( $runtime . '/ownership-journal', $canonical );
$ownership_record = $ownership_store->plan( array_replace( $intent, array( 'plan_id' => 'ownership-plan' ) ) );
$owner_a = $ownership_store->claim( $ownership_record['id'], $ownership_record['revision'], 'owner-a', 100, 10 );
mdi_reconcile_throws( fn() => $ownership_store->claim( $ownership_record['id'], $owner_a['revision'], 'owner-b', 105, 10 ), WP_Markdown_Reconciliation_Store_Conflict::class, 'active operation lease rejects concurrent ownership' );
$owner_b = $ownership_store->claim( $ownership_record['id'], $owner_a['revision'], 'owner-b', 110, 10 );
$fs_state = 'before'; $fs_mutations = 0;
$fs_adapter = new WP_Markdown_Filesystem_Reconciliation_Adapter( $runtime . '/ownership-fences', static fn() => array( 'canonical' => $fs_state ), static function () use ( &$fs_mutations ): void { ++$fs_mutations; } );
$fs_adapter->fence( $owner_a ); $fs_adapter->fence( $owner_b );
mdi_reconcile_throws( fn() => $fs_adapter->apply( $owner_a ), WP_Markdown_Reconciliation_Store_Conflict::class, 'filesystem ownership adapter rejects stale concurrent owner' );
mdi_reconcile_check( 0 === $fs_mutations && $owner_b['fence'] > $owner_a['fence'], 'stale filesystem owner cannot reach mutation callback' );
$equal_owner = $owner_b; $equal_owner['id'] = str_repeat( 'e', 64 );
mdi_reconcile_throws( fn() => $fs_adapter->fence( $equal_owner ), WP_Markdown_Reconciliation_Store_Conflict::class, 'equal fence token from a different operation does not acquire ownership' );

// Real SQLite PDO engine behavior: fence and content mutation commit together, stale owner is rejected.
$sqlite = new PDO( 'sqlite:' . $runtime . '/ownership.sqlite' ); $sqlite->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$sqlite->exec( 'CREATE TABLE `_mdi_resource_fences` (`resource_key` VARCHAR(191) PRIMARY KEY, `operation_id` VARCHAR(64) NOT NULL, `fence` BIGINT NOT NULL)' );
$sqlite->exec( 'CREATE TABLE content (id INTEGER PRIMARY KEY, value TEXT NOT NULL)' ); $sqlite->exec( "INSERT INTO content VALUES (1, 'before')" );
$sqlite_adapter = new WP_Markdown_PDO_Reconciliation_Adapter( $sqlite, static fn( array $op, PDO $db ): array => array( 'wordpress' => $db->query( 'SELECT value FROM content WHERE id = 1' )->fetchColumn() ), static fn( array $op, PDO $db ) => $db->exec( "UPDATE content SET value = 'after' WHERE id = 1" ) );
$sqlite_intent = array_replace( $intent, array( 'plan_id' => 'sqlite-real', 'resource' => array( 'type' => 'post', 'id' => 'sqlite-1' ), 'kind' => 'updated_from_file', 'direction' => 'canonical_to_wordpress', 'before' => array( 'wordpress' => mdi_reconcile_identity( 'before' ) ), 'after' => array( 'wordpress' => mdi_reconcile_identity( 'after' ) ) ) );
$sqlite_result = ( new WP_Markdown_Durable_Reconciliation_Coordinator( mdi_reconcile_store( $runtime . '/sqlite-journal', $canonical ) ) )->reconcile( $sqlite_intent, $sqlite_adapter );
mdi_reconcile_check( 'completed' === $sqlite_result['state'] && 'after' === $sqlite->query( 'SELECT value FROM content WHERE id = 1' )->fetchColumn(), 'real SQLite PDO adapter fences, mutates, and verifies committed state' );
$sqlite_stale = $sqlite_result; $sqlite_stale['id'] = str_repeat( 'a', 64 ); $sqlite_stale['fence'] = 1;
$sqlite_newer = $sqlite_stale; $sqlite_newer['id'] = str_repeat( 'b', 64 ); $sqlite_newer['fence'] = $sqlite_result['fence'] + 10;
$sqlite_adapter->fence( $sqlite_newer );
mdi_reconcile_throws( fn() => $sqlite_adapter->apply( $sqlite_stale ), WP_Markdown_Reconciliation_Store_Conflict::class, 'real SQLite PDO fence rejects stale database ownership' );

$mysql = new MDI_Reconciliation_MySQL_PDO(); $mysql_state = 'before';
$mysql_adapter = new WP_Markdown_PDO_Reconciliation_Adapter( $mysql, static function () use ( &$mysql_state ): array { return array( 'wordpress' => $mysql_state ); }, static function () use ( &$mysql_state ): void { $mysql_state = 'after'; } );
$mysql_result = ( new WP_Markdown_Durable_Reconciliation_Coordinator( mdi_reconcile_store( $runtime . '/mysql-journal', $canonical ) ) )->reconcile( array_replace( $sqlite_intent, array( 'plan_id' => 'mysql-protocol', 'resource' => array( 'type' => 'post', 'id' => 'mysql-1' ) ) ), $mysql_adapter );
$mysql_sql = implode( "\n", $mysql->sql );
mdi_reconcile_check( 'completed' === $mysql_result['state'] && ! str_contains( $mysql_sql, 'CREATE TABLE' ) && str_contains( $mysql_sql, 'BEGIN' ) && str_contains( $mysql_sql, 'COMMIT' ) && str_contains( $mysql_sql, 'DELETE FROM' ) && str_contains( $mysql_sql, 'INSERT INTO' ) && str_contains( $mysql_sql, 'SELECT operation_id, fence FROM' ), 'MySQL-compatible PDO protocol uses a pre-provisioned fence without mutation-path DDL' );

WP_Markdown_CLI::register();
foreach ( $GLOBALS['mdi_reconcile_actions']['wp_abilities_api_categories_init'] ?? array() as $callback ) { $callback(); }
foreach ( $GLOBALS['mdi_reconcile_actions']['wp_abilities_api_init'] ?? array() as $callback ) { $callback(); }
$ability = $GLOBALS['mdi_reconcile_abilities']['markdown-db/reconcile'] ?? array();
$input_keys = array( 'dry_run', 'canonical_root', 'managed_scope', 'direction', 'deletion_policy', 'conflict_policy', 'batch_size', 'continuation', 'plan_id', 'source_identity', 'layout_profile' );
mdi_reconcile_check( isset( $GLOBALS['mdi_reconcile_categories']['markdown-db'] ) && array( WP_Markdown_CLI::class, 'reconcile' ) === ( $ability['execute_callback'] ?? null ) && is_callable( $ability['execute_callback'] ?? null ), 'public reconciliation facade callback registers without plugin bootstrap' );
mdi_reconcile_check( array( 'type', 'properties' ) === array_keys( $ability['input_schema'] ?? array() ) && $input_keys === array_keys( $ability['input_schema']['properties'] ?? array() ), 'ability input schema has the exact reconciliation property set' );
$output = $ability['output_schema'] ?? array();
mdi_reconcile_check( array( 'type', 'properties', 'required' ) === array_keys( $output ) && $top_keys === array_keys( $output['properties'] ?? array() ) && $top_keys === ( $output['required'] ?? array() ), 'ability output schema requires the exact top-level response keys' );
mdi_reconcile_check( $category_keys === array_keys( $output['properties']['categories']['properties'] ?? array() ) && $category_keys === ( $output['properties']['categories']['required'] ?? array() ) && $category_keys === array_keys( $output['properties']['counts']['properties'] ?? array() ), 'ability output schema registers exact category and count keys' );
$schema_entry = $output['properties']['categories']['properties']['conflicts']['items'] ?? array();
mdi_reconcile_check( $entry_required === ( $schema_entry['required'] ?? array() ) && array( 'algorithm', 'digest' ) === ( $schema_entry['properties']['canonical_identity']['anyOf'][0]['required'] ?? array() ), 'ability output schema requires path/resource and all exact identity fields' );

echo "Reconciliation checks: $passed passed, $failed failed.\n";
exit( $failed > 0 ? 1 : 0 );
