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

/** Typed, row-local expression reusable by SELECT, WHERE, ORDER BY, and HAVING. */
final class WP_Markdown_Native_SQL_Scalar_Expression {
	/** @param array<int,self> $arguments @param array<int,array{predicates:array,value:self}> $branches */
	public function __construct(
		private readonly string $kind,
		private readonly ?WP_Markdown_Native_SQL_Identifier $identifier = null,
		private readonly int|string|null $literal = null,
		private readonly array $arguments = array(),
		private readonly array $branches = array(),
		private readonly ?self $else = null
	) {}

	public function kind(): string {
		return $this->kind;
	}

	public function identifier(): ?WP_Markdown_Native_SQL_Identifier {
		return $this->identifier;
	}

	public function literal(): int|string|null {
		return $this->literal;
	}

	/** @return array<int,self> */
	public function arguments(): array {
		return $this->arguments;
	}

	/** @return array<int,array{predicates:array,value:self}> */
	public function branches(): array {
		return $this->branches;
	}

	public function else(): ?self {
		return $this->else;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Identifier> */
	public function columns(): array {
		$columns = null === $this->identifier ? array() : array( $this->identifier );
		foreach ( $this->arguments as $argument ) {
			$columns = array_merge( $columns, $argument->columns() );
		}
		foreach ( $this->branches as $branch ) {
			foreach ( $branch['predicates'] as $predicate ) {
				$columns = array_merge( $columns, $this->predicate_columns( $predicate ) );
			}
			$columns = array_merge( $columns, $branch['value']->columns() );
		}
		if ( null !== $this->else ) {
			$columns = array_merge( $columns, $this->else->columns() );
		}
		return $columns;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Identifier> */
	private function predicate_columns( WP_Markdown_Native_SQL_Predicate $predicate ): array {
		$columns = array( $predicate->column() );
		foreach ( $predicate->any() as $alternative ) {
			$columns = array_merge( $columns, $this->predicate_columns( $alternative ) );
		}
		return $columns;
	}
}

final class WP_Markdown_Native_SQL_Predicate {
	/** @param array<int,WP_Markdown_Native_SQL_Literal> $values @param array<int,self> $any */
	public function __construct(
		private readonly WP_Markdown_Native_SQL_Identifier $column,
		private readonly string $operator,
		private readonly array $values,
		private readonly array $any = array(),
		private readonly ?string $cast = null,
		private readonly ?WP_Markdown_Native_SQL_Identifier $comparison = null
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

	public function cast(): ?string {
		return $this->cast;
	}

	public function comparison(): ?WP_Markdown_Native_SQL_Identifier {
		return $this->comparison;
	}
}

/** A SELECT predicate whose right-hand side is another typed SELECT. */
final class WP_Markdown_Native_SQL_Subquery_Predicate {
	public function __construct(
		private readonly string $operator,
		private readonly ?WP_Markdown_Native_SQL_Identifier $column,
		private readonly WP_Markdown_Native_SQL_Select $query
	) {}
	public function operator(): string { return $this->operator; }
	public function column(): ?WP_Markdown_Native_SQL_Identifier { return $this->column; }
	public function query(): WP_Markdown_Native_SQL_Select { return $this->query; }
}

final class WP_Markdown_Native_SQL_Join {
	/** @param array<int,WP_Markdown_Native_SQL_Predicate> $on_predicates */
	public function __construct(
		private readonly WP_Markdown_Native_SQL_Identifier $table,
		private readonly WP_Markdown_Native_SQL_Identifier $alias,
		private readonly WP_Markdown_Native_SQL_Identifier $left,
		private readonly WP_Markdown_Native_SQL_Identifier $right,
		private readonly bool $outer = false,
		private readonly array $on_predicates = array()
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

	public function is_outer(): bool {
		return $this->outer;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Predicate> */
	public function on_predicates(): array {
		return $this->on_predicates;
	}
}

final class WP_Markdown_Native_SQL_Found_Rows {}

final class WP_Markdown_Native_SQL_Select {
/** @param array<int,WP_Markdown_Native_SQL_Identifier> $projection @param array<int,WP_Markdown_Native_SQL_Predicate> $predicates @param array<int,WP_Markdown_Native_SQL_Subquery_Predicate> $subqueries @param array<int,WP_Markdown_Native_SQL_Predicate> $having @param array<int,WP_Markdown_Native_SQL_Join> $joins @param array<int,array{expression:WP_Markdown_Native_SQL_Scalar_Expression,alias:string,position:int}> $scalar_projection */
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
		private readonly bool $contradiction = false,
		private readonly ?WP_Markdown_Native_SQL_Identifier $group_by = null,
		private readonly array $aggregates = array(),
		private readonly array $scalar_projection = array(),
		private readonly array $having = array(),
		private readonly array $subqueries = array(),
		private readonly ?self $union = null
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

	public function group_by(): ?WP_Markdown_Native_SQL_Identifier {
		return $this->group_by;
	}

	/** @return array<int,array{function:string,column:?WP_Markdown_Native_SQL_Identifier,alias:string}> */
	public function aggregates(): array {
		return $this->aggregates;
	}

	/** @return array<int,array{expression:WP_Markdown_Native_SQL_Scalar_Expression,alias:string,position:int}> */
	public function scalar_projection(): array {
		return $this->scalar_projection;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Predicate> */
	public function having(): array {
		return $this->having;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Subquery_Predicate> */
	public function subqueries(): array { return $this->subqueries; }

	public function union(): ?self { return $this->union; }
}
