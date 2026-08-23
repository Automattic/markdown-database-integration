<?php
/**
 * Disposable native MariaDB outbox probe. Run through WordPress bootstrap:
 * wp eval-file wp-content/plugins/markdown-database-integration/tests/probe-native-mariadb-mysql-outbox.php
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'MARKDOWN_DB_BACKEND' ) || 'mysql-full' !== MARKDOWN_DB_BACKEND ) {
	fwrite( STDERR, "SKIP: requires native MariaDB WordPress with MARKDOWN_DB_BACKEND=mysql-full.\n" );
	exit( 0 );
}

global $wpdb;
$outbox = $GLOBALS['markdown_db_mysql_outbox'] ?? null;
$drain = $GLOBALS['markdown_db_mysql_semantic_drain'] ?? null;
if ( ! $wpdb instanceof WP_Markdown_MySQL_WPDB || ! $outbox instanceof WP_Markdown_MySQL_Outbox || ! $outbox->is_ready() || ! $drain instanceof WP_Markdown_MySQL_Semantic_Drain ) {
	throw new RuntimeException( 'mysql-full did not bootstrap a ready durable outbox.' );
}

function mdi_native_outbox_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function mdi_native_outbox_payloads( array $records ): array {
	return array_values( array_map( static fn( array $record ): array => $record['payload'], $records ) );
}
function mdi_native_outbox_ack_all( WP_Markdown_MySQL_Outbox $outbox, array $records, string $token ): void {
	foreach ( $records as $record ) {
		mdi_native_outbox_assert( $outbox->acknowledge( (int) $record['id'], $token, (string) $record['lease_token'] ), 'Could not acknowledge native outbox record.' );
	}
}

$connection = $wpdb->markdown_db_mysql_connection();
$base_prefix = (string) $wpdb->base_prefix;
$outbox_table = $base_prefix . 'mdi_mysql_outbox';
$suffix = substr( hash( 'sha256', getmypid() . ':' . microtime( true ) ), 0, 10 );
$original_blog_id = (int) $wpdb->blogid;
$wpdb->set_blog_id( 2 );
$table = $wpdb->prefix . 'mdi_outbox_probe_' . $suffix;
$ddl_table = $table . '_ddl';
$bad_outbox_table = $table . '_bad_outbox';
$connection->query( "TRUNCATE TABLE `{$outbox_table}`" );

try {
	$state_fields = array( 'last_error', 'insert_id', 'rows_affected', 'num_rows', 'last_query', 'last_result', 'num_queries', 'queries' );
	$before_count = (int) $wpdb->num_queries;
	$sql = "CREATE TABLE `{$table}` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `value` VARCHAR(40) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB";
	mdi_native_outbox_assert( false !== $wpdb->query( $sql ), 'Native probe table creation failed.' );
	mdi_native_outbox_assert( $before_count + 1 === (int) $wpdb->num_queries && $sql === $wpdb->last_query, 'Outbox schema write changed caller-visible query state.' );

	$insert_sql = "INSERT INTO `{$table}` (`value`) VALUES ('autocommit')";
	mdi_native_outbox_assert( 1 === $wpdb->query( $insert_sql ), 'Native autocommit insert failed.' );
	$insert_state = array_intersect_key( get_object_vars( $wpdb ), array_flip( $state_fields ) );
	mdi_native_outbox_assert( 1 === $insert_state['rows_affected'] && 0 < $insert_state['insert_id'] && $insert_sql === $insert_state['last_query'], 'Autocommit outbox persistence changed wpdb result fields.' );
	$token = 'native-autocommit-' . $suffix;
	$records = $outbox->claim( $token, 10, 60 );
	$payloads = mdi_native_outbox_payloads( $records );
	mdi_native_outbox_assert( 1 === count( $payloads ) && 'CREATE' === $payloads[0]['mutation']['operation'], 'Raw claim exposes only the global head-of-line event.' );
	mdi_native_outbox_ack_all( $outbox, $records, $token );
	$records = $outbox->claim( $token, 10, 60 );
	$payloads = mdi_native_outbox_payloads( $records );
	mdi_native_outbox_assert( 1 === count( $payloads ) && 'INSERT' === $payloads[0]['mutation']['operation'] && 2 === $payloads[0]['scope']['blog_id'] && $wpdb->prefix === $payloads[0]['scope']['table_prefix'] && $base_prefix === $payloads[0]['scope']['base_prefix'] && array( $table ) === $payloads[0]['mutation']['tables'], 'Native record lost concrete multisite scope.' );
	mdi_native_outbox_ack_all( $outbox, $records, $token );

	$wpdb->query( "UPDATE `{$table}` SET `value`='planned' WHERE `id`=1" );
	$seen = array();
	$failed_once = $drain->drain( 'native-semantic-failure-' . $suffix, static function ( array $envelope ) use ( &$seen ): bool { $seen[] = $envelope; return false; }, 10, 60 );
	mdi_native_outbox_assert( 1 === $failed_once['failed'] && 0 === $failed_once['acknowledged'], 'Consumer failure did not retain the leased record for retry.' );
	$replayed = $drain->drain( 'native-semantic-replay-' . $suffix, static function ( array $envelope ) use ( &$seen ): bool { $seen[] = $envelope; return true; }, 10, 60 );
	mdi_native_outbox_assert( 1 === $replayed['acknowledged'] && 2 === count( $seen ) && $seen[0] === $seen[1] && 2 === $seen[1]['intents'][0]['blog_id'] && ! empty( $seen[1]['intents'][0]['current_rows'] ) && 'planned' === $seen[1]['intents'][0]['current_rows'][0]['value'], 'Semantic replay did not preserve its stable event envelope or perform a current-row lookup.' );
	$wpdb->query( "ALTER TABLE `{$table}` ADD `planned_marker` TINYINT NOT NULL DEFAULT 0" );
	$schemas = array();
	$drain->drain( 'native-semantic-schema-' . $suffix, static function ( array $intent ) use ( &$schemas ): bool { $schemas[] = $intent; return true; }, 10, 60 );
	mdi_native_outbox_assert( 1 === count( $schemas ) && str_contains( (string) ( $schemas[0]['schema']['create_sql'] ?? '' ), 'planned_marker' ), 'Semantic DDL intent did not carry current SHOW CREATE TABLE evidence.' );
	$wpdb->query( "ALTER TABLE `{$table}` ADD `canonical_marker` TINYINT NOT NULL DEFAULT 0" );
	$wpdb->query( "UPDATE `{$table}` SET `canonical_marker`=1 WHERE `id`=1" );
	$publication = wp_markdown_mysql_full_flush( 10 );
	$state_root = rtrim( MARKDOWN_DB_STATE_DIR, '/\\' ) . '/sites/2';
	$table_suffix = substr( $table, strlen( $wpdb->prefix ) );
	$canonical_table = $state_root . '/_tables/' . $table_suffix . '.json';
	$canonical_schema = $state_root . '/_schema/' . $table_suffix . '.sql';
	mdi_native_outbox_assert( 2 === $publication['acknowledged'] && is_file( $canonical_table ) && is_file( $canonical_schema ) && str_contains( (string) file_get_contents( $canonical_schema ), 'canonical_marker' ) && 1 === (int) ( json_decode( (string) file_get_contents( $canonical_table ), true )[0]['canonical_marker'] ?? 0 ), 'Canonical publisher did not durably materialize native MariaDB table and schema intents.' );

	$mode_row = $connection->query( 'SELECT @@SESSION.sql_mode AS `sql_mode`' )->fetch_assoc();
	$original_sql_mode = (string) $mode_row['sql_mode'];
	$connection->query( "SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'" );
	$mode_sql = "INSERT INTO `{$table}` (`value`) VALUES ('mode-safe')";
	mdi_native_outbox_assert( 1 === $wpdb->query( $mode_sql ), 'Prepared outbox persistence failed under NO_BACKSLASH_ESCAPES.' );
	$token = 'native-sql-mode-' . $suffix;
	$records = $outbox->claim( $token, 10, 60 );
	mdi_native_outbox_assert( 1 === count( $records ) && $mode_sql === $records[0]['payload']['mutation']['sql'], 'Outbox payload quoting was not SQL-mode independent.' );
	mdi_native_outbox_ack_all( $outbox, $records, $token );
	$restore_mode = $connection->prepare( 'SET SESSION sql_mode=?' );
	$restore_mode->bind_param( 's', $original_sql_mode );
	$restore_mode->execute();
	$restore_mode->close();

	$wpdb->query( 'SET AUTOCOMMIT=0' );
	$wpdb->query( "CREATE TABLE `{$ddl_table}` (`id` BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB" );
	$wpdb->query( 'ROLLBACK' );
	$token = 'native-autocommit-off-ddl-' . $suffix;
	$records = $outbox->claim( $token, 10, 60 );
	mdi_native_outbox_assert( 1 === count( $records ) && 'CREATE' === $records[0]['payload']['mutation']['operation'], 'Autocommit-off DDL was not immediately durable across a later rollback.' );
	mdi_native_outbox_ack_all( $outbox, $records, $token );
	$wpdb->query( 'SET AUTOCOMMIT=1' );

	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( "INSERT INTO `{$table}` (`value`) VALUES ('commit-1')" );
	$wpdb->query( "INSERT INTO `{$table}` (`value`) VALUES ('commit-2')" );
	mdi_native_outbox_assert( array() === $outbox->claim( 'native-before-commit-' . $suffix, 10, 60 ), 'Uncommitted records became drainable.' );
	mdi_native_outbox_assert( false !== $wpdb->query( 'COMMIT' ), 'Native transaction commit failed.' );
	$token = 'native-committed-' . $suffix;
	$records = $outbox->claim( $token, 10, 60 );
	mdi_native_outbox_assert( 1 === count( $records ) && str_contains( $records[0]['payload']['mutation']['sql'], 'commit-1' ), 'Raw claim did not retain the first committed event as global head.' );
	mdi_native_outbox_ack_all( $outbox, $records, $token );
	$records = $outbox->claim( $token, 10, 60 );
	mdi_native_outbox_assert( 1 === count( $records ) && str_contains( $records[0]['payload']['mutation']['sql'], 'commit-2' ), 'Raw claim did not expose the next committed event after acknowledgement.' );
	mdi_native_outbox_ack_all( $outbox, $records, $token );

	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( "INSERT INTO `{$table}` (`value`) VALUES ('rolled-back')" );
	$wpdb->query( 'ROLLBACK' );
	mdi_native_outbox_assert( array() === $outbox->claim( 'native-after-rollback-' . $suffix, 10, 60 ), 'Rolled-back mutation became drainable.' );

	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( "INSERT INTO `{$table}` (`value`) VALUES ('before-savepoint')" );
	$wpdb->query( 'SAVEPOINT keep_first' );
	$wpdb->query( "INSERT INTO `{$table}` (`value`) VALUES ('after-savepoint')" );
	$wpdb->query( 'ROLLBACK TO SAVEPOINT keep_first' );
	$wpdb->query( 'COMMIT' );
	$token = 'native-savepoint-' . $suffix;
	$records = $outbox->claim( $token, 10, 60 );
	mdi_native_outbox_assert( 1 === count( $records ) && str_contains( $records[0]['payload']['mutation']['sql'], 'before-savepoint' ), 'Savepoint rollback did not discard only later observations.' );
	mdi_native_outbox_ack_all( $outbox, $records, $token );

	$wpdb->query( "UPDATE `{$table}` SET `value`='retry' WHERE `id`=1" );
	$first = $outbox->claim( 'native-worker-a-' . $suffix, 1, 1 );
	mdi_native_outbox_assert( 1 === count( $first ) && $outbox->fail( $first[0]['id'], 'native-worker-a-' . $suffix, $first[0]['lease_token'], 'transient', 0 ), 'Native failure/retry setup failed.' );
	$retry = $outbox->claim( 'native-worker-b-' . $suffix, 1, 1 );
	mdi_native_outbox_assert( 2 === $retry[0]['attempts'] && 1 === $retry[0]['failures'], 'Native retry did not retain attempts and failure state.' );
	sleep( 2 );
	mdi_native_outbox_assert( ! $outbox->acknowledge( $retry[0]['id'], 'native-worker-b-' . $suffix, $retry[0]['lease_token'] ) && ! $outbox->fail( $retry[0]['id'], 'native-worker-b-' . $suffix, $retry[0]['lease_token'], 'expired' ), 'Expired native lease owner retained mutation authority.' );
	$reclaimed = $outbox->claim( 'native-worker-b-' . $suffix, 1, 60 );
	mdi_native_outbox_assert( $retry[0]['id'] === $reclaimed[0]['id'] && 1 <= $reclaimed[0]['reclaims'] && ! $outbox->acknowledge( $reclaimed[0]['id'], 'native-worker-b-' . $suffix, $retry[0]['lease_token'] ) && $outbox->acknowledge( $reclaimed[0]['id'], 'native-worker-b-' . $suffix, $reclaimed[0]['lease_token'] ), 'Native expired same-worker lease reclaim or generation fencing failed.' );

	$before_rows = (int) $connection->query( "SELECT COUNT(*) AS `count` FROM `{$outbox_table}`" )->fetch_assoc()['count'];
	$connection->query( "INSERT INTO `{$table}` (`value`) VALUES ('direct-handle')" );
	$after_rows = (int) $connection->query( "SELECT COUNT(*) AS `count` FROM `{$outbox_table}`" )->fetch_assoc()['count'];
	$diagnostics = $outbox->diagnostics();
	mdi_native_outbox_assert( $before_rows === $after_rows && in_array( 'direct mysqli writes', $diagnostics['capture_limitations'], true ), 'Direct-handle limitation was not explicit.' );

	$connection->query( "CREATE TABLE `{$bad_outbox_table}` LIKE `{$outbox_table}`" );
	$connection->query( "ALTER TABLE `{$bad_outbox_table}` DROP INDEX `mdi_event`, ADD KEY `mdi_event` (`event_id`)" );
	$rejected_schema = false;
	try {
		new WP_Markdown_MySQL_Outbox( $connection, $bad_outbox_table );
	} catch ( RuntimeException $error ) {
		$rejected_schema = str_contains( $error->getMessage(), 'unique event_id index' );
	}
	mdi_native_outbox_assert( $rejected_schema, 'Outbox accepted a non-unique event identity index.' );

	$wpdb->query( 'DO 1' );
	$diagnostics = $outbox->diagnostics();
	mdi_native_outbox_assert( 1 <= $diagnostics['backlog']['unsupported_boundaries'] && is_array( $diagnostics['unsupported_boundary_sample'] ), 'Unsupported boundary did not reach health diagnostics.' );

	echo "PASS: native MariaDB durable mysql-full outbox probe.\n";
} finally {
	$connection->query( "DROP TABLE IF EXISTS `{$table}`" );
	$connection->query( "DROP TABLE IF EXISTS `{$ddl_table}`" );
	$connection->query( "DROP TABLE IF EXISTS `{$bad_outbox_table}`" );
	$connection->query( "TRUNCATE TABLE `{$outbox_table}`" );
	if ( isset( $canonical_table ) && is_file( $canonical_table ) ) { unlink( $canonical_table ); }
	if ( isset( $canonical_schema ) && is_file( $canonical_schema ) ) { unlink( $canonical_schema ); }
	$wpdb->set_blog_id( $original_blog_id );
}
