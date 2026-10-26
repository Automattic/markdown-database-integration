<?php
/** Production durable reconciliation adapter smoke test. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-reconciliation-adapters.php';
require_once __DIR__ . '/../inc/class-wp-markdown-frontmatter-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';

$failures = array();
function mdi_production_check( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) { $failures[] = $message; echo "FAIL: $message\n"; return; }
	echo "PASS: $message\n";
}
function mdi_production_remove( string $path ): void {
	if ( is_link( $path ) || is_file( $path ) ) { unlink( $path ); return; }
	if ( ! is_dir( $path ) ) { return; }
	foreach ( scandir( $path ) ?: array() as $entry ) { if ( '.' === $entry || '..' === $entry ) { continue; } $child = $path . '/' . $entry; is_dir( $child ) ? mdi_production_remove( $child ) : unlink( $child ); }
	rmdir( $path );
}
function mdi_production_intent( string $root, string $plan, string $kind, array $before, array $after ): array {
	return array( 'plan_id' => $plan, 'continuation' => array( 'cursor' => $plan ), 'canonical_root' => $root, 'resource' => array( 'type' => 'post', 'id' => '42' ), 'kind' => $kind, 'direction' => 'wordpress_to_canonical', 'before' => $before, 'after' => $after );
}

final class MDI_MySQL_Protocol_PDO extends PDO {
	public array $sql = array();
	public array $fences = array();
	private bool $transaction = false;
	public function __construct() {}
	public function exec( string $statement ): int|false { $this->sql[] = $statement; return 0; }
	public function prepare( string $query, array $options = array() ): PDOStatement|false { unset( $options ); $this->sql[] = $query; return new MDI_MySQL_Protocol_Statement( $this, $query ); }
	public function beginTransaction(): bool { $this->sql[] = 'BEGIN'; $this->transaction = true; return true; }
	public function commit(): bool { $this->sql[] = 'COMMIT'; $this->transaction = false; return true; }
	public function rollBack(): bool { $this->sql[] = 'ROLLBACK'; $this->transaction = false; return true; }
	public function inTransaction(): bool { return $this->transaction; }
}
final class MDI_MySQL_Protocol_Statement extends PDOStatement {
	private array $row = array();
	public function __construct( private MDI_MySQL_Protocol_PDO $pdo, private string $sql ) {}
	public function execute( ?array $params = null ): bool {
		$params ??= array();
		if ( str_starts_with( $this->sql, 'SELECT fence' ) ) { $this->row = isset( $this->pdo->fences[ $params[0] ] ) ? array( 'fence' => $this->pdo->fences[ $params[0] ]['fence'] ) : array(); }
		if ( str_starts_with( $this->sql, 'SELECT operation_id' ) ) { $this->row = $this->pdo->fences[ $params[0] ] ?? array(); }
		if ( str_starts_with( $this->sql, 'DELETE FROM' ) ) { unset( $this->pdo->fences[ $params[0] ] ); }
		if ( str_starts_with( $this->sql, 'INSERT INTO' ) ) { $this->pdo->fences[ $params[0] ] = array( 'operation_id' => $params[1], 'fence' => (int) $params[2] ); }
		return true;
	}
	public function fetchColumn( int $column = 0 ): mixed { unset( $column ); return $this->row['fence'] ?? false; }
	public function fetch( int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0 ): mixed { unset( $mode, $cursorOrientation, $cursorOffset ); return empty( $this->row ) ? false : $this->row; }
}

$root = tempnam( sys_get_temp_dir(), 'mdi-production-reconciliation-' );
if ( false === $root || ! unlink( $root ) || ! mkdir( $root, 0700 ) ) { throw new RuntimeException( 'Unable to create test root.' ); }
$canonical = $root . '/canonical'; $journal = $root . '/journal'; $fences = $root . '/fences';
mkdir( $canonical, 0700 );
register_shutdown_function( static fn() => mdi_production_remove( $root ) );
$key = str_repeat( 'production-adapter-key-', 2 );
$store = new WP_Markdown_Filesystem_Reconciliation_Operation_Store( $journal, $key, array( $canonical ) );
$coordinator = new WP_Markdown_Durable_Reconciliation_Coordinator( $store, 'process-a', 30 );

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

// Production enumeration returns original authenticated IDs, including planned intent.
$enumerated = $store->plan( array_merge( mdi_production_intent( $canonical, 'enumerated-original', 'update', array( 'canonical' => WP_Markdown_Reconciliation_Identity::exact( 'before' ) ), array( 'canonical' => WP_Markdown_Reconciliation_Identity::exact( 'after' ) ) ) ) );
$recoverable_ids = array_column( $reopened->recoverable( 1 ), 'id' );
mdi_production_check( array( $enumerated['id'] ) === $recoverable_ids, 'bounded production enumeration returns the original durable operation ID before deriving intent' );

// Persisted filesystem fencing rejects an older owner before replacement.
$stale = $claimed; $newer = $stale; $newer['id'] = str_repeat( 'b', 64 ); $newer['fence'] = $recovered['fence'] + 1;
$recovery_adapter->fence( $newer ); $stale_rejected = false;
try { $recovery_adapter->apply( $stale ); } catch ( WP_Markdown_Reconciliation_Store_Conflict ) { $stale_rejected = true; }
mdi_production_check( $stale_rejected && 'recovered' === file_get_contents( $path ), 'durable filesystem fence rejects stale replacement owner' );

// Recover a post-commit effect that was applied before its observation could complete.
$checkpoint_state = array( 'wordpress' => 'before', 'canonical' => 'before', 'index' => null );
$checkpoint_observer = static function () use ( &$checkpoint_state ): array { return $checkpoint_state; };
$checkpoint_applies = 0;
$checkpoint_apply = static function () use ( &$checkpoint_state, &$checkpoint_applies ): void { ++$checkpoint_applies; $checkpoint_state['canonical'] = 'after'; $checkpoint_state['index'] = array( 'post_id' => 42 ); };
$checkpoint_adapter = new WP_Markdown_Filesystem_Reconciliation_Adapter( $root . '/checkpoint-fences', $checkpoint_observer, $checkpoint_apply );
$checkpoint_intent = array( 'plan_id' => 'prepared-production-write', 'continuation' => array( 'post_id' => 42 ), 'canonical_root' => $canonical, 'resource' => array( 'type' => 'post', 'id' => '42' ), 'kind' => 'update', 'direction' => 'wordpress_to_canonical', 'before' => $checkpoint_state, 'checkpoint' => array( 'wordpress' => 'after', 'canonical' => 'before', 'index' => null ), 'after' => array( 'wordpress' => 'after', 'canonical' => 'after', 'index' => array( 'post_id' => 42 ) ) );
$checkpoint_intent['checkpoint'] = array_map( array( WP_Markdown_Reconciliation_Identity::class, 'exact' ), $checkpoint_intent['checkpoint'] );
$checkpoint_record = $coordinator->plan( $checkpoint_intent );
$checkpoint_operations = new WP_Markdown_Durable_Reconciliation_Operations( $store );
$checkpoint_prepared = $checkpoint_operations->prepare( $checkpoint_record['id'], 'post-commit-worker', 100, 1, $checkpoint_adapter );
$checkpoint_state['wordpress'] = 'after';
$checkpoint_adapter->apply( $checkpoint_prepared );
$checkpoint_recovered = $checkpoint_operations->recover( $checkpoint_record['id'], 'post-commit-recovery', 200, 30, $checkpoint_adapter );
mdi_production_check( 'completed' === $checkpoint_recovered['state'] && 1 === $checkpoint_applies && 'after' === $checkpoint_state['canonical'] && array( 'post_id' => 42 ) === $checkpoint_state['index'], 'interrupted post-commit recovery observes an applied canonical effect without replay' );

// An interrupted post-commit mismatch expires, is reclaimed, and fails closed without replay.
$mismatch_state = array( 'wordpress' => 'before', 'canonical' => 'before', 'index' => null );
$mismatch_observer = static function () use ( &$mismatch_state ): array { return $mismatch_state; };
$mismatch_applies = 0;
$mismatch_apply = static function () use ( &$mismatch_state, &$mismatch_applies ): void { ++$mismatch_applies; $mismatch_state['canonical'] = 'different'; $mismatch_state['index'] = array( 'post_id' => 42 ); };
$mismatch_adapter = new WP_Markdown_Filesystem_Reconciliation_Adapter( $root . '/checkpoint-fences', $mismatch_observer, $mismatch_apply );
$mismatch_intent = array( 'plan_id' => 'prepared-production-mismatch', 'continuation' => array( 'post_id' => 42 ), 'canonical_root' => $canonical, 'resource' => array( 'type' => 'post', 'id' => '42' ), 'kind' => 'update', 'direction' => 'wordpress_to_canonical', 'before' => $mismatch_state, 'checkpoint' => array( 'wordpress' => 'after', 'canonical' => 'before', 'index' => null ), 'after' => array( 'wordpress' => 'after', 'canonical' => 'after', 'index' => array( 'post_id' => 42 ) ) );
$mismatch_intent['checkpoint'] = array_map( array( WP_Markdown_Reconciliation_Identity::class, 'exact' ), $mismatch_intent['checkpoint'] );
$mismatch_record = $coordinator->plan( $mismatch_intent );
$mismatch_prepared = $checkpoint_operations->prepare( $mismatch_record['id'], 'post-commit-mismatch-worker', 300, 1, $mismatch_adapter );
$mismatch_state['wordpress'] = 'after';
$mismatch_adapter->apply( $mismatch_prepared );
$mismatch_rejected = false;
try { $checkpoint_operations->recover( $mismatch_record['id'], 'post-commit-mismatch-recovery', 400, 30, $mismatch_adapter ); } catch ( WP_Markdown_Reconciliation_Conflict $error ) { $mismatch_rejected = 'after_state_not_proven' === ( $error->conflict()['reason'] ?? null ); }
mdi_production_check( $mismatch_rejected && 'reconciliation_required' === $store->get( $mismatch_record['id'] )['state'] && 1 === $mismatch_applies && 'different' === $mismatch_state['canonical'], 'interrupted post-commit mismatch recovery fails closed without replay' );

// Recovery apply failures become terminal conflicts instead of remaining perpetually claimed.
$apply_failure_state = array( 'wordpress' => 'before', 'canonical' => 'before', 'index' => null );
$apply_failure_observer = static function () use ( &$apply_failure_state ): array { return $apply_failure_state; };
$apply_failure_adapter = new WP_Markdown_Filesystem_Reconciliation_Adapter( $root . '/checkpoint-fences', $apply_failure_observer, static function (): void { throw new RuntimeException( 'cannot apply' ); } );
$apply_failure_intent = array( 'plan_id' => 'prepared-apply-failure', 'continuation' => array( 'post_id' => 42 ), 'canonical_root' => $canonical, 'resource' => array( 'type' => 'post', 'id' => '42' ), 'kind' => 'update', 'direction' => 'wordpress_to_canonical', 'before' => $apply_failure_state, 'checkpoint' => array( 'wordpress' => 'after', 'canonical' => 'before', 'index' => null ), 'after' => array( 'wordpress' => 'after', 'canonical' => 'after', 'index' => array( 'post_id' => 42 ) ) );
$apply_failure_intent['checkpoint'] = array_map( array( WP_Markdown_Reconciliation_Identity::class, 'exact' ), $apply_failure_intent['checkpoint'] );
$apply_failure_record = $coordinator->plan( $apply_failure_intent );
$checkpoint_operations->prepare( $apply_failure_record['id'], 'apply-failure-worker', 500, 1, $apply_failure_adapter );
$apply_failure_state['wordpress'] = 'after';
$apply_failure_rejected = false;
try { $checkpoint_operations->recover( $apply_failure_record['id'], 'apply-failure-recovery', 502, 30, $apply_failure_adapter ); } catch ( WP_Markdown_Reconciliation_Conflict $error ) { $apply_failure_rejected = 'apply_outcome_not_proven' === ( $error->conflict()['reason'] ?? null ); }
mdi_production_check( $apply_failure_rejected && 'reconciliation_required' === $store->get( $apply_failure_record['id'] )['state'], 'recovery apply failure exits claimed state and requires explicit reconciliation' );

// Hostile pre-existing symlink state is rejected instead of traversed.
$hostile_target = $root . '/hostile-target'; mkdir( $hostile_target, 0700 );
$hostile_link = $root . '/hostile-fences';
$symlink_rejected = false;
if ( @symlink( $hostile_target, $hostile_link ) ) {
	try { new WP_Markdown_Filesystem_Reconciliation_Adapter( $hostile_link, static fn() => array(), static fn() => null ); } catch ( RuntimeException ) { $symlink_rejected = true; }
	mdi_production_check( $symlink_rejected, 'production filesystem fencing rejects a hostile pre-existing directory symlink' );
	unlink( $hostile_link );
}

// No live MySQL service is available here. This PDO double is protocol-faithful for
// the adapter's transaction and parameterized fence SQL contract; it is not an engine test.
$mysql_pdo = new MDI_MySQL_Protocol_PDO(); $mysql_state = 'before';
$mysql_adapter = new WP_Markdown_PDO_Reconciliation_Adapter( $mysql_pdo, static function () use ( &$mysql_state ): array { return array( 'wordpress' => $mysql_state ); }, static function () use ( &$mysql_state ): void { $mysql_state = 'after'; } );
$mysql_result = $coordinator->reconcile( mdi_production_intent( $canonical, 'mysql-protocol', 'update', array( 'wordpress' => 'before' ), array( 'wordpress' => 'after' ) ), $mysql_adapter );
$mysql_sql = implode( "\n", $mysql_pdo->sql );
mdi_production_check( 'completed' === $mysql_result['state'] && ! str_contains( $mysql_sql, 'CREATE TABLE' ) && str_contains( $mysql_sql, 'BEGIN' ) && str_contains( $mysql_sql, 'COMMIT' ) && str_contains( $mysql_sql, 'DELETE FROM' ) && str_contains( $mysql_sql, 'INSERT INTO' ) && str_contains( $mysql_sql, 'SELECT operation_id, fence FROM' ), 'MySQL-compatible PDO double verifies pre-provisioned transaction and parameterized fence protocol (no live MySQL engine available)' );

exit( empty( $failures ) ? 0 : 1 );
