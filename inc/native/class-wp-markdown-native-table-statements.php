<?php
/** Parsed statements for generic table mutations. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Table_Insert {
	/**
	 * @param array<string,int|string|null> $values
	 * @param array<int,WP_Markdown_Native_Table_Predicate>|null $unless_exists
	 * @param array<int,array{target:string,kind:string,source:?string,value:int|string|null|WP_Markdown_Native_Query_Scalar_Expression}>|null $upsert_assignments
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

	/** @return array<int,array{target:string,kind:string,source:?string,value:int|string|null|WP_Markdown_Native_Query_Scalar_Expression}>|null */
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

/** A nested group of restrictions, using AND when all is true and OR otherwise. */
final class WP_Markdown_Native_Table_Predicate_Group {

	/** @param array<int,WP_Markdown_Native_Table_Predicate|self> $any */
	public function __construct(
		private readonly array $any,
		private readonly bool $all = false
	) {}

	/** @return array<int,WP_Markdown_Native_Table_Predicate|self> */
	public function any(): array {
		return $this->any;
	}

	public function all(): bool {
		return $this->all;
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

/** A bounded, same-table derived selection used by an alias JOIN UPDATE. */
final class WP_Markdown_Native_Table_Derived_Selection {
	public function __construct(
		private readonly string $primary_key,
		private readonly WP_Markdown_Native_SQL_Select $query
	) {}

	public function primary_key(): string {
		return $this->primary_key;
	}

	public function query(): WP_Markdown_Native_SQL_Select {
		return $this->query;
	}
}

/** One generic UPDATE or DELETE against a persisted snapshot table. */
final class WP_Markdown_Native_Table_Write {

	/**
	 * @param array<string,int|string|null|WP_Markdown_Native_Query_Scalar_Expression> $values Assignments for an UPDATE, in evaluation order.
	 * @param array<int,WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group|WP_Markdown_Native_Table_Subquery_Predicate> $predicates Conjunctive restrictions.
	 * @param array<int,array{column:string,descending:bool}> $order_by Row order that bounds LIMIT, as in MySQL single-table writes.
	 */
	public function __construct(
		private string $kind,
		private string $table,
		private array $values,
		private array $predicates,
		private ?WP_Markdown_Native_Table_Derived_Selection $derived_selection = null,
		private int $limit = PHP_INT_MAX,
		private array $order_by = array()
	) {}

	/** @return array<int,array{column:string,descending:bool}> */
	public function order_by(): array {
		return $this->order_by;
	}

	public function is_update(): bool {
		return 'update' === $this->kind;
	}

	public function table(): string {
		return $this->table;
	}

	/** @return array<string,int|string|null|WP_Markdown_Native_Query_Scalar_Expression> */
	public function values(): array {
		return $this->values;
	}

	/** @return array<int,WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group|WP_Markdown_Native_Table_Subquery_Predicate> */
	public function predicates(): array {
		return $this->predicates;
	}

	public function derived_selection(): ?WP_Markdown_Native_Table_Derived_Selection {
		return $this->derived_selection;
	}

	public function limit(): int {
		return $this->limit;
	}
}

/**
 * Row selection shared by every UPDATE/DELETE executor.
 *
 * One definition of which rows a write touches: its WHERE restrictions, then
 * MySQL's single-table `ORDER BY … LIMIT n` bound. Keeping it here means the
 * generic snapshot, partition, and wp_posts paths cannot drift apart on
 * comparison operators or on which n rows a bounded write selects.
 */
final class WP_Markdown_Native_Table_Write_Selection {

	/**
	 * Whether a row satisfies every conjunctive restriction.
	 *
	 * @param array<string,mixed>                                                                     $row
	 * @param array<int,WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group> $predicates
	 */
	public static function restricts( array $row, array $predicates, WP_Markdown_Native_Table_Schema $schema ): bool {
		foreach ( $predicates as $predicate ) {
			if ( ! self::restricts_predicate( $row, $predicate, $schema ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string,mixed>                                                      $row
	 * @param WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group $predicate
	 */
	public static function restricts_predicate( array $row, $predicate, WP_Markdown_Native_Table_Schema $schema ): bool {
		if ( $predicate instanceof WP_Markdown_Native_Table_Predicate_Group ) {
			foreach ( $predicate->any() as $alternative ) {
				$matches = self::restricts_predicate( $row, $alternative, $schema );
				if ( $matches !== $predicate->all() ) {
					return $matches;
				}
			}
			return $predicate->all();
		}
		$value = $row[ $predicate->column() ] ?? null;
		if ( $predicate->matches_null() && null === $value ) {
			return true;
		}
		$operator = $predicate->operator();
		if ( '<>' === $operator ) {
			// Like MySQL, comparisons against NULL are unknown rather than true.
			return null !== $value && ! $schema->values_match( $predicate->column(), $value, $predicate->values()[0] ?? null );
		}
		if ( in_array( $operator, array( '<', '<=', '>', '>=' ), true ) ) {
			// A comparison with NULL is unknown, which never restricts.
			if ( null === $value ) {
				return false;
			}
			$comparison = $schema->compare_values( $predicate->column(), $value, $predicate->values()[0] ?? null );
			return match ( $operator ) {
				'<' => $comparison < 0,
				'<=' => $comparison <= 0,
				'>' => $comparison > 0,
				default => $comparison >= 0,
			};
		}
		foreach ( $predicate->values() as $candidate ) {
			if ( $schema->values_match( $predicate->column(), $value, $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Offsets of the rows a bounded write selects, honoring ORDER BY then LIMIT.
	 *
	 * Without ORDER BY the first matching rows in storage order are selected,
	 * which is the pre-existing behavior. With ORDER BY the matching rows are
	 * ordered by the same schema comparator SELECT uses before LIMIT applies.
	 *
	 * @param array<int,array<string,mixed>>                                                         $rows
	 * @param array<int,WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group> $predicates
	 * @return array<int,true>|null Selected offsets, or null when a column cannot be ordered.
	 */
	public static function selected_offsets( array $rows, array $predicates, WP_Markdown_Native_Table_Write $write, WP_Markdown_Native_Table_Schema $schema ): ?array {
		$matching = array();
		foreach ( $rows as $offset => $row ) {
			if ( self::restricts( $row, $predicates, $schema ) ) {
				$matching[ $offset ] = $row;
			}
		}
		if ( array() !== $write->order_by() ) {
			$matching = $schema->ordered_rows( $matching, $write->order_by() );
			if ( null === $matching ) {
				return null;
			}
		}
		$selected = array();
		foreach ( array_keys( $matching ) as $offset ) {
			if ( count( $selected ) >= $write->limit() ) {
				break;
			}
			$selected[ $offset ] = true;
		}
		return $selected;
	}
}
