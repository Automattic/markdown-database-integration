<?php
/** Standalone mysql-full wpdb boundary compatibility checks. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$mdi_test_filters = array();
function add_filter( string $hook, callable $callback, int $priority = 10 ): void { global $mdi_test_filters; $mdi_test_filters[ $hook ][ $priority ][] = $callback; }
function remove_filter( string $hook, callable $callback, int $priority = 10 ): void { global $mdi_test_filters; foreach ( $mdi_test_filters[ $hook ][ $priority ] ?? array() as $index => $registered ) { if ( $registered === $callback ) { unset( $mdi_test_filters[ $hook ][ $priority ][ $index ] ); } } }
function apply_filters( string $hook, mixed $value ): mixed { global $mdi_test_filters; $priorities = $mdi_test_filters[ $hook ] ?? array(); ksort( $priorities ); foreach ( $priorities as $callbacks ) { foreach ( $callbacks as $callback ) { $value = $callback( $value ); } } return $value; }

class wpdb {
	public string $prefix = 'wp_';
	public string $base_prefix = 'wp_';
	public int $blogid = 1;
	public string $last_error = '';
	public int $insert_id = 0;
	public int $rows_affected = 0;
	public int $num_rows = 0;
	public int $num_queries = 0;
	public string $last_query = '';
	public array $last_result = array();
	public array $queries = array();
	public string $func_call = '';
	public ?string $sink_added_property = null;
	public function __construct( $user, $password, $name, $host ) { unset( $user, $password, $name, $host ); }
	public function query( $query ) {
		$executed = (string) apply_filters( 'query', $query );
		if ( '' === $executed ) { $this->insert_id = 0; return false; }
		$this->last_query = $executed;
		if ( 'CREATE TABLE core_rejected' === $executed ) { $this->last_error = 'invalid query text'; return false; }
		$this->func_call = 'wpdb::query(' . $executed . ')';
		++$this->num_queries;
		$this->queries[] = array( $executed, microtime( true ), 'wpdb::query' );
		if ( 'BROKEN SQL' === $executed || 'CREATE TABLE broken' === $executed ) { $this->last_error = 'syntax error'; return false; }
		$this->last_error = '';
		if ( str_starts_with( strtoupper( $executed ), 'INSERT' ) ) { $this->insert_id = 17; $this->rows_affected = 1; return 1; }
		if ( str_starts_with( strtoupper( $executed ), 'SELECT' ) ) { $this->last_result = array( (object) array( 'id' => 1 ) ); $this->num_rows = 1; return 1; }
		$this->rows_affected = 0;
		return true;
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-mysql-wpdb.php';

add_filter( 'query', static function ( string $query ): string {
	return match ( $query ) {
		'FILTER_TO_INSERT' => 'INSERT INTO `wp_filtered` VALUES (1)',
		'FILTER_TO_DDL' => 'CREATE TABLE `wp_filtered_ddl` (`id` int)',
		'FILTER_TO_LOCK' => 'LOCK TABLES wp_items WRITE',
		default => $query,
	};
} );

$failures = array();
function mdi_mysql_full_assert( bool $condition, string $message ): void { global $failures; if ( ! $condition ) { $failures[] = $message; } }

$observations = array();
$db = new WP_Markdown_MySQL_WPDB( 'user', 'pass', 'db', 'host', static function ( array $observation ) use ( &$observations ): void { $observations[] = $observation; } );

mdi_mysql_full_assert( array( 'type' => 'DML', 'op' => 'INSERT', 'table' => 'wp_items' ) === WP_Markdown_SQL_Classifier::mutation( 'INSERT INTO `wp_items` VALUES (1)' ), 'classifier normalizes DML' );
mdi_mysql_full_assert( array( 'type' => 'DDL', 'op' => 'ALTER', 'table' => 'wp_items' ) === WP_Markdown_SQL_Classifier::mutation( 'DROP INDEX `items_name` ON `wp_items`' ), 'classifier normalizes index DDL to its table' );
mdi_mysql_full_assert( array( 'type' => 'DDL', 'op' => 'ALTER', 'table' => 'wp_items' ) === WP_Markdown_SQL_Classifier::mutation( 'CREATE UNIQUE INDEX `items_name` ON `wp_items` (`name`)' ), 'classifier normalizes CREATE INDEX variants to their table' );
mdi_mysql_full_assert( array( 'type' => 'DDL', 'op' => 'ALTER', 'table' => 'wp_items' ) === WP_Markdown_SQL_Classifier::mutation( 'CREATE INDEX `items_name` USING BTREE ON `wp_items` (`name`)' ), 'classifier normalizes CREATE INDEX access-method variants to their table' );
mdi_mysql_full_assert( array( 'type' => 'DDL', 'op' => 'TRUNCATE', 'table' => 'wp_items' ) === WP_Markdown_SQL_Classifier::mutation( 'TRUNCATE TABLE `wp_items`' ), 'classifier normalizes TRUNCATE TABLE' );
mdi_mysql_full_assert( array( 'type' => 'DML', 'op' => 'DELETE', 'tables' => array( 'wp_options' ) ) === WP_Markdown_SQL_Classifier::mutation( 'DELETE a, b FROM wp_options a, wp_options b WHERE a.option_name = b.option_name' ), 'classifier preserves every target of the WordPress core multi-table DELETE shape' );
mdi_mysql_full_assert( array( 'type' => 'DML', 'op' => 'DELETE', 'tables' => array( 'wp_options', 'wp_sitemeta' ) ) === WP_Markdown_SQL_Classifier::mutation( 'DELETE a, b FROM wp_options a, wp_sitemeta b WHERE a.option_name = b.meta_key' ), 'classifier reports every distinct table mutated by a multi-table DELETE' );
mdi_mysql_full_assert( array( 'action' => 'rollback_to', 'savepoint' => 'before_write' ) === WP_Markdown_SQL_Classifier::transaction_control( 'ROLLBACK TO SAVEPOINT before_write' ), 'classifier recognizes savepoint rollback' );
mdi_mysql_full_assert( WP_Markdown_SQL_Classifier::unsupported_implicit_commit( 'CREATE VIEW wp_items_view AS SELECT * FROM wp_items' ) && WP_Markdown_SQL_Classifier::unsupported_implicit_commit( 'FLUSH TABLES' ) && WP_Markdown_SQL_Classifier::unsupported_implicit_commit( 'CREATE SEQUENCE wp_item_ids' ), 'classifier fails closed for non-table, MariaDB sequence, and administrative implicit commits' );

$before_prefix = $db->prefix;
$insert = $db->query( 'INSERT INTO `wp_items` (`name`) VALUES (\'first\')' );
mdi_mysql_full_assert( 1 === $insert && 17 === $db->insert_id && 1 === $db->rows_affected, 'parent insert return and public insert state are preserved' );
mdi_mysql_full_assert( $before_prefix === $db->prefix && 1 === $db->num_queries && 'INSERT INTO `wp_items` (`name`) VALUES (\'first\')' === $db->last_query, 'boundary does not alter prefix, query count, or last query' );
mdi_mysql_full_assert( 1 === count( $observations ) && 'table' === $observations[0]['kind'] && 'INSERT' === $observations[0]['operation'] && 'wp_items' === $observations[0]['table'] && $observations[0]['transaction']['autocommit'] && 17 === $observations[0]['insert_id'] && 1 === $observations[0]['rows_affected'] && 1 === $observations[0]['blog_id'] && 'wp_' === $observations[0]['table_prefix'], 'successful mutation emits one normalized autocommit observation with result and blog scope' );

$db->query( 'FILTER_TO_INSERT' );
mdi_mysql_full_assert( 2 === count( $observations ) && 'wp_filtered' === $observations[1]['table'] && 'INSERT INTO `wp_filtered` VALUES (1)' === $observations[1]['query'], 'observation classifies the effective query after core filtering' );

$db->query( 'START TRANSACTION' );
$db->query( 'SAVEPOINT before_write' );
$db->query( 'UPDATE `wp_items` SET `name` = \'second\' WHERE `id` = 17' );
$db->query( 'ROLLBACK TO SAVEPOINT before_write' );
mdi_mysql_full_assert( 3 === count( $observations ) && $observations[2]['transaction']['active'] && array( 'before_write' ) === $observations[2]['transaction']['savepoints'], 'mutation observations retain transaction and savepoint context' );

$db->query( 'CREATE TABLE `wp_transaction_test` (`id` int)' );
$db->query( 'INSERT INTO `wp_items` (`name`) VALUES (\'after-ddl\')' );
mdi_mysql_full_assert( 5 === count( $observations ) && ! $observations[4]['transaction']['active'] && array() === $observations[4]['transaction']['savepoints'], 'implicit DDL commits clear transaction context before later observations' );
$db->query( 'START TRANSACTION' );
$db->query( 'SAVEPOINT old' );
$db->query( 'COMMIT AND CHAIN' );
$db->query( 'INSERT INTO `wp_items` (`name`) VALUES (\'chained\')' );
mdi_mysql_full_assert( 6 === count( $observations ) && $observations[5]['transaction']['active'] && array() === $observations[5]['transaction']['savepoints'], 'COMMIT AND CHAIN begins a clean transaction context' );

$autocommit_observations = array();
$autocommit_db = new WP_Markdown_MySQL_WPDB( 'user', 'pass', 'db', 'host', static function ( array $observation ) use ( &$autocommit_observations ): void { $autocommit_observations[] = $observation; } );
$autocommit_db->query( 'SET AUTOCOMMIT=0' );
$autocommit_db->query( 'INSERT INTO `wp_items` VALUES (1)' );
$autocommit_db->query( 'SET AUTOCOMMIT=0' );
$autocommit_db->query( 'COMMIT' );
$autocommit_db->query( 'CREATE TABLE `wp_autocommit_ddl` (`id` int)' );
$autocommit_db->query( 'INSERT INTO `wp_items` VALUES (2)' );
mdi_mysql_full_assert( $autocommit_observations[0]['transaction']['active'] && ! $autocommit_observations[1]['transaction']['active'] && $autocommit_observations[1]['commit_outbox'] && $autocommit_observations[2]['transaction']['active'], 'autocommit-off starts on DML while implicitly committed DDL requests an immediately committed outbox write' );

$unsupported_observations = array();
$unsupported_db = new WP_Markdown_MySQL_WPDB( 'user', 'pass', 'db', 'host', static function ( array $observation ) use ( &$unsupported_observations ): void { $unsupported_observations[] = $observation; } );
$unsupported_db->query( 'START TRANSACTION' );
$unsupported_db->query( 'UPDATE `wp_items` SET `name` = \'before-rename\'' );
$unsupported_db->query( 'RENAME TABLE `wp_items` TO `wp_items_renamed`' );
$unsupported_db->query( 'INSERT INTO `wp_items_renamed` VALUES (1)' );
mdi_mysql_full_assert( $unsupported_observations[0]['transaction']['active'] && ! $unsupported_observations[1]['transaction']['active'], 'unsupported implicit commits reset transaction context before later mutations' );
$unsupported_db->query( 'START TRANSACTION' );
$unsupported_db->query( 'UPDATE `wp_items_renamed` SET `name` = \'before-view\'' );
$unsupported_db->query( 'CREATE VIEW wp_items_view AS SELECT * FROM wp_items_renamed' );
$unsupported_db->query( 'ROLLBACK' );
$unsupported_db->query( 'INSERT INTO `wp_items_renamed` VALUES (2)' );
mdi_mysql_full_assert( ! $unsupported_observations[3]['transaction']['active'], 'non-table DDL implicit commits reset transaction context before a later rollback' );

$failed_ddl_observations = array();
$failed_ddl = new WP_Markdown_MySQL_WPDB( 'user', 'pass', 'db', 'host', static function ( array $observation ) use ( &$failed_ddl_observations ): void { $failed_ddl_observations[] = $observation; } );
$failed_ddl->query( 'START TRANSACTION' );
$failed_ddl->query( 'SAVEPOINT before_failed_ddl' );
mdi_mysql_full_assert( false === $failed_ddl->query( 'CREATE TABLE broken' ), 'failed DDL preserves the parent error return' );
$failed_ddl->query( 'INSERT INTO `wp_items` (`name`) VALUES (\'after-failed-ddl\')' );
mdi_mysql_full_assert( 1 === count( $failed_ddl_observations ) && ! $failed_ddl_observations[0]['transaction']['active'] && array() === $failed_ddl_observations[0]['transaction']['savepoints'], 'failed implicit-commit DDL clears transaction context without emitting an observation' );

$rejected_ddl_observations = array();
$rejected_ddl = new WP_Markdown_MySQL_WPDB( 'user', 'pass', 'db', 'host', static function ( array $observation ) use ( &$rejected_ddl_observations ): void { $rejected_ddl_observations[] = $observation; } );
$rejected_ddl->query( 'START TRANSACTION' );
$queries_before_rejection = $rejected_ddl->num_queries;
mdi_mysql_full_assert( false === $rejected_ddl->query( 'CREATE TABLE core_rejected' ) && $queries_before_rejection === $rejected_ddl->num_queries, 'core-side DDL rejection does not claim a server attempt' );
$rejected_ddl->query( 'INSERT INTO `wp_items` VALUES (1)' );
mdi_mysql_full_assert( 1 === count( $rejected_ddl_observations ) && $rejected_ddl_observations[0]['transaction']['active'], 'core-side DDL rejection preserves the caller transaction context' );

$nested_observations = array();
$nested = null;
$nested = new WP_Markdown_MySQL_WPDB( 'user', 'pass', 'db', 'host', static function ( array $observation ) use ( &$nested, &$nested_observations ): void {
	$nested_observations[] = $observation;
	$nested->prefix = 'corrupted_';
	$nested->last_error = 'corrupted';
	$nested->insert_id = 999;
	$nested->rows_affected = 999;
	$nested->num_rows = 999;
	$nested->last_result = array( 'corrupted' );
	$nested->sink_added_property = 'corrupted';
	$nested->query( 'SELECT * FROM `wp_items`' );
	throw new RuntimeException( 'sink failed' );
} );
$nested_result = $nested->query( 'INSERT INTO `wp_items` (`name`) VALUES (\'nested\')' );
mdi_mysql_full_assert( 1 === $nested_result && 'wp_' === $nested->prefix && '' === $nested->last_error && 17 === $nested->insert_id && 1 === $nested->rows_affected && 0 === $nested->num_rows && array() === $nested->last_result && null === $nested->sink_added_property, 'nested failing sink restores every caller-visible wpdb result field' );
mdi_mysql_full_assert( 1 === $nested->num_queries && 'INSERT INTO `wp_items` (`name`) VALUES (\'nested\')' === $nested->last_query && 1 === count( $nested->queries ) && 1 === count( $nested_observations ), 'nested sink query neither leaks SAVEQUERIES state nor recursively observes' );
mdi_mysql_full_assert( 'markdown_db_mysql_full_observer_failed' === ( $GLOBALS['markdown_db_mysql_full_diagnostic']['code'] ?? '' ), 'sink failure records a structured diagnostic without changing the caller result' );

class MDI_State_Mutating_Outbox {
	public WP_Markdown_MySQL_WPDB $db;
	public int $before_calls = 0;
	public int $control_calls = 0;
	public int $deferred_boundaries = 0;
	public array $before_mutations = array();
	public function __invoke( array $observation ): void { unset( $observation ); }
	public function before_query( ?array $control, ?array $mutation, string $query, array $transaction ): void {
		unset( $control, $query, $transaction ); ++$this->before_calls; $this->before_mutations[] = $mutation; $this->db->last_error = 'outbox'; $this->db->insert_id = 999; $this->db->num_queries = 999; $this->db->queries[] = array( 'outbox' );
	}
	public function after_control( array $control ): void { unset( $control ); ++$this->control_calls; $this->db->last_query = 'outbox'; $this->db->last_result = array( 'outbox' ); }
	public function record_unsupported_boundary_deferred( string $query, array $transaction, string $reason = '' ): void { unset( $query, $transaction, $reason ); ++$this->deferred_boundaries; }
}
$state_sink = new MDI_State_Mutating_Outbox();
$state_db = new WP_Markdown_MySQL_WPDB( 'user', 'pass', 'db', 'host' );
$state_sink->db = $state_db;
$state_db->set_mutation_sink( $state_sink );
$state_db->query( 'START TRANSACTION' );
$state_db->query( 'INSERT INTO `wp_items` VALUES (1)' );
$state_db->query( 'COMMIT' );
mdi_mysql_full_assert( 3 === $state_sink->before_calls && 2 === $state_sink->control_calls && 3 === $state_db->num_queries && 3 === count( $state_db->queries ) && 'COMMIT' === $state_db->last_query && array() === $state_db->last_result && '' === $state_db->last_error, 'outbox pre-commit and post-control writes preserve caller-visible wpdb and SAVEQUERIES state' );
$before_lock_queries = $state_db->num_queries;
mdi_mysql_full_assert( false === $state_db->query( 'LOCK TABLES wp_items WRITE' ) && $before_lock_queries === $state_db->num_queries && 1 === $state_sink->deferred_boundaries && 'markdown_db_mysql_full_unsupported_boundary' === ( $GLOBALS['markdown_db_mysql_full_diagnostic']['code'] ?? '' ), 'LOCK TABLES fails closed before entering an outbox-incompatible locked state' );
$state_db->query( 'START TRANSACTION' );
mdi_mysql_full_assert( true === $state_db->query( 'FILTER_TO_DDL' ) && 'DDL' === ( $state_sink->before_mutations[4]['type'] ?? null ), 'pre-commit handoff classifies query-filtered DDL before execution' );
$before_filtered_lock = $state_db->num_queries;
mdi_mysql_full_assert( false === $state_db->query( 'FILTER_TO_LOCK' ) && $before_filtered_lock === $state_db->num_queries && 2 === $state_sink->deferred_boundaries, 'query filters cannot bypass LOCK TABLES rejection' );

$operation_observations = array();
$operation_db = new WP_Markdown_MySQL_WPDB( 'user', 'pass', 'db', 'host', static function ( array $observation ) use ( &$operation_observations ): void { $operation_observations[] = $observation; } );
foreach ( array(
	'INSERT INTO `wp_items` VALUES (1)' => 'INSERT',
	'REPLACE INTO `wp_items` VALUES (1)' => 'REPLACE',
	'UPDATE `wp_items` SET `name` = \'updated\'' => 'UPDATE',
	'DELETE FROM `wp_items`' => 'DELETE',
	'CREATE TABLE `wp_plugin_items` (`id` int)' => 'CREATE',
	'CREATE FULLTEXT INDEX `name` ON `wp_plugin_items` (`name`)' => 'ALTER',
	'ALTER TABLE `wp_plugin_items` ADD `name` varchar(20)' => 'ALTER',
	'DROP INDEX `name` ON `wp_plugin_items`' => 'ALTER',
	'TRUNCATE TABLE `wp_plugin_items`' => 'TRUNCATE',
	'DROP TABLE `wp_plugin_items`' => 'DROP',
) as $query => $operation ) {
	$operation_db->query( $query );
}
mdi_mysql_full_assert( array_values( array_map( static fn( array $observation ): string => $observation['operation'], $operation_observations ) ) === array( 'INSERT', 'REPLACE', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'ALTER', 'ALTER', 'TRUNCATE', 'DROP' ), 'every supported DML and DDL operation emits exactly one normalized observation' );
$operation_db->query( 'DELETE a, b FROM wp_options a, wp_options b WHERE a.option_name = b.option_name' );
$multi_delete = $operation_observations[10] ?? array();
mdi_mysql_full_assert( 'DELETE' === ( $multi_delete['operation'] ?? null ) && array( 'wp_options' ) === ( $multi_delete['tables'] ?? null ) && ! isset( $multi_delete['table'] ), 'multi-table DELETE emits one complete target set rather than a false single-table observation' );

$last_error = $db->last_error;
$failed = $db->query( 'BROKEN SQL' );
mdi_mysql_full_assert( false === $failed && 'syntax error' === $db->last_error && 6 === count( $observations ), 'failed parent query retains its error and emits nothing' );
mdi_mysql_full_assert( '' !== $last_error || 'syntax error' === $db->last_error, 'parent error state remains caller-visible' );

if ( $failures ) { foreach ( $failures as $failure ) { echo "FAIL: {$failure}\n"; } exit( 1 ); }
echo "All mysql-full wpdb smoke checks passed.\n";
