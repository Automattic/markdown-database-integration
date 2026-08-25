<?php
/** Typed AST for native SELECT statements. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_SQL_Identifier {
	public function __construct(
		private readonly string $name,
		private readonly int $sql_offset
	) {}

	public function name(): string {
		return $this->name;
	}

	public function sql_offset(): int {
		return $this->sql_offset;
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
	/** @param array<int,WP_Markdown_Native_SQL_Literal> $values */
	public function __construct(
		private readonly WP_Markdown_Native_SQL_Identifier $column,
		private readonly string $operator,
		private readonly array $values
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
}

final class WP_Markdown_Native_SQL_Select {
	/** @param array<int,WP_Markdown_Native_SQL_Identifier> $projection */
	public function __construct(
		private readonly bool $select_all,
		private readonly array $projection,
		private readonly WP_Markdown_Native_SQL_Identifier $table,
		private readonly ?WP_Markdown_Native_SQL_Predicate $predicate,
		private readonly ?WP_Markdown_Native_SQL_Identifier $order,
		private readonly ?int $limit
	) {}

	public function selects_all(): bool {
		return $this->select_all;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Identifier> */
	public function projection(): array {
		return $this->projection;
	}

	public function table(): WP_Markdown_Native_SQL_Identifier {
		return $this->table;
	}

	public function predicate(): ?WP_Markdown_Native_SQL_Predicate {
		return $this->predicate;
	}

	public function order(): ?WP_Markdown_Native_SQL_Identifier {
		return $this->order;
	}

	public function limit(): ?int {
		return $this->limit;
	}
}
