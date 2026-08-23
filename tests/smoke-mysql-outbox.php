<?php
/** Durable mysql-full outbox state-machine checks. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-mysql-outbox.php';

class MDI_Test_MySQL_Outbox extends WP_Markdown_MySQL_Outbox {
	/** @var array<int,array<string,mixed>> */
	public array $records = array();
	public int $now = 1000;
	private int $next_id = 1;
	private int $next_lease = 1;

	protected function install_schema(): void { $this->ready = true; }
	protected function persist_record( array $record, string $state ): void {
		foreach ( $this->records as $existing ) { if ( $existing['event_id'] === $record['event_id'] ) { return; } }
		$this->records[] = array_merge( $record, array( 'id' => $this->next_id++, 'state' => $state, 'attempts' => 0, 'reclaims' => 0, 'failures' => 0, 'available_at' => $this->now, 'leased_until' => null, 'lease_token' => null, 'worker_token' => null, 'acknowledgement_token' => null, 'last_error' => null ) );
	}
	protected function claim_records( string $worker_token, int $limit, int $lease_seconds ): array {
		$active = array_values( array_filter( $this->records, fn( array $row ): bool => 'leased' === $row['state'] && $worker_token === $row['worker_token'] && $row['leased_until'] > $this->now ) );
		if ( $active ) { return array_slice( $active, 0, $limit ); }
		foreach ( $this->records as $head ) { if ( 'acked' !== $head['state'] && 'diagnostic' !== $head['state'] ) { if ( 'leased' === $head['state'] && $head['leased_until'] > $this->now ) { return array(); } break; } }
		$lease_token = 'lease-' . $this->next_lease++;
		foreach ( $this->records as &$row ) {
			$eligible = in_array( $row['state'], array( 'pending', 'failed' ), true ) && $row['available_at'] <= $this->now;
			$reclaim = 'leased' === $row['state'] && $row['leased_until'] <= $this->now;
			if ( ! $eligible && ! $reclaim ) { continue; }
			if ( $reclaim ) { ++$row['reclaims']; }
			$row['state'] = 'leased'; $row['lease_token'] = $lease_token; $row['worker_token'] = $worker_token; $row['leased_until'] = $this->now + $lease_seconds; ++$row['attempts'];
			return array( $row );
		}
		return array();
	}
	public function cache_semantic_envelope( int $id, string $worker_token, string $lease_token, array $envelope ): bool { foreach ( $this->records as &$row ) { if ( $id === $row['id'] && $worker_token === $row['worker_token'] && $lease_token === $row['lease_token'] ) { $row['semantic_envelope'] = $envelope; return true; } } return false; }
	protected function acknowledge_record( int $id, string $worker_token, string $lease_token ): bool {
		foreach ( $this->records as &$row ) {
			if ( $id !== $row['id'] ) { continue; }
			if ( 'acked' === $row['state'] && $lease_token === $row['acknowledgement_token'] ) { return true; }
			if ( 'leased' !== $row['state'] || $worker_token !== $row['worker_token'] || $lease_token !== $row['lease_token'] || $row['leased_until'] <= $this->now ) { return false; }
			$row['state'] = 'acked'; $row['acknowledgement_token'] = $lease_token; $row['leased_until'] = null; $row['lease_token'] = null; $row['worker_token'] = null; return true;
		}
		return false;
	}
	protected function fail_record( int $id, string $worker_token, string $lease_token, string $error, int $retry_delay_seconds ): bool {
		foreach ( $this->records as &$row ) {
			if ( $id === $row['id'] && 'leased' === $row['state'] && $worker_token === $row['worker_token'] && $lease_token === $row['lease_token'] && $row['leased_until'] > $this->now ) {
				$row['state'] = 'failed'; ++$row['failures']; $row['last_error'] = $error; $row['available_at'] = $this->now + $retry_delay_seconds; $row['lease_token'] = null; $row['worker_token'] = null; $row['leased_until'] = null; return true;
			}
		}
		return false;
	}
	protected function read_diagnostics(): array {
		$counts = array( 'pending' => 0, 'leased' => 0, 'failed' => 0, 'unsupported_boundaries' => 0, 'attempts' => 0, 'reclaims' => 0, 'failures' => 0, 'oldest_record_age_seconds' => 0 );
		foreach ( $this->records as $row ) { if ( isset( $counts[ $row['state'] ] ) ) { ++$counts[ $row['state'] ]; } $counts['unsupported_boundaries'] += 'diagnostic' === $row['state'] ? 1 : 0; $counts['attempts'] += $row['attempts']; $counts['reclaims'] += $row['reclaims']; $counts['failures'] += $row['failures']; }
		return array( 'backlog' => $counts, 'last_failure' => null, 'unsupported_boundary_sample' => null );
	}
}

class MDI_Test_MySQL_Connection {
	public array $queries = array();
	public string $error = '';
	public function query( string $query ): bool { $this->queries[] = $query; return true; }
}

$failures = array();
function mdi_outbox_assert( bool $condition, string $message ): void { global $failures; if ( ! $condition ) { $failures[] = $message; } }
function mdi_outbox_observation( string $operation, string $table, bool $active = false, array $savepoints = array() ): array {
	return array( 'kind' => 'table', 'operation' => $operation, 'query' => "{$operation} {$table}", 'table' => $table, 'database' => 'wordpress', 'blog_id' => 2, 'table_prefix' => 'wp_2_', 'base_prefix' => 'wp_', 'insert_id' => 7, 'rows_affected' => 1, 'num_rows' => 0, 'transaction' => array( 'active' => $active, 'autocommit' => ! $active, 'savepoints' => $savepoints ) );
}

$outbox = new MDI_Test_MySQL_Outbox( new stdClass(), 'wp_mdi_mysql_outbox' );
$outbox( mdi_outbox_observation( 'INSERT', 'wp_2_posts' ) );
mdi_outbox_assert( 1 === count( $outbox->records ) && 'pending' === $outbox->records[0]['state'], 'autocommit mutation persists immediately' );
$payload = $outbox->records[0]['payload'];
mdi_outbox_assert( 2 === $payload['version'] && 'wordpress' === $payload['database'] && 2 === $payload['scope']['blog_id'] && array( 'wp_2_posts' ) === $payload['mutation']['tables'], 'payload retains versioned database and multisite scope' );
mdi_outbox_assert( hash( 'sha256', $outbox->records[0]['json'] ) === $outbox->records[0]['sha256'], 'payload identity hashes the normalized JSON bytes' );

$planned = new MDI_Test_MySQL_Outbox( new stdClass(), 'wp_mdi_mysql_outbox', static fn( array $record ): array => array( array( 'event_id' => $record['event_id'], 'kind' => 'table', 'operation' => 'UPDATE', 'table' => 'wp_2_posts' ) ) );
$planned( mdi_outbox_observation( 'UPDATE', 'wp_2_posts' ) );
mdi_outbox_assert( $planned->records[0]['event_id'] === ( $planned->records[0]['semantic_envelope']['event_id'] ?? null ) && 1 === count( $planned->records[0]['semantic_envelope']['intents'] ?? array() ), 'semantic evidence is captured at the successful mutation boundary' );
$failed_plan = new MDI_Test_MySQL_Outbox( new stdClass(), 'wp_mdi_mysql_outbox', static function (): array { throw new RuntimeException( 'planner unavailable' ); } );
try { $failed_plan( mdi_outbox_observation( 'UPDATE', 'wp_2_posts' ) ); } catch ( RuntimeException $error ) {}
mdi_outbox_assert( 1 === count( $failed_plan->records ) && ! isset( $failed_plan->records[0]['semantic_envelope'] ), 'planning failure preserves the raw durable outbox record for retry' );

$txbox = new MDI_Test_MySQL_Outbox( new stdClass(), 'wp_mdi_mysql_outbox' );
$txbox->after_control( array( 'action' => 'begin' ) );
$txbox( mdi_outbox_observation( 'UPDATE', 'wp_2_posts', true ) );
$txbox->after_control( array( 'action' => 'savepoint', 'savepoint' => 'before_meta' ) );
$txbox( mdi_outbox_observation( 'DELETE', 'wp_2_postmeta', true, array( 'before_meta' ) ) );
mdi_outbox_assert( array() === $txbox->claim( 'pre-commit' ), 'transaction observations are not claimable before commit' );
$txbox->after_control( array( 'action' => 'rollback_to', 'savepoint' => 'before_meta' ) );
$txbox->before_query( array( 'action' => 'commit' ), null, 'COMMIT', array( 'active' => true ) );
$txbox->after_control( array( 'action' => 'commit' ) );
mdi_outbox_assert( 1 === count( $txbox->records ) && 'UPDATE' === $txbox->records[0]['payload']['mutation']['operation'], 'commit persists retained observations in order and savepoint rollback discards later work' );

$txbox->after_control( array( 'action' => 'begin' ) );
$txbox( mdi_outbox_observation( 'INSERT', 'wp_2_options', true ) );
$txbox->after_control( array( 'action' => 'rollback' ) );
mdi_outbox_assert( 1 === count( $txbox->records ), 'full rollback discards pending observations' );

$autocommitbox = new MDI_Test_MySQL_Outbox( new stdClass(), 'wp_mdi_mysql_outbox' );
$autocommitbox( mdi_outbox_observation( 'INSERT', 'wp_2_posts', true ) );
$autocommitbox->after_control( array( 'action' => 'autocommit_0' ) );
$autocommitbox->before_query( array( 'action' => 'commit' ), null, 'COMMIT', array( 'active' => true ) );
$autocommitbox->after_control( array( 'action' => 'commit' ) );
mdi_outbox_assert( 1 === count( $autocommitbox->records ), 'reissuing autocommit-off does not discard an active transaction' );

$ddl_connection = new MDI_Test_MySQL_Connection();
$autocommitddlbox = new MDI_Test_MySQL_Outbox( $ddl_connection, 'wp_mdi_mysql_outbox' );
$ddl_observation = mdi_outbox_observation( 'CREATE', 'wp_2_new', false );
$ddl_observation['transaction']['autocommit'] = false;
$ddl_observation['commit_outbox'] = true;
$autocommitddlbox( $ddl_observation );
mdi_outbox_assert( array( 'COMMIT' ) === $ddl_connection->queries, 'autocommit-off implicit DDL explicitly commits its new outbox transaction' );

$implicitbox = new MDI_Test_MySQL_Outbox( new stdClass(), 'wp_mdi_mysql_outbox' );
$implicitbox( mdi_outbox_observation( 'UPDATE', 'wp_2_posts', true ) );
$implicitbox->before_unsupported_boundary( 'RENAME TABLE wp_2_posts TO wp_2_posts_old', array( 'active' => true ) );
$implicitbox->after_implicit_commit();
$implicitbox->after_control( array( 'action' => 'rollback' ) );
mdi_outbox_assert( 1 === count( $implicitbox->records ), 'unsupported implicit commit retains prior transaction observations after a later rollback' );

$ddlbox = new MDI_Test_MySQL_Outbox( new stdClass(), 'wp_mdi_mysql_outbox' );
$ddlbox->after_control( array( 'action' => 'begin' ) );
$ddlbox( mdi_outbox_observation( 'UPDATE', 'wp_2_posts', true ) );
$ddlbox->before_query( null, array( 'type' => 'DDL', 'op' => 'CREATE', 'table' => 'wp_2_new' ), 'CREATE TABLE wp_2_new (id int)', array( 'active' => true ) );
$ddlbox->after_failure( null, array( 'type' => 'DDL', 'op' => 'CREATE', 'table' => 'wp_2_new' ), 'CREATE TABLE wp_2_new (id int)', array( 'active' => true ) );
mdi_outbox_assert( 1 === count( $ddlbox->records ) && 'UPDATE' === $ddlbox->records[0]['payload']['mutation']['operation'], 'failed implicit-commit DDL retains prior transaction mutations but emits no DDL mutation' );

$commitbox = new MDI_Test_MySQL_Outbox( new stdClass(), 'wp_mdi_mysql_outbox' );
$commitbox->after_control( array( 'action' => 'begin' ) );
$commitbox( mdi_outbox_observation( 'UPDATE', 'wp_2_posts', true ) );
$commitbox->before_query( array( 'action' => 'commit' ), null, 'COMMIT', array( 'active' => true ) );
$commitbox->after_failure( array( 'action' => 'commit' ), null, 'COMMIT', array( 'active' => true ), true );
$commitbox->before_query( array( 'action' => 'commit' ), null, 'COMMIT', array( 'active' => true ) );
$commitbox->after_control( array( 'action' => 'commit' ) );
mdi_outbox_assert( 1 === count( $commitbox->records ) && 1 === $commitbox->diagnostics()['deferred_unsupported_boundaries'], 'failed commit reflushes the stable event id without duplicate handoff' );

$claimbox = new MDI_Test_MySQL_Outbox( new stdClass(), 'wp_mdi_mysql_outbox' );
$claimbox( mdi_outbox_observation( 'INSERT', 'wp_2_posts' ) );
$first = $claimbox->claim( 'worker-a', 1, 10 );
mdi_outbox_assert( 1 === count( $first ) && 1 === $first[0]['attempts'], 'claim is bounded and increments attempts' );
mdi_outbox_assert( array() === $claimbox->claim( 'worker-b', 1, 10 ), 'a second worker cannot bypass a leased global head-of-line event' );
mdi_outbox_assert( $first === $claimbox->claim( 'worker-a', 1, 10 ), 'active claim token is idempotent' );
mdi_outbox_assert( $claimbox->fail( $first[0]['id'], 'worker-a', $first[0]['lease_token'], 'temporary', 5 ) && array() === $claimbox->claim( 'worker-b', 1, 10 ), 'failure records a delayed retry' );
$claimbox->now += 5;
$retry = $claimbox->claim( 'worker-b', 1, 10 );
mdi_outbox_assert( 2 === $retry[0]['attempts'] && 1 === $retry[0]['failures'], 'failed record becomes retryable after its delay' );
mdi_outbox_assert( ! $claimbox->acknowledge( $retry[0]['id'], 'worker-a', $first[0]['lease_token'] ) && $claimbox->acknowledge( $retry[0]['id'], 'worker-b', $retry[0]['lease_token'] ) && $claimbox->acknowledge( $retry[0]['id'], 'worker-b', $retry[0]['lease_token'] ), 'acknowledgement rejects stale owners and is idempotent for the lease generation' );

$claimbox( mdi_outbox_observation( 'UPDATE', 'wp_2_posts' ) );
$leased = $claimbox->claim( 'worker-c', 1, 2 );
$claimbox->now += 2;
mdi_outbox_assert( ! $claimbox->acknowledge( $leased[0]['id'], 'worker-c', $leased[0]['lease_token'] ) && ! $claimbox->fail( $leased[0]['id'], 'worker-c', $leased[0]['lease_token'], 'too late' ), 'expired lease owner cannot acknowledge or fail work' );
$reclaimed = $claimbox->claim( 'worker-c', 1, 2 );
mdi_outbox_assert( $leased[0]['id'] === $reclaimed[0]['id'] && 1 === $reclaimed[0]['reclaims'] && ! $claimbox->acknowledge( $reclaimed[0]['id'], 'worker-c', $leased[0]['lease_token'] ) && $claimbox->acknowledge( $reclaimed[0]['id'], 'worker-c', $reclaimed[0]['lease_token'] ), 'expired same-worker lease is recoverable and fenced by generation' );
$claimbox->record_unsupported_boundary( 'LOCK TABLES wp_posts WRITE', array( 'active' => false ), 'unsupported_transaction_boundary' );
mdi_outbox_assert( 1 === $claimbox->diagnostics()['backlog']['unsupported_boundaries'], 'health diagnostics count unsupported boundaries' );

if ( $failures ) { foreach ( $failures as $failure ) { echo "FAIL: {$failure}\n"; } exit( 1 ); }
echo "All mysql-full outbox smoke checks passed.\n";
