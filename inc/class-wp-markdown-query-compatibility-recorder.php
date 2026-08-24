<?php
/** wpdb observation recorder for backend compatibility work. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Query_Compatibility_Recorder {
	public const SCHEMA = 'mdi-query-compatibility-corpus/v1';
	private int $sequence = 0;
	/** @var array<int,array<string,mixed>> */
	private array $observations = array();
	/** @var array<int,array{scenario:string,class:string,message:string}> */
	private array $capture_failures = array();

	public function __construct( private WP_Markdown_Query_Compatibility_Normalizer $normalizer ) {}

	/**
	 * Observe one query without changing its return value or caller-visible wpdb state.
	 *
	 * @param callable():mixed      $execute
	 * @param callable():array|null $transaction_state
	 */
	public function capture( string $scenario, string $query, callable $execute, object $database, ?callable $transaction_state = null ): mixed {
		if ( '' === trim( $scenario ) || '' === trim( $query ) ) {
			throw new InvalidArgumentException( 'Query corpus observations require a scenario and SQL.' );
		}
		$before = array();
		$capture_ready = true;
		try {
			$before = null === $transaction_state ? array() : $transaction_state();
		} catch ( Throwable $capture_error ) {
			$this->record_capture_failure( $scenario, $capture_error );
			$capture_ready = false;
		}

		$exception = null;
		try {
			$result = $execute();
		} catch ( Throwable $error ) {
			$result = null;
			$exception = $error;
		}

		try {
			if ( ! $capture_ready ) {
				throw new RuntimeException( 'Query compatibility capture failed before execution.' );
			}
			$after = null === $transaction_state ? array() : $transaction_state();
			$this->observations[] = $this->normalizer->value(
				array(
					'schema_version' => 1,
					'sequence' => ++$this->sequence,
					'scenario' => $scenario,
					'query' => trim( $query ),
					'result' => array(
						'return' => $this->typed_value( $result ),
						'rows' => $this->rows( $database->last_result ?? array() ),
						'columns' => $this->columns( $database ),
						'last_error' => (string) ( $database->last_error ?? '' ),
						'error_code' => $this->error_code( $database ),
						'insert_id' => (int) ( $database->insert_id ?? 0 ),
						'rows_affected' => (int) ( $database->rows_affected ?? 0 ),
						'num_rows' => (int) ( $database->num_rows ?? 0 ),
						'exception' => null === $exception ? null : array(
							'class' => get_class( $exception ),
							'message' => $exception->getMessage(),
						),
					),
					'transaction' => array(
						'before' => $before,
						'after' => $after,
					),
				)
			);
		} catch ( Throwable $capture_error ) {
			if ( $capture_ready ) {
				$this->record_capture_failure( $scenario, $capture_error );
			}
		}

		if ( null !== $exception ) {
			throw $exception;
		}
		return $result;
	}

	/** @return array{schema:string,observations:array<int,array<string,mixed>>} */
	public function document(): array {
		if ( $this->capture_failures ) {
			throw new RuntimeException( 'Query compatibility corpus contains capture failures.' );
		}
		return array(
			'schema' => self::SCHEMA,
			'observations' => $this->observations,
		);
	}

	/** @return array<int,array{scenario:string,class:string,message:string}> */
	public function capture_failures(): array {
		return $this->capture_failures;
	}

	public function json(): string {
		return json_encode( $this->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
	}

	private function typed_value( mixed $value ): array {
		$type = match ( true ) {
			null === $value => 'null',
			is_bool( $value ) => 'boolean',
			is_int( $value ) => 'integer',
			is_float( $value ) => 'float',
			is_string( $value ) => 'string',
			default => 'unsupported',
		};
		if ( 'unsupported' === $type ) {
			throw new RuntimeException( 'Query corpus return values must be scalar.' );
		}
		return array(
			'type' => $type,
			'value' => $value,
		);
	}

	private function record_capture_failure( string $scenario, Throwable $error ): void {
		$this->capture_failures[] = array(
			'scenario' => $scenario,
			'class' => get_class( $error ),
			'message' => $this->normalizer->string( $error->getMessage() ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function rows( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) && ! is_object( $row ) ) {
				throw new RuntimeException( 'Query corpus rows must be arrays or objects.' );
			}
			$out[] = (array) $row;
		}
		return $out;
	}

	/** Capture the column metadata exposed through wpdb's public compatibility API. */
	private function columns( object $database ): array {
		if ( ! method_exists( $database, 'get_col_info' ) ) {
			return $this->rows( $database->col_info ?? array() );
		}
		try {
			$property = new ReflectionProperty( $database, 'col_info' );
			$before = $property->getValue( $database );
		} catch ( ReflectionException $error ) {
			return array();
		}
		if ( is_array( $before ) ) {
			return $this->column_names_and_types( $before );
		}
		try {
			$result_property = new ReflectionProperty( $database, 'result' );
			$result = $result_property->getValue( $database );
		} catch ( ReflectionException $error ) {
			return array();
		}
		if ( ! $result instanceof mysqli_result ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_field_tell -- Preserve the native metadata cursor while observing wpdb.
		$offset = mysqli_field_tell( $result );
		try {
			$names = (array) $database->get_col_info( 'name' );
			$types = (array) $database->get_col_info( 'type' );
			$out = array();
			foreach ( $names as $index => $name ) {
				$out[] = array(
					'name' => (string) $name,
					'type' => isset( $types[ $index ] ) ? (string) $types[ $index ] : null,
				);
			}
			return $out;
		} finally {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_field_seek -- Restore the cursor consumed by wpdb::get_col_info().
			mysqli_field_seek( $result, $offset );
			$property->setValue( $database, $before );
		}
	}

	private function column_names_and_types( array $columns ): array {
		$out = array();
		foreach ( $columns as $column ) {
			$column = (array) $column;
			$out[] = array(
				'name' => (string) ( $column['name'] ?? '' ),
				'type' => isset( $column['type'] ) ? (string) $column['type'] : null,
			);
		}
		return $out;
	}

	private function error_code( object $database ): int|string|null {
		if ( isset( $database->last_errno ) && ( is_int( $database->last_errno ) || is_string( $database->last_errno ) ) ) {
			return $database->last_errno;
		}
		try {
			$property = new ReflectionProperty( $database, 'dbh' );
			$connection = $property->getValue( $database );
		} catch ( ReflectionException $error ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- Record the native oracle error code without issuing a query.
		return $connection instanceof mysqli ? mysqli_errno( $connection ) : null;
	}
}
