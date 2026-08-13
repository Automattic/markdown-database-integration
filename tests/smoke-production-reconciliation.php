<?php
/** Production durable reconciliation adapter smoke test. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-reconciliation-adapters.php';

$failures = array();
function mdi_production_check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) { $failures[] = $message; echo "FAIL: $message\n"; return; }
	echo "PASS: $message\n";
}
function mdi_production_remove( string $path ): void {
	if ( ! is_dir( $path ) ) { return; }
	foreach ( scandir( $path ) ?: array() as $entry ) { if ( '.' === $entry || '..' === $entry ) { continue; } $child = $path . '/' . $entry; is_dir( $child ) ? mdi_production_remove( $child ) : unlink( $child ); }
	rmdir( $path );
}
function mdi_production_intent( string $root, string $plan, string $kind, array $before, array $after ): array {
	return array( 'plan_id' => $plan, 'continuation' => array( 'cursor' => $plan ), 'canonical_root' => $root, 'resource' => array( 'type' => 'post', 'id' => '42' ), 'kind' => $kind, 'direction' => 'wordpress_to_canonical', 'before' => $before, 'after' => $after );
}

$root = tempnam( sys_get_temp_dir(), 'mdi-production-reconciliation-' );
if ( false === $root || ! unlink( $root ) || ! mkdir( $root, 0700 ) ) { throw new RuntimeException( 'Unable to create test root.' ); }
$canonical = $root . '/canonical'; $journal = $root . '/journal'; $fences = $root . '/fences';
mkdir( $canonical, 0700 );
register_shutdown_function( static fn() => mdi_production_remove( $root ) );
$key = str_repeat( 'production-adapter-key-', 2 );
$store = new WP_Markdown_Filesystem_Reconciliation_Operation_Store( $journal, $key, array( $canonical ) );
$coordinator = new WP_Markdown_Durable_Reconciliation_Coordinator( $store, 'process-a', 1 );

// Real filesystem create/update/move/delete effects complete with exact receipts.
$old = $canonical . '/old.md'; $new = $canonical . '/new.md';
$state = null;
$run_filesystem = static function ( string $plan, string $kind, mixed $before, mixed $after, callable $mutation ) use ( $coordinator, $canonical, $fences, &$state ): array {
	$observer = static function () use ( &$state ): array { return array( 'canonical' => $state ); };
	$adapter = new WP_Markdown_Filesystem_Reconciliation_Adapter( $fences, $observer, static function () use ( $mutation, &$state, $after ): void { $mutation(); $state = $after; } );
	return $coordinator->reconcile( mdi_production_intent( $canonical, $plan, $kind, array( 'canonical' => $before ), array( 'canonical' => $after ) ), $adapter );
};
$result = $run_filesystem( 'fs-create', 'create', null, array( 'path' => 'old.md', 'body' => 'one' ), static fn() => file_put_contents( $old, 'one' ) );
mdi_production_check( 'completed' === $result['state'] && 'one' === file_get_contents( $old ), 'production filesystem adapter completes create' );
$before = $state; $result = $run_filesystem( 'fs-update', 'update', $before, array( 'path' => 'old.md', 'body' => 'two' ), static fn() => file_put_contents( $old, 'two' ) );
mdi_production_check( 'completed' === $result['state'] && 'two' === file_get_contents( $old ), 'production filesystem adapter completes update' );
$before = $state; $result = $run_filesystem( 'fs-move', 'move', $before, array( 'path' => 'new.md', 'body' => 'two' ), static fn() => rename( $old, $new ) );
mdi_production_check( 'completed' === $result['state'] && ! file_exists( $old ) && file_exists( $new ), 'production filesystem adapter completes move' );
$before = $state; $result = $run_filesystem( 'fs-delete', 'deletion', $before, null, static fn() => unlink( $new ) );
mdi_production_check( 'completed' === $result['state'] && ! file_exists( $new ), 'production filesystem adapter completes deletion' );

// Reopen the journal as another process and recover an effect without replay.
$path = $canonical . '/recovery.md'; $state = null;
$intent = mdi_production_intent( $canonical, 'cross-process', 'create', array( 'canonical' => null ), array( 'canonical' => 'recovered' ) );
$record = $store->plan( array_merge( $intent, array( 'before' => array( 'canonical' => WP_Markdown_Reconciliation_Identity::exact( null ) ), 'after' => array( 'canonical' => WP_Markdown_Reconciliation_Identity::exact( 'recovered' ) ) ) ) );
$claimed = $store->claim( $record['id'], $record['revision'], 'process-a', 100, 1 );
$recovery_observer = static function () use ( &$state ): array { return array( 'canonical' => $state ); };
$recovery_adapter = new WP_Markdown_Filesystem_Reconciliation_Adapter( $fences, $recovery_observer, static function (): void { throw new RuntimeException( 'Recovery must not replay.' ); } );
$recovery_adapter->fence( $claimed ); $state = 'recovered'; file_put_contents( $path, $state );
$reopened = new WP_Markdown_Durable_Reconciliation_Coordinator( new WP_Markdown_Filesystem_Reconciliation_Operation_Store( $journal, $key, array( $canonical ) ), 'process-b', 1 );
$recovered = $reopened->recover( $record['id'], $recovery_adapter );
mdi_production_check( 'completed' === $recovered['state'] && 'recovered' === file_get_contents( $path ), 'cross-process journal reopening recovers observed filesystem effect without replay' );

// Persisted filesystem fencing rejects an older owner before replacement.
$stale = $claimed; $newer = $stale; $newer['id'] = str_repeat( 'b', 64 ); $newer['fence'] = $stale['fence'] + 1;
$recovery_adapter->fence( $newer ); $stale_rejected = false;
try { $recovery_adapter->apply( $stale ); } catch ( WP_Markdown_Reconciliation_Store_Conflict ) { $stale_rejected = true; }
mdi_production_check( $stale_rejected && 'recovered' === file_get_contents( $path ), 'durable filesystem fence rejects stale replacement owner' );

// Real SQLite fence ownership and mutation share one transaction boundary.
$database = $root . '/wordpress.sqlite'; $pdo = new PDO( 'sqlite:' . $database ); $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT NOT NULL)' ); $pdo->exec( "INSERT INTO wp_posts VALUES (42, 'before')" );
$observe_db = static function ( array $operation, PDO $connection ): array { unset( $operation ); $value = $connection->query( 'SELECT post_title FROM wp_posts WHERE ID = 42' )->fetchColumn(); return array( 'wordpress' => false === $value ? null : $value ); };
$mutate_db = static function ( array $operation, PDO $connection ): void { unset( $operation ); $connection->exec( "UPDATE wp_posts SET post_title = 'after' WHERE ID = 42" ); };
$db_adapter = new WP_Markdown_PDO_Reconciliation_Adapter( $pdo, $observe_db, $mutate_db );
$db_result = $coordinator->reconcile( mdi_production_intent( $canonical, 'db-update', 'update', array( 'wordpress' => 'before' ), array( 'wordpress' => 'after' ) ), $db_adapter );
mdi_production_check( 'completed' === $db_result['state'] && 'after' === $pdo->query( 'SELECT post_title FROM wp_posts WHERE ID = 42' )->fetchColumn(), 'production SQLite adapter atomically fences and mutates WordPress state' );

$db_plan = $store->plan( array_merge( mdi_production_intent( $canonical, 'db-stale', 'update', array( 'wordpress' => WP_Markdown_Reconciliation_Identity::exact( 'after' ) ), array( 'wordpress' => WP_Markdown_Reconciliation_Identity::exact( 'stale-write' ) ) ) ) );
$db_stale = $store->claim( $db_plan['id'], $db_plan['revision'], 'process-a', 200, 1 ); $db_adapter->fence( $db_stale );
$db_newer = $db_stale; $db_newer['id'] = str_repeat( 'c', 64 ); $db_newer['fence'] = $db_stale['fence'] + 1; $db_adapter->fence( $db_newer );
$db_rejected = false; try { $db_adapter->apply( $db_stale ); } catch ( WP_Markdown_Reconciliation_Store_Conflict ) { $db_rejected = true; }
mdi_production_check( $db_rejected && 'after' === $pdo->query( 'SELECT post_title FROM wp_posts WHERE ID = 42' )->fetchColumn(), 'persisted SQLite fence rejects stale database owner before mutation' );

exit( empty( $failures ) ? 0 : 1 );
