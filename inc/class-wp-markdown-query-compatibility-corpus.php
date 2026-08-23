<?php
/** Deterministic wpdb observation corpus for backend compatibility work. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Query_Compatibility_Normalizer {
	/** @var array<string,string> */
	private array $replacements;

	/** @param array<string,string> $replacements Sensitive or volatile bytes keyed by their stable token. */
	public function __construct( array $replacements = array() ) {
		$normalized = array();
		foreach ( $replacements as $token => $value ) {
			if ( ! is_string( $token ) || '' === $token || ! is_string( $value ) || '' === $value ) {
				throw new InvalidArgumentException( 'Query corpus replacements require non-empty string tokens and values.' );
			}
			$normalized[ $value ] = '<' . trim( $token, '<>' ) . '>';
		}
		uksort( $normalized, static fn( string $left, string $right ): int => strlen( $right ) <=> strlen( $left ) ?: strcmp( $left, $right ) );
		$this->replacements = $normalized;
	}

	public function value( mixed $value ): mixed {
		if ( is_string( $value ) ) { return $this->string( $value ); }
		if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) { $out[ $key ] = $this->value( $item ); }
			return $out;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
		throw new InvalidArgumentException( 'Query corpus values must be JSON-compatible.' );
	}

	public function string( string $value ): string {
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		if ( $this->replacements ) { $value = str_replace( array_keys( $this->replacements ), array_values( $this->replacements ), $value ); }
		$value = (string) preg_replace( '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i', '<uuid>', $value );
		return (string) preg_replace( '/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\b/', '<timestamp>', $value );
	}
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
		if ( '' === trim( $scenario ) || '' === trim( $query ) ) { throw new InvalidArgumentException( 'Query corpus observations require a scenario and SQL.' ); }
		$before = array();
		$capture_ready = true;
		try { $before = null === $transaction_state ? array() : $transaction_state(); }
		catch ( Throwable $capture_error ) { $this->record_capture_failure( $scenario, $capture_error ); $capture_ready = false; }
		$exception = null;
		try { $result = $execute(); }
		catch ( Throwable $error ) { $result = null; $exception = $error; }
		try {
			if ( ! $capture_ready ) { throw new RuntimeException( 'Query compatibility capture failed before execution.' ); }
			$after = null === $transaction_state ? array() : $transaction_state();
			$this->observations[] = $this->normalizer->value( array(
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
					'exception' => null === $exception ? null : array( 'class' => get_class( $exception ), 'message' => $exception->getMessage() ),
				),
				'transaction' => array( 'before' => $before, 'after' => $after ),
			) );
		} catch ( Throwable $capture_error ) { if ( $capture_ready ) { $this->record_capture_failure( $scenario, $capture_error ); } }
		if ( null !== $exception ) { throw $exception; }
		return $result;
	}

	/** @return array{schema:string,observations:array<int,array<string,mixed>>} */
	public function document(): array { if ( $this->capture_failures ) { throw new RuntimeException( 'Query compatibility corpus contains capture failures.' ); } return array( 'schema' => self::SCHEMA, 'observations' => $this->observations ); }
	/** @return array<int,array{scenario:string,class:string,message:string}> */
	public function capture_failures(): array { return $this->capture_failures; }

	public function json(): string { return json_encode( $this->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n"; }

	private function typed_value( mixed $value ): array {
		$type = match ( true ) { null === $value => 'null', is_bool( $value ) => 'boolean', is_int( $value ) => 'integer', is_float( $value ) => 'float', is_string( $value ) => 'string', default => 'unsupported' };
		if ( 'unsupported' === $type ) { throw new RuntimeException( 'Query corpus return values must be scalar.' ); }
		return array( 'type' => $type, 'value' => $value );
	}

	private function record_capture_failure( string $scenario, Throwable $error ): void { $this->capture_failures[] = array( 'scenario' => $scenario, 'class' => get_class( $error ), 'message' => $this->normalizer->string( $error->getMessage() ) ); }

	/** @return array<int,array<string,mixed>> */
	private function rows( mixed $rows ): array {
		if ( ! is_array( $rows ) ) { return array(); }
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) && ! is_object( $row ) ) { throw new RuntimeException( 'Query corpus rows must be arrays or objects.' ); }
			$out[] = (array) $row;
		}
		return $out;
	}

	/** Capture the column metadata exposed through wpdb's public compatibility API. */
	private function columns( object $database ): array {
		if ( ! method_exists( $database, 'get_col_info' ) ) { return $this->rows( $database->col_info ?? array() ); }
		try { $property = new ReflectionProperty( $database, 'col_info' ); $before = $property->getValue( $database ); }
		catch ( ReflectionException $error ) { return array(); }
		if ( is_array( $before ) ) { return $this->column_names_and_types( $before ); }
		try { $result_property = new ReflectionProperty( $database, 'result' ); $result = $result_property->getValue( $database ); }
		catch ( ReflectionException $error ) { return array(); }
		if ( ! $result instanceof mysqli_result ) { return array(); }
		$offset = mysqli_field_tell( $result );
		try {
			$names = (array) $database->get_col_info( 'name' );
			$types = (array) $database->get_col_info( 'type' );
			$out = array();
			foreach ( $names as $index => $name ) { $out[] = array( 'name' => (string) $name, 'type' => isset( $types[ $index ] ) ? (string) $types[ $index ] : null ); }
			return $out;
		} finally {
			mysqli_field_seek( $result, $offset );
			$property->setValue( $database, $before );
		}
	}

	private function column_names_and_types( array $columns ): array { $out = array(); foreach ( $columns as $column ) { $column = (array) $column; $out[] = array( 'name' => (string) ( $column['name'] ?? '' ), 'type' => isset( $column['type'] ) ? (string) $column['type'] : null ); } return $out; }

	private function error_code( object $database ): int|string|null {
		if ( isset( $database->last_errno ) && ( is_int( $database->last_errno ) || is_string( $database->last_errno ) ) ) { return $database->last_errno; }
		try { $property = new ReflectionProperty( $database, 'dbh' ); $connection = $property->getValue( $database ); }
		catch ( ReflectionException $error ) { return null; }
		return $connection instanceof mysqli ? mysqli_errno( $connection ) : null;
	}
}

final class WP_Markdown_Query_Compatibility_Comparator {
	/** @return array{compatible:bool,mismatches:array<int,array{path:string,expected:mixed,actual:mixed}>} */
	public static function compare( array $expected, array $actual ): array {
		$mismatches = array();
		self::compare_value( '$', $expected, $actual, $mismatches );
		return array( 'compatible' => ! $mismatches, 'mismatches' => $mismatches );
	}

	private static function compare_value( string $path, mixed $expected, mixed $actual, array &$mismatches ): void {
		if ( gettype( $expected ) !== gettype( $actual ) ) { $mismatches[] = self::mismatch( $path, $expected, $actual ); return; }
		if ( ! is_array( $expected ) ) { if ( $expected !== $actual ) { $mismatches[] = self::mismatch( $path, $expected, $actual ); } return; }
		if ( array_keys( $expected ) !== array_keys( $actual ) ) { $mismatches[] = self::mismatch( $path . '.__keys', array_keys( $expected ), array_keys( $actual ) ); }
		$keys = array_values( array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ), SORT_REGULAR ) );
		foreach ( $keys as $key ) {
			$child = $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $key );
			if ( ! array_key_exists( $key, $expected ) || ! array_key_exists( $key, $actual ) ) { $mismatches[] = self::mismatch( $child, $expected[ $key ] ?? null, $actual[ $key ] ?? null, array_key_exists( $key, $expected ), array_key_exists( $key, $actual ) ); continue; }
			self::compare_value( $child, $expected[ $key ], $actual[ $key ], $mismatches );
		}
	}

	private static function mismatch( string $path, mixed $expected, mixed $actual, bool $expected_present = true, bool $actual_present = true ): array { return array( 'path' => $path, 'expected' => $expected, 'actual' => $actual, 'expected_present' => $expected_present, 'actual_present' => $actual_present ); }
}
