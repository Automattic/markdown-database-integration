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
		if ( null !== $predicate->comparison() ) {
			$columns[] = $predicate->comparison();
		}
		foreach ( $predicate->any() as $alternative ) {
			$columns = array_merge( $columns, $this->predicate_columns( $alternative ) );
		}
		return $columns;
	}
}

/** A comparison over row-local expressions, evaluated after bounded reads. */
final class WP_Markdown_Native_SQL_Scalar_Predicate {
	public function __construct(
		private readonly WP_Markdown_Native_SQL_Scalar_Expression $left,
		private readonly string $operator,
		private readonly WP_Markdown_Native_SQL_Scalar_Expression $right
	) {}
	public function left(): WP_Markdown_Native_SQL_Scalar_Expression { return $this->left; }
	public function operator(): string { return $this->operator; }
	public function right(): WP_Markdown_Native_SQL_Scalar_Expression { return $this->right; }
	/** @return array<int,WP_Markdown_Native_SQL_Identifier> */
	public function columns(): array { return array_merge( $this->left->columns(), $this->right->columns() ); }
}

/** A disjunction of conjunctions evaluated after bounded source reads. */
final class WP_Markdown_Native_SQL_Boolean_Predicate {
	/** @param array<int,array<int,WP_Markdown_Native_SQL_Predicate|WP_Markdown_Native_SQL_Scalar_Predicate|WP_Markdown_Native_SQL_Subquery_Predicate>> $groups */
	public function __construct( private readonly array $groups ) {}
	public function groups(): array { return $this->groups; }
	/** @return array<int,WP_Markdown_Native_SQL_Identifier> */
	public function columns(): array {
		$columns = array();
		foreach ( $this->groups as $group ) {
			foreach ( $group as $predicate ) {
				if ( $predicate instanceof WP_Markdown_Native_SQL_Subquery_Predicate ) {
					if ( null !== $predicate->column() ) { $columns[] = $predicate->column(); }
					continue;
				}
				$columns = array_merge( $columns, $predicate->columns() );
			}
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

	/** @return array<int,WP_Markdown_Native_SQL_Identifier> */
	public function columns(): array {
		$columns = array( $this->column );
		if ( null !== $this->comparison ) { $columns[] = $this->comparison; }
		foreach ( $this->any as $predicate ) { $columns = array_merge( $columns, $predicate->columns() ); }
		return $columns;
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
		private readonly ?WP_Markdown_Native_SQL_Identifier $left,
		private readonly ?WP_Markdown_Native_SQL_Identifier $right,
		private readonly bool $outer = false,
		private readonly array $on_predicates = array(),
		private readonly ?WP_Markdown_Native_SQL_Select $derived = null
	) {}

	public function table(): WP_Markdown_Native_SQL_Identifier {
		return $this->table;
	}

	public function alias(): WP_Markdown_Native_SQL_Identifier {
		return $this->alias;
	}

	public function left(): ?WP_Markdown_Native_SQL_Identifier {
		return $this->left;
	}

	public function right(): ?WP_Markdown_Native_SQL_Identifier {
		return $this->right;
	}

	public function is_outer(): bool {
		return $this->outer;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Predicate> */
	public function on_predicates(): array {
		return $this->on_predicates;
	}

	public function derived(): ?WP_Markdown_Native_SQL_Select {
		return $this->derived;
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
		private readonly ?self $union = null,
		private readonly array $scalar_predicates = array(),
		private readonly array $scalar_having = array(),
		private readonly ?WP_Markdown_Native_SQL_Scalar_Expression $group_expression = null,
		private readonly ?WP_Markdown_Native_SQL_Boolean_Predicate $boolean_predicate = null,
		private readonly ?self $derived = null,
		private readonly bool $union_all = false,
		private readonly array $union_orders = array(),
		private readonly ?int $union_limit = null,
		private readonly int $union_limit_offset = 0
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
	/** @return array<int,WP_Markdown_Native_SQL_Scalar_Predicate> */
	public function scalar_predicates(): array { return $this->scalar_predicates; }
	/** @return array<int,WP_Markdown_Native_SQL_Scalar_Predicate> */
	public function scalar_having(): array { return $this->scalar_having; }
	public function group_expression(): ?WP_Markdown_Native_SQL_Scalar_Expression { return $this->group_expression; }
	public function boolean_predicate(): ?WP_Markdown_Native_SQL_Boolean_Predicate { return $this->boolean_predicate; }

	public function derived(): ?self { return $this->derived; }

	public function union_all(): bool { return $this->union_all; }
	/** @return array<int,array{column:WP_Markdown_Native_SQL_Identifier,descending:bool}> */
	public function union_orders(): array { return $this->union_orders; }
	public function union_limit(): ?int { return $this->union_limit; }
	public function union_limit_offset(): int { return $this->union_limit_offset; }

	/** Remove clauses that syntactically follow an unparenthesized UNION branch. */
	public function without_order_limit(): self {
		return new self( $this->select_all, $this->count_all, $this->projection, $this->table, $this->predicates, array(), null, $this->alias, $this->joins, $this->calculates_found_rows, 0, $this->distinct, $this->contradiction, $this->group_by, $this->aggregates, $this->scalar_projection, $this->having, $this->subqueries, $this->union, $this->scalar_predicates, $this->scalar_having, $this->group_expression, $this->boolean_predicate, $this->derived, $this->union_all, $this->union_orders, $this->union_limit, $this->union_limit_offset );
	}
}
