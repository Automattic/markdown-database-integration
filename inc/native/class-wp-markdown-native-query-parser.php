<?php
/** Typed parser and query-plan lowering for bounded native SELECT queries. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Query_Parser {
	public function __construct(
		private WP_Markdown_Native_SQL_Tokenizer $tokenizer = new WP_Markdown_Native_SQL_Tokenizer()
	) {}

	public function parse( string $sql ): WP_Markdown_Native_Query_Plan|WP_Markdown_Native_Found_Rows_Plan|WP_Markdown_Query_Result {
		$ast = $this->parse_ast( $sql );
		return $ast instanceof WP_Markdown_Query_Result ? $ast : $this->lower( $ast );
	}

	public function parse_ast( string $sql ): WP_Markdown_Native_SQL_Select|WP_Markdown_Native_SQL_Found_Rows|WP_Markdown_Query_Result {
		try {
			// A single trailing statement terminator is not a second statement.
			// Offsets stay source-accurate because only the tail is removed.
			$terminated = rtrim( $sql );
			if ( str_ends_with( $terminated, ';' ) ) {
				$sql = rtrim( substr( $terminated, 0, -1 ) );
			}
			return ( new WP_Markdown_Native_Select_AST_Parser( $this->tokenizer->tokenize( $sql ) ) )->parse();
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return $this->failure( $error->reason(), $error->getMessage(), $error->sql_offset() );
		}
	}

	public function lower( WP_Markdown_Native_SQL_Select|WP_Markdown_Native_SQL_Found_Rows $ast ): WP_Markdown_Native_Query_Plan|WP_Markdown_Native_Found_Rows_Plan|WP_Markdown_Query_Result {
		if ( $ast instanceof WP_Markdown_Native_SQL_Found_Rows ) {
			return new WP_Markdown_Native_Found_Rows_Plan();
		}
		// SQL_CALC_FOUND_ROWS COUNT(*) asks for the same number twice. The
		// aggregate is the unbounded match count, so FOUND_ROWS() answers it.
		if ( $ast->is_distinct() && $ast->counts_all() ) {
			return $this->failure( 'unsupported_select_modifier', 'DISTINCT requires a row projection.', $ast->table()->sql_offset() );
		}
		$projection = $ast->selects_all()
			? array( '*' )
			: array_map( static fn( WP_Markdown_Native_SQL_Identifier $column ): string => $column->name(), $ast->projection() );
		$seen = array();
		foreach ( $ast->projection() as $column ) {
			$key = ( $column->qualifier() ?? '' ) . '.' . $column->name();
			if ( isset( $seen[ $key ] ) ) {
				return $this->failure(
					'duplicate_projection',
					'mdi-native projections must not repeat columns.',
					$column->sql_offset()
				);
			}
			$seen[ $key ] = true;
		}

		$base_source = array() === $ast->joins() ? null : ( $ast->alias()?->name() ?? $ast->table()->name() );
		$predicates = array();
		foreach ( $ast->predicates() as $predicate ) {
			$predicates[] = $this->lower_predicate( $predicate, $base_source );
		}
		$joins = array_map(
			fn( WP_Markdown_Native_SQL_Join $join ): WP_Markdown_Native_Query_Join => new WP_Markdown_Native_Query_Join(
				$join->table()->name(),
				$join->alias()->name(),
				(string) $join->left()->qualifier(),
				$join->left()->name(),
				(string) $join->right()->qualifier(),
				$join->right()->name(),
				$join->is_outer(),
				array_map(
					fn( WP_Markdown_Native_SQL_Predicate $predicate ): WP_Markdown_Native_Query_Predicate => $this->lower_predicate( $predicate, $join->alias()->name() ),
					$join->on_predicates()
				)
			),
			$ast->joins()
		);
		$referenced_columns = $this->referenced_columns( $ast );
		if ( array() === $joins ) {
			$source = $ast->alias()?->name() ?? $ast->table()->name();
			foreach ( $referenced_columns as $column ) {
				if ( null !== $column->qualifier() && $source !== $column->qualifier() ) {
					return $this->failure( 'unsupported_qualifier', 'mdi-native single-table columns must use the selected table qualifier.', $column->sql_offset() );
				}
			}
		}
		if ( array() !== $joins ) {
			$base_alias = $ast->alias()?->name();
			if ( null === $base_alias || $ast->selects_all() ) {
				return $this->failure( 'unsupported_join_shape', 'mdi-native supports retained bounded equality JOIN queries only.', $ast->table()->sql_offset() );
			}
			$available = array( $base_alias => true );
			foreach ( $ast->joins() as $join ) {
				$alias = $join->alias()->name();
				$left_source = (string) $join->left()->qualifier();
				$right_source = (string) $join->right()->qualifier();
				if ( isset( $available[ $alias ] )
					|| ( $alias !== $left_source && $alias !== $right_source )
					|| ( $alias === $left_source && ! isset( $available[ $right_source ] ) )
					|| ( $alias === $right_source && ! isset( $available[ $left_source ] ) )
				) {
					return $this->failure( 'unsupported_join_shape', 'mdi-native JOINs must extend the bounded source chain.', $join->table()->sql_offset() );
				}
				$available[ $alias ] = true;
			}
			foreach ( $referenced_columns as $column ) {
				if ( ! isset( $available[ $column->qualifier() ?? $base_alias ] ) ) {
					return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested qualified column.', $column->sql_offset() );
				}
			}
		}

		$order_by = array_map(
			static fn( array $item ): array => array(
				'column'     => $item['column']->name(),
				'descending' => $item['descending'],
				'source'     => $item['column']->qualifier() ?? $base_source,
				'numeric'    => $item['numeric'] ?? false,
				'like'       => $item['like'] ?? null,
			),
			$ast->orders()
		);
		return new WP_Markdown_Native_Query_Plan(
			$ast->table()->name(),
			$projection,
			$predicates,
			$ast->order()?->name(),
			$ast->limit() ?? PHP_INT_MAX,
			$ast->counts_all(),
			$ast->alias()?->name(),
			array_map( static fn( WP_Markdown_Native_SQL_Identifier $column ): ?string => $column->qualifier() ?? $base_source, $ast->projection() ),
			$joins,
			$ast->calculates_found_rows(),
			$ast->order_descending(),
			$ast->limit_offset(),
			$ast->is_distinct(),
			$ast->order()?->qualifier(),
			$order_by,
			$ast->is_contradiction(),
			$ast->group_count_alias(),
			array_map(
				static fn( array $aggregate ): array => array(
					'function' => $aggregate['function'],
					'column'   => $aggregate['column']?->name(),
					'source'   => $aggregate['column']?->qualifier() ?? $base_source,
					'alias'    => $aggregate['alias'],
				),
				$ast->aggregates()
			)
		);
	}

	private function lower_predicate( WP_Markdown_Native_SQL_Predicate $predicate, ?string $base_source = null ): WP_Markdown_Native_Query_Predicate {
		$values = array_map( static fn( WP_Markdown_Native_SQL_Literal $literal ): int|string => $literal->value(), $predicate->values() );
		if ( 'IN' === $predicate->operator() || 'NOT IN' === $predicate->operator() ) {
			$values = array_values( array_unique( $values, SORT_REGULAR ) );
		}
		return new WP_Markdown_Native_Query_Predicate(
			$predicate->column()->name(),
			$predicate->operator(),
			$values,
			$predicate->column()->qualifier() ?? $base_source,
			array_map( fn( WP_Markdown_Native_SQL_Predicate $alternative ): WP_Markdown_Native_Query_Predicate => $this->lower_predicate( $alternative, $base_source ), $predicate->any() )
		);
	}

	/** @return array<int,WP_Markdown_Native_SQL_Identifier> */
	private function referenced_columns( WP_Markdown_Native_SQL_Select $ast ): array {
		$columns = $ast->projection();
		foreach ( $ast->predicates() as $predicate ) {
			$columns = array_merge( $columns, $this->predicate_columns( $predicate ) );
		}
		foreach ( $ast->orders() as $item ) {
			$columns[] = $item['column'];
		}
		foreach ( $ast->aggregates() as $aggregate ) {
			if ( null !== $aggregate['column'] ) {
				$columns[] = $aggregate['column'];
			}
		}
		foreach ( $ast->joins() as $join ) {
			$columns[] = $join->left();
			$columns[] = $join->right();
			foreach ( $join->on_predicates() as $predicate ) {
				$columns = array_merge( $columns, $this->predicate_columns( $predicate ) );
			}
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

	private function failure( string $reason, string $message, int $sql_offset ): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure(
			array(
				'code'       => 'markdown_db_native_unsupported_query',
				'reason'     => $reason,
				'message'    => $message,
				'sql_offset' => $sql_offset,
			)
		);
	}
}

final class WP_Markdown_Native_Select_AST_Parser {
	private int $current = 0;
	private bool $contradiction = false;

	/** @param array<int,WP_Markdown_Native_SQL_Token> $tokens */
	public function __construct( private readonly array $tokens ) {}

	public function parse(): WP_Markdown_Native_SQL_Select|WP_Markdown_Native_SQL_Found_Rows {
		$this->expect_keyword( 'SELECT' );
		if ( $this->matches_function( 'FOUND_ROWS' ) ) {
			$this->unqualified_identifier();
			$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			$this->expect_type( WP_Markdown_Native_SQL_Token::END );
			return new WP_Markdown_Native_SQL_Found_Rows();
		}
		$calculate_found_rows = $this->match_keyword( 'SQL_CALC_FOUND_ROWS' );
		$distinct = $this->match_keyword( 'DISTINCT' );
		$select_all = $this->match_type( WP_Markdown_Native_SQL_Token::STAR );
		$count_all = false;
		$group_count_alias = null;
		$aggregates = array();
		$projection = array();
		// COUNT(*) reports over rows; COUNT(column) counts values, so it is an
		// aggregate like the others rather than the row-count shortcut.
		$counts_rows = $this->matches_function( 'COUNT' )
			&& WP_Markdown_Native_SQL_Token::STAR === ( $this->tokens[ $this->current + 2 ] ?? null )?->type();
		if ( ! $select_all && $counts_rows ) {
			$count_all = true;
			$this->identifier();
			$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
			$this->expect_type( WP_Markdown_Native_SQL_Token::STAR );
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		} elseif ( ! $select_all ) {
			do {
				$aggregate = $this->match_aggregate();
				if ( null !== $aggregate ) {
					$aggregates[] = $aggregate;
					continue;
				}
				$projection[] = $this->identifier();
			} while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) );
		}

		$this->expect_keyword( 'FROM' );
		$table = $this->unqualified_identifier();
		$alias = null;
		if ( $this->match_keyword( 'AS' ) ) {
			$alias = $this->unqualified_identifier();
		} elseif ( $this->matches_identifier() && ( $this->next_is_keyword( 'JOIN' ) || $this->next_is_keyword( 'INNER' ) || $this->next_is_keyword( 'LEFT' ) ) ) {
			$alias = $this->unqualified_identifier();
		}
		$joins = array();
		while ( null !== ( $join_kind = $this->match_join_kind() ) ) {
			$join_table = $this->unqualified_identifier();
			$this->match_keyword( 'AS' );
			if ( $this->is_on() ) {
				$join_alias = $join_table;
			} else {
				$join_alias = $this->unqualified_identifier();
			}
			$this->expect_keyword( 'ON' );
			$wrapped = $this->match_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
			$left = $this->identifier();
			$this->expect_type( WP_Markdown_Native_SQL_Token::EQUALS );
			$right = $this->identifier();
			$on_predicates = array();
			while ( $this->match_keyword( 'AND' ) ) {
				$on_predicates[] = $this->predicate();
			}
			if ( $wrapped ) {
				$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			}
			if ( null === $left->qualifier() && null === $right->qualifier() ) {
				throw new WP_Markdown_Native_SQL_Parse_Error(
					'unsupported_join_shape',
					$left->sql_offset(),
					'mdi-native JOIN equality columns must be qualified.'
				);
			}
			$base_source = $alias ?? $table;
			if ( null === $left->qualifier() ) {
				$left = new WP_Markdown_Native_SQL_Identifier( $left->name(), $left->sql_offset(), $base_source->name() );
			}
			if ( null === $right->qualifier() ) {
				$right = new WP_Markdown_Native_SQL_Identifier( $right->name(), $right->sql_offset(), $base_source->name() );
			}
			$joins[] = new WP_Markdown_Native_SQL_Join( $join_table, $join_alias, $left, $right, 'left' === $join_kind, $on_predicates );
		}
		if ( null === $alias && array() !== $joins ) {
			$alias = $table;
		}
		$predicates = array();
		if ( $this->match_keyword( 'WHERE' ) ) {
			$predicates = $this->disjunction();
		}
		$grouped = WP_Markdown_Native_SQL_Token::KEYWORD === $this->current()->type()
			&& 0 === strcasecmp( 'GROUP', (string) $this->current()->value() );
		// An ungrouped aggregate reports one row over the whole match, which is
		// only answerable here when no projected column rides alongside it.
		if ( array() !== $aggregates && ! $grouped && ( array() !== $projection || array() !== $joins ) ) {
			$this->unsupported( $this->current() );
		}
		if ( $grouped ) {
			if ( $count_all || $select_all || $distinct ) {
				$this->unsupported( $this->current() );
			}
			$this->expect_keyword( 'GROUP' );
			$this->expect_keyword( 'BY' );
			$group = $this->identifier();
			if ( WP_Markdown_Native_SQL_Token::COMMA === $this->current()->type() ) {
				$this->unsupported( $this->current() );
			}
			if ( ! $this->contradiction ) {
				// Every projected column must be functionally dependent on the
				// grouping column. A wildcard over the grouped table qualifies
				// because its identity is the group.
				foreach ( $projection as $column ) {
					$same_column = $column->name() === $group->name() && $column->qualifier() === $group->qualifier();
					$grouped_wildcard = '*' === $column->name() && null !== $group->qualifier() && $column->qualifier() === $group->qualifier();
					if ( ! $same_column && ! $grouped_wildcard ) {
						throw new WP_Markdown_Native_SQL_Parse_Error(
							'unsupported_group',
							$group->sql_offset(),
							'mdi-native supports GROUP BY only as identity grouping of the selected column.'
						);
					}
				}
				if ( array() === $aggregates && array() !== $joins ) {
					$distinct = true;
				}
				if ( 1 === count( $aggregates ) && 'COUNT' === $aggregates[0]['function'] && null === $aggregates[0]['column'] && array() === $joins ) {
					$group_count_alias = $aggregates[0]['alias'];
					$aggregates = array();
				}
			}
		}
		$orders = array();
		if ( $this->match_keyword( 'ORDER' ) ) {
			$this->expect_keyword( 'BY' );
			do {
				$column = $this->identifier();
				$numeric = false;
				if ( $this->match_type( WP_Markdown_Native_SQL_Token::PLUS ) ) {
					$this->integer( 'overflow_scalar', 'mdi-native cannot decode an overflowing integer literal.' );
					$numeric = true;
				}
				// WordPress ranks search results by ORDER BY <column> LIKE
				// <pattern>, which sorts on whether the row matched.
				$like = null;
				if ( $this->match_keyword( 'LIKE' ) ) {
					$pattern = $this->literal()->value();
					if ( ! is_string( $pattern ) ) {
						$this->unsupported( $this->current() );
					}
					$like = $pattern;
				}
				$descending = false;
				if ( ! $this->match_keyword( 'ASC' ) && $this->match_keyword( 'DESC' ) ) {
					$descending = true;
				}
				$orders[] = array(
					'column'     => $column,
					'descending' => $descending,
					'numeric'    => $numeric,
					'like'       => $like,
				);
			} while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) );
		}
		$limit = null;
		$limit_offset = 0;
		if ( $this->match_keyword( 'LIMIT' ) ) {
			$limit = $this->integer( 'overflow_limit', 'mdi-native cannot apply the requested LIMIT.' );
			if ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) ) {
				$limit_offset = $limit;
				$limit = $this->integer( 'overflow_limit', 'mdi-native cannot apply the requested LIMIT.' );
				if ( $limit_offset > PHP_INT_MAX - $limit ) {
					throw new WP_Markdown_Native_SQL_Parse_Error( 'overflow_limit', $this->current()->sql_offset(), 'mdi-native cannot apply the requested LIMIT.' );
				}
			}
		}
		$this->expect_type( WP_Markdown_Native_SQL_Token::END );

		return new WP_Markdown_Native_SQL_Select( $select_all, $count_all, $projection, $table, $predicates, $orders, $limit, $alias, $joins, $calculate_found_rows, $limit_offset, $distinct, $this->contradiction, $group_count_alias, $aggregates );
	}

	private function match_join_kind(): ?string {
		if ( $this->match_keyword( 'JOIN' ) ) {
			return 'inner';
		}
		if ( $this->match_keyword( 'INNER' ) ) {
			$this->expect_keyword( 'JOIN' );
			return 'inner';
		}
		if ( ! $this->match_keyword( 'LEFT' ) ) {
			return null;
		}
		$this->match_keyword( 'OUTER' );
		$this->expect_keyword( 'JOIN' );
		return 'left';
	}

	private function is_on(): bool {
		return WP_Markdown_Native_SQL_Token::KEYWORD === $this->current()->type()
			&& 0 === strcasecmp( 'ON', (string) $this->current()->value() );
	}

	/**
	 * Parse a WHERE expression.
	 *
	 * AND binds tighter than OR, matching SQL. A disjunction is accepted only
	 * when every alternative is a supported equality predicate. Same-column
	 * alternatives collapse to membership; cross-column alternatives retain
	 * an explicit bounded disjunction. Inequality OR stays fail-closed.
	 *
	 * @return array<int,WP_Markdown_Native_SQL_Predicate>
	 */
	private function disjunction(): array {
		$groups = array( $this->conjunction() );
		$offset = $this->current()->sql_offset();
		while ( $this->match_keyword( 'OR' ) ) {
			$groups[] = $this->conjunction();
		}
		return $this->coalesce_disjunction( $groups, $offset );
	}

	/**
	 * Collapse same-column equality OR into one membership predicate.
	 *
	 * @param array<int,array<int,WP_Markdown_Native_SQL_Predicate>> $groups
	 * @return array<int,WP_Markdown_Native_SQL_Predicate>
	 */
	private function coalesce_disjunction( array $groups, int $sql_offset ): array {
		if ( 1 === count( $groups ) ) {
			return $groups[0];
		}
		$likes = array();
		foreach ( $groups as $group ) {
			if ( 1 === count( $group ) && 'LIKE' === $group[0]->operator() ) {
				$likes[] = $group[0];
			}
		}
		if ( count( $likes ) === count( $groups ) ) {
			return array( new WP_Markdown_Native_SQL_Predicate( $likes[0]->column(), 'OR', array(), $likes ) );
		}
		$column      = null;
		$qualifier   = null;
		$values      = array();
		$identifier  = null;
		$same_column = true;
		// An alternative may be a conjunction, which is how WordPress asks for
		// a public post or one this author owns privately.
		$conjunctions = false;
		foreach ( $groups as $group ) {
			if ( 1 !== count( $group ) ) {
				$conjunctions = true;
				$same_column  = false;
			}
			foreach ( $group as $conjunct ) {
				if ( ! in_array( $conjunct->operator(), array( '=', 'IN', 'IS NULL', 'LOWER =' ), true ) ) {
					throw new WP_Markdown_Native_SQL_Parse_Error(
						'unsupported_or',
						$sql_offset,
						'mdi-native supports OR only as equality or LIKE alternatives.'
					);
				}
				if ( null === $identifier ) {
					$identifier = $conjunct->column();
				}
			}
			if ( 1 !== count( $group ) ) {
				continue;
			}
			$predicate = $group[0];
			if ( ! in_array( $predicate->operator(), array( '=', 'IN', 'IS NULL', 'LOWER =' ), true ) ) {
				throw new WP_Markdown_Native_SQL_Parse_Error(
					'unsupported_or',
					$sql_offset,
					'mdi-native supports OR only as equality or LIKE alternatives.'
				);
			}
			$name = $predicate->column()->name();
			$qual = $predicate->column()->qualifier();
			if ( null === $column ) {
				$column     = $name;
				$qualifier  = $qual;
				$identifier = $predicate->column();
			} elseif ( $column !== $name || $qualifier !== $qual ) {
				$same_column = false;
			}
			$values = array_merge( $values, $predicate->values() );
		}
		$alternatives = array_map(
			static fn( array $group ): WP_Markdown_Native_SQL_Predicate => 1 === count( $group )
				? $group[0]
				: new WP_Markdown_Native_SQL_Predicate( $group[0]->column(), 'AND', array(), $group ),
			$groups
		);
		if ( $conjunctions || ! $same_column ) {
			return array( new WP_Markdown_Native_SQL_Predicate( $identifier, 'OR', array(), $alternatives ) );
		}
		foreach ( $alternatives as $alternative ) {
			if ( 'IS NULL' === $alternative->operator() ) {
				return array( new WP_Markdown_Native_SQL_Predicate( $identifier, 'OR', array(), $alternatives ) );
			}
		}
		return array( new WP_Markdown_Native_SQL_Predicate( $identifier, 'IN', $values ) );
	}

	/** @return array<int,WP_Markdown_Native_SQL_Predicate> */
	private function conjunction(): array {
		$predicates = $this->predicate_term();
		while ( $this->match_keyword( 'AND' ) ) {
			$predicates = array_merge( $predicates, $this->predicate_term() );
		}
		return $predicates;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Predicate> */
	private function predicate_term(): array {
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN ) ) {
			$predicates = $this->disjunction();
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return $predicates;
		}
		if ( WP_Markdown_Native_SQL_Token::INTEGER === $this->current()->type() ) {
			$offset = $this->current()->sql_offset();
			$left = $this->integer( 'overflow_scalar', 'mdi-native cannot decode an overflowing integer literal.' );
			$this->expect_type( WP_Markdown_Native_SQL_Token::EQUALS );
			$right = $this->integer( 'overflow_scalar', 'mdi-native cannot decode an overflowing integer literal.' );
			if ( $left !== $right ) {
				$this->contradiction = true;
			}
			return array();
		}
		return array( $this->predicate() );
	}

	/**
	 * Parse one aliased aggregate in a projection.
	 *
	 * @return array{function:string,column:?WP_Markdown_Native_SQL_Identifier,alias:string}|null
	 */
	private function match_aggregate(): ?array {
		foreach ( array( 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX' ) as $function ) {
			if ( ! $this->matches_function( $function ) ) {
				continue;
			}
			$this->identifier();
			$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
			$argument = $this->current();
			if ( WP_Markdown_Native_SQL_Token::KEYWORD === $argument->type() && 0 === strcasecmp( 'DISTINCT', (string) $argument->value() ) ) {
				// A distinct aggregate argument is its own feature.
				$this->unsupported( $argument );
			}
			$column = $this->match_type( WP_Markdown_Native_SQL_Token::STAR ) ? null : $this->identifier();
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			if ( 'COUNT' !== $function && null === $column ) {
				// Only COUNT reports over rows rather than over a column.
				$this->unsupported( $argument );
			}
			$this->expect_keyword( 'AS' );
			return array(
				'function' => $function,
				'column'   => $column,
				'alias'    => $this->unqualified_identifier()->name(),
			);
		}
		return null;
	}

	private function matches_function( string $function ): bool {
		$token = $this->current();
		return in_array( $token->type(), array( WP_Markdown_Native_SQL_Token::WORD, WP_Markdown_Native_SQL_Token::KEYWORD ), true )
			&& 0 === strcasecmp( $function, (string) $token->value() )
			&& WP_Markdown_Native_SQL_Token::LEFT_PAREN === ( $this->tokens[ $this->current + 1 ] ?? null )?->type();
	}

	private function predicate(): WP_Markdown_Native_SQL_Predicate {
		if ( $this->matches_function( 'LOWER' ) ) {
			return $this->lower_equality_predicate();
		}
		$column = $this->identifier();
		if ( $this->match_keyword( 'IS' ) ) {
			$operator = $this->match_keyword( 'NOT' ) ? 'IS NOT NULL' : 'IS NULL';
			$this->expect_keyword( 'NULL' );
			return new WP_Markdown_Native_SQL_Predicate( $column, $operator, array() );
		}
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::EQUALS ) ) {
			return new WP_Markdown_Native_SQL_Predicate( $column, '=', array( $this->literal() ) );
		}
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::NOT_EQUALS ) ) {
			return new WP_Markdown_Native_SQL_Predicate( $column, '<>', array( $this->literal() ) );
		}
		foreach ( array(
			WP_Markdown_Native_SQL_Token::LESS_EQUALS => '<=',
			WP_Markdown_Native_SQL_Token::GREATER_EQUALS => '>=',
			WP_Markdown_Native_SQL_Token::LESS_THAN => '<',
			WP_Markdown_Native_SQL_Token::GREATER_THAN => '>',
		) as $type => $operator ) {
			if ( $this->match_type( $type ) ) {
				return new WP_Markdown_Native_SQL_Predicate( $column, $operator, array( $this->literal() ) );
			}
		}
		if ( $this->match_keyword( 'BETWEEN' ) ) {
			// MySQL BETWEEN is inclusive on both ends, which is the pair of
			// range comparisons this engine already answers.
			$lower = $this->literal();
			$this->expect_keyword( 'AND' );
			return new WP_Markdown_Native_SQL_Predicate( $column, 'BETWEEN', array( $lower, $this->literal() ) );
		}
		if ( $this->match_keyword( 'LIKE' ) ) {
			return $this->like_predicate( $column, 'LIKE' );
		}
		if ( $this->match_keyword( 'NOT' ) ) {
			if ( $this->match_keyword( 'LIKE' ) ) {
				return $this->like_predicate( $column, 'NOT LIKE' );
			}
			$this->expect_keyword( 'IN' );
			return new WP_Markdown_Native_SQL_Predicate( $column, 'NOT IN', $this->in_list() );
		}
		$this->expect_keyword( 'IN' );
		return new WP_Markdown_Native_SQL_Predicate( $column, 'IN', $this->in_list() );
	}

	private function lower_equality_predicate(): WP_Markdown_Native_SQL_Predicate {
		$this->unqualified_identifier();
		$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$column = $this->identifier();
		$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		$this->expect_type( WP_Markdown_Native_SQL_Token::EQUALS );
		if ( ! $this->matches_function( 'LOWER' ) ) {
			$this->unsupported( $this->current() );
		}
		$this->unqualified_identifier();
		$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$value = $this->literal();
		$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		if ( ! is_string( $value->value() ) || 1 === preg_match( '/[^\x00-\x7F]/', $value->value() ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_literal', $value->sql_offset(), 'mdi-native LOWER equality requires an ASCII string literal.' );
		}
		return new WP_Markdown_Native_SQL_Predicate( $column, 'LOWER =', array( $value ) );
	}

	private function like_predicate( WP_Markdown_Native_SQL_Identifier $column, string $operator ): WP_Markdown_Native_SQL_Predicate {
		$pattern = $this->literal();
		if ( ! is_string( $pattern->value() ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_literal', $pattern->sql_offset(), 'mdi-native LIKE requires a string pattern.' );
		}
		return new WP_Markdown_Native_SQL_Predicate( $column, $operator, array( $pattern ) );
	}

	/** @return array<int,WP_Markdown_Native_SQL_Literal> */
	private function in_list(): array {
		$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$values = array( $this->literal() );
		while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) ) {
			$values[] = $this->literal();
		}
		$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		return $values;
	}

	private function identifier(): WP_Markdown_Native_SQL_Identifier {
		$first = $this->unqualified_identifier();
		if ( ! $this->match_type( WP_Markdown_Native_SQL_Token::DOT ) ) {
			return $first;
		}
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::STAR ) ) {
			return new WP_Markdown_Native_SQL_Identifier( '*', $first->sql_offset(), $first->name() );
		}
		$column = $this->unqualified_identifier();
		return new WP_Markdown_Native_SQL_Identifier( $column->name(), $first->sql_offset(), $first->name() );
	}

	private function unqualified_identifier(): WP_Markdown_Native_SQL_Identifier {
		$token = $this->current();
		if ( ! in_array( $token->type(), array( WP_Markdown_Native_SQL_Token::WORD, WP_Markdown_Native_SQL_Token::KEYWORD, WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER ), true ) ) {
			$this->unsupported( $token );
		}
		++$this->current;
		return new WP_Markdown_Native_SQL_Identifier( (string) $token->value(), $token->sql_offset() );
	}

	private function matches_identifier(): bool {
		return in_array( $this->current()->type(), array( WP_Markdown_Native_SQL_Token::WORD, WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER ), true );
	}

	private function next_is_keyword( string $keyword ): bool {
		$token = $this->tokens[ $this->current + 1 ] ?? null;
		return WP_Markdown_Native_SQL_Token::KEYWORD === $token?->type()
			&& 0 === strcasecmp( $keyword, (string) $token->value() );
	}

	private function literal(): WP_Markdown_Native_SQL_Literal {
		$token = $this->current();
		if ( WP_Markdown_Native_SQL_Token::STRING === $token->type() ) {
			++$this->current;
			return new WP_Markdown_Native_SQL_Literal( (string) $token->value(), $token->sql_offset() );
		}
		if ( WP_Markdown_Native_SQL_Token::INTEGER === $token->type() ) {
			$value = $this->integer( 'overflow_scalar', 'mdi-native cannot decode an overflowing integer literal.' );
			return new WP_Markdown_Native_SQL_Literal( $value, $token->sql_offset() );
		}
		if ( WP_Markdown_Native_SQL_Token::DECIMAL === $token->type() ) {
			++$this->current;
			return new WP_Markdown_Native_SQL_Literal( (string) $token->value(), $token->sql_offset() );
		}
		$this->unsupported( $token );
	}

	private function integer( string $overflow_reason, string $overflow_message ): int {
		$token = $this->expect_type( WP_Markdown_Native_SQL_Token::INTEGER );
		$value = (string) $token->value();
		$normalized = ltrim( $value, '0' );
		$normalized = '' === $normalized ? '0' : $normalized;
		$maximum    = (string) PHP_INT_MAX;
		if ( strlen( $normalized ) > strlen( $maximum ) || ( strlen( $normalized ) === strlen( $maximum ) && strcmp( $normalized, $maximum ) > 0 ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( $overflow_reason, $token->sql_offset(), $overflow_message );
		}
		return (int) $normalized;
	}

	private function expect_keyword( string $keyword ): void {
		if ( ! $this->match_keyword( $keyword ) ) {
			$this->unsupported( $this->current() );
		}
	}

	private function match_keyword( string $keyword ): bool {
		$token = $this->current();
		if ( WP_Markdown_Native_SQL_Token::KEYWORD !== $token->type() || 0 !== strcasecmp( $keyword, (string) $token->value() ) ) {
			return false;
		}
		++$this->current;
		return true;
	}

	private function match_type( string $type ): bool {
		if ( $type !== $this->current()->type() ) {
			return false;
		}
		++$this->current;
		return true;
	}

	private function expect_type( string $type ): WP_Markdown_Native_SQL_Token {
		$token = $this->current();
		if ( $type !== $token->type() ) {
			$this->unsupported( $token );
		}
		++$this->current;
		return $token;
	}

	private function current(): WP_Markdown_Native_SQL_Token {
		return $this->tokens[ $this->current ];
	}

	private function unsupported( WP_Markdown_Native_SQL_Token $token ): never {
		throw new WP_Markdown_Native_SQL_Parse_Error(
			'unsupported_grammar',
			$token->sql_offset(),
			'mdi-native supports bounded single-table SELECT queries only.'
		);
	}
}
