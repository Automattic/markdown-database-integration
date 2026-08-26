<?php
/** DDL schema compiler, generated core catalog, and native descriptor builder. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Schema_Catalog {
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
			throw new InvalidArgumentException( 'Schema DDL contains no CREATE TABLE statements.' );
		}

		$catalog = array();
		foreach ( $tables as $table ) {
			$name = trim( $table[1], '`' );
			$qualified = false;
			foreach ( $prefixes as $prefix ) {
				if ( '' !== $prefix && str_starts_with( $name, $prefix ) ) {
					$name = substr( $name, strlen( $prefix ) );
					$qualified = true;
					break;
				}
			}
			if ( ! $qualified || '' === $name || isset( $catalog[ $name ] ) ) {
				throw new InvalidArgumentException( 'Schema DDL contains a duplicate or unqualified table.' );
			}

			$columns = array();
			$indexes = array();
			foreach ( self::split_definitions( $table[2] ) as $line ) {
				$line = rtrim( trim( $line ), ',' );
				if ( '' === $line ) {
					continue;
				}
				if ( preg_match( '/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY)\s*(?:`?([A-Za-z0-9_]+)`?)?\s*\((.+)\)$/i', $line, $index ) ) {
					$index_columns = array();
					foreach ( explode( ',', $index[3] ) as $column ) {
						if ( ! preg_match( '/^\s*`?([A-Za-z0-9_]+)`?(?:\(([0-9]+)\))?\s*$/', $column, $part ) ) {
							throw new InvalidArgumentException( 'Schema DDL contains an unsupported index expression.' );
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
					throw new InvalidArgumentException( 'Schema DDL contains an unsupported column definition.' );
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
				if ( 1 === preg_match( '/\bPRIMARY\s+KEY\b/i', $tail ) ) {
					$indexes[] = array(
						'name'    => 'PRIMARY',
						'unique'  => true,
						'columns' => array( array( 'name' => $column[1], 'length' => null ) ),
					);
				}
			}
			if ( array() === $columns ) {
				throw new InvalidArgumentException( 'Schema DDL table contains no columns.' );
			}
			$catalog[ $name ] = array( 'columns' => $columns, 'indexes' => $indexes );
		}
		return $catalog;
	}

	/** @return array<int,string> */
	private static function split_definitions( string $body ): array {
		$definitions = array();
		$current = '';
		$depth = 0;
		$quote = null;
		$length = strlen( $body );
		for ( $offset = 0; $offset < $length; ++$offset ) {
			$character = $body[ $offset ];
			if ( null !== $quote ) {
				$current .= $character;
				if ( $character === $quote ) {
					if ( "'" === $quote && $offset + 1 < $length && "'" === $body[ $offset + 1 ] ) {
						$current .= $body[ ++$offset ];
					} else {
						$quote = null;
					}
				}
				continue;
			}
			if ( in_array( $character, array( "'", '"', '`' ), true ) ) {
				$quote = $character;
				$current .= $character;
				continue;
			}
			if ( '(' === $character ) {
				++$depth;
			} elseif ( ')' === $character ) {
				--$depth;
			} elseif ( ',' === $character && 0 === $depth ) {
				$definitions[] = trim( $current );
				$current = '';
				continue;
			}
			$current .= $character;
		}
		if ( '' !== trim( $current ) ) {
			$definitions[] = trim( $current );
		}
		return $definitions;
	}

	/** @return array{schema:string,wordpress_version:string,single_site:array<string,mixed>,multisite:array<string,mixed>,hashes:array<string,string>} */
	public static function artifact(): array {
		static $artifact = null;
		if ( null !== $artifact ) {
			return $artifact;
		}
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
	 * @param 'integer'|'string'|'mixed' $numeric_storage
	 * @param array{columns?:array<string,array<string,mixed>>,natural_order?:string,order_columns?:array<int,string>} $overlay
	 */
	public static function table_schema(
		string $table,
		string $numeric_storage,
		array $overlay = array(),
		bool $multisite = false
	): WP_Markdown_Native_Table_Schema {
		$definitions = self::definitions( $multisite );
		if ( ! isset( $definitions[ $table ] ) ) {
			throw new InvalidArgumentException( 'A generated core table and numeric storage representation are required.' );
		}
		return self::schema( $definitions[ $table ], $numeric_storage, $overlay );
	}

	/**
	 * @param array<string,mixed> $definition
	 * @param 'integer'|'string'|'mixed' $numeric_storage
	 * @param array{columns?:array<string,array<string,mixed>>,natural_order?:string,order_columns?:array<int,string>} $overlay
	 */
	public static function schema(
		array $definition,
		string $numeric_storage,
		array $overlay = array()
	): WP_Markdown_Native_Table_Schema {
		if ( ! isset( $definition['columns'], $definition['indexes'] )
			|| ! is_array( $definition['columns'] )
			|| ! is_array( $definition['indexes'] )
			|| ! in_array( $numeric_storage, array( 'integer', 'string', 'mixed' ), true )
		) {
			throw new InvalidArgumentException( 'A compiled table definition and numeric storage representation are required.' );
		}
		$columns = array();
		foreach ( $definition['columns'] as $name => $column_definition ) {
			$column_overlay = $overlay['columns'][ $name ] ?? array();
			$normalizer = $column_overlay['normalizer'] ?? self::normalizer( $column_definition );
			$columns[ $name ] = new WP_Markdown_Native_Column(
				self::field_type( $column_definition['type'] ),
				(bool) $column_definition['nullable'],
				$column_overlay['validator'] ?? self::validator( $column_definition, $numeric_storage ),
				$normalizer,
				$column_overlay['lookup_operators'] ?? array(),
				$column_overlay['lookup_validator'] ?? null,
				$column_overlay['filter_operators'] ?? array( '=', 'IN' ),
				$column_overlay['filter_validator'] ?? null
			);
		}
		$primary = array_values( array_filter( $definition['indexes'], static fn( array $index ): bool => 'PRIMARY' === $index['name'] ) );
		$natural_order = $overlay['natural_order'] ?? ( $primary[0]['columns'][0]['name'] ?? array_key_first( $columns ) );
		$identity_columns = array_map( static fn( array $column ): string => $column['name'], $primary[0]['columns'] ?? array() );
		return new WP_Markdown_Native_Table_Schema( $columns, $natural_order, $overlay['order_columns'] ?? array(), $identity_columns );
	}

	/** Build the conservative execution contract supported by a generic JSON snapshot. */
	public static function indexed_snapshot_schema(
		array $definition,
		array $column_overlays = array(),
		array $order_columns = array()
	): ?WP_Markdown_Native_Table_Schema {
		$primary = array_values( array_filter( $definition['indexes'] ?? array(), static fn( array $index ): bool => 'PRIMARY' === ( $index['name'] ?? null ) ) );
		$identity_columns = $primary[0]['columns'] ?? array();
		if ( 1 !== count( $primary ) || array() === $identity_columns ) {
			return null;
		}
		$identity = $identity_columns[0]['name'] ?? '';
		foreach ( $identity_columns as $identity_column ) {
			$name = $identity_column['name'] ?? '';
			if ( ! isset( $definition['columns'][ $name ] ) || ! self::is_integer( $definition['columns'][ $name ]['type'] ) ) {
				return null;
			}
		}

		$overlay = array( 'columns' => array(), 'natural_order' => $identity, 'order_columns' => $order_columns );
		foreach ( $definition['columns'] as $name => $column ) {
			$overlay['columns'][ $name ] = array(
				'filter_operators' => self::is_integer( $column['type'] ) ? array( '=', 'IN' ) : array(),
			);
		}
		foreach ( $definition['indexes'] as $index ) {
			$name = $index['columns'][0]['name'] ?? '';
			if ( isset( $definition['columns'][ $name ] ) && self::is_integer( $definition['columns'][ $name ]['type'] ) ) {
				$overlay['columns'][ $name ]['lookup_operators'] = array( '=', 'IN' );
			}
		}
		foreach ( $column_overlays as $name => $column_overlay ) {
			if ( isset( $overlay['columns'][ $name ] ) && is_array( $column_overlay ) ) {
				$overlay['columns'][ $name ] = array_merge( $overlay['columns'][ $name ], $column_overlay );
			}
		}
		return self::schema( $definition, 'mixed', $overlay );
	}

	/** @param array<string,mixed> $definition */
	private static function validator( array $definition, string $numeric_storage ): callable {
		if ( self::is_integer( $definition['type'] ) ) {
			if ( 'integer' === $numeric_storage ) {
				return $definition['unsigned']
					? static fn( mixed $value ): bool => is_int( $value ) && $value >= 0
					: 'is_int';
			}
			if ( 'mixed' === $numeric_storage ) {
				return $definition['unsigned']
					? static fn( mixed $value ): bool => ( is_int( $value ) && $value >= 0 ) || ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) )
					: static fn( mixed $value ): bool => is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?(?:0|[1-9][0-9]*)$/D', $value ) );
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
			default => throw new InvalidArgumentException( 'Schema DDL contains an unsupported SQL field type.' ),
		};
	}
}
