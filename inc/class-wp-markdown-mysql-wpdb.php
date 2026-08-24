<?php
/** Normal mysqli wpdb boundary for mysql-full. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-sql-classifier.php';
require_once __DIR__ . '/class-wp-markdown-query-observer-boundary.php';

class WP_Markdown_MySQL_WPDB extends wpdb {
	public const BOOTSTRAP_ABI = 2;

	/** @var callable|object|null */
	private $mutation_sink;
	private string $markdown_db_database_name;
	/** @var array{active:bool,autocommit:bool,savepoints:string[]} */
	private array $transaction = array( 'active' => false, 'autocommit' => true, 'savepoints' => array() );
	private bool $observing_mutation = false;
	private int $query_depth = 0;
	private mixed $native_shadow_verifier = null;

	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost, $mutation_sink = null ) {
		$this->mutation_sink = $mutation_sink;
		$this->markdown_db_database_name = (string) $dbname;
		parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );
	}

	public function has_mutation_sink(): bool {
		return is_callable( $this->mutation_sink );
	}

	public function set_mutation_sink( $mutation_sink ): void {
		if ( ! is_callable( $mutation_sink ) ) {
			throw new InvalidArgumentException( 'The mysql-full mutation sink must be callable.' );
		}
		$this->mutation_sink = $mutation_sink;
	}

	public function set_native_shadow_verifier( mixed $verifier ): void {
		if ( ! is_object( $verifier ) || ! method_exists( $verifier, 'observe' ) ) {
			throw new InvalidArgumentException( 'The native shadow verifier must observe authoritative queries.' );
		}
		$this->native_shadow_verifier = $verifier;
	}

	public function markdown_db_mysql_connection(): object {
		if ( ! is_object( $this->dbh ) || ! method_exists( $this->dbh, 'query' ) || ! method_exists( $this->dbh, 'prepare' ) ) {
			throw new RuntimeException( 'mysql-full requires a connected mysqli-compatible wpdb handle.' );
		}
		return $this->dbh;
	}

	/** Delegate all execution to stock wpdb, then observe proven-success mutations. */
	public function query( $query ) {
		$effective_query = '';
		$pre_control     = null;
		$pre_mutation    = null;
		$rejected        = false;
		$depth           = ++$this->query_depth;
		$preflight       = function ( $filtered_query ) use ( &$effective_query, &$pre_control, &$pre_mutation, &$rejected, $depth ) {
			if ( $this->query_depth !== $depth ) {
				return $filtered_query;
			}
			$effective_query = (string) $filtered_query;
			$pre_control     = WP_Markdown_SQL_Classifier::transaction_control( $effective_query );
			$pre_mutation    = null === $pre_control ? WP_Markdown_SQL_Classifier::mutation( $effective_query ) : null;
			if ( null !== $pre_mutation && 'DML' === $pre_mutation['type'] && ! $this->transaction['autocommit'] ) {
				$this->transaction['active'] = true;
			}
			if ( preg_match( '/^\s*LOCK\s+TABLES\b/i', $effective_query ) ) {
				$this->invoke_sink_method( 'record_unsupported_boundary_deferred', array( $effective_query, $this->transaction, 'lock_tables_rejected' ) );
				$GLOBALS['markdown_db_mysql_full_diagnostic'] = array( 'code' => 'markdown_db_mysql_full_unsupported_boundary', 'message' => 'mysql-full rejects LOCK TABLES because outbox writes cannot be made observable while arbitrary table locks are active.' );
				$rejected = true;
				return '';
			}
			if ( ! $this->invoke_sink_method( 'before_query', array( $pre_control, $pre_mutation, $effective_query, $this->transaction ), true ) ) {
				$rejected = true;
				return '';
			}
			if ( null === $pre_control && null === $pre_mutation && WP_Markdown_SQL_Classifier::unsupported_transaction_boundary( $effective_query ) && ! preg_match( '/^\s*UNLOCK\s+TABLES\b/i', $effective_query ) && ! $this->invoke_sink_method( 'before_unsupported_boundary', array( $effective_query, $this->transaction ), true ) ) {
				$rejected = true;
				return '';
			}
			return $filtered_query;
		};
		add_filter( 'query', $preflight, PHP_INT_MAX );
		$queries_before = (int) $this->num_queries;
		try {
			$result = parent::query( $query );
		} finally {
			remove_filter( 'query', $preflight, PHP_INT_MAX );
			--$this->query_depth;
		}
		if ( $rejected ) {
			return false;
		}
		$server_attempted = (int) $this->num_queries > $queries_before;
		// The final query filter captures the exact SQL before wpdb sends it to mysqli.
		$effective_query = '' !== $effective_query ? $effective_query : (string) $this->last_query;
		if ( $server_attempted ) {
			WP_Markdown_Query_Observer_Boundary::observe( $this->native_shadow_verifier, $effective_query, $result, $this );
		}
		$control         = WP_Markdown_SQL_Classifier::transaction_control( $effective_query );
		$mutation        = null === $control ? WP_Markdown_SQL_Classifier::mutation( $effective_query ) : null;
		$implicit_commit = ( null !== $mutation && 'DDL' === $mutation['type'] ) || WP_Markdown_SQL_Classifier::unsupported_implicit_commit( $effective_query );
		if ( false === $result ) {
			$this->invoke_sink_method( 'after_failure', array( $control, $mutation, $effective_query, $this->transaction, $server_attempted, $implicit_commit ) );
			// MySQL commits before attempting implicit-commit statements, including ones that fail.
			if ( $server_attempted && $implicit_commit && '' !== $this->last_error ) {
				$this->apply_implicit_ddl_commit();
			}
			return $result;
		}
		if ( null !== $control ) {
			$this->apply_transaction_control( $control );
			$this->invoke_sink_method( 'after_control', array( $control ) );
			return $result;
		}
		if ( $implicit_commit ) {
			$this->apply_implicit_ddl_commit();
			$this->invoke_sink_method( 'after_implicit_commit', array() );
		}
		if ( null !== $mutation && is_callable( $this->mutation_sink ) && ! $this->observing_mutation ) {
			$observation = array(
				'kind'          => 'DDL' === $mutation['type'] ? 'schema' : 'table',
				'operation'     => $mutation['op'],
				'query'         => $effective_query,
				'transaction'   => $this->transaction,
				'database'      => $this->markdown_db_database_name,
				'blog_id'       => (int) ( $this->blogid ?? 0 ),
				'table_prefix'  => (string) ( $this->prefix ?? '' ),
				'base_prefix'   => (string) ( $this->base_prefix ?? '' ),
				'insert_id'     => (int) $this->insert_id,
				'rows_affected' => (int) $this->rows_affected,
				'num_rows'      => (int) $this->num_rows,
				'commit_outbox' => $implicit_commit && ! $this->transaction['autocommit'],
			);
			if ( isset( $mutation['tables'] ) ) {
				$observation['tables'] = $mutation['tables'];
			} else {
				$observation['table'] = $mutation['table'];
			}
			$this->invoke_sink( $observation );
		} elseif ( null === $control && null === $mutation && WP_Markdown_SQL_Classifier::unsupported_transaction_boundary( $effective_query ) ) {
			$method = preg_match( '/^\s*UNLOCK\s+TABLES\b/i', $effective_query ) ? 'record_unsupported_boundary_deferred' : 'record_unsupported_boundary';
			$reason = WP_Markdown_SQL_Classifier::unsupported_implicit_commit( $effective_query ) ? 'unsupported_implicit_commit' : 'unsupported_transaction_boundary';
			$this->invoke_sink_method( $method, array( $effective_query, $this->transaction, $reason ) );
		}
		return $result;
	}

	/** @param array<string,mixed> $observation */
	private function invoke_sink( array $observation ): void {
		$state = $this->public_state();
		$this->observing_mutation = true;
		try {
			( $this->mutation_sink )( $observation );
		} catch ( Throwable $error ) {
			$this->record_observer_failure( $error );
		} finally {
			$this->restore_public_state( $state );
			$this->observing_mutation = false;
		}
	}

	private function invoke_sink_method( string $method, array $arguments, bool $fail_closed = false ): bool {
		if ( $this->observing_mutation || ! is_object( $this->mutation_sink ) || ! method_exists( $this->mutation_sink, $method ) ) {
			return true;
		}
		$state = $this->public_state();
		$this->observing_mutation = true;
		try {
			$this->mutation_sink->{$method}( ...$arguments );
			return true;
		} catch ( Throwable $error ) {
			$this->record_observer_failure( $error );
			return ! $fail_closed;
		} finally {
			$this->restore_public_state( $state );
			$this->observing_mutation = false;
		}
	}

	private function record_observer_failure( Throwable $error ): void {
		$GLOBALS['markdown_db_mysql_full_diagnostic'] = array( 'code' => 'markdown_db_mysql_full_observer_failed', 'message' => $error->getMessage() );
		error_log( 'Markdown DB MySQL mutation observer failed: ' . $error->getMessage() );
	}

	/** @return array<string,mixed> */
	private function public_state(): array {
		$state = array();
		foreach ( get_object_vars( $this ) as $name => $value ) {
			if ( $this->is_public_property( $name ) ) {
				$state[ $name ] = $value;
			}
		}
		return $state;
	}

	/** @param array<string,mixed> $state */
	private function restore_public_state( array $state ): void {
		foreach ( array_keys( get_object_vars( $this ) ) as $name ) {
			if ( $this->is_public_property( $name ) && ! array_key_exists( $name, $state ) ) {
				unset( $this->{$name} );
			}
		}
		foreach ( $state as $name => $value ) {
			$this->{$name} = $value;
		}
	}

	private function is_public_property( string $name ): bool {
		try {
			return ( new ReflectionProperty( $this, $name ) )->isPublic();
		} catch ( ReflectionException $error ) {
			return true;
		}
	}

	/** @param array{action:string,savepoint?:string} $control */
	private function apply_transaction_control( array $control ): void {
		switch ( $control['action'] ) {
			case 'begin':
				$this->transaction['active'] = true;
				$this->transaction['savepoints'] = array();
				break;
			case 'commit':
			case 'rollback':
				$this->end_transaction();
				break;
			case 'commit_chain':
			case 'rollback_chain':
				$this->transaction['active'] = true;
				$this->transaction['savepoints'] = array();
				break;
			case 'autocommit_0':
				$this->transaction['autocommit'] = false;
				break;
			case 'autocommit_1':
				$this->transaction['autocommit'] = true;
				$this->end_transaction();
				break;
			case 'savepoint':
				$this->transaction['savepoints'] = array_values( array_filter( $this->transaction['savepoints'], static fn( string $savepoint ): bool => $savepoint !== $control['savepoint'] ) );
				$this->transaction['savepoints'][] = $control['savepoint'];
				break;
			case 'release_savepoint':
				$this->transaction['savepoints'] = array_values( array_filter( $this->transaction['savepoints'], static fn( string $savepoint ): bool => $savepoint !== $control['savepoint'] ) );
				break;
			case 'rollback_to':
				$position = array_key_last( $this->transaction['savepoints'] );
				while ( is_int( $position ) && $position >= 0 && $this->transaction['savepoints'][ $position ] !== $control['savepoint'] ) {
					--$position;
				}
				if ( is_int( $position ) && $position >= 0 ) {
					$this->transaction['savepoints'] = array_slice( $this->transaction['savepoints'], 0, $position + 1 );
				}
				break;
		}
	}

	/** DDL commits the current transaction and discards all savepoints in MySQL. */
	private function apply_implicit_ddl_commit(): void {
		$this->end_transaction();
	}

	private function end_transaction(): void {
		// With autocommit disabled, the next transactional statement starts a transaction.
		$this->transaction['active'] = false;
		$this->transaction['savepoints'] = array();
	}
}
