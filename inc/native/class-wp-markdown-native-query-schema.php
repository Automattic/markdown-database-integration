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
		private readonly array $filter_operators = array( '=', 'IN', 'NOT IN', '<>', 'LIKE', 'NOT LIKE' ),
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
		return isset( $this->columns[ $column ] ) && $this->columns[ $column ]->allows_filter( $operator, $values );
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
			$column = $item['column'];
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
	 * @param array<string,mixed>                             $left
	 * @param array<string,mixed>                             $right
	 * @param array<int,array{column:string,descending:bool}> $order_by
	 */
	public function compare_ordered_rows( array $left, array $right, array $order_by ): int {
		foreach ( $order_by as $item ) {
			$comparison = ( $item['descending'] ? -1 : 1 ) * $this->compare_rows( $item['column'], $left, $right );
			if ( 0 !== $comparison ) {
				return $comparison;
			}
		}
		return 0;
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
		if ( null === $schema || null === $provider ) {
			$this->register_definition( $table, $definition );
			return;
		}
		$this->register( $table, $schema, $provider );
	}

	/** @return array<int,string> */
	public function table_names(): array {
		return array_keys( $this->definitions );
	}
}
