<?php
/** Backend-neutral contracts for bounded native queries. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Query_Request {
	public function __construct(
		private string $sql,
		private string $table_prefix = 'wp_'
	) {
		if ( '' === trim( $this->sql ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $this->table_prefix ) ) {
			throw new InvalidArgumentException( 'A query and supported table prefix are required.' );
		}
	}

	public function sql(): string {
		return $this->sql;
	}

	public function table_prefix(): string {
		return $this->table_prefix;
	}

}

final class WP_Markdown_Native_Query_Predicate {
	/** @param array<int,int|string> $values @param array<int,self> $any */
	public function __construct(
		private readonly string $column,
		private readonly string $operator,
		private readonly array $values,
		private readonly ?string $source = null,
		private readonly array $any = array()
	) {}

	public function column(): string {
		return $this->column;
	}

	public function operator(): string {
		return $this->operator;
	}

	/** @return array<int,int|string> */
	public function values(): array {
		return $this->values;
	}

	public function source(): ?string {
		return $this->source;
	}

	/** @return array<int,self> */
	public function any(): array {
		return $this->any;
	}

	/** @return array<int,string> */
	public function columns(): array {
		$columns = array( $this->column );
		foreach ( $this->any as $predicate ) {
			$columns = array_merge( $columns, $predicate->columns() );
		}
		return array_values( array_unique( $columns ) );
	}
}

final class WP_Markdown_Native_Query_Join {
	/** @param array<int,WP_Markdown_Native_Query_Predicate> $on_filters */
	public function __construct(
		private readonly string $table,
		private readonly string $alias,
		private readonly string $left_source,
		private readonly string $left_column,
		private readonly string $right_source,
		private readonly string $right_column,
		private readonly bool $outer = false,
		private readonly array $on_filters = array()
	) {}

	public function table(): string {
		return $this->table;
	}

	public function alias(): string {
		return $this->alias;
	}

	public function left_source(): string {
		return $this->left_source;
	}

	public function left_column(): string {
		return $this->left_column;
	}

	public function right_source(): string {
		return $this->right_source;
	}

	public function right_column(): string {
		return $this->right_column;
	}

	public function is_outer(): bool {
		return $this->outer;
	}

	/** @return array<int,WP_Markdown_Native_Query_Predicate> */
	public function on_filters(): array {
		return $this->on_filters;
	}
}

final class WP_Markdown_Native_Found_Rows_Plan {}

final class WP_Markdown_Native_Query_Plan {
	/** @param array<int,string> $projection @param array<int,WP_Markdown_Native_Query_Predicate> $predicates @param array<int,string|null> $projection_sources @param array<int,WP_Markdown_Native_Query_Join> $joins */
	public function __construct(
		private readonly string $table,
		private readonly array $projection,
		private readonly array $predicates,
		private readonly ?string $order,
		private readonly int $limit,
		private readonly bool $count_all = false,
		private readonly ?string $table_alias = null,
		private readonly array $projection_sources = array(),
		private readonly array $joins = array(),
		private readonly bool $calculate_found_rows = false,
		private readonly bool $order_descending = false,
		private readonly int $limit_offset = 0,
		private readonly bool $distinct = false,
		private readonly ?string $order_source = null,
		private readonly array $order_by = array(),
		private readonly bool $unsatisfiable = false,
		private readonly ?string $group_count_alias = null,
		private readonly array $aggregates = array()
	) {}

	public function table(): string {
		return $this->table;
	}

	/** @return array<int,string> */
	public function projection(): array {
		return $this->projection;
	}

	public function predicate(): ?WP_Markdown_Native_Query_Predicate {
		return $this->predicates[0] ?? null;
	}

	/** @return array<int,WP_Markdown_Native_Query_Predicate> */
	public function predicates(): array {
		return $this->predicates;
	}

	public function order(): ?string {
		return $this->order;
	}

	public function limit(): int {
		return $this->limit;
	}

	public function counts_all(): bool {
		return $this->count_all;
	}

	public function table_alias(): ?string {
		return $this->table_alias;
	}

	/** @return array<int,string|null> */
	public function projection_sources(): array {
		return $this->projection_sources;
	}

	/** @return array<int,WP_Markdown_Native_Query_Join> */
	public function joins(): array {
		return $this->joins;
	}

	public function calculates_found_rows(): bool {
		return $this->calculate_found_rows;
	}

	public function order_descending(): bool {
		return $this->order_descending;
	}

	public function limit_offset(): int {
		return $this->limit_offset;
	}

	public function is_distinct(): bool {
		return $this->distinct;
	}

	public function order_source(): ?string {
		return $this->order_source;
	}

	/** @return array<int,array{column:string,descending:bool,source:?string}> */
	public function order_by(): array {
		if ( array() !== $this->order_by ) {
			return $this->order_by;
		}
		if ( null === $this->order ) {
			return array();
		}
		return array(
			array(
				'column'     => $this->order,
				'descending' => $this->order_descending,
				'source'     => $this->order_source,
			),
		);
	}

	public function is_unsatisfiable(): bool {
		return $this->unsatisfiable;
	}

	public function group_count_alias(): ?string {
		return $this->group_count_alias;
	}

	/** @return array<int,array{function:string,column:?string,source:?string,alias:string}> */
	public function aggregates(): array {
		return $this->aggregates;
	}
}

final class WP_Markdown_Native_Table_Access {
	/** @param array<int,string> $projection */
	public function __construct(
		private readonly array $projection,
		private readonly ?WP_Markdown_Native_Query_Predicate $predicate,
		private readonly string $order,
		private readonly int $limit,
		private readonly bool $order_descending = false,
		private readonly array $order_by = array(),
		private readonly array $predicates = array()
	) {
		if ( array() === $projection || $limit < 0 ) {
			throw new InvalidArgumentException( 'Native table access requires a projection and nonnegative bound.' );
		}
	}

	/** @return array<int,string> */
	public function projection(): array {
		return $this->projection;
	}

	public function predicate(): ?WP_Markdown_Native_Query_Predicate {
		return $this->predicate;
	}

	/** @return array<int,WP_Markdown_Native_Query_Predicate> */
	public function predicates(): array {
		return $this->predicates;
	}

	public function order(): string {
		return $this->order;
	}

	public function limit(): int {
		return $this->limit;
	}

	public function order_descending(): bool {
		return $this->order_descending;
	}

	/** @return array<int,array{column:string,descending:bool}> */
	public function order_by(): array {
		if ( array() !== $this->order_by ) {
			return $this->order_by;
		}
		return array(
			array(
				'column'     => $this->order,
				'descending' => $this->order_descending,
			),
		);
	}
}

final class WP_Markdown_Query_Result {
	/** @param array<int,array<string,string|null>> $rows @param array<int,array{name:string,type:int,table?:string}> $columns */
	private function __construct(
		private int|bool $return_value,
		private array $rows,
		private array $columns,
		private string $last_error = '',
		private int|string $error_code = 0,
		private ?array $diagnostic = null,
		private int $insert_id = 0,
		private int $rows_affected = 0
	) {}

	public static function selected( array $rows, array $columns ): self {
		return new self( count( $rows ), $rows, $columns );
	}

	public static function mutated( int $rows_affected, int $insert_id = 0 ): self {
		return new self( $rows_affected, array(), array(), '', 0, null, $insert_id, $rows_affected );
	}

	public static function schema_changed(): self {
		return new self( true, array(), array() );
	}

	/** @param array{code:string,message:string,reason:string,sql_offset?:int} $diagnostic */
	public static function failure( array $diagnostic ): self {
		return new self( false, array(), array(), $diagnostic['message'], $diagnostic['code'], $diagnostic );
	}

	public function return_value(): int|bool {
		return $this->return_value;
	}

	public function succeeded(): bool {
		return false !== $this->return_value;
	}

	public function diagnostic(): ?array {
		return $this->diagnostic;
	}

	/** @return array<string,mixed> State consumed by wpdb compatibility helpers. */
	public function wpdb_state(): array {
		return array(
			'last_result' => array_map( static fn( array $row ): object => (object) $row, $this->rows ),
			'col_info' => array_map( static fn( array $column ): object => (object) $column, $this->columns ),
			'last_error' => $this->last_error,
			'last_errno' => $this->error_code,
			'insert_id' => $this->insert_id,
			'rows_affected' => $this->rows_affected,
			'num_rows' => count( $this->rows ),
		);
	}

	public function corpus_result(): array {
		return array(
			'return' => array(
				'type' => is_bool( $this->return_value ) ? 'boolean' : 'integer',
				'value' => $this->return_value,
			),
			'rows' => $this->rows,
			'columns' => array_map( static fn( array $column ): array => array( 'name' => $column['name'], 'type' => (string) $column['type'] ), $this->columns ),
			'last_error' => $this->last_error,
			'error_code' => $this->error_code,
			'insert_id' => $this->insert_id,
			'rows_affected' => $this->rows_affected,
			'num_rows' => count( $this->rows ),
			'exception' => null,
		);
	}
}

interface WP_Markdown_Query_Runtime {
	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result;
}

/** Providers supply validated rows without exposing storage to the executor. */
interface WP_Markdown_Native_Table_Provider {
	/** @return iterable<int,array<string,mixed>>|WP_Markdown_Query_Result */
	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result;
}
