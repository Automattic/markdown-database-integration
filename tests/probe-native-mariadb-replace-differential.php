<?php
/** Compare caller-visible persisted-table REPLACE behavior with disposable MariaDB. */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'wpdb' ) || ! extension_loaded( 'mysqli' ) ) {
	throw new RuntimeException( 'The REPLACE differential probe requires WordPress wpdb and mysqli.' );
}

$source = (string) getenv( 'MDI_REPLACE_PARITY_SOURCE' );
if ( '/workspace/mdi' !== $source ) {
	throw new RuntimeException( 'MDI_REPLACE_PARITY_SOURCE must identify the staged MDI source package.' );
}
require_once $source . '/inc/class-wp-markdown-wpdb-result-snapshot.php';
require_once $source . '/inc/native/class-wp-markdown-native-wpdb.php';
$source_package = json_decode( (string) file_get_contents( $source . '/.wp-codebox-source-package.json' ), true, 512, JSON_THROW_ON_ERROR );
$source_digest = $source_package['digest']['sha256'] ?? '';
if ( ! is_string( $source_digest ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_digest ) ) {
	throw new RuntimeException( 'The staged MDI source package requires a verified digest.' );
}

// Playground may reuse an OS process while resetting PHP request state. The
// standalone REPLACE test supplies the separate OS-process persistence proof.
if ( ! defined( 'MDI_REPLACE_PARITY_REQUEST_ID' ) ) {
	define( 'MDI_REPLACE_PARITY_REQUEST_ID', bin2hex( random_bytes( 16 ) ) );
}

/** @return array{host:string,port:int,user:string,password:string,database:string} */
function mdi_replace_reference_config(): array {
	$values = array();
	foreach ( array( 'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME' ) as $name ) {
		$value = getenv( $name );
		if ( false === $value || '' === $value ) {
			throw new RuntimeException( "The disposable reference database requires {$name}." );
		}
		$values[ $name ] = $value;
	}
	if ( ! ctype_digit( $values['DB_PORT'] ) || 0 === (int) $values['DB_PORT'] ) {
		throw new RuntimeException( 'The disposable reference database has an invalid DB_PORT.' );
	}
	return array( 'host' => $values['DB_HOST'], 'port' => (int) $values['DB_PORT'], 'user' => $values['DB_USER'], 'password' => $values['DB_PASSWORD'], 'database' => $values['DB_NAME'] );
}

/** @return array<int,array<string,string|null>> */
function mdi_replace_rows( array $rows ): array {
	return array_map(
		static function ( array $row ): array {
			ksort( $row );
			return array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row );
		},
		$rows
	);
}

/** @return array<int,array{Level:string,Code:string,Message:string}> */
function mdi_replace_warnings( array $rows ): array {
	return array_map(
		static fn( array $row ): array => array( 'Level' => (string) ( $row['Level'] ?? '' ), 'Code' => (string) ( $row['Code'] ?? '' ), 'Message' => substr( (string) ( $row['Message'] ?? '' ), 0, 512 ) ),
		array_slice( $rows, 0, 64 )
	);
}

/** @return array<string,mixed> */
function mdi_replace_statement( wpdb $database, string $sql ): array {
	$exception = null;
	try {
		$return_value = $database->query( $sql );
	} catch ( Throwable $error ) {
		$return_value = false;
		$exception = $error;
	}
	$snapshot = WP_Markdown_WPDB_Result_Snapshot::capture( $return_value, $database, $exception );
	if ( false === $database->query( 'SELECT @@SESSION.warning_count AS warning_count' ) || ! isset( $database->last_result[0]->warning_count ) ) {
		throw new RuntimeException( get_class( $database ) . ' could not read the statement warning count.' );
	}
	$warning_count = (int) $database->last_result[0]->warning_count;
	if ( false === $database->query( 'SHOW WARNINGS' ) ) {
		throw new RuntimeException( get_class( $database ) . ' could not read statement warnings.' );
	}
	return array(
		'snapshot'      => $snapshot,
		'warning_count' => $warning_count,
		'warnings'      => mdi_replace_warnings( array_map( static fn( object|array $row ): array => (array) $row, $database->last_result ) ),
	);
}

/** @return array<int,array<string,string|null>> */
function mdi_replace_table_rows( wpdb $database, string $table ): array {
	$return_value = $database->query( "SELECT id, handle, path, state, payload FROM `{$table}` ORDER BY id" );
	if ( false === $return_value ) {
		throw new RuntimeException( get_class( $database ) . ' could not read the persisted REPLACE fixture.' );
	}
	$snapshot = WP_Markdown_WPDB_Result_Snapshot::capture( $return_value, $database, null, true );
	return mdi_replace_rows( $snapshot['rows'] );
}

/** @return array<string,mixed> */
function mdi_replace_semantic_snapshot( array $snapshot ): array {
	$error_code = $snapshot['error_code'] ?? null;
	return array(
		'return'        => $snapshot['return'] ?? null,
		'rows'          => mdi_replace_rows( $snapshot['rows'] ?? array() ),
		'columns'       => $snapshot['columns'] ?? array(),
		'last_error'    => $snapshot['last_error'] ?? '',
		'error_code'    => is_int( $error_code ) || is_string( $error_code ) ? (string) $error_code : null,
		'insert_id'     => (int) ( $snapshot['insert_id'] ?? 0 ),
		'rows_affected' => (int) ( $snapshot['rows_affected'] ?? 0 ),
		'num_rows'      => (int) ( $snapshot['num_rows'] ?? 0 ),
		'exception'     => $snapshot['exception'] ?? null,
	);
}

/** @return array<int,array{identity:string,field:string,native:mixed,reference:mixed}> */
function mdi_replace_differences( string $identity, array $native, array $reference ): array {
	$differences = array();
	foreach ( array( 'snapshot', 'warning_count', 'warnings', 'rows' ) as $field ) {
		$native_value = 'snapshot' === $field ? mdi_replace_semantic_snapshot( $native['snapshot'] ?? array() ) : ( $native[ $field ] ?? null );
		$reference_value = 'snapshot' === $field ? mdi_replace_semantic_snapshot( $reference['snapshot'] ?? array() ) : ( $reference[ $field ] ?? null );
		if ( $native_value !== $reference_value ) {
			$differences[] = array( 'identity' => $identity, 'field' => $field, 'native' => $native_value, 'reference' => $reference_value );
		}
	}
	return $differences;
}

$root = (string) getenv( 'MDI_REPLACE_PARITY_STATE' );
if ( ! preg_match( '#^/tmp/mdi-377-replace-parity$#D', $root ) ) {
	throw new RuntimeException( 'MDI_REPLACE_PARITY_STATE must be the owned /tmp/mdi-377-replace-parity root.' );
}
$marker_path = $root . '/phase.json';
$report_path = $root . '/report.json';
$fixture_id = substr( hash( 'sha256', $root ), 0, 10 );
$tables = array( 'empty' => 'wp_mdi377_' . $fixture_id . '_empty', 'strict' => 'wp_mdi377_' . $fixture_id . '_strict' );

if ( ! is_file( $marker_path ) ) {
	if ( file_exists( $root ) || ! mkdir( $root . '/_options', 0700, true ) || ! mkdir( $root . '/_tables', 0700, true ) ) {
		throw new RuntimeException( 'The first REPLACE phase requires a new owned fixture root.' );
	}
	$config = mdi_replace_reference_config();
	$reference = new wpdb( $config['user'], $config['password'], $config['database'], $config['host'] . ':' . $config['port'] );
	$reference->set_prefix( 'wp_' );
	if ( ! $reference->ready ) {
		throw new RuntimeException( 'The disposable reference wpdb connection did not become ready.' );
	}
	$native = new WP_Markdown_Native_WPDB( WP_Markdown_Native_Runtime_Factory::runtime( $root ), 'wp_' );
	$phase = array( 'schema' => 'mdi-native-replace-parity-phase/v1', 'writer_pid' => getmypid(), 'writer_request_id' => MDI_REPLACE_PARITY_REQUEST_ID, 'fixture_id' => $fixture_id, 'tables' => $tables, 'modes' => array(), 'differences' => array() );
	$phase['source_package'] = array( 'sha256' => $source_digest );
	$owned_reference_tables = array();
	try {
		foreach ( array( 'empty' => '', 'strict' => 'STRICT_TRANS_TABLES' ) as $mode_id => $mode ) {
			$table = $tables[ $mode_id ];
			$mode_sql = "SET SESSION sql_mode = '{$mode}'";
			$native_mode = mdi_replace_statement( $native, $mode_sql );
			$reference_mode = mdi_replace_statement( $reference, $mode_sql );
			if ( false === ( $native_mode['snapshot']['return']['value'] ?? false ) || false === ( $reference_mode['snapshot']['return']['value'] ?? false ) ) {
				throw new RuntimeException( 'The REPLACE fixture SQL mode was rejected before DML.' );
			}
			if ( false === $reference->query( "SHOW TABLES LIKE '{$table}'" ) ) {
				throw new RuntimeException( 'The disposable reference database could not check fixture ownership.' );
			}
			if ( 0 !== (int) $reference->num_rows ) {
				throw new RuntimeException( 'The disposable reference database already contains the REPLACE fixture table.' );
			}
			$create = "CREATE TABLE `{$table}` (id bigint unsigned NOT NULL auto_increment, handle varchar(191) NOT NULL, path varchar(191) NOT NULL, state varchar(16) NOT NULL DEFAULT 'ready', payload longtext NULL, PRIMARY KEY (id), UNIQUE KEY handle (handle))";
			$native_create = mdi_replace_statement( $native, $create );
			$reference_create = mdi_replace_statement( $reference, $create );
			if ( false !== ( $reference_create['snapshot']['return']['value'] ?? false ) ) {
				$owned_reference_tables[] = $table;
			}
			if ( false === ( $native_create['snapshot']['return']['value'] ?? false ) || false === ( $reference_create['snapshot']['return']['value'] ?? false ) ) {
				throw new RuntimeException( 'The REPLACE fixture CREATE statement was rejected before DML.' );
			}
			$steps = array(
				'session-mode-alias' => 'SELECT @@SESSION.sql_mode AS `sql``mode`',
				'warning-count-alias' => 'SELECT @@SESSION.warning_count `warning``count`',
				'insert' => "REPLACE INTO `{$table}` (`handle`, `path`, `payload`) VALUES ('alpha', '/first', 'one')",
				'primary-conflict' => "REPLACE INTO `{$table}` (`id`, `handle`, `path`, `payload`) VALUES (1, 'primary', '/primary', 'two')",
				'unique-conflict' => "REPLACE INTO `{$table}` (`handle`, `path`, `payload`) VALUES ('primary', '/unique', 'three')",
				'optional-into' => "REPLACE `{$table}` (`handle`, `path`, `payload`) VALUES ('beta', '/beta', 'four')",
				'omitted-required' => "REPLACE INTO `{$table}` (`handle`) VALUES ('partial')",
				'transaction-begin' => 'START TRANSACTION',
				'transaction-replace' => "REPLACE INTO `{$table}` (`handle`, `path`, `payload`) VALUES ('primary', '/rolled-back', 'discarded')",
				'savepoint' => 'SAVEPOINT replace_stage',
				'savepoint-replace' => "REPLACE INTO `{$table}` (`handle`, `path`, `payload`) VALUES ('primary', '/savepoint', 'discarded')",
				'rollback-to-savepoint' => 'ROLLBACK TO SAVEPOINT replace_stage',
				'rollback' => 'ROLLBACK',
			);
			$observations = array();
			foreach ( $steps as $step_id => $sql ) {
				$native_observation = mdi_replace_statement( $native, $sql );
				$reference_observation = mdi_replace_statement( $reference, $sql );
				$native_observation['rows'] = mdi_replace_table_rows( $native, $table );
				$reference_observation['rows'] = mdi_replace_table_rows( $reference, $table );
				$identity = $mode_id . ':' . $step_id;
				$phase['differences'] = array_merge( $phase['differences'], mdi_replace_differences( $identity, $native_observation, $reference_observation ) );
				$observations[ $step_id ] = array( 'sql' => $sql, 'native' => $native_observation, 'reference' => $reference_observation );
			}
			$phase['differences'] = array_merge( $phase['differences'], mdi_replace_differences( $mode_id . ':set-mode', $native_mode, $reference_mode ), mdi_replace_differences( $mode_id . ':create', $native_create, $reference_create ) );
			$phase['modes'][ $mode_id ] = array( 'sql_mode' => $mode, 'observations' => $observations, 'final_native_rows' => mdi_replace_table_rows( $native, $table ), 'final_reference_rows' => mdi_replace_table_rows( $reference, $table ) );
		}
		$phase['reference_server_version'] = $reference->db_server_info();
		$phase['native_server_version'] = $native->db_server_info();
		$phase['baseline_commit'] = preg_match( '/^[0-9a-f]{7,64}$/D', (string) getenv( 'MDI_BASELINE_COMMIT' ) ) ? getenv( 'MDI_BASELINE_COMMIT' ) : null;
		$phase['candidate_commit'] = preg_match( '/^[0-9a-f]{7,64}$/D', (string) getenv( 'MDI_CANDIDATE_COMMIT' ) ) ? getenv( 'MDI_CANDIDATE_COMMIT' ) : null;
		if ( false === file_put_contents( $marker_path, json_encode( $phase, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n", LOCK_EX ) ) {
			throw new RuntimeException( 'Could not persist the REPLACE phase evidence.' );
		}
	} catch ( Throwable $error ) {
		$cleanup_failed = false;
		foreach ( $owned_reference_tables as $table ) {
			if ( false === $reference->query( "DROP TABLE IF EXISTS `{$table}`" ) ) {
				$cleanup_failed = true;
			}
		}
		if ( $cleanup_failed ) {
			throw new RuntimeException( 'Reference fixture cleanup failed after an incomplete first phase.', 0, $error );
		}
		throw $error;
	} finally {
		$reference->close();
		$native->close();
	}
	exit( 0 );
}

if ( is_file( $report_path ) ) {
	throw new RuntimeException( 'The verification phase refuses a stale existing report.' );
}
$phase = json_decode( (string) file_get_contents( $marker_path ), true, 512, JSON_THROW_ON_ERROR );
if ( ! is_array( $phase ) || 'mdi-native-replace-parity-phase/v1' !== ( $phase['schema'] ?? null ) || $fixture_id !== ( $phase['fixture_id'] ?? null ) || $source_digest !== ( $phase['source_package']['sha256'] ?? null ) || ! is_string( $phase['writer_request_id'] ?? null ) || MDI_REPLACE_PARITY_REQUEST_ID === $phase['writer_request_id'] ) {
	throw new RuntimeException( 'The verification phase requires the first phase marker from a distinct PHP request.' );
}
$config = mdi_replace_reference_config();
$reference = new wpdb( $config['user'], $config['password'], $config['database'], $config['host'] . ':' . $config['port'] );
$reference->set_prefix( 'wp_' );
$native = new WP_Markdown_Native_WPDB( WP_Markdown_Native_Runtime_Factory::runtime( $root ), 'wp_' );
$differences = $phase['differences'] ?? array();
$cleanup_failures = array();
try {
	foreach ( $tables as $mode_id => $table ) {
		$native_rows = mdi_replace_table_rows( $native, $table );
		$reference_rows = mdi_replace_table_rows( $reference, $table );
		foreach ( array( 'native-persistence' => array( $native_rows, $phase['modes'][ $mode_id ]['final_native_rows'] ?? null ), 'reference-persistence' => array( $reference_rows, $phase['modes'][ $mode_id ]['final_reference_rows'] ?? null ), 'cross-runtime-persistence' => array( $native_rows, $reference_rows ) ) as $identity => $comparison ) {
			if ( $comparison[0] !== $comparison[1] ) {
				$differences[] = array( 'identity' => $mode_id . ':' . $identity, 'field' => 'rows', 'native' => $comparison[0], 'reference' => $comparison[1] );
			}
		}
	}
	$report = array( 'schema' => 'mdi-native-replace-parity/v1', 'passed' => array() === $differences, 'fixture_id' => $fixture_id, 'writer_process_id' => $phase['writer_pid'], 'verifier_process_id' => getmypid(), 'baseline_commit' => $phase['baseline_commit'] ?? null, 'candidate_commit' => $phase['candidate_commit'] ?? null, 'native_server_version' => $phase['native_server_version'] ?? null, 'reference_server_version' => $reference->db_server_info(), 'modes' => $phase['modes'] ?? array(), 'mismatch_count' => count( $differences ), 'mismatches' => array_slice( $differences, 0, 128 ) );
	$report['verification_boundary'] = 'fresh_php_request';
	$report['source_package'] = $phase['source_package'];
	$report['writer_request_id'] = $phase['writer_request_id'];
	$report['verifier_request_id'] = MDI_REPLACE_PARITY_REQUEST_ID;
} finally {
	foreach ( $tables as $table ) {
		if ( false === $reference->query( "DROP TABLE IF EXISTS `{$table}`" ) ) {
			$cleanup_failures[] = $table;
		}
	}
	$reference->close();
	$native->close();
}
$report['cleanup_failures'] = $cleanup_failures;
$report['passed'] = $report['passed'] && array() === $cleanup_failures;
if ( false === file_put_contents( $report_path, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n", LOCK_EX ) ) {
	throw new RuntimeException( 'Could not persist the REPLACE parity report.' );
}
echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
exit( $report['passed'] ? 0 : 1 );
