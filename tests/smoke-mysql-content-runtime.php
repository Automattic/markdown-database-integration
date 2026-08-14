<?php
/** Isolated lifecycle regressions for the mysql-content runtime. Usage: php tests/smoke-mysql-content-runtime.php */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MARKDOWN_DB_BACKEND', 'mysql-content' );
define( 'MARKDOWN_DB_CONTENT_DIR', sys_get_temp_dir() . '/mdi-mysql-content-runtime' );
define( 'MARKDOWN_DB_MANAGED_POST_TYPES', 'post,page' );

$GLOBALS['mdi_runtime_hooks'] = array();
$GLOBALS['mdi_runtime_posts'] = array();
$GLOBALS['mdi_runtime_types'] = array();
$GLOBALS['mdi_runtime_calls'] = array();
$GLOBALS['mdi_runtime_apply'] = null;
$GLOBALS['mdi_runtime_meta_posts'] = array();
$GLOBALS['mdi_runtime_term_posts'] = array();
$GLOBALS['mdi_runtime_paged_apply'] = null;

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['mdi_runtime_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}
function do_action( string $hook, mixed ...$args ): void {
	$hooks = $GLOBALS['mdi_runtime_hooks'][ $hook ] ?? array();
	ksort( $hooks, SORT_NUMERIC );
	foreach ( $hooks as $callbacks ) {
		foreach ( $callbacks as $registered ) {
			list( $callback, $accepted_args ) = $registered;
			$callback( ...array_slice( $args, 0, $accepted_args ) );
		}
	}
}
function get_post_type( int $id ): string|false { return $GLOBALS['mdi_runtime_posts'][ $id ]['type'] ?? false; }
function post_type_exists( string $type ): bool { return in_array( $type, $GLOBALS['mdi_runtime_types'], true ); }
function get_posts( array $query ): array {
	if ( isset( $query['meta_key'], $query['meta_value'] ) ) { return $GLOBALS['mdi_runtime_meta_posts'][ $query['meta_value'] ] ?? array(); }
	$ids = array();
	foreach ( $GLOBALS['mdi_runtime_posts'] as $id => $post ) {
		if ( (int) ( $query['post_parent'] ?? -1 ) === $post['parent'] && in_array( $post['type'], (array) $query['post_type'], true ) ) { $ids[] = $id; }
	}
	return $ids;
}
function get_objects_in_term( int $term_id, string $taxonomy ): array { return $GLOBALS['mdi_runtime_term_posts'][ $taxonomy . ':' . $term_id ] ?? array(); }
function is_wp_error( mixed $value ): bool { return false; }

final class WP_Markdown_CLI {
	public static function reconcile( array $request ): array {
		$GLOBALS['mdi_runtime_calls'][] = $request;
		if ( ! empty( $request['dry_run'] ) ) { return array( 'plan_id' => 'plan-' . count( $GLOBALS['mdi_runtime_calls'] ), 'source_identity' => 'fixture-source' ); }
		if ( is_callable( $GLOBALS['mdi_runtime_paged_apply'] ) ) { return ( $GLOBALS['mdi_runtime_paged_apply'] )( $request ); }
		$apply = $GLOBALS['mdi_runtime_apply'];
		return is_callable( $apply ) ? $apply( $request ) : array( 'categories' => array(), 'continuation' => null );
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-mysql-content-runtime.php';
require_once __DIR__ . '/../inc/class-wp-markdown-frontmatter-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-content-layout-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/class-wp-markdown-wordpress-reconciliation-adapter.php';

$passed = 0;
$failed = 0;
function mdi_mysql_runtime_assert( bool $condition, string $message ): void {
	global $passed, $failed;
	if ( $condition ) { ++$passed; echo "PASS: $message\n"; return; }
	++$failed; echo "FAIL: $message\n";
}
function mdi_mysql_runtime_ids( array $request ): array { return $request['resource_ids'] ?? array(); }
function mdi_mysql_runtime_apply_success( array $request ): array {
	return array( 'categories' => array( 'written_from_wordpress' => array_map( static fn( string $id ): array => array( 'resource_id' => $id, 'expected_canonical_path' => 'post/' . $id . '.md' ), mdi_mysql_runtime_ids( $request ) ) ), 'continuation' => null );
}

$GLOBALS['mdi_runtime_types'] = array( 'post', 'page' );
WP_Markdown_MySQL_Content_Runtime::bootstrap();

// Hydration is deferred until init, after normal post-type registration has run.
$GLOBALS['mdi_runtime_types'] = array();
do_action( 'init' );
mdi_mysql_runtime_assert( empty( $GLOBALS['mdi_runtime_calls'] ), 'unknown managed post types reject hydration without reconciling' );
add_action( 'init', static function (): void { $GLOBALS['mdi_runtime_types'] = array( 'post', 'page' ); }, 10 );
do_action( 'init' );
mdi_mysql_runtime_assert( 6 === count( $GLOBALS['mdi_runtime_calls'] ) && 'canonical_to_wordpress' === $GLOBALS['mdi_runtime_calls'][0]['direction'] && 'wordpress_to_canonical' === $GLOBALS['mdi_runtime_calls'][2]['direction'] && 'canonical_to_wordpress' === $GLOBALS['mdi_runtime_calls'][4]['direction'], 'hydration initializes parent management metadata before retrying canonical children' );
$GLOBALS['mdi_runtime_calls'] = array();

// Dirty hooks project only managed resources and pass their canonical resource IDs exactly.
$GLOBALS['mdi_runtime_posts'] = array( 7 => array( 'type' => 'post', 'parent' => 0 ), 8 => array( 'type' => 'attachment', 'parent' => 0 ) );
do_action( 'save_post', 7 ); do_action( 'added_post_meta', 1, 8 ); do_action( 'set_object_terms', 7, array( 2 ) );
$GLOBALS['mdi_runtime_apply'] = 'mdi_mysql_runtime_apply_success';
WP_Markdown_MySQL_Content_Runtime::flush_now();
$apply_calls = array_values( array_filter( $GLOBALS['mdi_runtime_calls'], static fn( array $call ): bool => empty( $call['dry_run'] ) ) );
mdi_mysql_runtime_assert( array( 'post:00000000000000000007' ) === mdi_mysql_runtime_ids( $apply_calls[0] ), 'dirty flush scopes reconciliation to exact managed resource IDs' );

// Batches above the transport limit must be drained in full, not truncated.
$GLOBALS['mdi_runtime_posts'] = array();
for ( $id = 1; $id <= 1001; ++$id ) { $GLOBALS['mdi_runtime_posts'][ $id ] = array( 'type' => 'post', 'parent' => 0 ); do_action( 'save_post', $id ); }
$GLOBALS['mdi_runtime_calls'] = array();
WP_Markdown_MySQL_Content_Runtime::flush_now();
$apply_calls = array_values( array_filter( $GLOBALS['mdi_runtime_calls'], static fn( array $call ): bool => empty( $call['dry_run'] ) ) );
$drained = array_merge( ...array_map( 'mdi_mysql_runtime_ids', $apply_calls ) ); sort( $drained, SORT_STRING );
mdi_mysql_runtime_assert( 2 === count( $apply_calls ) && 1001 === count( $drained ) && 'post:00000000000000000001' === $drained[0] && 'post:00000000000000001001' === $drained[1000], 'IDs beyond 1000 drain in bounded batches without loss' );

// A conflict retains only its resource; successful peers must not replay on the next flush.
$GLOBALS['mdi_runtime_posts'] = array( 41 => array( 'type' => 'post', 'parent' => 0 ), 42 => array( 'type' => 'post', 'parent' => 0 ) );
do_action( 'save_post', 41 ); do_action( 'save_post', 42 );
$GLOBALS['mdi_runtime_apply'] = static function ( array $request ): array {
	return array( 'categories' => array( 'written_from_wordpress' => array( array( 'resource_id' => 'post:00000000000000000041', 'expected_canonical_path' => 'post/41.md' ) ), 'conflicts' => array( array( 'resource_id' => 'post:00000000000000000042' ) ) ), 'continuation' => null );
};
$conflicted = WP_Markdown_MySQL_Content_Runtime::flush_now();
mdi_mysql_runtime_assert( array( 'post:00000000000000000042' ) === $conflicted['pending'], 'conflicted resource remains pending while its successful peer clears' );
$GLOBALS['mdi_runtime_calls'] = array();
$GLOBALS['mdi_runtime_apply'] = 'mdi_mysql_runtime_apply_success';
$retry = WP_Markdown_MySQL_Content_Runtime::flush_now();
$apply_calls = array_values( array_filter( $GLOBALS['mdi_runtime_calls'], static fn( array $call ): bool => empty( $call['dry_run'] ) ) );
mdi_mysql_runtime_assert( array( 'post:00000000000000000042' ) === ( $retry['changed'] ? mdi_mysql_runtime_ids( $apply_calls[0] ) : array() ), 'successful IDs clear while conflicted IDs remain pending' );

// A scoped hierarchy can span multiple authenticated pages; later dirty IDs must complete.
$GLOBALS['mdi_runtime_posts'] = array( 1101 => array( 'type' => 'post', 'parent' => 0 ), 1102 => array( 'type' => 'page', 'parent' => 1101 ) );
do_action( 'save_post', 1102 );
$GLOBALS['mdi_runtime_calls'] = array();
$GLOBALS['mdi_runtime_paged_apply'] = static function ( array $request ): array {
	if ( ! empty( $request['dry_run'] ) ) { return array( 'plan_id' => 'page-plan-' . count( $GLOBALS['mdi_runtime_calls'] ), 'source_identity' => 'paged-source' ); }
	if ( null === ( $request['continuation'] ?? null ) ) { return array( 'categories' => array( 'unchanged' => array() ), 'operation_ids' => array(), 'continuation' => array( 'cursor' => 'parent-context', 'identity' => str_repeat( 'a', 64 ) ) ); }
	return array( 'categories' => array( 'written_from_wordpress' => array( array( 'resource_id' => 'post:00000000000000001102', 'expected_canonical_path' => 'page/parent/child.md' ) ) ), 'operation_ids' => array( 'later-page-operation' ), 'continuation' => null );
};
$paged = WP_Markdown_MySQL_Content_Runtime::flush_now();
$apply_calls = array_values( array_filter( $GLOBALS['mdi_runtime_calls'], static fn( array $call ): bool => empty( $call['dry_run'] ) ) );
mdi_mysql_runtime_assert( 2 === count( $apply_calls ) && null === $apply_calls[0]['continuation'] && 'parent-context' === $apply_calls[1]['continuation']['cursor'] && array() === $paged['pending'] && array( 'page/parent/child.md' ) === $paged['changed'], 'scoped hierarchy continuation visits every page once and clears only after the later dirty resource completes' );

// Cold hydration uses the same continuation drain rather than re-running page one.
$GLOBALS['mdi_runtime_calls'] = array();
$GLOBALS['mdi_runtime_paged_apply'] = static function ( array $request ): array {
	if ( ! empty( $request['dry_run'] ) ) { return array( 'plan_id' => 'cold-plan-' . count( $GLOBALS['mdi_runtime_calls'] ), 'source_identity' => 'cold-source' ); }
	return null === ( $request['continuation'] ?? null ) ? array( 'categories' => array(), 'operation_ids' => array( 'cold-page-1' ), 'continuation' => array( 'cursor' => '1000', 'identity' => str_repeat( 'b', 64 ) ) ) : array( 'categories' => array(), 'operation_ids' => array( 'cold-page-2' ), 'continuation' => null );
};
do_action( 'init' );
$cold_apply_calls = array_values( array_filter( $GLOBALS['mdi_runtime_calls'], static fn( array $call ): bool => empty( $call['dry_run'] ) ) );
$cold_canonical_calls = array_values( array_filter( $cold_apply_calls, static fn( array $call ): bool => 'canonical_to_wordpress' === $call['direction'] ) );
mdi_mysql_runtime_assert( 4 === count( $cold_canonical_calls ) && '1000' === $cold_canonical_calls[1]['continuation']['cursor'] && '1000' === $cold_canonical_calls[3]['continuation']['cursor'], 'both canonical hydration passes consume authenticated continuations beyond 1000 resources' );
$GLOBALS['mdi_runtime_paged_apply'] = null;

// Continuations are incomplete receipts, so the entire submitted page remains dirty.
$GLOBALS['mdi_runtime_posts'] = array( 41 => array( 'type' => 'post', 'parent' => 0 ), 42 => array( 'type' => 'post', 'parent' => 0 ) );
do_action( 'save_post', 41 ); do_action( 'save_post', 42 );
$GLOBALS['mdi_runtime_apply'] = static fn( array $request ): array => array( 'categories' => array(), 'continuation' => 'next-page' );
$repeated_continuation_rejected = false;
try { WP_Markdown_MySQL_Content_Runtime::flush_now(); } catch ( RuntimeException $error ) { $repeated_continuation_rejected = str_contains( $error->getMessage(), 'no progress' ); }
mdi_mysql_runtime_assert( $repeated_continuation_rejected, 'repeated continuation fails closed without clearing dirty resources' );
$GLOBALS['mdi_runtime_apply'] = 'mdi_mysql_runtime_apply_success';
WP_Markdown_MySQL_Content_Runtime::flush_now();

// Delete hooks run while the hierarchy still exists and capture every descendant.
$GLOBALS['mdi_runtime_posts'] = array( 70 => array( 'type' => 'post', 'parent' => 0 ), 71 => array( 'type' => 'page', 'parent' => 70 ), 72 => array( 'type' => 'page', 'parent' => 71 ) );
do_action( 'before_delete_post', 70 );
$GLOBALS['mdi_runtime_posts'] = array();
$GLOBALS['mdi_runtime_calls'] = array();
$GLOBALS['mdi_runtime_apply'] = 'mdi_mysql_runtime_apply_success';
WP_Markdown_MySQL_Content_Runtime::flush_now();
$apply_calls = array_values( array_filter( $GLOBALS['mdi_runtime_calls'], static fn( array $call ): bool => empty( $call['dry_run'] ) ) );
mdi_mysql_runtime_assert( array( 'post:00000000000000000070', 'post:00000000000000000071', 'post:00000000000000000072' ) === mdi_mysql_runtime_ids( $apply_calls[0] ), 'pre-delete recursively captures descendants before WordPress removes them' );

// Parent route changes enqueue all descendants exactly once with the parent.
$GLOBALS['mdi_runtime_posts'] = array( 80 => array( 'type' => 'page', 'parent' => 0 ), 81 => array( 'type' => 'page', 'parent' => 80 ), 82 => array( 'type' => 'page', 'parent' => 81 ) );
do_action( 'wp_after_insert_post', 80, (object) array( 'post_name' => 'renamed', 'post_parent' => 0 ), true, (object) array( 'post_name' => 'old', 'post_parent' => 0 ) );
$GLOBALS['mdi_runtime_calls'] = array(); WP_Markdown_MySQL_Content_Runtime::flush_now();
$apply_calls = array_values( array_filter( $GLOBALS['mdi_runtime_calls'], static fn( array $call ): bool => empty( $call['dry_run'] ) ) );
mdi_mysql_runtime_assert( array( 'post:00000000000000000080', 'post:00000000000000000081', 'post:00000000000000000082' ) === mdi_mysql_runtime_ids( $apply_calls[0] ), 'slug rename queues each managed descendant for its hierarchy-derived move exactly once' );
do_action( 'wp_after_insert_post', 80, (object) array( 'post_name' => 'renamed', 'post_parent' => 9 ), true, (object) array( 'post_name' => 'renamed', 'post_parent' => 0 ) );
$GLOBALS['mdi_runtime_calls'] = array(); WP_Markdown_MySQL_Content_Runtime::flush_now();
$apply_calls = array_values( array_filter( $GLOBALS['mdi_runtime_calls'], static fn( array $call ): bool => empty( $call['dry_run'] ) ) );
mdi_mysql_runtime_assert( array( 'post:00000000000000000080', 'post:00000000000000000081', 'post:00000000000000000082' ) === mdi_mysql_runtime_ids( $apply_calls[0] ), 'reparent queues each managed descendant for its hierarchy-derived move exactly once' );

// Term mutations fan out to every attached managed post, never unrelated types.
$GLOBALS['mdi_runtime_posts'] = array( 90 => array( 'type' => 'post', 'parent' => 0 ), 91 => array( 'type' => 'page', 'parent' => 0 ), 92 => array( 'type' => 'attachment', 'parent' => 0 ) );
$GLOBALS['mdi_runtime_term_posts'] = array( 'category:4' => array( 90, 91, 92 ) );
do_action( 'edited_term', 4, 44, 'category', array( 'slug' => 'changed' ) ); $GLOBALS['mdi_runtime_calls'] = array(); WP_Markdown_MySQL_Content_Runtime::flush_now();
$apply_calls = array_values( array_filter( $GLOBALS['mdi_runtime_calls'], static fn( array $call ): bool => empty( $call['dry_run'] ) ) );
mdi_mysql_runtime_assert( array( 'post:00000000000000000090', 'post:00000000000000000091' ) === mdi_mysql_runtime_ids( $apply_calls[0] ), 'edited_term fans out only to attached managed posts' );
do_action( 'delete_term', 4, 44, 'category', null, array( 90, 91, 92 ) ); $GLOBALS['mdi_runtime_calls'] = array(); WP_Markdown_MySQL_Content_Runtime::flush_now();
$apply_calls = array_values( array_filter( $GLOBALS['mdi_runtime_calls'], static fn( array $call ): bool => empty( $call['dry_run'] ) ) );
mdi_mysql_runtime_assert( array( 'post:00000000000000000090', 'post:00000000000000000091' ) === mdi_mysql_runtime_ids( $apply_calls[0] ), 'delete_term fans out only to attached managed posts' );

// Canonical parent IDs are foreign to the live site; hierarchy resolves through source identity.
$hierarchy_root = MARKDOWN_DB_CONTENT_DIR . '-hierarchy-' . getmypid();
mkdir( $hierarchy_root . '/page', 0755, true );
file_put_contents( $hierarchy_root . '/page/old-parent-route.md', "---\nid: 900\ntype: page\nsource_identity: immutable-parent\n---\n\nParent\n" );
$GLOBALS['mdi_runtime_meta_posts'] = array( 'immutable-parent' => array( 12 ) );
$parent_id = new ReflectionMethod( WP_Markdown_WordPress_Reconciliation_Adapter::class, 'runtime_parent_id' );
$resolved_parent = $parent_id->invoke( new WP_Markdown_WordPress_Reconciliation_Adapter(), (object) array( 'post_parent' => 900 ), new WP_Markdown_Storage( $hierarchy_root ), $hierarchy_root );
mdi_mysql_runtime_assert( 12 === $resolved_parent, 'hierarchy maps canonical parents by source identity, not their path or foreign ID' );
unlink( $hierarchy_root . '/page/old-parent-route.md' ); rmdir( $hierarchy_root . '/page' ); rmdir( $hierarchy_root );

if ( $failed ) { exit( 1 ); }
echo "All tests passed ($passed assertions).\n";
