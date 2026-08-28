<?php
/** Typed AST for native SELECT statements. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_SQL_Identifier {
	public function __construct(
		private readonly string $name,
		private readonly int $sql_offset,
		private readonly ?string $qualifier = null
	) {}

	public function name(): string {
		return $this->name;
	}

	public function sql_offset(): int {
		return $this->sql_offset;
	}

	public function qualifier(): ?string {
		return $this->qualifier;
	}
}

final class WP_Markdown_Native_SQL_Literal {
	public function __construct(
		private readonly int|string $value,
		private readonly int $sql_offset
	) {}

	public function value(): int|string {
		return $this->value;
	}

	public function sql_offset(): int {
		return $this->sql_offset;
	}
}

final class WP_Markdown_Native_SQL_Predicate {
	/** @param array<int,WP_Markdown_Native_SQL_Literal> $values @param array<int,self> $any */
	public function __construct(
		private readonly WP_Markdown_Native_SQL_Identifier $column,
		private readonly string $operator,
		private readonly array $values,
		private readonly array $any = array()
	) {}

	public function column(): WP_Markdown_Native_SQL_Identifier {
		return $this->column;
	}

	public function operator(): string {
		return $this->operator;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Literal> */
	public function values(): array {
		return $this->values;
	}

	/** @return array<int,self> */
	public function any(): array {
		return $this->any;
	}
}

final class WP_Markdown_Native_SQL_Join {
	public function __construct(
		private readonly WP_Markdown_Native_SQL_Identifier $table,
		private readonly WP_Markdown_Native_SQL_Identifier $alias,
		private readonly WP_Markdown_Native_SQL_Identifier $left,
		private readonly WP_Markdown_Native_SQL_Identifier $right
	) {}

	public function table(): WP_Markdown_Native_SQL_Identifier {
		return $this->table;
	}

	public function alias(): WP_Markdown_Native_SQL_Identifier {
		return $this->alias;
	}

	public function left(): WP_Markdown_Native_SQL_Identifier {
		return $this->left;
	}

	public function right(): WP_Markdown_Native_SQL_Identifier {
		return $this->right;
	}
}

final class WP_Markdown_Native_SQL_Found_Rows {}

final class WP_Markdown_Native_SQL_Select {
	/** @param array<int,WP_Markdown_Native_SQL_Identifier> $projection @param array<int,WP_Markdown_Native_SQL_Predicate> $predicates @param array<int,WP_Markdown_Native_SQL_Join> $joins */
	public function __construct(
		private readonly bool $select_all,
		private readonly bool $count_all,
		private readonly array $projection,
		private readonly WP_Markdown_Native_SQL_Identifier $table,
		private readonly array $predicates,
		private readonly array $orders,
		private readonly ?int $limit,
		private readonly ?WP_Markdown_Native_SQL_Identifier $alias = null,
		private readonly array $joins = array(),
		private readonly bool $calculates_found_rows = false,
		private readonly int $limit_offset = 0,
		private readonly bool $distinct = false,
		private readonly bool $contradiction = false
	) {}

	public function selects_all(): bool {
		return $this->select_all;
	}

	public function counts_all(): bool {
		return $this->count_all;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Identifier> */
	public function projection(): array {
		return $this->projection;
	}

	public function table(): WP_Markdown_Native_SQL_Identifier {
		return $this->table;
	}

	public function alias(): ?WP_Markdown_Native_SQL_Identifier {
		return $this->alias;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Join> */
	public function joins(): array {
		return $this->joins;
	}

	public function predicate(): ?WP_Markdown_Native_SQL_Predicate {
		return $this->predicates[0] ?? null;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Predicate> */
	public function predicates(): array {
		return $this->predicates;
	}

	public function order(): ?WP_Markdown_Native_SQL_Identifier {
		return $this->orders[0]['column'] ?? null;
	}

	/** @return array<int,array{column:WP_Markdown_Native_SQL_Identifier,descending:bool}> */
	public function orders(): array {
		return $this->orders;
	}

	public function limit(): ?int {
		return $this->limit;
	}

	public function calculates_found_rows(): bool {
		return $this->calculates_found_rows;
	}

	public function order_descending(): bool {
		return $this->orders[0]['descending'] ?? false;
	}

	public function limit_offset(): int {
		return $this->limit_offset;
	}

	public function is_distinct(): bool {
		return $this->distinct;
	}

	public function is_contradiction(): bool {
		return $this->contradiction;
	}
}
