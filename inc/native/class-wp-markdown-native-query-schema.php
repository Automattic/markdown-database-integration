<?php
/** Schema descriptors and exact table-provider registry. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Column {
	/** @param array<int,string> $lookup_operators @param array<int,string> $filter_operators */
	public function __construct(
		private readonly int $type,
		private readonly bool $nullable,
		private readonly mixed $validator = null,
		private readonly mixed $normalizer = null,
		private readonly array $lookup_operators = array(),
		private readonly mixed $lookup_validator = null,
		private readonly array $filter_operators = array( '=', 'IN', 'NOT IN', '<>', 'LIKE', 'NOT LIKE', '<', '<=', '>', '>=', 'BETWEEN' ),
		private readonly mixed $filter_validator = null
	) {
		foreach ( array( $validator, $normalizer, $lookup_validator, $filter_validator ) as $callback ) {
			if ( null !== $callback && ! is_callable( $callback ) ) {
				throw new InvalidArgumentException( 'Column callbacks must be callable.' );
			}
		}
	}

	public function type(): int {
		return $this->type;
	}

	public function validates( mixed $value ): bool {
		if ( null === $value ) {
			return $this->nullable;
		}
		return null === $this->validator || ( $this->validator )( $value );
	}

	public function normalize( mixed $value ): mixed {
		return null === $this->normalizer ? $value : ( $this->normalizer )( $value );
	}

	/** @param array<int,int|string> $values */
	public function allows_lookup( string $operator, array $values ): bool {
		return in_array( $operator, $this->lookup_operators, true )
			&& ( null === $this->lookup_validator || ( $this->lookup_validator )( $values ) );
	}

	public function supports_lookup(): bool {
		return array() !== $this->lookup_operators;
	}

	/** @param array<int,int|string> $values */
	public function allows_filter( string $operator, array $values ): bool {
		if ( ! in_array( $operator, $this->filter_operators, true ) || array() === $values ) {
			return false;
		}
		if ( 'LIKE' === $operator || 'NOT LIKE' === $operator ) {
			foreach ( $values as $value ) {
				if ( ! is_string( $value ) || 1 === preg_match( '/[^\x00-\x7F]/', $value ) ) {
					return false;
				}
			}
			return true;
		}
		foreach ( $values as $value ) {
			if ( ! $this->validates( $value ) ) {
				return false;
			}
		}
		return null === $this->filter_validator || ( $this->filter_validator )( $values );
	}
}

final class WP_Markdown_Native_Table_Schema {

	/**
	 * @param array<string,WP_Markdown_Native_Column> $columns       Column declarations.
	 * @param string                                  $natural_order Natural row order column.
	 * @param array<int,string>                       $order_columns Explicitly orderable columns.
	 * @param array<int,string>                       $identity_columns Composite natural identity columns.
	 */
	public function __construct(
		private array $columns,
		private string $natural_order,
		private array $order_columns = array(),
		private array $identity_columns = array(),
		private array $definition = array()
	) {
		foreach ( $columns as $column ) {
			if ( ! $column instanceof WP_Markdown_Native_Column ) {
				throw new InvalidArgumentException( 'Every schema column must use a typed descriptor.' );
			}
		}
		if ( ! isset( $columns[ $natural_order ] ) ) {
			throw new InvalidArgumentException( 'The natural order column must exist.' );
		}
		$this->identity_columns = array() === $this->identity_columns ? array( $natural_order ) : $this->identity_columns;
		foreach ( $this->identity_columns as $column ) {
			if ( ! isset( $columns[ $column ] ) ) {
				throw new InvalidArgumentException( 'Every identity column must exist in the schema.' );
			}
		}
		foreach ( $order_columns as $column ) {
			if ( ! isset( $columns[ $column ] ) ) {
				throw new InvalidArgumentException( 'Every order column must exist in the schema.' );
			}
		}
	}

	/** @return array<int,string> */
	public function column_names(): array {
		return array_keys( $this->columns );
	}

	public function column( string $column ): WP_Markdown_Native_Column {
		if ( ! isset( $this->columns[ $column ] ) ) {
			throw new OutOfBoundsException( 'The requested column is not declared.' );
		}
		return $this->columns[ $column ];
	}

	public function has_column( string $column ): bool {
		return isset( $this->columns[ $column ] );
	}

	public function natural_order(): string {
		return $this->natural_order;
	}

	/** @return array<int,string> */
	public function identity_columns(): array {
		return $this->identity_columns;
	}

	/** @return array{columns:array<string,array<string,mixed>>,indexes:array<int,array<string,mixed>>}|array{} */
	public function definition(): array {
		return $this->definition;
	}

	public function is_lookup( string $column ): bool {
		return isset( $this->columns[ $column ] ) && $this->columns[ $column ]->supports_lookup();
	}

	public function allows_order( string $column ): bool {
		return $column === $this->natural_order || in_array( $column, $this->order_columns, true );
	}

	/** @param array<int,int|string> $values */
	public function allows_lookup( string $column, string $operator, array $values ): bool {
		if ( ! $this->is_lookup( $column ) ) {
			return false;
		}
		return $this->columns[ $column ]->allows_lookup( $operator, $values );
	}

	/** @param array<int,int|string> $values */
	public function allows_filter( string $column, string $operator, array $values ): bool {
		if ( ! isset( $this->columns[ $column ] ) ) {
			return false;
		}
		if ( self::is_range_operator( $operator ) && ! $this->allows_range( $column, $values ) ) {
			return false;
		}
		if ( 'BETWEEN' === $operator && ( 2 !== count( $values ) || ! $this->allows_range( $column, $values ) ) ) {
			return false;
		}
		return $this->columns[ $column ]->allows_filter( $operator, $values );
	}

	public function supports_predicate( WP_Markdown_Native_Query_Predicate $predicate ): bool {
		if ( 'SIGNED' === $predicate->cast() ) {
			return $this->has_column( $predicate->column() )
				&& in_array( $predicate->operator(), array( '=', '<>', '<', '<=', '>', '>=' ), true )
				&& 1 === count( $predicate->values() )
				&& null !== $this->signed_integer( $predicate->values()[0] );
		}
		if ( in_array( $predicate->operator(), array( 'AND', 'OR' ), true ) ) {
			if ( array() === $predicate->any() ) {
				return false;
			}
			foreach ( $predicate->any() as $nested ) {
				if ( ! $this->supports_predicate( $nested ) ) {
					return false;
				}
			}
			return true;
		}
		if ( in_array( $predicate->operator(), array( 'IS NULL', 'IS NOT NULL' ), true ) ) {
			return $this->has_column( $predicate->column() );
		}
		if ( 'LOWER =' === $predicate->operator() ) {
			return $this->has_column( $predicate->column() )
				&& 1 === count( $predicate->values() )
				&& null !== WP_Markdown_Native_Runtime_Factory::normalize_ascii_ci( $predicate->values()[0] );
		}
		return $this->allows_lookup( $predicate->column(), $predicate->operator(), $predicate->values() )
			|| $this->allows_filter( $predicate->column(), $predicate->operator(), $predicate->values() );
	}

	/** @param array<string,mixed> $row @param array<int,WP_Markdown_Native_Query_Predicate> $predicates */
	public function matches( array $row, array $predicates ): bool {
		foreach ( $predicates as $predicate ) {
			if ( ! $this->matches_predicate( $row, $predicate ) ) {
				return false;
			}
		}
		return true;
	}

	/** Numeric and temporal field types, whose order is unambiguous. */
	private const RANGE_TYPES = array( 1, 2, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 246 );

	/** Field types that carry arithmetic, as opposed to only an order. */
	private const NUMERIC_TYPES = array( 1, 2, 3, 4, 5, 8, 9, 246 );

	public static function is_range_operator( string $operator ): bool {
		return in_array( $operator, array( '<', '<=', '>', '>=' ), true );
	}

	/** Whether this column can be summed or averaged. */
	public function is_numeric_column( string $column ): bool {
		return isset( $this->columns[ $column ] ) && in_array( $this->columns[ $column ]->type(), self::NUMERIC_TYPES, true );
	}

	/** Whether this column carries one unambiguous order. */
	public function is_comparable_column( string $column ): bool {
		return isset( $this->columns[ $column ] ) && in_array( $this->columns[ $column ]->type(), self::RANGE_TYPES, true );
	}

	/**
	 * Whether a range comparison over this column is deterministic.
	 *
	 * Numeric and temporal columns carry one unambiguous order, so a range
	 * over them answers the same question MySQL answers. Text ordering depends
	 * on a collation this engine does not implement, so a textual range stays
	 * fail-closed rather than guessing at one.
	 *
	 * @param array<int,int|string> $values
	 */
	private function allows_range( string $column, array $values ): bool {
		if ( array() === $values || ! in_array( $this->columns[ $column ]->type(), self::RANGE_TYPES, true ) ) {
			return false;
		}
		foreach ( $values as $value ) {
			if ( null === $value || null === $this->order_value( $column, $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Order two values of one column, or report that they cannot be ordered.
	 *
	 * @return int|null -1, 0, or 1, or null when either side has no place in
	 *                  this column's declared order.
	 */
	public function ordered_comparison( string $column, mixed $left, mixed $right ): ?int {
		if ( ! isset( $this->columns[ $column ] ) ) {
			return null;
		}
		$left  = $this->order_value( $column, $left );
		$right = $this->order_value( $column, $right );
		if ( null === $left || null === $right ) {
			return null;
		}
		if ( $this->orders_textually( $column ) && ( ! is_string( $left ) || ! is_string( $right ) ) ) {
			return null;
		}
		return $left <=> $right;
	}

	public function values_match( string $column, mixed $left, mixed $right ): bool {
		$left  = $this->column( $column )->normalize( $left );
		$right = $this->column( $column )->normalize( $right );
		return null !== $left && null !== $right && $left === $right;
	}

	public function value_matches_like( string $column, mixed $value, string $pattern ): bool {
		if ( ! is_string( $value ) || 1 === preg_match( '/[^\x00-\x7F]/', $value ) ) {
			return false;
		}
		$regex = '';
		$length = strlen( $pattern );
		for ( $offset = 0; $offset < $length; $offset++ ) {
			$character = $pattern[ $offset ];
			if ( '\\' === $character && $offset + 1 < $length ) {
				$regex .= preg_quote( $pattern[ ++$offset ], '/' );
				continue;
			}
			if ( '%' === $character ) {
				$regex .= '.*';
				continue;
			}
			if ( '_' === $character ) {
				$regex .= '.';
				continue;
			}
			$regex .= preg_quote( $character, '/' );
		}
		return 1 === preg_match( '/^' . $regex . '$/is', $value );
	}

	public function values_differ( string $column, mixed $left, mixed $right ): bool {
		$left  = $this->column( $column )->normalize( $left );
		$right = $this->column( $column )->normalize( $right );
		return null !== $left && null !== $right && $left !== $right;
	}

	public function compare_values( string $column, mixed $left, mixed $right ): int {
		$left  = $this->column( $column )->normalize( $left );
		$right = $this->column( $column )->normalize( $right );
		if ( $this->orders_textually( $column ) ) {
			$left  = is_string( $left ) ? strtolower( $left ) : $left;
			$right = is_string( $right ) ? strtolower( $right ) : $right;
		}
		return $left <=> $right;
	}

	/** @param array<string,mixed> $left @param array<string,mixed> $right */
	public function compare_rows( string $column, array $left, array $right ): int {
		$comparison = $this->compare_values( $column, $left[ $column ], $right[ $column ] );
		if ( 0 !== $comparison || $column !== $this->natural_order ) {
			return $comparison;
		}
		foreach ( $this->identity_columns as $identity ) {
			if ( $identity === $column ) {
				continue;
			}
			$comparison = $this->compare_values( $identity, $left[ $identity ], $right[ $identity ] );
			if ( 0 !== $comparison ) {
				return $comparison;
			}
		}
		return 0;
	}

	/**
	 * @param array<int,array{column:string,descending:bool}> $order_by
	 * @param array<int,array<string,mixed>>                  $rows
	 */
	public function unsupported_order_reason( array $order_by, array $rows ): ?string {
		foreach ( $order_by as $item ) {
			$case = $item['case'] ?? null;
			if ( null !== $case ) {
				foreach ( $case['branches'] as $branch ) {
					foreach ( $branch['predicates'] as $predicate ) {
						if ( ! $this->supports_predicate( $predicate ) ) {
							return 'unsupported_order';
						}
					}
				}
				continue;
			}
			$column = $item['column'];
			$pattern = $item['like'] ?? null;
			if ( null !== $pattern ) {
				// Ordering by a match ranks rows by a value the engine derives,
				// so the column must answer the pattern rather than be orderable.
				if ( ! $this->allows_filter( $column, 'LIKE', array( $pattern ) ) ) {
					return 'unsupported_order';
				}
				continue;
			}
			if ( ! $this->allows_order( $column ) ) {
				return 'unsupported_order';
			}
			if ( ! $this->orders_textually( $column ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				$value = $row[ $column ] ?? null;
				if ( null === $value ) {
					continue;
				}
				if ( ! is_string( $value ) || 1 === preg_match( '/[^\x00-\x7F]/', $value ) ) {
					return 'unsupported_order';
				}
			}
		}
		return null;
	}

	/**
	 * Validate and order rows after normalizing each comparison value once.
	 *
	 * Original offsets are retained because post providers use them to hydrate
	 * the bounded rows from their corresponding canonical files.
	 *
	 * @param array<int,array<string,mixed>>                  $rows
	 * @param array<int,array{column:string,descending:bool}> $order_by
	 * @return array<int,array<string,mixed>>|null
	 */
	public function ordered_rows( array $rows, array $order_by ): ?array {
		if ( null !== $this->unsupported_order_reason( $order_by, $rows ) ) {
			return null;
		}
		$item = 1 === count( $order_by ) ? $order_by[0] : null;
		if ( null !== $item && ( null !== ( $item['case'] ?? null ) || null !== ( $item['like'] ?? null ) || $item['column'] !== $this->natural_order || array() === array_diff( $this->identity_columns, array( $item['column'] ) ) ) ) {
			$keys = array();
			foreach ( $rows as $offset => $row ) {
				$keys[ $offset ] = $this->order_item_value( $item, $row );
			}
			if ( $item['descending'] ) {
				arsort( $keys, SORT_REGULAR );
			} else {
				asort( $keys, SORT_REGULAR );
			}
			$offsets = array_keys( $keys );
		} else {
			$keys = array();
			foreach ( $rows as $offset => $row ) {
				$keys[ $offset ] = $this->order_key( $row, $order_by );
			}
			$offsets = array_keys( $rows );
			usort(
				$offsets,
				static function ( int $left, int $right ) use ( $keys ): int {
					foreach ( $keys[ $left ] as $index => $key ) {
						$comparison = ( $key['descending'] ? -1 : 1 ) * ( $key['value'] <=> $keys[ $right ][ $index ]['value'] );
						if ( 0 !== $comparison ) {
							return $comparison;
						}
					}
					return 0;
				}
			);
		}
		$ordered = array();
		foreach ( $offsets as $offset ) {
			$ordered[ $offset ] = $rows[ $offset ];
		}
		return $ordered;
	}

	/**
	 * @param array<string,mixed>                             $row
	 * @param array<int,array{column:string,descending:bool}> $order_by
	 * @return array<int,array{value:mixed,descending:bool}>
	 */
	private function order_key( array $row, array $order_by ): array {
		$key = array();
		foreach ( $order_by as $item ) {
			if ( null !== ( $item['case'] ?? null ) || null !== ( $item['like'] ?? null ) ) {
				$key[] = array(
					'value'      => $this->order_item_value( $item, $row ),
					'descending' => $item['descending'],
				);
				continue;
			}
			$columns = array( $item['column'] );
			if ( $item['column'] === $this->natural_order ) {
				$columns = array_merge( $columns, array_diff( $this->identity_columns, $columns ) );
			}
			foreach ( $columns as $column ) {
				$key[] = array(
					'value'      => $this->order_value( $column, $row[ $column ] ),
					'descending' => $item['descending'],
				);
			}
		}
		return $key;
	}

	/**
	 * The value one ORDER BY term sorts a row by.
	 *
	 * A match term reports 1 or 0, which is the value MySQL sorts by when a
	 * query ranks rows by a LIKE expression.
	 *
	 * @param array<string,mixed> $item Order term.
	 * @param array<string,mixed> $row  Source row.
	 */
	private function order_item_value( array $item, array $row ): mixed {
		$case = $item['case'] ?? null;
		if ( null !== $case ) {
			foreach ( $case['branches'] as $branch ) {
				if ( $this->matches( $row, $branch['predicates'] ) ) {
					return $branch['value'];
				}
			}
			return $case['else'];
		}
		$pattern = $item['like'] ?? null;
		if ( null === $pattern ) {
			return $this->order_value( $item['column'], $row[ $item['column'] ] ?? null );
		}
		return $this->value_matches_like( $item['column'], $row[ $item['column'] ] ?? null, (string) $pattern ) ? 1 : 0;
	}

	/** @param array<string,mixed> $row */
	private function matches_predicate( array $row, WP_Markdown_Native_Query_Predicate $predicate ): bool {
		if ( 'SIGNED' === $predicate->cast() ) {
			$left  = $this->signed_integer( $row[ $predicate->column() ] ?? null );
			$right = $this->signed_integer( $predicate->values()[0] ?? null );
			if ( null === $left || null === $right ) {
				return false;
			}
			$comparison = $left <=> $right;
			return match ( $predicate->operator() ) {
				'='  => 0 === $comparison,
				'<>' => 0 !== $comparison,
				'<'  => $comparison < 0,
				'<=' => $comparison <= 0,
				'>'  => $comparison > 0,
				default => $comparison >= 0,
			};
		}
		if ( 'AND' === $predicate->operator() ) {
			return $this->matches( $row, $predicate->any() );
		}
		if ( 'OR' === $predicate->operator() ) {
			foreach ( $predicate->any() as $alternative ) {
				if ( $this->matches_predicate( $row, $alternative ) ) {
					return true;
				}
			}
			return false;
		}
		if ( 'IS NULL' === $predicate->operator() ) {
			return null === ( $row[ $predicate->column() ] ?? null );
		}
		if ( 'IS NOT NULL' === $predicate->operator() ) {
			return null !== ( $row[ $predicate->column() ] ?? null );
		}
		if ( 'LOWER =' === $predicate->operator() ) {
			$left  = WP_Markdown_Native_Runtime_Factory::normalize_ascii_ci( $row[ $predicate->column() ] ?? null );
			$right = WP_Markdown_Native_Runtime_Factory::normalize_ascii_ci( $predicate->values()[0] ?? null );
			return null !== $left && null !== $right && $left === $right;
		}
		if ( self::is_range_operator( $predicate->operator() ) ) {
			$comparison = $this->ordered_comparison( $predicate->column(), $row[ $predicate->column() ] ?? null, $predicate->values()[0] ?? null );
			return null !== $comparison && match ( $predicate->operator() ) {
				'<'  => $comparison < 0,
				'<=' => $comparison <= 0,
				'>'  => $comparison > 0,
				default => $comparison >= 0,
			};
		}
		if ( 'BETWEEN' === $predicate->operator() ) {
			$lower = $this->ordered_comparison( $predicate->column(), $row[ $predicate->column() ] ?? null, $predicate->values()[0] ?? null );
			$upper = $this->ordered_comparison( $predicate->column(), $row[ $predicate->column() ] ?? null, $predicate->values()[1] ?? null );
			return null !== $lower && null !== $upper && $lower >= 0 && $upper <= 0;
		}
		$negated = in_array( $predicate->operator(), array( 'NOT IN', 'NOT LIKE' ), true );
		if ( $negated && null === ( $row[ $predicate->column() ] ?? null ) ) {
			return false;
		}
		foreach ( $predicate->values() as $value ) {
			$compare = match ( $predicate->operator() ) {
				'<>' => $this->values_differ( $predicate->column(), $row[ $predicate->column() ] ?? null, $value ),
				'LIKE', 'NOT LIKE' => is_string( $value ) && $this->value_matches_like( $predicate->column(), $row[ $predicate->column() ] ?? null, $value ),
				default => $this->values_match( $predicate->column(), $row[ $predicate->column() ] ?? null, $value ),
			};
			if ( $compare ) {
				return ! $negated;
			}
		}
		return $negated;
	}

	/** Convert a scalar the way MySQL's signed integer cast begins its comparison. */
	private function signed_integer( mixed $value ): ?int {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[\x20\t\n\r\v\f]*([+-]?)([0-9]+)/', $value, $match ) ) {
			return 0;
		}
		$digits = ltrim( $match[2], '0' );
		if ( '' === $digits ) {
			return 0;
		}
		$negative = '-' === $match[1];
		$boundary = $negative ? ltrim( (string) PHP_INT_MIN, '-' ) : (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $boundary ) || ( strlen( $digits ) === strlen( $boundary ) && strcmp( $digits, $boundary ) > 0 ) ) {
			return $negative ? PHP_INT_MIN : PHP_INT_MAX;
		}
		if ( $negative && $digits === ltrim( (string) PHP_INT_MIN, '-' ) ) {
			return PHP_INT_MIN;
		}
		$integer = (int) $digits;
		return $negative ? -$integer : $integer;
	}

	private function order_value( string $column, mixed $value ): mixed {
		$value = $this->column( $column )->normalize( $value );
		return $this->orders_textually( $column ) && is_string( $value ) ? strtolower( $value ) : $value;
	}

	private function orders_textually( string $column ): bool {
		return in_array( $this->column( $column )->type(), array( 252, 253, 254 ), true );
	}

	/** @param array<string,mixed> $row */
	public function identity_key( array $row ): ?string {
		$identity = array();
		foreach ( $this->identity_columns as $column ) {
			$value = $this->column( $column )->normalize( $row[ $column ] ?? null );
			if ( null === $value ) {
				return null;
			}
			$identity[] = $value;
		}
		return serialize( $identity );
	}

	public function value_key( string $column, mixed $value ): ?string {
		$value = $this->column( $column )->normalize( $value );
		return null === $value ? null : serialize( $value );
	}

	/** @return true|string */
	public function validate_row( array $row ) {
		if ( count( $row ) !== count( $this->columns )
			|| array_diff_key( $row, $this->columns )
			|| array_diff_key( $this->columns, $row )
		) {
			return 'invalid_row_schema';
		}
		foreach ( $this->columns as $name => $column ) {
			if ( ! $column->validates( $row[ $name ] ) ) {
				return 'invalid_' . $name;
			}
		}
		return true;
	}

	/** @param array<int,string> $projection @return true|string */
	public function validate_projection( array $row, array $projection ) {
		if ( count( $row ) !== count( $projection ) || array_keys( $row ) !== $projection ) {
			return 'invalid_row_projection';
		}
		foreach ( $projection as $name ) {
			if ( ! $this->column( $name )->validates( $row[ $name ] ) ) {
				return 'invalid_' . $name;
			}
		}
		return true;
	}
}

final class WP_Markdown_Native_Table_Registry {

	/** @var array<string,array{schema:WP_Markdown_Native_Table_Schema,provider:WP_Markdown_Native_Table_Provider}> */
	private array $tables = array();
	/** @var array<string,array<string,mixed>> */
	private array $definitions = array();

	public function register(
		string $table,
		WP_Markdown_Native_Table_Schema $schema,
		WP_Markdown_Native_Table_Provider $provider
	): void {
		if ( isset( $this->tables[ $table ] ) ) {
			throw new InvalidArgumentException( 'A unique exact table identifier is required.' );
		}
		$this->register_definition( $table, $schema->definition() );
		$this->tables[ $table ] = array(
			'schema'   => $schema,
			'provider' => $provider,
		);
	}

	/** @return array{schema:WP_Markdown_Native_Table_Schema,provider:WP_Markdown_Native_Table_Provider}|null */
	public function table( string $table ): ?array {
		return $this->tables[ $table ] ?? null;
	}

	/** @param array<string,mixed> $definition */
	public function register_definition( string $table, array $definition ): void {
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $table )
			|| ( isset( $this->definitions[ $table ] ) && $definition !== $this->definitions[ $table ] )
		) {
			throw new InvalidArgumentException( 'A consistent exact table definition is required.' );
		}
		$this->definitions[ $table ] = $definition;
	}

	/** @return array<string,mixed>|null */
	public function definition( string $table ): ?array {
		return $this->definitions[ $table ] ?? null;
	}

	/**
	 * Replace a registered table after its schema is altered.
	 *
	 * Registration is otherwise append-only so a table cannot be shadowed. A
	 * persisted schema alteration is the one lifecycle event that legitimately
	 * changes an existing definition.
	 */
	public function reregister(
		string $table,
		?WP_Markdown_Native_Table_Schema $schema,
		?WP_Markdown_Native_Table_Provider $provider,
		array $definition
	): void {
		if ( ! isset( $this->definitions[ $table ] ) ) {
			throw new InvalidArgumentException( 'An altered table must already be registered.' );
		}
		unset( $this->tables[ $table ], $this->definitions[ $table ] );
		$this->register_definition( $table, $definition );
		if ( null !== $schema && null !== $provider ) {
			$this->tables[ $table ] = array(
				'schema'   => $schema,
				'provider' => $provider,
			);
		}
	}

	/**
	 * Forget a dropped table.
	 *
	 * Registration is otherwise append-only. A persisted DROP is the one
	 * lifecycle event that legitimately removes a table from the catalog.
	 */
	public function unregister( string $table ): void {
		unset( $this->tables[ $table ], $this->definitions[ $table ] );
	}

	/** Forget request-scoped generic snapshots after canonical files are restored. */
	public function forget_snapshots(): void {
		foreach ( $this->tables as $table ) {
			if ( $table['provider'] instanceof WP_Markdown_Native_JSON_Snapshot_Provider ) {
				$table['provider']->forget_rows();
			}
		}
	}

	/** @return array<int,string> */
	public function table_names(): array {
		return array_keys( $this->definitions );
	}
}
