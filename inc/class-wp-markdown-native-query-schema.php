<?php
/** Schema descriptors and exact table-provider registry. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Column {
	/** @param array<int,string> $lookup_operators */
	public function __construct(
		private readonly int $type,
		private readonly bool $nullable,
		private readonly mixed $validator = null,
		private readonly mixed $normalizer = null,
		private readonly array $lookup_operators = array(),
		private readonly mixed $lookup_validator = null
	) {
		if ( null !== $validator && ! is_callable( $validator ) ) {
			throw new InvalidArgumentException( 'Column validators must be callable.' );
		}
		if ( null !== $normalizer && ! is_callable( $normalizer ) ) {
			throw new InvalidArgumentException( 'Column normalizers must be callable.' );
		}
		if ( null !== $lookup_validator && ! is_callable( $lookup_validator ) ) {
			throw new InvalidArgumentException( 'Column lookup validators must be callable.' );
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
}

final class WP_Markdown_Native_Table_Schema {

	/**
	 * @param array<string,WP_Markdown_Native_Column> $columns       Column declarations.
	 * @param string                                  $natural_order Natural row order column.
	 * @param array<int,string>                       $order_columns Explicitly orderable columns.
	 */
	public function __construct(
		private array $columns,
		private string $natural_order,
		private array $order_columns = array()
	) {
		foreach ( $columns as $column ) {
			if ( ! $column instanceof WP_Markdown_Native_Column ) {
				throw new InvalidArgumentException( 'Every schema column must use a typed descriptor.' );
			}
		}
		if ( ! isset( $columns[ $natural_order ] ) ) {
			throw new InvalidArgumentException( 'The natural order column must exist.' );
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

	public function values_match( string $column, mixed $left, mixed $right ): bool {
		$left  = $this->column( $column )->normalize( $left );
		$right = $this->column( $column )->normalize( $right );
		return null !== $left && null !== $right && $left === $right;
	}

	public function compare_values( string $column, mixed $left, mixed $right ): int {
		return $this->column( $column )->normalize( $left ) <=> $this->column( $column )->normalize( $right );
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
}

final class WP_Markdown_Native_Table_Registry {

	/** @var array<string,array{schema:WP_Markdown_Native_Table_Schema,provider:WP_Markdown_Native_Table_Provider}> */
	private array $tables = array();

	public function register(
		string $table,
		WP_Markdown_Native_Table_Schema $schema,
		WP_Markdown_Native_Table_Provider $provider
	): void {
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $table ) || isset( $this->tables[ $table ] ) ) {
			throw new InvalidArgumentException( 'A unique exact table identifier is required.' );
		}
		$this->tables[ $table ] = array(
			'schema'   => $schema,
			'provider' => $provider,
		);
	}

	/** @return array{schema:WP_Markdown_Native_Table_Schema,provider:WP_Markdown_Native_Table_Provider}|null */
	public function table( string $table ): ?array {
		return $this->tables[ $table ] ?? null;
	}
}
