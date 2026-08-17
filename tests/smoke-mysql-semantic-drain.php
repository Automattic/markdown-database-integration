<?php
/** Standalone regression checks for database-neutral mutation impact semantics. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-mutation-impact.php';
require_once __DIR__ . '/../inc/class-wp-markdown-mysql-impact-adapter.php';
require_once __DIR__ . '/../inc/class-wp-markdown-mysql-outbox.php';
require_once __DIR__ . '/../inc/class-wp-markdown-mysql-semantic-drain.php';
require_once __DIR__ . '/../inc/class-wp-markdown-sql-classifier.php';

$failures = array();
function mdi_semantic_assert( bool $condition, string $message ): void { global $failures; if ( ! $condition ) { $failures[] = $message; } }
function mdi_semantic_plan( string $query, array $operation, string $table = 'wp_2_posts', int $insert_id = 0 ): array { return WP_Markdown_Mutation_Impact::for_query( $query, $operation, $table, $insert_id ); }

$dml = array( 'type' => 'DML', 'op' => 'UPDATE' );
mdi_semantic_assert( array( '42' ) === mdi_semantic_plan( 'UPDATE wp_2_posts SET post_title="x" WHERE ID=42', $dml )[0]['resource_ids'], 'exact predicates retain their resource identity' );
mdi_semantic_assert( array( 'post_status' ) === mdi_semantic_plan( 'UPDATE wp_2_posts SET post_status="draft" WHERE ID=42', $dml )[0]['scope']['assigned_columns'], 'updates retain changed-column evidence' );
mdi_semantic_assert( array( '*' ) === mdi_semantic_plan( 'UPDATE wp_2_posts SET post_status="draft" WHERE ID=1 OR ID=2', $dml )[0]['resource_ids'], 'ambiguous predicates fail safe to whole-resource scope' );
mdi_semantic_assert( array( '*' ) === mdi_semantic_plan( 'UPDATE wp_2_posts SET post_status="draft" WHERE ID=1 AND ID=2', $dml )[0]['resource_ids'], 'contradictory repeated identities fail safe to whole-resource scope' );
mdi_semantic_assert( array( '*' ) === mdi_semantic_plan( 'INSERT INTO wp_2_posts (ID,post_title) VALUES (1,"a"),(2,"b")', array( 'type' => 'DML', 'op' => 'INSERT' ) )[0]['resource_ids'], 'multi-row inserts fail safe to whole-resource scope' );
mdi_semantic_assert( array( 'siteurl' ) === mdi_semantic_plan( "INSERT INTO wp_2_options (option_name,option_value) VALUES ('siteurl','x')", array( 'type' => 'DML', 'op' => 'INSERT' ), 'wp_2_options' )[0]['resource_ids'], 'generated option identities remain exact' );
mdi_semantic_assert( array( '17' ) === mdi_semantic_plan( 'INSERT INTO wp_2_posts (post_title) VALUES ("x")', array( 'type' => 'DML', 'op' => 'INSERT' ), 'wp_2_posts', 17 )[0]['resource_ids'], 'insert result identity is retained when SQL provides none' );
mdi_semantic_assert( array( '*' ) === mdi_semantic_plan( 'DELETE FROM wp_2_posts', array( 'type' => 'DML', 'op' => 'DELETE' ) )[0]['resource_ids'], 'unqualified delete remains conservatively scoped' );
mdi_semantic_assert( array( 'comment_id' => array( '9' ) ) === mdi_semantic_plan( 'UPDATE wp_2_comments SET comment_content="x" WHERE comment_ID=9', $dml, 'wp_2_comments' )[0]['scope']['resource_ids_by_column'], 'comments retain their exact comment_ID identity' );
mdi_semantic_assert( array( 'id' => array( '7' ) ) === mdi_semantic_plan( 'UPDATE wp_users SET user_login="x" WHERE ID=7', $dml, 'wp_users' )[0]['scope']['resource_ids_by_column'], 'users retain their exact ID identity' );
mdi_semantic_assert( array( 'event_id' => array( 'evt-7' ) ) === mdi_semantic_plan( "UPDATE wp_2_events SET payload='x' WHERE event_id='evt-7'", $dml, 'wp_2_events' )[0]['scope']['resource_ids_by_column'], 'plugin keys retain their exact identity column and value' );
mdi_semantic_assert( array( 'wp_2_a', 'wp_2_b' ) === ( WP_Markdown_SQL_Classifier::mutation( 'DROP TABLE IF EXISTS `wp_2_a`, wp_2_b' )['tables'] ?? array() ), 'multi-table DROP captures every validated operand' );
mdi_semantic_assert( array( 'wp_2_a', 'wp_2_b' ) === ( WP_Markdown_SQL_Classifier::mutation( 'DROP TABLE IF EXISTS `wp_2_a`, wp_2_b; /* retained comment */' )['tables'] ?? array() ), 'multi-table DROP accepts only safe trailing comments' );

class MDI_Semantic_Statement { public function bind_param( string $types, &...$values ): bool { return true; } public function execute(): bool { return true; } public function get_result(): object { return new class() { public function fetch_all( int $mode ): array { return array( array( 'event_id' => 'evt-7' ) ); } public function free(): void {} }; } public function close(): void {} }
class MDI_Semantic_Connection { public function prepare( string $sql ): object { return new MDI_Semantic_Statement(); } }
$adapter = new WP_Markdown_MySQL_Impact_Adapter( new MDI_Semantic_Connection() );
$record = array( 'event_id' => 'event-1', 'payload' => array( 'database' => 'wordpress', 'scope' => array( 'blog_id' => 2, 'table_prefix' => 'wp_2_', 'base_prefix' => 'wp_' ), 'mutation' => array( 'kind' => 'table', 'operation' => 'UPDATE', 'tables' => array( 'wp_2_events' ), 'sql' => "UPDATE wp_2_events SET payload='x' WHERE event_id='evt-7'" ), 'result' => array( 'insert_id' => 0 ) ) );
$intent = $adapter->intents( $record )[0];
mdi_semantic_assert( 'event_id' === $intent['scope']['identity']['column'] && array( array( 'event_id' => 'evt-7' ) ) === $intent['current_rows'], 'production adapter performs parameterized exact generic-key lookup' );
$rejected = false; try { $record['payload']['mutation']['tables'] = array( 'wp_3_posts' ); $adapter->intents( $record ); } catch ( RuntimeException $error ) { $rejected = true; }
mdi_semantic_assert( $rejected, 'another blog prefix is rejected even though it shares base_prefix' );
$record['payload']['mutation']['tables'] = array( 'wp_users' );
mdi_semantic_assert( 'wp_users' === $adapter->intents( $record )[0]['table'], 'explicit WordPress global tables are permitted through base_prefix' );
$record['payload']['scope']['table_prefix'] = 'wp_';
$record['payload']['scope']['base_prefix'] = 'wp_';
$record['payload']['mutation']['tables'] = array( 'wp_2_posts' );
$rejected = false; try { $adapter->intents( $record ); } catch ( RuntimeException $error ) { $rejected = true; }
mdi_semantic_assert( $rejected, 'base-prefix current site scope rejects child blog namespaces' );
$record['payload']['mutation']['tables'] = array( 'wp_posts' );
mdi_semantic_assert( 'wp_posts' === $adapter->intents( $record )[0]['table'], 'base-prefix current site scope permits its own tables' );
$record['payload']['mutation']['tables'] = array( 'wp_users' );
mdi_semantic_assert( 'wp_users' === $adapter->intents( $record )[0]['table'], 'base-prefix current site scope permits explicit global tables' );
$record['payload']['result']['rows_affected'] = 0;
mdi_semantic_assert( array() === $adapter->intents( $record ), 'proven no-op DML emits no semantic impact' );

class MDI_Semantic_Drain_Outbox extends WP_Markdown_MySQL_Outbox {
	public array $records = array();
	public function __construct() {}
	public function is_ready(): bool { return true; }
	public function claim( string $worker_token, int $limit = 100, int $lease_seconds = 60 ): array { foreach ( $this->records as &$record ) { if ( 'pending' === $record['state'] ) { $record['state'] = 'leased'; $record['worker_token'] = $worker_token; $record['lease_token'] = 'lease-' . $record['id']; return array( $record ); } } return array(); }
	public function cache_semantic_envelope( int $id, string $worker_token, string $lease_token, array $envelope ): bool { foreach ( $this->records as &$record ) { if ( $id === $record['id'] && $worker_token === $record['worker_token'] && $lease_token === $record['lease_token'] ) { $record['semantic_envelope'] = $envelope; return true; } } return false; }
	public function acknowledge( int $id, string $worker_token, string $lease_token ): bool { foreach ( $this->records as &$record ) { if ( $id === $record['id'] && $worker_token === $record['worker_token'] && $lease_token === $record['lease_token'] ) { $record['state'] = 'acked'; return true; } } return false; }
	public function fail( int $id, string $worker_token, string $lease_token, string $error, int $retry_delay_seconds = 0 ): bool { foreach ( $this->records as &$record ) { if ( $id === $record['id'] && $worker_token === $record['worker_token'] && $lease_token === $record['lease_token'] ) { $record['state'] = 'failed'; $record['last_error'] = $error; return true; } } return false; }
}
$drain_outbox = new MDI_Semantic_Drain_Outbox();
foreach ( array( 1, 2, 3 ) as $id ) { $drain_outbox->records[] = array( 'id' => $id, 'event_id' => 'event-' . $id, 'payload_sha256' => '', 'semantic_envelope' => null, 'state' => 'pending', 'payload' => array( 'database' => 'wordpress', 'scope' => array( 'blog_id' => 1, 'table_prefix' => 'wp_', 'base_prefix' => 'wp_' ), 'mutation' => array( 'kind' => 'table', 'operation' => 'UPDATE', 'tables' => array( 'wp_posts' ), 'sql' => "UPDATE wp_posts SET post_title='x' WHERE ID={$id}" ), 'result' => array( 'rows_affected' => 1 ) ) ); }
$seen = array();
$drain = new WP_Markdown_MySQL_Semantic_Drain( $drain_outbox, $adapter );
$drained = $drain->drain( 'semantic-worker', static function ( array $envelope ) use ( &$seen ): bool { $seen[] = $envelope['outbox_id']; return true; }, 2 );
mdi_semantic_assert( 2 === $drained['acknowledged'] && array( 1, 2 ) === $seen && 'pending' === $drain_outbox->records[2]['state'], 'semantic drain processes sequential global heads up to its limit' );
$drain_outbox->records[2]['payload']['mutation']['tables'] = array( 'wp_2_posts' );
$failed_drain = $drain->drain( 'semantic-worker', static fn( array $envelope ): bool => true, 2 );
$failure = json_decode( (string) $drain_outbox->records[2]['last_error'], true );
mdi_semantic_assert( 1 === $failed_drain['failed'] && 'failed' === $drain_outbox->records[2]['state'] && 'planning' === ( $failure['stage'] ?? null ) && isset( $failure['event_id'], $failure['message'] ), 'planning failures are bounded structured diagnostics persisted through the outbox' );

if ( $failures ) { foreach ( $failures as $failure ) { echo "FAIL: {$failure}\n"; } exit( 1 ); }
echo "All semantic impact smoke checks passed.\n";
