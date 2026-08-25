<?php
/** Generated WordPress core schema catalog and native descriptor builder. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Core_Schema_Catalog {
	private const SCHEMA = 'mdi-native-core-schema/v1';

	/** @param array<int,string> $prefixes @return array<string,array<string,mixed>> */
	public static function compile( string $ddl, array $prefixes ): array {
		$prefixes = array_values( array_unique( array_filter( $prefixes, 'is_string' ) ) );
		usort( $prefixes, static fn( string $left, string $right ): int => strlen( $right ) <=> strlen( $left ) );
		$tables = array();
		foreach ( explode( ';', $ddl ) as $statement ) {
			if ( preg_match( '/CREATE\s+TABLE\s+(`?[A-Za-z0-9_]+`?)\s*\((.*)\)\s*.*$/is', trim( $statement ), $table ) ) {
				$tables[] = $table;
			}
		}
		if ( array() === $tables ) {
			throw new InvalidArgumentException( 'WordPress core schema contains no CREATE TABLE statements.' );
		}

		$catalog = array();
		foreach ( $tables as $table ) {
			$name = trim( $table[1], '`' );
			foreach ( $prefixes as $prefix ) {
				if ( '' !== $prefix && str_starts_with( $name, $prefix ) ) {
					$name = substr( $name, strlen( $prefix ) );
					break;
				}
			}
			if ( '' === $name || isset( $catalog[ $name ] ) ) {
				throw new InvalidArgumentException( 'WordPress core schema contains a duplicate or unqualified table.' );
			}

			$columns = array();
			$indexes = array();
			foreach ( preg_split( '/\R/', trim( $table[2] ) ) ?: array() as $line ) {
				$line = rtrim( trim( $line ), ',' );
				if ( '' === $line ) {
					continue;
				}
				if ( preg_match( '/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY)\s*(?:`?([A-Za-z0-9_]+)`?)?\s*\((.+)\)$/i', $line, $index ) ) {
					$index_columns = array();
					foreach ( explode( ',', $index[3] ) as $column ) {
						if ( ! preg_match( '/^\s*`?([A-Za-z0-9_]+)`?(?:\(([0-9]+)\))?\s*$/', $column, $part ) ) {
							throw new InvalidArgumentException( 'WordPress core schema contains an unsupported index expression.' );
						}
						$index_columns[] = array(
							'name'   => $part[1],
							'length' => isset( $part[2] ) ? (int) $part[2] : null,
						);
					}
					$primary = 0 === strcasecmp( preg_replace( '/\s+/', ' ', $index[1] ), 'PRIMARY KEY' );
					$indexes[] = array(
						'name'    => $primary ? 'PRIMARY' : $index[2],
						'unique'  => $primary || 0 === strcasecmp( preg_replace( '/\s+/', ' ', $index[1] ), 'UNIQUE KEY' ),
						'columns' => $index_columns,
					);
					continue;
				}
				if ( ! preg_match( '/^`?([A-Za-z0-9_]+)`?\s+([A-Za-z]+)(?:\(([^)]*)\))?\s*(unsigned\b)?(.*)$/i', $line, $column ) ) {
					throw new InvalidArgumentException( 'WordPress core schema contains an unsupported column definition.' );
				}
				$length = null;
				if ( '' !== ( $column[3] ?? '' ) ) {
					$length = ctype_digit( $column[3] ) ? (int) $column[3] : $column[3];
				}
				$tail = $column[5] ?? '';
				$default = null;
				if ( preg_match( '/\bdefault\s+(\'(?:[^\']|\'\')*\'|[^\s,]+)/i', $tail, $matched_default ) ) {
					$literal = $matched_default[1];
					if ( 0 !== strcasecmp( $literal, 'NULL' ) ) {
						$default = str_starts_with( $literal, "'" )
							? str_replace( "''", "'", substr( $literal, 1, -1 ) )
							: $literal;
					}
				}
				$columns[ $column[1] ] = array(
					'type'           => strtolower( $column[2] ),
					'length'         => $length,
					'unsigned'       => '' !== ( $column[4] ?? '' ),
					'nullable'       => 1 !== preg_match( '/\bNOT\s+NULL\b/i', $tail ),
					'default'        => $default,
					'auto_increment' => 1 === preg_match( '/\bauto_increment\b/i', $tail ),
				);
			}
			if ( array() === $columns ) {
				throw new InvalidArgumentException( 'WordPress core schema table contains no columns.' );
			}
			$catalog[ $name ] = array( 'columns' => $columns, 'indexes' => $indexes );
		}
		return $catalog;
	}

	/** @return array{schema:string,wordpress_version:string,single_site:array<string,mixed>,multisite:array<string,mixed>,hashes:array<string,string>} */
	public static function artifact(): array {
		$artifact = require __DIR__ . '/generated/wp-core-schema-catalog.php';
		if ( ! is_array( $artifact ) || self::SCHEMA !== ( $artifact['schema'] ?? null ) ) {
			throw new RuntimeException( 'The generated WordPress core schema catalog is invalid.' );
		}
		return $artifact;
	}

	/** @return array<string,array<string,mixed>> */
	public static function definitions( bool $multisite = false ): array {
		$artifact = self::artifact();
		return $multisite ? $artifact['multisite'] : $artifact['single_site'];
	}

	/** @param array<int,string> $prefixes */
	public static function assert_current( string $ddl, array $prefixes, bool $multisite ): void {
		$compiled = self::compile( $ddl, $prefixes );
		$artifact = self::artifact();
		$scope    = $multisite ? 'multisite' : 'single_site';
		if ( ! hash_equals( $artifact['hashes'][ $scope ], self::hash( $compiled ) ) ) {
			throw new RuntimeException( 'The generated native schema catalog does not match this WordPress core.' );
		}
	}

	/** @param array<string,mixed> $definitions */
	public static function hash( array $definitions ): string {
		return hash( 'sha256', json_encode( $definitions, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
	}

	/**
	 * @param 'integer'|'string' $numeric_storage
	 * @param array{columns?:array<string,array<string,mixed>>,natural_order?:string,order_columns?:array<int,string>} $overlay
	 */
	public static function table_schema(
		string $table,
		string $numeric_storage,
		array $overlay = array(),
		bool $multisite = false
	): WP_Markdown_Native_Table_Schema {
		$definitions = self::definitions( $multisite );
		if ( ! isset( $definitions[ $table ] ) || ! in_array( $numeric_storage, array( 'integer', 'string' ), true ) ) {
			throw new InvalidArgumentException( 'A generated core table and numeric storage representation are required.' );
		}
		$columns = array();
		foreach ( $definitions[ $table ]['columns'] as $name => $definition ) {
			$column_overlay = $overlay['columns'][ $name ] ?? array();
			$normalizer = $column_overlay['normalizer'] ?? self::normalizer( $definition );
			$columns[ $name ] = new WP_Markdown_Native_Column(
				self::field_type( $definition['type'] ),
				(bool) $definition['nullable'],
				$column_overlay['validator'] ?? self::validator( $definition, $numeric_storage ),
				$normalizer,
				$column_overlay['lookup_operators'] ?? array(),
				$column_overlay['lookup_validator'] ?? null
			);
		}
		$primary = array_values( array_filter( $definitions[ $table ]['indexes'], static fn( array $index ): bool => 'PRIMARY' === $index['name'] ) );
		$natural_order = $overlay['natural_order'] ?? ( $primary[0]['columns'][0]['name'] ?? array_key_first( $columns ) );
		return new WP_Markdown_Native_Table_Schema( $columns, $natural_order, $overlay['order_columns'] ?? array() );
	}

	/** @param array<string,mixed> $definition */
	private static function validator( array $definition, string $numeric_storage ): callable {
		if ( self::is_integer( $definition['type'] ) ) {
			if ( 'integer' === $numeric_storage ) {
				return $definition['unsigned']
					? static fn( mixed $value ): bool => is_int( $value ) && $value >= 0
					: 'is_int';
			}
			return $definition['unsigned']
				? static fn( mixed $value ): bool => is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value )
				: static fn( mixed $value ): bool => is_string( $value ) && 1 === preg_match( '/^-?(?:0|[1-9][0-9]*)$/D', $value );
		}
		$maximum = match ( $definition['type'] ) {
			'char', 'varchar' => is_int( $definition['length'] ) ? $definition['length'] : null,
			'tinytext', 'tinyblob' => 255,
			'date' => 10,
			'time' => 10,
			'datetime', 'timestamp' => 19,
			'year' => 4,
			default => null,
		};
		return null === $maximum
			? 'is_string'
			: static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= $maximum;
	}

	/** @param array<string,mixed> $definition */
	private static function normalizer( array $definition ): mixed {
		if ( ! self::is_integer( $definition['type'] ) ) {
			return null;
		}
		return $definition['unsigned']
			? array( WP_Markdown_Native_Runtime_Factory::class, 'normalize_unsigned' )
			: array( WP_Markdown_Native_Runtime_Factory::class, 'normalize_signed' );
	}

	private static function is_integer( string $type ): bool {
		return in_array( $type, array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint' ), true );
	}

	private static function field_type( string $type ): int {
		return match ( $type ) {
			'tinyint' => 1,
			'smallint' => 2,
			'int', 'integer' => 3,
			'float' => 4,
			'double', 'real' => 5,
			'timestamp' => 7,
			'bigint' => 8,
			'mediumint' => 9,
			'date' => 10,
			'time' => 11,
			'datetime' => 12,
			'year' => 13,
			'decimal', 'numeric' => 246,
			'tinyblob', 'mediumblob', 'longblob', 'blob', 'tinytext', 'mediumtext', 'longtext', 'text' => 252,
			'varchar', 'varbinary' => 253,
			'char', 'binary' => 254,
			default => throw new InvalidArgumentException( 'WordPress core schema contains an unsupported MySQL field type.' ),
		};
	}
}
