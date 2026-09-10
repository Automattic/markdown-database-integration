<?php
/** Parsed statements for generic table mutations. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Table_Insert {
	/**
	 * @param array<string,int|string|null> $values
	 * @param array<int,WP_Markdown_Native_Table_Predicate>|null $unless_exists
	 * @param array<int,array{target:string,kind:string,source:?string,value:int|string|null}>|null $upsert_assignments
	 */
	public function __construct(
		private readonly string $table,
		private readonly array $values,
		private readonly ?array $unless_exists = null,
		private readonly bool $ignore_duplicate = false,
		private readonly ?array $upsert_assignments = null,
		private readonly bool $replace = false
	) {}

	public function table(): string {
		return $this->table;
	}

	/** @return array<string,int|string|null> */
	public function values(): array {
		return $this->values;
	}

	/** @return array<int,WP_Markdown_Native_Table_Predicate>|null */
	public function unless_exists(): ?array {
		return $this->unless_exists;
	}

	public function ignores_duplicate(): bool {
		return $this->ignore_duplicate;
	}

	/** @return array<int,array{target:string,kind:string,source:?string,value:int|string|null}>|null */
	public function upsert_assignments(): ?array {
		return $this->upsert_assignments;
	}

	public function is_replace(): bool {
		return $this->replace;
	}
}

/**
 * One column restriction in a generic DML statement.
 *
 * Values are disjunctive, matching the engine's SELECT predicate model, so
 * `column IS NULL OR column = ''` is one predicate over a single column.
 */
final class WP_Markdown_Native_Table_Predicate {

	/** @param array<int,int|string> $values */
	public function __construct(
		private string $column,
		private array $values,
		private bool $matches_null,
		private string $operator = '='
	) {}

	public function column(): string {
		return $this->column;
	}

	/** @return array<int,int|string> */
	public function values(): array {
		return $this->values;
	}

	public function matches_null(): bool {
		return $this->matches_null;
	}

	public function operator(): string {
		return $this->operator;
	}
}

/** One OR group of restrictions, evaluated as a disjunction per row. */
final class WP_Markdown_Native_Table_Predicate_Group {

	/** @param array<int,WP_Markdown_Native_Table_Predicate|self> $any */
	public function __construct(
		private readonly array $any
	) {}

	/** @return array<int,WP_Markdown_Native_Table_Predicate|self> */
	public function any(): array {
		return $this->any;
	}
}

/** A typed single-column IN predicate whose members come from a typed SELECT. */
final class WP_Markdown_Native_Table_Subquery_Predicate {
	public function __construct(
		private readonly string $column,
		private readonly WP_Markdown_Native_SQL_Select $query
	) {}

	public function column(): string {
		return $this->column;
	}

	public function query(): WP_Markdown_Native_SQL_Select {
		return $this->query;
	}
}

/** One generic UPDATE or DELETE against a persisted snapshot table. */
final class WP_Markdown_Native_Table_Write {

	/**
	 * @param array<string,int|string|null>              $values     Assignments for an UPDATE.
	 * @param array<int,WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group|WP_Markdown_Native_Table_Subquery_Predicate> $predicates Conjunctive restrictions.
	 */
	public function __construct(
		private string $kind,
		private string $table,
		private array $values,
		private array $predicates
	) {}

	public function is_update(): bool {
		return 'update' === $this->kind;
	}

	public function table(): string {
		return $this->table;
	}

	/** @return array<string,int|string|null> */
	public function values(): array {
		return $this->values;
	}

	/** @return array<int,WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group|WP_Markdown_Native_Table_Subquery_Predicate> */
	public function predicates(): array {
		return $this->predicates;
	}
}
