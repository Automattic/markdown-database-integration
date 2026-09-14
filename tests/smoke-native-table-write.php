<?php
/** Generic UPDATE and DELETE proof over a persisted snapshot table. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

class wpdb {
	public string $prefix = '';
	public string $options = '';
	public bool $ready = false;
	public int $num_queries = 0;
	public int $num_rows = 0;
	public int $rows_affected = 0;
	public int $insert_id = 0;
	public string $last_error = '';
	public ?string $last_query = null;
	public string $func_call = '';
	public array $last_result = array();
	protected array $col_info = array();
	protected bool $check_current_query = true;
	protected bool $result = false;

	public function set_prefix( string $prefix ): string {
		$this->prefix  = $prefix;
		$this->options = $prefix . 'options';
		return $prefix;
	}

	public function flush(): void {
		$this->last_result = array();
		$this->col_info    = array();
		$this->last_error  = '';
		$this->num_rows    = 0;
	}

	public function add_placeholder_escape( string $value ): string {
		return $value;
	}

	public function remove_placeholder_escape( string $value ): string {
		return $value;
	}
}
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-wpdb.php';

$root = sys_get_temp_dir() . '/mdi-native-table-write-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the generic table write fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$created = $runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_agents (id BIGINT NOT NULL AUTO_INCREMENT, instance_key VARCHAR(60) NULL, label VARCHAR(60) NULL, PRIMARY KEY (id))',
		'wp_'
	)
);
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_jobs (id BIGINT NOT NULL AUTO_INCREMENT, label VARCHAR(60) NULL, PRIMARY KEY (id))',
		'wp_'
	)
);
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_cleanup_agents (id BIGINT NOT NULL AUTO_INCREMENT, label VARCHAR(60) NULL, PRIMARY KEY (id))',
		'wp_'
	)
);
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_unique_jobs (id BIGINT NOT NULL AUTO_INCREMENT, scope VARCHAR(20) NULL, token VARCHAR(20) NULL, PRIMARY KEY (id), UNIQUE KEY scoped_token (scope, token(3)))',
		'wp_'
	)
);
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_corrupt_jobs (id BIGINT NOT NULL AUTO_INCREMENT, token VARCHAR(20) NOT NULL, state VARCHAR(20) NOT NULL, PRIMARY KEY (id), UNIQUE KEY token (token))',
		'wp_'
	)
);
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_proof_jobs (id BIGINT NOT NULL AUTO_INCREMENT, token VARCHAR(20) NOT NULL, state VARCHAR(20) NOT NULL, PRIMARY KEY (id), UNIQUE KEY token (token))',
		'wp_'
	)
);
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_initial_corrupt_jobs (id BIGINT NOT NULL AUTO_INCREMENT, token VARCHAR(20) NOT NULL, state VARCHAR(20) NOT NULL, PRIMARY KEY (id), UNIQUE KEY token (token))',
		'wp_'
	)
);
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_jobs (label) VALUES ('ready')", 'wp_' ) );
foreach ( array(
	"INSERT INTO wp_unique_jobs (scope, token) VALUES (NULL, 'abc1')",
	"INSERT INTO wp_unique_jobs (scope, token) VALUES (NULL, 'abc2')",
	"INSERT INTO wp_unique_jobs (scope, token) VALUES ('one', 'abc1')",
	"INSERT INTO wp_unique_jobs (scope, token) VALUES ('one', 'def1')",
) as $insert ) {
	$runtime->execute( new WP_Markdown_Query_Request( $insert, 'wp_' ) );
}

foreach ( array(
	"INSERT INTO wp_agents (instance_key, label) VALUES (NULL, 'first')",
	"INSERT INTO wp_agents (instance_key, label) VALUES ('', 'second')",
	"INSERT INTO wp_agents (instance_key, label) VALUES ('keep', 'third')",
) as $insert ) {
	$runtime->execute( new WP_Markdown_Query_Request( $insert, 'wp_' ) );
}
foreach ( array(
	"INSERT INTO wp_cleanup_agents (label) VALUES ('first')",
	"INSERT INTO wp_cleanup_agents (label) VALUES ('admin')",
	"INSERT INTO wp_cleanup_agents (label) VALUES ('third')",
) as $insert ) {
	$runtime->execute( new WP_Markdown_Query_Request( $insert, 'wp_' ) );
}
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_corrupt_jobs (token, state) VALUES ('first', 'pending')", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_corrupt_jobs (token, state) VALUES ('second', 'pending')", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_proof_jobs (token, state) VALUES ('first', 'pending')", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_initial_corrupt_jobs (token, state) VALUES ('first', 'pending')", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_initial_corrupt_jobs (token, state) VALUES ('second', 'pending')", 'wp_' ) );

/** @return array<int,array<string,mixed>> */
function table_rows( string $root, string $table = 'agents' ): array {
	$rows = json_decode( (string) file_get_contents( $root . '/_tables/' . $table . '.json' ), true );
	return is_array( $rows ) ? $rows : array();
}

/** @return array<int,mixed> */
function column_values( string $root, string $column, string $table = 'agents' ): array {
	return array_map( static fn( array $row ): mixed => $row[ $column ] ?? null, table_rows( $root, $table ) );
}

// The corpus blocker: a disjunctive restriction over one column including NULL.
$backfill = $runtime->execute(
	new WP_Markdown_Query_Request(
		"UPDATE `wp_agents` SET instance_key = 'default' WHERE instance_key IS NULL OR instance_key = ''",
		'wp_'
	)
);
$after_backfill = column_values( $root, 'instance_key' );

$unmatched = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_agents SET label = 'none' WHERE instance_key = 'absent'", 'wp_' )
);

// A reused index must carry NULL/empty summaries across non-key updates.
$creates_null = $runtime->execute(
	new WP_Markdown_Query_Request( 'UPDATE wp_agents SET instance_key = NULL WHERE id = 1', 'wp_' )
);
$matches_new_null = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_agents SET label = 'null-target' WHERE instance_key IS NULL", 'wp_' )
);

$deleted = $runtime->execute(
	new WP_Markdown_Query_Request( "DELETE FROM wp_agents WHERE instance_key = 'keep'", 'wp_' )
);
$after_delete = column_values( $root, 'label' );

// WordPress fixture cleanup retains its administrative row with this shape.
$inequality_delete = $runtime->execute(
	new WP_Markdown_Query_Request( 'DELETE FROM wp_cleanup_agents WHERE id != 2', 'wp_' )
);
$after_inequality_delete = column_values( $root, 'label', 'cleanup_agents' );

// Serialized values carry semicolons, which must not read as a statement separator.
$serialized = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_agents (instance_key, label) VALUES ('serialized', 'a:1:{s:3:\"key\";i:42;}')",
		'wp_'
	)
);
$serialized_rows = table_rows( $root );
$semicolon_text = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_agents SET label = 'one; two' WHERE instance_key = 'serialized'", 'wp_' )
);

$unknown_column = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_agents SET missing_column = 'x' WHERE id = 1", 'wp_' )
);
$unknown_table = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_absent SET label = 'x' WHERE id = 1", 'wp_' )
);
$cross_column_or = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_agents SET label = 'x' WHERE id = 1 OR label = 'first'", 'wp_' )
);
$null_equality = $runtime->execute(
	new WP_Markdown_Query_Request( 'UPDATE wp_agents SET label = 1 WHERE instance_key = NULL', 'wp_' )
);
$null_unique = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_unique_jobs SET token = 'abc3' WHERE scope IS NULL", 'wp_' )
);
$prefix_duplicate = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_unique_jobs SET token = 'abc9' WHERE id = 4", 'wp_' )
);
$composite_distinct = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_unique_jobs SET scope = 'two', token = 'abc9' WHERE id = 4", 'wp_' )
);

// A verified set survives a normal append, but supplied identities still scan
// the canonical snapshot and enforce every unique key.
$proof_initial_update = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_proof_jobs SET state = 'ready' WHERE id = 1", 'wp_' )
);
$proof_append = $runtime->execute(
	new WP_Markdown_Query_Request( "INSERT INTO wp_proof_jobs (token, state) VALUES ('second', 'pending')", 'wp_' )
);
$proof_interleaved_update = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_proof_jobs SET state = 'done' WHERE token = 'second'", 'wp_' )
);
$proof_explicit_duplicate = $runtime->execute(
	new WP_Markdown_Query_Request( "INSERT INTO wp_proof_jobs (id, token, state) VALUES (1, 'third', 'pending')", 'wp_' )
);
$runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION', 'wp_' ) );
$proof_rolled_back_insert = $runtime->execute(
	new WP_Markdown_Query_Request( "INSERT INTO wp_proof_jobs (token, state) VALUES ('rolled-back', 'pending')", 'wp_' )
);
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK', 'wp_' ) );
$proof_after_rollback = column_values( $root, 'token', 'proof_jobs' );

// An INSERT that encounters a corrupt initial snapshot must not certify it.
$initial_corrupt_path = $root . '/_tables/initial_corrupt_jobs.json';
$initial_corrupt_rows = json_decode( (string) file_get_contents( $initial_corrupt_path ), true, 512, JSON_THROW_ON_ERROR );
$initial_corrupt_rows[1]['token'] = 'first';
$initial_corrupt_temp = $initial_corrupt_path . '.replacement';
file_put_contents( $initial_corrupt_temp, json_encode( $initial_corrupt_rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
rename( $initial_corrupt_temp, $initial_corrupt_path );
$initial_corrupt_runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$initial_corrupt_insert = $initial_corrupt_runtime->execute(
	new WP_Markdown_Query_Request( "INSERT INTO wp_initial_corrupt_jobs (token, state) VALUES ('third', 'pending')", 'wp_' )
);
$initial_corrupt_update = $initial_corrupt_runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_initial_corrupt_jobs SET state = 'running' WHERE id = 1", 'wp_' )
);

// A first non-key UPDATE must still inspect an externally corrupted snapshot.
$corrupt_path = $root . '/_tables/corrupt_jobs.json';
$corrupt_rows = json_decode( (string) file_get_contents( $corrupt_path ), true, 512, JSON_THROW_ON_ERROR );
$corrupt_rows[1]['token'] = 'first';
$corrupt_temp = $corrupt_path . '.replacement';
file_put_contents( $corrupt_temp, json_encode( $corrupt_rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
rename( $corrupt_temp, $corrupt_path );
$corrupt_non_key_update = WP_Markdown_Native_Runtime_Factory::runtime( $root )->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_corrupt_jobs SET state = 'running' WHERE id = 1", 'wp_' )
);

// A writer for one canonical table must not serialize an unrelated table.
$agents_lock_path = $root . '/_tables/.mdi-native-' . hash( 'sha256', 'agents' ) . '.lock';
$held_agents_lock = fopen( $agents_lock_path, 'c+b' );
if ( false === $held_agents_lock || ! flock( $held_agents_lock, LOCK_EX | LOCK_NB ) ) {
	throw new RuntimeException( 'Failed to hold the agents table lock fixture.' );
}
$unrelated_write = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_jobs SET label = 'running' WHERE id = 1", 'wp_' )
);
flock( $held_agents_lock, LOCK_UN );
fclose( $held_agents_lock );

// A rolled back generic write must leave the snapshot untouched.
$runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION', 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_agents SET label = 'rolled-back' WHERE id = 1", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK', 'wp_' ) );
$after_rollback = column_values( $root, 'label' );

$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_action_claims (action_id bigint NOT NULL, hook varchar(40) NOT NULL, claim_id bigint NOT NULL, priority int NOT NULL, attempts int NOT NULL, scheduled_date_gmt datetime NOT NULL, last_attempt_gmt datetime NULL, last_attempt_local datetime NULL, PRIMARY KEY (action_id))', 'wp_' ) );
$action_fixture = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_action_claims (action_id, hook, claim_id, priority, attempts, scheduled_date_gmt, last_attempt_gmt, last_attempt_local) VALUES (1,'alpha',9,20,0,'2026-09-13 03:00:00',NULL,NULL),(2,'alpha',0,10,1,'2026-09-13 02:00:00',NULL,NULL),(3,'alpha',0,10,0,'2026-09-13 01:00:00',NULL,NULL),(4,'beta',0,1,0,'2026-09-13 00:00:00',NULL,NULL),(5,'race',0,20,0,'2026-09-13 03:00:00',NULL,NULL),(6,'race',0,10,1,'2026-09-13 02:00:00',NULL,NULL),(7,'race',0,10,0,'2026-09-13 01:00:00',NULL,NULL),(8,'race',0,10,0,'2026-09-13 00:00:00',NULL,NULL)", 'wp_' ) );
$action_claim_sql = "UPDATE wp_action_claims t1 JOIN ( SELECT action_id FROM wp_action_claims WHERE claim_id = 0 AND hook = 'alpha' ORDER BY priority ASC, attempts ASC, scheduled_date_gmt ASC, action_id ASC LIMIT 2 FOR UPDATE ) t2 ON t1.action_id = t2.action_id SET claim_id=71, last_attempt_gmt='2026-09-13 00:00:00', last_attempt_local='2026-09-13 00:00:00'";
$action_claim = $runtime->execute( new WP_Markdown_Query_Request( $action_claim_sql, 'wp_' ) );
$action_claim_rows = table_rows( $root, 'action_claims' );
$action_claim_replay = $runtime->execute( new WP_Markdown_Query_Request( $action_claim_sql, 'wp_' ) );
$unsupported_action_claim = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_action_claims t1 JOIN ( SELECT action_id FROM wp_action_claims WHERE claim_id = 0 ORDER BY action_id ASC LIMIT 1 FOR UPDATE ) t2 ON t1.action_id = t2.action_id SET claim_id=t1.action_id", 'wp_' ) );
$unlocked_action_claim = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_action_claims t1 JOIN ( SELECT action_id FROM wp_action_claims WHERE claim_id = 0 ORDER BY action_id ASC LIMIT 1 ) t2 ON t1.action_id = t2.action_id SET claim_id=72", 'wp_' ) );
$derived_before_rejections = table_rows( $root, 'action_claims' );
$scalar_predicate_claim = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_action_claims t1 JOIN ( SELECT action_id FROM wp_action_claims WHERE COALESCE(claim_id, 0) = 0 ORDER BY action_id ASC LIMIT 1 FOR UPDATE ) t2 ON t1.action_id = t2.action_id SET claim_id=72", 'wp_' ) );
$negated_predicate_claim = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_action_claims t1 JOIN ( SELECT action_id FROM wp_action_claims WHERE claim_id NOT IN (9) ORDER BY action_id ASC LIMIT 1 FOR UPDATE ) t2 ON t1.action_id = t2.action_id SET claim_id=72", 'wp_' ) );
$alias_collision_claim = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_action_claims t1 JOIN ( SELECT action_id FROM wp_action_claims WHERE claim_id = 0 ORDER BY action_id ASC LIMIT 1 FOR UPDATE ) t1 ON t1.action_id = t1.action_id SET claim_id=72", 'wp_' ) );
$contradiction_claim = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_action_claims t1 JOIN ( SELECT action_id FROM wp_action_claims WHERE 1 = 0 ORDER BY action_id ASC LIMIT 1 FOR UPDATE ) t2 ON t1.action_id = t2.action_id SET claim_id=72", 'wp_' ) );
$derived_after_rejections = table_rows( $root, 'action_claims' );

$claim_workers = array();
$claim_worker_pipes = array();
foreach ( array( 81, 82 ) as $claim_id ) {
	$pipes = array();
	$claim_workers[] = proc_open( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/native-derived-claim-worker.php' ) . ' ' . escapeshellarg( $root ) . ' ' . $claim_id, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$claim_worker_pipes[] = $pipes;
}
$claim_worker_outputs = array();
$claim_worker_statuses = array();
foreach ( $claim_workers as $index => $worker ) {
	$pipes = $claim_worker_pipes[ $index ];
	if ( ! is_resource( $worker ) ) { $claim_worker_statuses[] = false; continue; }
	fclose( $pipes[0] );
	$claim_worker_outputs[] = rtrim( (string) stream_get_contents( $pipes[1] ) );
	stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$claim_worker_statuses[] = 0 === proc_close( $worker );
}
$race_claims = array_filter( table_rows( $root, 'action_claims' ), static fn( array $row ): bool => 'race' === $row['hook'] );
$race_claim_ids = array_map( 'intval', array_column( $race_claims, 'claim_id' ) );
sort( $race_claim_ids, SORT_NUMERIC );

$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_actionscheduler_actions (action_id bigint unsigned NOT NULL auto_increment, hook varchar(191) NOT NULL, status varchar(20) NOT NULL, scheduled_date_gmt datetime NULL, scheduled_date_local datetime NULL, priority tinyint unsigned NOT NULL default 10, args varchar(191) NULL, schedule longtext NULL, group_id bigint unsigned NOT NULL default 0, attempts int NOT NULL default 0, last_attempt_gmt datetime NULL, last_attempt_local datetime NULL, claim_id bigint unsigned NOT NULL default 0, extended_args varchar(8000) NULL, PRIMARY KEY (action_id), KEY hook_status_scheduled_date_gmt (hook(163), status, scheduled_date_gmt), KEY status_scheduled_date_gmt (status, scheduled_date_gmt), KEY scheduled_date_gmt (scheduled_date_gmt), KEY args (args(191)), KEY group_id (group_id), KEY last_attempt_gmt (last_attempt_gmt), KEY claim_id_status_priority_scheduled_date_gmt (claim_id, status, priority, scheduled_date_gmt), KEY status_last_attempt_gmt (status, last_attempt_gmt), KEY status_claim_id (status, claim_id))', 'wp_' ) );
$as_fixture = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_actionscheduler_actions (action_id, hook, status, scheduled_date_gmt, scheduled_date_local, priority, args, schedule, group_id, attempts, last_attempt_gmt, last_attempt_local, claim_id, extended_args) VALUES (11,'followup','pending','2026-09-13 03:00:00','2026-09-13 03:00:00',5,NULL,NULL,0,2,NULL,NULL,0,NULL),(12,'followup','pending','2026-09-13 02:00:00','2026-09-13 02:00:00',5,NULL,NULL,0,1,NULL,NULL,0,NULL),(13,'followup','pending','2026-09-13 01:00:00','2026-09-13 01:00:00',10,NULL,NULL,0,0,NULL,NULL,0,NULL)", 'wp_' ) );
$as_claim = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_actionscheduler_actions t1 JOIN ( SELECT action_id FROM wp_actionscheduler_actions WHERE claim_id = 0 AND hook = 'followup' ORDER BY priority ASC, attempts ASC, scheduled_date_gmt ASC, action_id ASC LIMIT 2 FOR UPDATE ) t2 ON t1.action_id = t2.action_id SET claim_id=101, last_attempt_gmt='2026-09-13 00:00:00', last_attempt_local='2026-09-13 00:00:00'", 'wp_' ) );
$as_followup = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT action_id, scheduled_date_gmt FROM wp_actionscheduler_actions WHERE claim_id = 101 ORDER BY priority ASC, attempts ASC, scheduled_date_gmt ASC, action_id ASC', 'wp_' ) );
$as_followup_rows = $as_followup->wpdb_state()['last_result'];

$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_claims (id bigint NOT NULL, generation bigint NULL, mirror bigint NULL, state varchar(20) NOT NULL, claimed_at datetime NULL, label varchar(10) NOT NULL, PRIMARY KEY (id))' ) );
$claim_fixture = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_claims (id, generation, mirror, state, claimed_at, label) VALUES (1,NULL,NULL,'preparing',NULL,'one'),(2,2,NULL,'enqueuing','2026-09-10 23:00:00','1234567890'),(3,2,NULL,'enqueuing','2026-09-10 20:00:00','three'),(4,9,NULL,'enqueuing','2026-09-10 20:00:00','four')" ) );
if ( ! $claim_fixture->succeeded() ) { throw new RuntimeException( json_encode( $claim_fixture->diagnostic() ) ); }
$claim_sql = "UPDATE wp_claims SET generation = COALESCE(generation, 0) + 1, mirror = generation, state = 'enqueuing', claimed_at = '2026-09-10 23:00:00' WHERE id < 4 AND (state IN ('preparing', 'enqueue_failed') OR (state = 'enqueuing' AND (claimed_at IS NULL OR claimed_at < '2026-09-10 22:00:00')))";
$claimed = $runtime->execute( new WP_Markdown_Query_Request( $claim_sql ) );
$claim_rows = table_rows( $root, 'claims' );
$claim_replay = $runtime->execute( new WP_Markdown_Query_Request( $claim_sql ) );
$bad_expression = $runtime->execute( new WP_Markdown_Query_Request( 'UPDATE wp_claims SET generation = COALESCE(missing, 0) + 1 WHERE id = 999' ) );
$wrong_source = $runtime->execute( new WP_Markdown_Query_Request( 'UPDATE wp_claims SET generation = other.generation WHERE id = 999' ) );
$repeated_target = $runtime->execute( new WP_Markdown_Query_Request( 'UPDATE wp_claims SET generation = 1, generation = generation + 1' ) );
$late_failure = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_claims SET label = CONCAT(label, '!') WHERE id IN (1, 2)" ) );
$after_late_failure = table_rows( $root, 'claims' );
$unique_failure = $runtime->execute( new WP_Markdown_Query_Request( 'UPDATE wp_claims SET id = COALESCE(mirror, 1) WHERE id IN (1, 2)' ) );
$after_unique_failure = table_rows( $root, 'claims' );
$runtime->execute( new WP_Markdown_Query_Request( 'BEGIN' ) );
$precedence = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_claims SET generation = generation + 10 WHERE id = 1 OR id = 3 AND state = 'never'" ) );
$precedence_rows = table_rows( $root, 'claims' );
$nested_delete = $runtime->execute( new WP_Markdown_Query_Request( "DELETE FROM wp_claims WHERE (id = 1 OR id = 3) AND state = 'enqueuing'" ) );
$runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_claims SET generation = '9007199254740993' WHERE id = 4" ) );
$large_increment = $runtime->execute( new WP_Markdown_Query_Request( 'UPDATE wp_claims SET generation = generation + 1 WHERE id = 4' ) );
$large_rows = table_rows( $root, 'claims' );
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK' ) );
$restored_claims = table_rows( $root, 'claims' );

$checks = array(
	'nested lease predicates claim only eligible rows and assignments observe earlier values' => 2 === $claimed->return_value() && array( '1', '2', '3', '9' ) === array_column( $claim_rows, 'generation' ) && array( '1', null, '3', null ) === array_column( $claim_rows, 'mirror' ),
	'a repeated claim cannot take an active lease' => 0 === $claim_replay->return_value(),
	'unknown sources and repeated targets fail before mutation even without matching rows' => ! $bad_expression->succeeded() && ! $wrong_source->succeeded() && ! $repeated_target->succeeded(),
	'a later invalid row prevents every scalar UPDATE write' => ! $late_failure->succeeded() && $claim_rows === $after_late_failure,
	'scalar assignments preserve unique-key enforcement atomically' => ! $unique_failure->succeeded() && $claim_rows === $after_unique_failure,
	'AND binds tighter than OR in write predicates' => 1 === $precedence->return_value() && array( '11', '2', '3', '9' ) === array_column( $precedence_rows, 'generation' ),
	'rollback restores scalar updates and grouped deletes' => 2 === $nested_delete->return_value() && $claim_rows === $restored_claims,
	'integer arithmetic preserves values beyond floating-point precision' => 1 === $large_increment->return_value() && '9007199254740994' === $large_rows[1]['generation'],
	'the bounded ordered same-table derived selection claims only its hook group' => $action_fixture->succeeded() && 2 === $action_claim->return_value()
		&& array( '9', '71', '71', '0' ) === array_column( array_slice( $action_claim_rows, 0, 4 ), 'claim_id' ),
	'repeated derived claims do not reclaim already claimed rows' => 0 === $action_claim_replay->return_value()
		&& ! $unsupported_action_claim->succeeded()
		&& ! $unlocked_action_claim->succeeded(),
	'unsupported scalar, negated, and ambiguous derived selections leave rows unchanged' => ! $scalar_predicate_claim->succeeded()
		&& ! $negated_predicate_claim->succeeded()
		&& ! $alias_collision_claim->succeeded()
		&& 0 === $contradiction_claim->return_value()
		&& $derived_before_rejections === $derived_after_rejections,
	'two derived claim processes serialize and receive disjoint bounded selections' => array( '2', '2' ) === ( sort( $claim_worker_outputs, SORT_STRING ) ? $claim_worker_outputs : array() )
		&& array( true, true ) === $claim_worker_statuses
		&& array( 81, 81, 82, 82 ) === $race_claim_ids,
	'Action Scheduler claim follow-up reads every typed ordering term in claim order' => $as_fixture->succeeded()
		&& 2 === $as_claim->return_value()
		&& 2 === $as_followup->return_value()
		&& array( '12', '11' ) === array_map( static fn( object $row ): string => $row->action_id, $as_followup_rows ),
	'the fixture table is created' => 0 === $created->return_value() || true === $created->succeeded(),
	'a disjunctive NULL restriction updates every matching row' => 2 === $backfill->return_value()
		&& array( 'default', 'default', 'keep' ) === $after_backfill,
	'an unmatched restriction reports zero affected rows' => 0 === $unmatched->return_value(),
	'a non-key UPDATE refreshes NULL index summaries' => 1 === $creates_null->return_value()
		&& 1 === $matches_new_null->return_value(),
	'DELETE removes only the restricted rows' => 1 === $deleted->return_value()
		&& array( 'null-target', 'second' ) === $after_delete,
	'a not-equal DELETE retains only its selected row' => 2 === $inequality_delete->return_value()
		&& array( 'admin' ) === $after_inequality_delete,
	'a serialized value is not read as a statement separator' => 1 === $serialized->return_value()
		&& 'a:1:{s:3:"key";i:42;}' === ( $serialized_rows[ count( $serialized_rows ) - 1 ]['label'] ?? null ),
	'a semicolon inside a literal survives an update' => 1 === $semicolon_text->return_value(),
	'an unknown assignment column fails closed' => false === $unknown_column->return_value()
		&& 'unsupported_mutation_column' === ( $unknown_column->diagnostic()['reason'] ?? null ),
	'an unregistered table fails closed' => false === $unknown_table->return_value()
		&& 'unsupported_mutation_table' === ( $unknown_table->diagnostic()['reason'] ?? null ),
	'an OR group across columns updates its disjunction' => 1 === $cross_column_or->return_value(),
	'NULL equality fails closed' => false === $null_equality->return_value(),
	'multiple NULL composite keys remain unique during UPDATE' => 2 === $null_unique->return_value(),
	'UPDATE rejects a duplicate composite prefix key' => false === $prefix_duplicate->return_value()
		&& 'duplicate_key' === ( $prefix_duplicate->diagnostic()['reason'] ?? null ),
	'UPDATE accepts the same prefix in a distinct composite scope' => 1 === $composite_distinct->return_value(),
	'a verified unique set remains valid through an interleaved append and UPDATE' => 1 === $proof_initial_update->return_value()
		&& 1 === $proof_append->return_value()
		&& 1 === $proof_interleaved_update->return_value(),
	'an explicit identity still enforces the primary-key constraint' => false === $proof_explicit_duplicate->return_value()
		&& 'duplicate_key' === ( $proof_explicit_duplicate->diagnostic()['reason'] ?? null ),
	'a rolled back append restores the canonical unique set' => 1 === $proof_rolled_back_insert->return_value()
		&& array( 'first', 'second' ) === $proof_after_rollback,
	'an INSERT never certifies an initially corrupt unique snapshot' => 1 === $initial_corrupt_insert->return_value()
		&& false === $initial_corrupt_update->return_value()
		&& 'duplicate_key' === ( $initial_corrupt_update->diagnostic()['reason'] ?? null ),
	'a first non-key UPDATE rejects an externally corrupt unique snapshot' => false === $corrupt_non_key_update->return_value()
		&& 'duplicate_key' === ( $corrupt_non_key_update->diagnostic()['reason'] ?? null ),
	'an unrelated table writes while another table is locked' => 1 === $unrelated_write->return_value()
		&& array( 'running' ) === column_values( $root, 'label', 'jobs' ),
	'a rolled back generic write restores the snapshot' => array( 'x', 'second', 'one; two' ) === $after_rollback,
);

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
