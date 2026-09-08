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

$checks = array(
	'the fixture table is created' => 0 === $created->return_value() || true === $created->succeeded(),
	'a disjunctive NULL restriction updates every matching row' => 2 === $backfill->return_value()
		&& array( 'default', 'default', 'keep' ) === $after_backfill,
	'an unmatched restriction reports zero affected rows' => 0 === $unmatched->return_value(),
	'a non-key UPDATE refreshes NULL index summaries' => 1 === $creates_null->return_value()
		&& 1 === $matches_new_null->return_value(),
	'DELETE removes only the restricted rows' => 1 === $deleted->return_value()
		&& array( 'null-target', 'second' ) === $after_delete,
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
