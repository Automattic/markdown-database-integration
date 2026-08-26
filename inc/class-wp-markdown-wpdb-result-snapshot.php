<?php
/** Canonical caller-visible result snapshot from an authoritative wpdb query. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_WPDB_Result_Snapshot {
	/** @return array<string,mixed> */
	public static function capture(
		mixed $return_value,
		object $database,
		?Throwable $exception = null,
		bool $load_lazy_metadata = false
	): array {
		return array(
			'return'        => self::typed_value( $return_value ),
			'rows'          => self::rows( $database->last_result ?? array() ),
			'columns'       => self::columns( $database, $load_lazy_metadata ),
			'last_error'    => (string) ( $database->last_error ?? '' ),
			'error_code'    => self::error_code( $database ),
			'insert_id'     => (int) ( $database->insert_id ?? 0 ),
			'rows_affected' => (int) ( $database->rows_affected ?? 0 ),
			'num_rows'      => (int) ( $database->num_rows ?? 0 ),
			'exception'     => null === $exception ? null : array(
				'class'   => get_class( $exception ),
				'message' => $exception->getMessage(),
			),
		);
	}

	private static function typed_value( mixed $value ): array {
		$type = match ( true ) {
			null === $value => 'null',
			is_bool( $value ) => 'boolean',
			is_int( $value ) => 'integer',
			is_float( $value ) => 'float',
			is_string( $value ) => 'string',
			default => 'unsupported',
		};
		if ( 'unsupported' === $type ) {
			throw new RuntimeException( 'Query result return values must be scalar.' );
		}
		return array( 'type' => $type, 'value' => $value );
	}

	/** @return array<int,array<string,mixed>> */
	private static function rows( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) && ! is_object( $row ) ) {
				throw new RuntimeException( 'Query result rows must be arrays or objects.' );
			}
			$out[] = (array) $row;
		}
		return $out;
	}

	/** @return array<int,array{name:string,type:string|null}> */
	private static function columns( object $database, bool $load_lazy_metadata ): array {
		if ( ! method_exists( $database, 'get_col_info' ) ) {
			return self::column_names_and_types( $database->col_info ?? array() );
		}
		try {
			$property = new ReflectionProperty( $database, 'col_info' );
			$before = $property->getValue( $database );
		} catch ( ReflectionException $error ) {
			return array();
		}
		if ( is_array( $before ) ) {
			return self::column_names_and_types( $before );
		}

		$result = null;
		try {
			$result_property = new ReflectionProperty( $database, 'result' );
			$result = $result_property->getValue( $database );
		} catch ( ReflectionException $error ) {
			if ( ! $load_lazy_metadata ) {
				return array();
			}
		}
		if ( ! $load_lazy_metadata && ! $result instanceof mysqli_result ) {
			return array();
		}

		$offset = null;
		if ( $result instanceof mysqli_result ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_field_tell -- Preserve the authoritative metadata cursor while observing wpdb.
			$offset = mysqli_field_tell( $result );
		}
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
			if ( null !== $offset ) {
				// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_field_seek -- Restore the cursor consumed by wpdb::get_col_info().
				mysqli_field_seek( $result, $offset );
			}
			$property->setValue( $database, $before );
		}
	}

	private static function column_names_and_types( mixed $columns ): array {
		if ( ! is_array( $columns ) ) {
			return array();
		}
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

	private static function error_code( object $database ): int|string|null {
		if ( isset( $database->last_errno ) && ( is_int( $database->last_errno ) || is_string( $database->last_errno ) ) ) {
			return $database->last_errno;
		}
		try {
			$property = new ReflectionProperty( $database, 'dbh' );
			$connection = $property->getValue( $database );
		} catch ( ReflectionException $error ) {
			return '' === (string) ( $database->last_error ?? '' ) ? 0 : null;
		}
		if ( $connection instanceof mysqli ) {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- Read the authoritative oracle error code without issuing a query.
			return mysqli_errno( $connection );
		}
		return '' === (string) ( $database->last_error ?? '' ) ? 0 : null;
	}
}
