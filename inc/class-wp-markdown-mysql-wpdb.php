<?php
/** Normal mysqli wpdb boundary for mysql-full. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-sql-classifier.php';

class WP_Markdown_MySQL_WPDB extends wpdb {
	public const BOOTSTRAP_ABI = 1;

	/** @var callable|null */
	private $mutation_sink;
	/** @var array{active:bool,autocommit:bool,savepoints:string[]} */
	private array $transaction = array( 'active' => false, 'autocommit' => true, 'savepoints' => array() );
	private bool $observing_mutation = false;

	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost, ?callable $mutation_sink = null ) {
		$this->mutation_sink = $mutation_sink;
		parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );
	}

	public function has_mutation_sink(): bool {
		return null !== $this->mutation_sink;
	}

	/** Delegate all execution to stock wpdb, then observe proven-success mutations. */
	public function query( $query ) {
		$result = parent::query( $query );
		// Core's query filter may transform the caller input before it reaches mysqli.
		$effective_query = (string) $this->last_query;
		$control         = WP_Markdown_SQL_Classifier::transaction_control( $effective_query );
		$mutation        = null === $control ? WP_Markdown_SQL_Classifier::mutation( $effective_query ) : null;
		if ( false === $result ) {
			// MySQL commits before attempting DDL, including DDL that then fails.
			if ( null !== $mutation && 'DDL' === $mutation['type'] && '' !== $this->last_error ) {
				$this->apply_implicit_ddl_commit();
			}
			return $result;
		}
		if ( null !== $control ) {
			$this->apply_transaction_control( $control );
			return $result;
		}
		if ( null !== $mutation && 'DDL' === $mutation['type'] ) {
			$this->apply_implicit_ddl_commit();
		}
		if ( null !== $mutation && null !== $this->mutation_sink && ! $this->observing_mutation ) {
			$state = $this->public_state();
			$this->observing_mutation = true;
			try {
				$observation = array(
					'kind'        => 'DDL' === $mutation['type'] ? 'schema' : 'table',
					'operation'   => $mutation['op'],
					'query'       => $effective_query,
					'transaction' => $this->transaction,
				);
				if ( isset( $mutation['tables'] ) ) {
					$observation['tables'] = $mutation['tables'];
				} else {
					$observation['table'] = $mutation['table'];
				}
				( $this->mutation_sink )( $observation );
			} catch ( Throwable $error ) {
				$GLOBALS['markdown_db_mysql_full_diagnostic'] = array( 'code' => 'markdown_db_mysql_full_observer_failed', 'message' => $error->getMessage() );
				error_log( 'Markdown DB MySQL mutation observer failed: ' . $error->getMessage() );
			} finally {
				$this->restore_public_state( $state );
				$this->observing_mutation = false;
			}
		}
		return $result;
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
				$this->transaction['active'] = true;
				$this->transaction['savepoints'] = array();
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
		// With autocommit disabled, MySQL starts the next transaction immediately.
		$this->transaction['active'] = ! $this->transaction['autocommit'];
		$this->transaction['savepoints'] = array();
	}
}
