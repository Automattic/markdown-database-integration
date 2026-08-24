<?php
/** Schema descriptors and exact table-provider registry. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Table_Schema {

	/**
	 * @param array<string,array{type:int,nullable:bool,validate?:callable,normalize?:callable,lookup_validate?:callable,lookup_operators?:array<int,string>}> $columns Column declarations.
	 * @param string                                                                                                                                    $natural_order Natural row order column.
	 * @param array<int,string>                                                                                                                         $lookup_columns Indexed predicate columns.
	 * @param array<int,string>                                                                                                                         $order_columns Explicitly orderable columns.
	 */
	public function __construct(
		private array $columns,
		private string $natural_order,
		private array $lookup_columns,
		private array $order_columns = array()
	) {
		if ( ! isset( $columns[ $natural_order ] ) ) {
			throw new InvalidArgumentException( 'The natural order column must exist.' );
		}
		foreach ( $lookup_columns as $column ) {
			if ( ! isset( $columns[ $column ] ) ) {
				throw new InvalidArgumentException( 'Every lookup column must exist in the schema.' );
			}
		}
		foreach ( $order_columns as $column ) {
			if ( ! isset( $columns[ $column ] ) ) {
				throw new InvalidArgumentException( 'Every order column must exist in the schema.' );
			}
		}
	}

	/** @return array<string,array<string,mixed>> */
	public function columns(): array {
		return $this->columns;
	}

	public function has_column( string $column ): bool {
		return isset( $this->columns[ $column ] );
	}

	public function natural_order(): string {
		return $this->natural_order;
	}

	public function is_lookup( string $column ): bool {
		return in_array( $column, $this->lookup_columns, true );
	}

	public function allows_order( string $column ): bool {
		return $column === $this->natural_order || in_array( $column, $this->order_columns, true );
	}

	/** @param array<int,int|string> $values */
	public function allows_lookup( string $column, string $operator, array $values ): bool {
		if ( ! $this->is_lookup( $column ) ) {
			return false;
		}
		$declaration = $this->columns[ $column ];
		if ( isset( $declaration['lookup_operators'] ) && ! in_array( $operator, $declaration['lookup_operators'], true ) ) {
			return false;
		}
		return ! isset( $declaration['lookup_validate'] ) || ( $declaration['lookup_validate'] )( $values );
	}

	public function values_match( string $column, mixed $left, mixed $right ): bool {
		$left  = $this->normalize( $column, $left );
		$right = $this->normalize( $column, $right );
		return null !== $left && null !== $right && $left === $right;
	}

	public function compare_values( string $column, mixed $left, mixed $right ): int {
		return $this->normalize( $column, $left ) <=> $this->normalize( $column, $right );
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
			$value = $row[ $name ];
			if ( null === $value && $column['nullable'] ) {
				continue;
			}
			if ( null === $value || ( isset( $column['validate'] ) && ! ( $column['validate'] )( $value ) ) ) {
				return 'invalid_' . $name;
			}
		}
		return true;
	}

	private function normalize( string $column, mixed $value ): mixed {
		$declaration = $this->columns[ $column ];
		return isset( $declaration['normalize'] ) ? ( $declaration['normalize'] )( $value ) : $value;
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
