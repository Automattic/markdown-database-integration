<?php
/** Production durable reconciliation adapter smoke test. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-reconciliation-adapters.php';
require_once __DIR__ . '/../inc/interface-wp-markdown-backend-operations.php';
require_once __DIR__ . '/../inc/class-wp-markdown-sqlite-operations.php';
require_once __DIR__ . '/../inc/class-wp-markdown-frontmatter-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';
require_once __DIR__ . '/../inc/class-wp-markdown-backend-adapter.php';
require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';

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
final class MDI_Production_Connection {
	public function __construct( private PDO $pdo ) {}
	public function get_pdo(): PDO { return $this->pdo; }
}
final class MDI_Production_Driver {
	private MDI_Production_Connection $connection;
	public function __construct( PDO $pdo ) { $this->connection = new MDI_Production_Connection( $pdo ); }
	public function get_connection(): MDI_Production_Connection { return $this->connection; }
	public function get_insert_id(): int { return (int) $this->connection->get_pdo()->lastInsertId(); }
	public function query( string $sql ): PDOStatement|false { return $this->connection->get_pdo()->query( $sql ); }
	public function query_cursor( string $sql ): PDOStatement|false { return $this->query( $sql ); }
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

// Production enumeration returns original authenticated IDs, including planned intent.
$enumerated = $store->plan( array_merge( mdi_production_intent( $canonical, 'enumerated-original', 'update', array( 'canonical' => WP_Markdown_Reconciliation_Identity::exact( 'before' ) ), array( 'canonical' => WP_Markdown_Reconciliation_Identity::exact( 'after' ) ) ) ) );
$recoverable_ids = array_column( $reopened->recoverable( 1 ), 'id' );
mdi_production_check( array( $enumerated['id'] ) === $recoverable_ids, 'bounded production enumeration returns the original durable operation ID before deriving intent' );

// Persisted filesystem fencing rejects an older owner before replacement.
$stale = $claimed; $newer = $stale; $newer['id'] = str_repeat( 'b', 64 ); $newer['fence'] = $recovered['fence'] + 1;
$recovery_adapter->fence( $newer ); $stale_rejected = false;
try { $recovery_adapter->apply( $stale ); } catch ( WP_Markdown_Reconciliation_Store_Conflict ) { $stale_rejected = true; }
mdi_production_check( $stale_rejected && 'recovered' === file_get_contents( $path ), 'durable filesystem fence rejects stale replacement owner' );

// Real SQLite fence ownership and mutation share one transaction boundary.
$database = $root . '/wordpress.sqlite'; $pdo = new PDO( 'sqlite:' . $database ); $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT NOT NULL, post_content TEXT NOT NULL DEFAULT \'\')' ); $pdo->exec( "INSERT INTO wp_posts (ID, post_title) VALUES (42, 'before')" );
$pdo->exec( 'CREATE TABLE wp_postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$pdo->exec( 'CREATE TABLE wp_terms (term_id INTEGER, slug TEXT)' );
$pdo->exec( 'CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER, term_id INTEGER, taxonomy TEXT)' );
$pdo->exec( 'CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER)' );
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

// Drive the actual production persistence, operations, and loader entry points.
$actual_driver = new MDI_Production_Driver( $pdo );
$actual_operations = new WP_Markdown_SQLite_Operations( $actual_driver, 'wp_' );
$actual_operations->ensure_reconciliation_state();
$actual_storage = new WP_Markdown_Storage( $canonical );
$actual_persistence = new WP_Markdown_Canonical_Persistence( $canonical, $actual_storage, $actual_operations, 'wp_', $canonical );
$actual_persistence->persist_mutation( array( 'key' => 'wp_posts:42', 'resource' => 'wp_posts:42', 'operation' => 'UPDATE', 'table' => 'wp_posts', 'context' => array( 'resource_ids' => array( '42' ) ) ) );
$actual_persistence->flush_dirty( true );
$actual_loader = new WP_Markdown_Loader( $canonical, $actual_operations, $actual_storage, 'wp_', $canonical );
$actual_loader->prepare_existing_cache();
$actual_index = $actual_operations->file_index_receipt( 42 );
mdi_production_check( null !== $actual_storage->read_post( 42 ) && array( 'post_id' => 42 ) === $actual_index, 'actual canonical persistence, SQLite operations, and loader entry points publish matching file and index state' );

// A claimed WordPress commit checkpoint can continue after commit under its original ID.
$checkpoint_state = array( 'wordpress' => 'before', 'canonical' => 'before', 'index' => null );
$checkpoint_observer = static function () use ( &$checkpoint_state ): array { return $checkpoint_state; };
$checkpoint_apply = static function () use ( &$checkpoint_state ): void { $checkpoint_state['canonical'] = 'after'; $checkpoint_state['index'] = array( 'post_id' => 42 ); };
$checkpoint_adapter = new WP_Markdown_Filesystem_Reconciliation_Adapter( $fences, $checkpoint_observer, $checkpoint_apply );
$prepared = $coordinator->prepare( array( 'plan_id' => 'prepared-production-write', 'continuation' => array( 'post_id' => 42 ), 'canonical_root' => $canonical, 'resource' => array( 'type' => 'post', 'id' => '42' ), 'kind' => 'update', 'direction' => 'wordpress_to_canonical', 'before' => $checkpoint_state, 'checkpoint' => array( 'wordpress' => 'after', 'canonical' => 'before', 'index' => null ), 'after' => array( 'wordpress' => 'after', 'canonical' => 'after', 'index' => array( 'post_id' => 42 ) ) ), $checkpoint_adapter );
$checkpoint_state['wordpress'] = 'after';
$continued = $coordinator->continue_prepared( $prepared['id'], $checkpoint_adapter );
mdi_production_check( 'completed' === $continued['state'] && 'after' === $checkpoint_state['canonical'] && array( 'post_id' => 42 ) === $checkpoint_state['index'], 'prepared production write continues canonical and index effects after the WordPress commit checkpoint' );

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
mdi_production_check( 'completed' === $mysql_result['state'] && str_contains( $mysql_sql, 'BEGIN' ) && str_contains( $mysql_sql, 'COMMIT' ) && str_contains( $mysql_sql, 'DELETE FROM' ) && str_contains( $mysql_sql, 'INSERT INTO' ) && str_contains( $mysql_sql, 'SELECT operation_id, fence FROM' ), 'MySQL-compatible PDO double verifies transaction and parameterized fence protocol (no live MySQL engine available)' );

exit( empty( $failures ) ? 0 : 1 );
