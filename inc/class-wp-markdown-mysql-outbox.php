<?php
/** Durable MySQL mutation handoff for the mysql-full backend. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_MySQL_Outbox {
	public const SCHEMA_VERSION = 2;
	private object $connection;
	private string $table;
	protected bool $ready = false;
	/** @var array<int,array<string,mixed>> */
	private array $pending = array();
	private int $flushed = 0;
	/** @var array<string,int> */
	private array $savepoints = array();
	/** @var array<int,array<string,mixed>> */
	private array $boundary_failures = array();
	private int $last_affected_rows = 0;

	public function __construct( object $connection, string $table ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			throw new InvalidArgumentException( 'Invalid MySQL outbox table name.' );
		}
		$this->connection = $connection;
		$this->table      = $table;
		$this->install_schema();
	}

	public function is_ready(): bool {
		return $this->ready;
	}

	/** Flush transaction-local observations before a boundary can commit them. */
	public function before_query( ?array $control, ?array $mutation, string $query, array $transaction ): void {
		$action = $control['action'] ?? null;
		if ( 'DDL' === ( $mutation['type'] ?? null ) || ( $transaction['active'] && in_array( $action, array( 'begin', 'commit', 'commit_chain', 'autocommit_1' ), true ) ) ) {
			$this->flush_pending();
		}
		unset( $query );
	}

	public function before_unsupported_boundary( string $query, array $transaction ): void {
		if ( $transaction['active'] ) {
			$this->flush_pending();
		}
		unset( $query );
	}

	/** Apply a proven-success transaction control to the pending handoff. */
	public function after_control( array $control ): void {
		$action = $control['action'];
		switch ( $action ) {
			case 'savepoint':
				$this->savepoints[ $control['savepoint'] ] = count( $this->pending );
				break;
			case 'release_savepoint':
				unset( $this->savepoints[ $control['savepoint'] ] );
				break;
			case 'rollback_to':
				if ( isset( $this->savepoints[ $control['savepoint'] ] ) ) {
					$position       = $this->savepoints[ $control['savepoint'] ];
					$this->pending  = array_slice( $this->pending, 0, $position );
					$this->flushed  = min( $this->flushed, $position );
					$this->savepoints = array_filter( $this->savepoints, static fn( int $offset ): bool => $offset <= $position );
				}
				break;
			case 'rollback':
			case 'rollback_chain':
			case 'commit':
			case 'commit_chain':
			case 'autocommit_1':
			case 'begin':
				$this->reset_transaction();
				break;
			case 'autocommit_0':
				break;
		}
	}

	public function after_implicit_commit(): void {
		$this->reset_transaction();
	}

	/** Reconcile local state after a failed boundary. */
	public function after_failure( ?array $control, ?array $mutation, string $query, array $transaction, bool $server_attempted = true, bool $implicit_commit = false ): void {
		if ( $implicit_commit && $server_attempted ) {
			// MySQL commits before attempting DDL, even when the DDL itself fails.
			$this->reset_transaction();
			return;
		}
		if ( null !== $control && in_array( $control['action'], array( 'begin', 'commit', 'commit_chain', 'autocommit_1' ), true ) && $transaction['active'] ) {
			// Reflush stable event IDs on retry. The no-op duplicate clause makes this
			// safe whether the server retained, rolled back, or committed the writes.
			$this->flushed = 0;
			$this->record_unsupported_boundary_deferred( $query, $transaction, 'failed_commit_boundary' );
		}
	}

	/** Accept one normalized successful mutation observation. */
	public function __invoke( array $observation ): void {
		$record = $this->normalize_observation( $observation );
		if ( ! empty( $observation['transaction']['active'] ) ) {
			$this->pending[] = $record;
			return;
		}
		$this->persist_record( $record, 'pending' );
		if ( ! empty( $observation['commit_outbox'] ) ) {
			$this->execute( 'COMMIT' );
		}
	}

	public function record_unsupported_boundary( string $query, array $transaction, string $reason = 'unsupported_transaction_boundary' ): void {
		$this->persist_record(
			$this->normalize_payload(
				array(
					'version'     => self::SCHEMA_VERSION,
					'observed_at' => gmdate( 'Y-m-d\TH:i:s.u\Z' ),
					'source'      => 'wpdb',
					'diagnostic'  => array( 'reason' => $reason, 'query' => $query ),
					'transaction' => $transaction,
				)
			),
			'diagnostic'
		);
		if ( 'unsupported_implicit_commit' === $reason && empty( $transaction['autocommit'] ) ) {
			$this->execute( 'COMMIT' );
		}
	}

	public function record_unsupported_boundary_deferred( string $query, array $transaction, string $reason = 'unsupported_transaction_boundary' ): void {
		$this->boundary_failures[] = array( 'reason' => $reason, 'query' => $query, 'transaction' => $transaction, 'observed_at' => gmdate( 'c' ) );
		$this->boundary_failures   = array_slice( $this->boundary_failures, -10 );
	}

	/** Claim a bounded batch. Reusing an active token returns the same lease. */
	public function claim( string $worker_token, int $limit = 100, int $lease_seconds = 60 ): array {
		$this->validate_token( $worker_token );
		$limit         = max( 1, min( 1000, $limit ) );
		$lease_seconds = max( 1, min( 3600, $lease_seconds ) );
		return $this->claim_records( $worker_token, $limit, $lease_seconds );
	}

	public function acknowledge( int $id, string $worker_token, string $lease_token ): bool {
		$this->validate_token( $worker_token );
		$this->validate_token( $lease_token );
		return 0 < $id && $this->acknowledge_record( $id, $worker_token, $lease_token );
	}

	public function fail( int $id, string $worker_token, string $lease_token, string $error, int $retry_delay_seconds = 0 ): bool {
		$this->validate_token( $worker_token );
		$this->validate_token( $lease_token );
		$error = substr( $error, 0, 2048 );
		return 0 < $id && $this->fail_record( $id, $worker_token, $lease_token, $error, max( 0, min( 86400, $retry_delay_seconds ) ) );
	}

	/** Persist an immutable event envelope while this exact lease is current. */
	public function cache_semantic_envelope( int $id, string $worker_token, string $lease_token, array $envelope ): bool {
		$this->validate_token( $worker_token );
		$this->validate_token( $lease_token );
		$json = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		$this->execute_bound( "UPDATE `{$this->table}` SET `semantic_envelope`=? WHERE `id`=? AND `state`='leased' AND `worker_token`=? AND `lease_token`=? AND `leased_until` > NOW() AND (`semantic_envelope` IS NULL OR `semantic_envelope`=?)", 'sisss', array( $json, $id, $worker_token, $lease_token, $json ) );
		return 1 === $this->affected_rows();
	}

	public function diagnostics(): array {
		$diagnostics = $this->read_diagnostics();
		$diagnostics['deferred_unsupported_boundaries'] = count( $this->boundary_failures );
		$diagnostics['deferred_unsupported_boundary_sample'] = $this->boundary_failures ? $this->boundary_failures[ array_key_last( $this->boundary_failures ) ] : null;
		return array_merge( array( 'ready' => $this->ready, 'schema_version' => self::SCHEMA_VERSION, 'table' => $this->table, 'capture_limitations' => array( 'direct mysqli writes', 'separate database connections', 'server-side writers', 'multi-statements', 'stored routine internal statements', 'XA transactions' ) ), $diagnostics );
	}

	protected function install_schema(): void {
		$sql = "CREATE TABLE IF NOT EXISTS `{$this->table}` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`event_id` CHAR(36) NOT NULL,
			`schema_version` SMALLINT UNSIGNED NOT NULL,
			`state` VARCHAR(16) NOT NULL DEFAULT 'pending',
			`database_name` VARCHAR(191) NOT NULL,
			`blog_id` BIGINT UNSIGNED NOT NULL,
			`table_prefix` VARCHAR(191) NOT NULL,
			`base_prefix` VARCHAR(191) NOT NULL,
			`event_kind` VARCHAR(16) NOT NULL,
			`operation_name` VARCHAR(32) NOT NULL,
			`payload` LONGTEXT NOT NULL,
			`payload_sha256` CHAR(64) NOT NULL,
			`created_at` DATETIME NOT NULL,
			`available_at` DATETIME NOT NULL,
			`leased_until` DATETIME NULL,
			`lease_token` VARCHAR(64) NULL,
			`worker_token` VARCHAR(64) NULL,
			`acknowledged_at` DATETIME NULL,
			`acknowledgement_token` VARCHAR(64) NULL,
			`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
			`reclaims` INT UNSIGNED NOT NULL DEFAULT 0,
			`failures` INT UNSIGNED NOT NULL DEFAULT 0,
			`last_error` TEXT NULL,
			`last_error_at` DATETIME NULL,
			`semantic_envelope` LONGTEXT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `mdi_event` (`event_id`),
			KEY `mdi_claim` (`state`,`available_at`,`id`),
			KEY `mdi_lease_reclaim` (`state`,`leased_until`,`id`),
			KEY `mdi_scope` (`database_name`,`blog_id`,`table_prefix`,`id`),
			KEY `mdi_payload` (`payload_sha256`)
		) ENGINE=InnoDB";
		$this->execute( $sql );
		$columns = $this->rows( "SHOW COLUMNS FROM `{$this->table}`" );
		$column_names = array_column( $columns, 'Field' );
		if ( ! in_array( 'semantic_envelope', $column_names, true ) ) {
			$this->execute( "ALTER TABLE `{$this->table}` ADD COLUMN `semantic_envelope` LONGTEXT NULL" );
			$columns = $this->rows( "SHOW COLUMNS FROM `{$this->table}`" );
			$column_names = array_column( $columns, 'Field' );
		}
		$indexes = $this->rows( "SHOW INDEX FROM `{$this->table}`" );
		$index_names  = array_values( array_unique( array_column( $indexes, 'Key_name' ) ) );
		foreach ( array( 'id', 'event_id', 'schema_version', 'state', 'database_name', 'blog_id', 'table_prefix', 'base_prefix', 'event_kind', 'operation_name', 'payload', 'payload_sha256', 'created_at', 'available_at', 'leased_until', 'lease_token', 'worker_token', 'acknowledged_at', 'acknowledgement_token', 'attempts', 'reclaims', 'failures', 'last_error', 'last_error_at', 'semantic_envelope' ) as $column ) {
			if ( ! in_array( $column, $column_names, true ) ) {
				throw new RuntimeException( 'MySQL outbox schema is missing column ' . $column . '.' );
			}
		}
		$column_types = array_column( $columns, 'Type', 'Field' );
		foreach ( array( 'id' => '/^bigint.*unsigned$/', 'event_id' => '/^char\(36\)$/', 'schema_version' => '/^smallint.*unsigned$/', 'state' => '/^varchar\(16\)$/', 'database_name' => '/^varchar\(191\)$/', 'blog_id' => '/^bigint.*unsigned$/', 'table_prefix' => '/^varchar\(191\)$/', 'base_prefix' => '/^varchar\(191\)$/', 'event_kind' => '/^varchar\(16\)$/', 'operation_name' => '/^varchar\(32\)$/', 'payload' => '/^longtext$/', 'payload_sha256' => '/^char\(64\)$/', 'created_at' => '/^datetime$/', 'available_at' => '/^datetime$/', 'leased_until' => '/^datetime$/', 'lease_token' => '/^varchar\(64\)$/', 'worker_token' => '/^varchar\(64\)$/', 'acknowledged_at' => '/^datetime$/', 'acknowledgement_token' => '/^varchar\(64\)$/', 'attempts' => '/^int.*unsigned$/', 'reclaims' => '/^int.*unsigned$/', 'failures' => '/^int.*unsigned$/', 'last_error' => '/^text$/', 'last_error_at' => '/^datetime$/', 'semantic_envelope' => '/^longtext$/' ) as $column => $pattern ) {
			if ( ! preg_match( $pattern, strtolower( (string) ( $column_types[ $column ] ?? '' ) ) ) ) {
				throw new RuntimeException( 'MySQL outbox schema has an incompatible type for column ' . $column . '.' );
			}
		}
		$column_definitions = array_column( $columns, null, 'Field' );
		$required_columns   = array( 'id', 'event_id', 'schema_version', 'state', 'database_name', 'blog_id', 'table_prefix', 'base_prefix', 'event_kind', 'operation_name', 'payload', 'payload_sha256', 'created_at', 'available_at', 'attempts', 'reclaims', 'failures' );
		foreach ( $required_columns as $column ) {
			if ( 'NO' !== ( $column_definitions[ $column ]['Null'] ?? null ) ) {
				throw new RuntimeException( 'MySQL outbox schema has incompatible nullability for column ' . $column . '.' );
			}
		}
		foreach ( array( 'leased_until', 'lease_token', 'worker_token', 'acknowledged_at', 'acknowledgement_token', 'last_error', 'last_error_at', 'semantic_envelope' ) as $column ) {
			if ( 'YES' !== ( $column_definitions[ $column ]['Null'] ?? null ) ) {
				throw new RuntimeException( 'MySQL outbox schema has incompatible nullability for column ' . $column . '.' );
			}
		}
		foreach ( array( 'state' => 'pending', 'attempts' => '0', 'reclaims' => '0', 'failures' => '0' ) as $column => $default ) {
			if ( $default !== (string) ( $column_definitions[ $column ]['Default'] ?? '' ) ) {
				throw new RuntimeException( 'MySQL outbox schema has an incompatible default for column ' . $column . '.' );
			}
		}
		if ( ! str_contains( strtolower( (string) ( $column_definitions['id']['Extra'] ?? '' ) ), 'auto_increment' ) ) {
			throw new RuntimeException( 'MySQL outbox schema requires an auto-increment id.' );
		}
		foreach ( array( 'PRIMARY', 'mdi_event', 'mdi_claim', 'mdi_lease_reclaim', 'mdi_scope', 'mdi_payload' ) as $index ) {
			if ( ! in_array( $index, $index_names, true ) ) {
				throw new RuntimeException( 'MySQL outbox schema is missing index ' . $index . '.' );
			}
		}
		$actual_indexes = array();
		$index_metadata = array();
		foreach ( $indexes as $index ) {
			$actual_indexes[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
			$index_metadata[ $index['Key_name'] ][] = $index;
		}
		foreach ( $actual_indexes as &$index_columns ) {
			ksort( $index_columns );
			$index_columns = array_values( $index_columns );
		}
		$expected_indexes = array( 'PRIMARY' => array( 'id' ), 'mdi_event' => array( 'event_id' ), 'mdi_claim' => array( 'state', 'available_at', 'id' ), 'mdi_lease_reclaim' => array( 'state', 'leased_until', 'id' ), 'mdi_scope' => array( 'database_name', 'blog_id', 'table_prefix', 'id' ), 'mdi_payload' => array( 'payload_sha256' ) );
		foreach ( $expected_indexes as $index => $index_columns ) {
			if ( $index_columns !== ( $actual_indexes[ $index ] ?? null ) ) {
				throw new RuntimeException( 'MySQL outbox schema has an incompatible definition for index ' . $index . '.' );
			}
		}
		foreach ( $index_metadata['mdi_event'] ?? array() as $index ) {
			if ( 0 !== (int) ( $index['Non_unique'] ?? 1 ) || null !== ( $index['Sub_part'] ?? null ) || 'BTREE' !== strtoupper( (string) ( $index['Index_type'] ?? '' ) ) ) {
				throw new RuntimeException( 'MySQL outbox schema requires an unprefixed unique event_id index.' );
			}
		}
		$status = $this->rows_prepared( 'SELECT `ENGINE` AS `Engine` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME`=?', 's', array( $this->table ) )[0] ?? array();
		if ( 'InnoDB' !== ( $status['Engine'] ?? null ) ) {
			throw new RuntimeException( 'MySQL outbox table must use InnoDB.' );
		}
		$this->ready = true;
	}

	/** @param array<string,mixed> $record */
	protected function persist_record( array $record, string $state ): void {
		$scope = $record['payload']['scope'] ?? array();
		$mutation = $record['payload']['mutation'] ?? array();
		$this->execute_bound(
			"INSERT INTO `{$this->table}` (`event_id`,`schema_version`,`state`,`database_name`,`blog_id`,`table_prefix`,`base_prefix`,`event_kind`,`operation_name`,`payload`,`payload_sha256`,`created_at`,`available_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE `event_id`=VALUES(`event_id`)",
			'sississssss',
			array( $record['event_id'], self::SCHEMA_VERSION, $state, (string) ( $record['payload']['database'] ?? '' ), max( 0, (int) ( $scope['blog_id'] ?? 0 ) ), (string) ( $scope['table_prefix'] ?? '' ), (string) ( $scope['base_prefix'] ?? '' ), isset( $record['payload']['diagnostic'] ) ? 'diagnostic' : (string) ( $mutation['kind'] ?? 'table' ), isset( $record['payload']['diagnostic'] ) ? (string) ( $record['payload']['diagnostic']['reason'] ?? 'unsupported' ) : (string) ( $mutation['operation'] ?? '' ), $record['json'], $record['sha256'] )
		);
	}

	protected function claim_records( string $worker_token, int $limit, int $lease_seconds ): array {
		$claim_lock = 'mdi:' . substr( hash( 'sha256', $this->table ), 0, 60 );
		if ( 1 !== (int) $this->scalar_prepared( 'SELECT GET_LOCK(?, 5)', 's', array( $claim_lock ) ) ) {
			throw new RuntimeException( 'Could not acquire the MySQL outbox worker claim lock.' );
		}
		try {
			$existing = $this->rows_prepared( "SELECT * FROM `{$this->table}` WHERE `state`='leased' AND `worker_token`=? AND `leased_until` > NOW() ORDER BY `id` LIMIT 1", 's', array( $worker_token ) );
			if ( $existing ) {
				return $this->decode_rows( $existing );
			}
			$head = $this->rows( "SELECT `id` FROM `{$this->table}` WHERE `state` <> 'acked' AND `state` <> 'diagnostic' ORDER BY `id` LIMIT 1" )[0] ?? null;
			if ( ! is_array( $head ) ) { return array(); }
			$lease_token = $this->uuid();
			$this->execute_bound( "UPDATE `{$this->table}` SET `reclaims`=`reclaims`+IF(`state`='leased',1,0),`state`='leased',`lease_token`=?,`worker_token`=?,`leased_until`=DATE_ADD(NOW(), INTERVAL {$lease_seconds} SECOND),`attempts`=`attempts`+1 WHERE `id`=? AND ((`state` IN ('pending','failed') AND `available_at` <= NOW()) OR (`state`='leased' AND `leased_until` <= NOW()))", 'ssi', array( $lease_token, $worker_token, (int) $head['id'] ) );
			return $this->decode_rows( $this->rows_prepared( "SELECT * FROM `{$this->table}` WHERE `state`='leased' AND `lease_token`=? ORDER BY `id` LIMIT 1", 's', array( $lease_token ) ) );
		} finally {
			$this->scalar_prepared( 'SELECT RELEASE_LOCK(?)', 's', array( $claim_lock ) );
		}
	}

	protected function acknowledge_record( int $id, string $worker_token, string $lease_token ): bool {
		$this->execute_bound( "UPDATE `{$this->table}` SET `state`='acked',`acknowledged_at`=NOW(),`acknowledgement_token`=?,`leased_until`=NULL,`lease_token`=NULL,`worker_token`=NULL WHERE `id`=? AND `state`='leased' AND `worker_token`=? AND `lease_token`=? AND `leased_until` > NOW()", 'siss', array( $lease_token, $id, $worker_token, $lease_token ) );
		if ( 1 === $this->affected_rows() ) {
			return true;
		}
		return (bool) $this->rows_prepared( "SELECT `id` FROM `{$this->table}` WHERE `id`=? AND `state`='acked' AND `acknowledgement_token`=? LIMIT 1", 'is', array( $id, $lease_token ) );
	}

	protected function fail_record( int $id, string $worker_token, string $lease_token, string $error, int $retry_delay_seconds ): bool {
		$this->execute_bound( "UPDATE `{$this->table}` SET `state`='failed',`failures`=`failures`+1,`last_error`=?,`last_error_at`=NOW(),`available_at`=DATE_ADD(NOW(), INTERVAL {$retry_delay_seconds} SECOND),`leased_until`=NULL,`lease_token`=NULL,`worker_token`=NULL WHERE `id`=? AND `state`='leased' AND `worker_token`=? AND `lease_token`=? AND `leased_until` > NOW()", 'siss', array( $error, $id, $worker_token, $lease_token ) );
		return 1 === $this->affected_rows();
	}

	protected function read_diagnostics(): array {
		$aggregate = $this->rows( "SELECT SUM(`state`='pending') AS `pending`,SUM(`state`='leased') AS `leased`,SUM(`state`='failed') AS `failed`,SUM(`state`='diagnostic') AS `unsupported_boundaries`,COALESCE(SUM(`attempts`),0) AS `attempts`,COALESCE(SUM(`reclaims`),0) AS `reclaims`,COALESCE(SUM(`failures`),0) AS `failures`,COALESCE(MAX(TIMESTAMPDIFF(SECOND,`created_at`,NOW())),0) AS `oldest_record_age_seconds` FROM `{$this->table}` WHERE `state` <> 'acked'" )[0] ?? array();
		$last_failure = $this->rows( "SELECT `id`,`last_error`,`last_error_at` FROM `{$this->table}` WHERE `last_error` IS NOT NULL ORDER BY `last_error_at` DESC,`id` DESC LIMIT 1" )[0] ?? null;
		$unsupported  = $this->rows( "SELECT `id`,`payload`,`created_at` FROM `{$this->table}` WHERE `state`='diagnostic' ORDER BY `id` DESC LIMIT 1" )[0] ?? null;
		if ( is_array( $last_failure ) && is_string( $last_failure['last_error'] ?? null ) ) {
			$structured = json_decode( $last_failure['last_error'], true );
			if ( is_array( $structured ) && isset( $structured['stage'], $structured['message'] ) ) {
				$last_failure['diagnostic'] = $structured;
			}
		}
		return array( 'backlog' => array_map( 'intval', $aggregate ), 'last_failure' => $last_failure, 'planning_failure_sample' => ( $last_failure['diagnostic']['stage'] ?? null ) === 'planning' ? $last_failure['diagnostic'] : null, 'unsupported_boundary_sample' => $unsupported ? json_decode( (string) $unsupported['payload'], true ) : null );
	}

	private function flush_pending(): void {
		$count = count( $this->pending );
		while ( $this->flushed < $count ) {
			$this->persist_record( $this->pending[ $this->flushed ], 'pending' );
			++$this->flushed;
		}
	}

	private function reset_transaction(): void {
		$this->pending   = array();
		$this->flushed   = 0;
		$this->savepoints = array();
	}

	/** @return array<string,mixed> */
	private function normalize_observation( array $observation ): array {
		$tables = $observation['tables'] ?? array( $observation['table'] ?? '' );
		$tables = array_values( array_unique( array_filter( array_map( 'strval', $tables ) ) ) );
		sort( $tables, SORT_STRING );
		return $this->normalize_payload(
			array(
				'version'     => self::SCHEMA_VERSION,
				'observed_at' => gmdate( 'Y-m-d\TH:i:s.u\Z' ),
				'source'      => 'wpdb',
				'database'    => (string) ( $observation['database'] ?? '' ),
				'scope'       => array( 'blog_id' => (int) ( $observation['blog_id'] ?? 0 ), 'table_prefix' => (string) ( $observation['table_prefix'] ?? '' ), 'base_prefix' => (string) ( $observation['base_prefix'] ?? '' ) ),
				'mutation'    => array( 'kind' => (string) $observation['kind'], 'operation' => (string) $observation['operation'], 'tables' => $tables, 'sql' => (string) $observation['query'] ),
				'result'      => array( 'insert_id' => (int) ( $observation['insert_id'] ?? 0 ), 'rows_affected' => (int) ( $observation['rows_affected'] ?? 0 ), 'num_rows' => (int) ( $observation['num_rows'] ?? 0 ) ),
				'transaction' => $observation['transaction'] ?? array(),
			)
		);
	}

	/** @return array{event_id:string,payload:array<string,mixed>,json:string,sha256:string} */
	private function normalize_payload( array $payload ): array {
		$payload = $this->sort_keys( $payload );
		$json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		return array( 'event_id' => $this->uuid(), 'payload' => $payload, 'json' => $json, 'sha256' => hash( 'sha256', $json ) );
	}

	private function sort_keys( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( $this, 'sort_keys' ), $value );
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->sort_keys( $item );
		}
		return $value;
	}

	private function uuid(): string {
		$bytes = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}

	private function validate_token( string $token ): void {
		if ( '' === trim( $token ) || 64 < strlen( $token ) ) {
			throw new InvalidArgumentException( 'A non-empty worker token of at most 64 bytes is required.' );
		}
	}

	private function execute( string $sql ): mixed {
		$result = $this->connection->query( $sql );
		if ( false === $result ) {
			$error = property_exists( $this->connection, 'error' ) ? (string) $this->connection->error : 'unknown MySQL error';
			throw new RuntimeException( 'MySQL outbox query failed: ' . $error );
		}
		return $result;
	}

	/** @return array<int,array<string,mixed>> */
	private function rows( string $sql ): array {
		$result = $this->execute( $sql );
		if ( ! is_object( $result ) || ! method_exists( $result, 'fetch_assoc' ) ) {
			return array();
		}
		$rows = array();
		while ( $row = $result->fetch_assoc() ) {
			$rows[] = $row;
		}
		if ( method_exists( $result, 'free' ) ) {
			$result->free();
		}
		return $rows;
	}

	private function execute_prepared( string $sql, string $types, array $values ): object {
		$statement = $this->connection->prepare( $sql );
		if ( false === $statement ) {
			throw new RuntimeException( 'MySQL outbox prepare failed: ' . $this->connection_error() );
		}
		if ( '' !== $types && ! $statement->bind_param( $types, ...$values ) ) {
			throw new RuntimeException( 'MySQL outbox parameter binding failed.' );
		}
		if ( ! $statement->execute() ) {
			$error = property_exists( $statement, 'error' ) ? (string) $statement->error : $this->connection_error();
			throw new RuntimeException( 'MySQL outbox statement failed: ' . $error );
		}
		$this->last_affected_rows = (int) ( $statement->affected_rows ?? $this->connection->affected_rows ?? 0 );
		return $statement;
	}

	private function execute_bound( string $sql, string $types, array $values ): void {
		$statement = $this->execute_prepared( $sql, $types, $values );
		$statement->close();
	}

	/** @return array<int,array<string,mixed>> */
	private function rows_prepared( string $sql, string $types, array $values ): array {
		$statement = $this->execute_prepared( $sql, $types, $values );
		$rows      = array();
		if ( method_exists( $statement, 'get_result' ) ) {
			$result = $statement->get_result();
			if ( is_object( $result ) ) {
				while ( $row = $result->fetch_assoc() ) {
					$rows[] = $row;
				}
				$result->free();
			}
		} elseif ( method_exists( $statement, 'result_metadata' ) ) {
			$metadata = $statement->result_metadata();
			if ( is_object( $metadata ) ) {
				$row        = array();
				$references = array();
				foreach ( $metadata->fetch_fields() as $field ) {
					$row[ $field->name ] = null;
					$references[]        =& $row[ $field->name ];
				}
				$statement->bind_result( ...$references );
				while ( $statement->fetch() ) {
					$rows[] = $row;
				}
				$metadata->free();
			}
		}
		$statement->close();
		return $rows;
	}

	private function scalar_prepared( string $sql, string $types, array $values ): mixed {
		$rows = $this->rows_prepared( $sql, $types, $values );
		return $rows ? reset( $rows[0] ) : null;
	}

	private function connection_error(): string {
		return property_exists( $this->connection, 'error' ) ? (string) $this->connection->error : 'unknown MySQL error';
	}

	private function affected_rows(): int {
		return $this->last_affected_rows;
	}

	/** @return array<int,array<string,mixed>> */
	private function decode_rows( array $rows ): array {
		foreach ( $rows as &$row ) {
			$row['id']      = (int) $row['id'];
			$row['attempts'] = (int) $row['attempts'];
			$row['failures'] = (int) $row['failures'];
			$row['reclaims'] = (int) $row['reclaims'];
			$row['payload']  = json_decode( (string) $row['payload'], true, 512, JSON_THROW_ON_ERROR );
			$row['semantic_envelope'] = null === ( $row['semantic_envelope'] ?? null ) ? null : json_decode( (string) $row['semantic_envelope'], true, 512, JSON_THROW_ON_ERROR );
		}
		return $rows;
	}
}
