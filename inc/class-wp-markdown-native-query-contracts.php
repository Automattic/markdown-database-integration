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
	/** @param array<int,int|string> $values */
	public function __construct(
		private readonly string $column,
		private readonly string $operator,
		private readonly array $values
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
}

final class WP_Markdown_Native_Query_Plan {
	/** @param array<int,string> $projection @param array<int,WP_Markdown_Native_Query_Predicate> $predicates */
	public function __construct(
		private readonly string $table,
		private readonly array $projection,
		private readonly array $predicates,
		private readonly ?string $order,
		private readonly int $limit
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
}

final class WP_Markdown_Native_Table_Access {
	/** @param array<int,string> $projection */
	public function __construct(
		private readonly array $projection,
		private readonly ?WP_Markdown_Native_Query_Predicate $predicate,
		private readonly string $order,
		private readonly int $limit
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

	public function order(): string {
		return $this->order;
	}

	public function limit(): int {
		return $this->limit;
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
		private ?array $diagnostic = null
	) {}

	public static function selected( array $rows, array $columns ): self {
		return new self( count( $rows ), $rows, $columns );
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
			'insert_id' => 0,
			'rows_affected' => 0,
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
			'insert_id' => 0,
			'rows_affected' => 0,
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
