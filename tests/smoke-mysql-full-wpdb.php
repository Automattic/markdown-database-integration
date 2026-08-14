<?php
/** Standalone mysql-full wpdb boundary compatibility checks. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class wpdb {
	public string $prefix = 'wp_';
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
		$executed = 'FILTER_TO_INSERT' === $query ? 'INSERT INTO `wp_filtered` VALUES (1)' : (string) $query;
		$this->last_query = $executed;
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

$before_prefix = $db->prefix;
$insert = $db->query( 'INSERT INTO `wp_items` (`name`) VALUES (\'first\')' );
mdi_mysql_full_assert( 1 === $insert && 17 === $db->insert_id && 1 === $db->rows_affected, 'parent insert return and public insert state are preserved' );
mdi_mysql_full_assert( $before_prefix === $db->prefix && 1 === $db->num_queries && 'INSERT INTO `wp_items` (`name`) VALUES (\'first\')' === $db->last_query, 'boundary does not alter prefix, query count, or last query' );
mdi_mysql_full_assert( 1 === count( $observations ) && 'table' === $observations[0]['kind'] && 'INSERT' === $observations[0]['operation'] && 'wp_items' === $observations[0]['table'] && $observations[0]['transaction']['autocommit'], 'successful mutation emits one normalized autocommit observation' );

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

$failed_ddl_observations = array();
$failed_ddl = new WP_Markdown_MySQL_WPDB( 'user', 'pass', 'db', 'host', static function ( array $observation ) use ( &$failed_ddl_observations ): void { $failed_ddl_observations[] = $observation; } );
$failed_ddl->query( 'START TRANSACTION' );
$failed_ddl->query( 'SAVEPOINT before_failed_ddl' );
mdi_mysql_full_assert( false === $failed_ddl->query( 'CREATE TABLE broken' ), 'failed DDL preserves the parent error return' );
$failed_ddl->query( 'INSERT INTO `wp_items` (`name`) VALUES (\'after-failed-ddl\')' );
mdi_mysql_full_assert( 1 === count( $failed_ddl_observations ) && ! $failed_ddl_observations[0]['transaction']['active'] && array() === $failed_ddl_observations[0]['transaction']['savepoints'], 'failed implicit-commit DDL clears transaction context without emitting an observation' );

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
