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
		if ( $ast instanceof WP_Markdown_Query_Result ) {
			return $ast;
		}
		try {
			return $this->lower( $ast );
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return $this->failure( $error->reason(), $error->getMessage(), $error->sql_offset() );
		}
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

	/** @param array<string,true> $outer_sources */
	public function lower( WP_Markdown_Native_SQL_Select|WP_Markdown_Native_SQL_Found_Rows $ast, array $outer_sources = array() ): WP_Markdown_Native_Query_Plan|WP_Markdown_Native_Found_Rows_Plan|WP_Markdown_Query_Result {
		if ( $ast instanceof WP_Markdown_Native_SQL_Found_Rows ) {
			return new WP_Markdown_Native_Found_Rows_Plan();
		}
		// SQL_CALC_FOUND_ROWS COUNT(*) asks for the same number twice. The
		// aggregate is the unbounded match count, so FOUND_ROWS() answers it.
		if ( $ast->is_distinct() && $ast->counts_all() ) {
			return $this->failure( 'unsupported_select_modifier', 'DISTINCT requires a row projection.', $ast->table()->sql_offset() );
		}
		$base_source = array() === $ast->joins() ? null : ( $ast->alias()?->name() ?? $ast->table()->name() );
		$child_outer_sources = $outer_sources;
		$child_outer_sources[ $ast->alias()?->name() ?? $ast->table()->name() ] = true;
		foreach ( $ast->joins() as $join ) { $child_outer_sources[ $join->alias()->name() ] = true; }
		$projection = $ast->selects_all()
			? array( '*' )
			: array_map( static fn( WP_Markdown_Native_SQL_Identifier $column ): string => $column->name(), $ast->projection() );
		$scalar_projection = array_map(
			fn( array $scalar ): array => array(
				'expression' => $this->lower_scalar_expression( $scalar['expression'], $base_source ?? null ),
				'alias'      => $scalar['alias'],
				'position'   => $scalar['position'],
			),
			$ast->scalar_projection()
		);
		$scalar_predicates = array_map( fn( WP_Markdown_Native_SQL_Scalar_Predicate $predicate ): WP_Markdown_Native_Query_Scalar_Predicate => $this->lower_scalar_predicate( $predicate, $base_source ), $ast->scalar_predicates() );
		$boolean_predicate = null === $ast->boolean_predicate() ? null : new WP_Markdown_Native_Query_Boolean_Predicate( array_map( function ( array $group ) use ( $base_source, $child_outer_sources ): array {
			return array_map( function ( WP_Markdown_Native_SQL_Predicate|WP_Markdown_Native_SQL_Scalar_Predicate|WP_Markdown_Native_SQL_Subquery_Predicate $predicate ) use ( $base_source, $child_outer_sources ): WP_Markdown_Native_Query_Predicate|WP_Markdown_Native_Query_Scalar_Predicate|WP_Markdown_Native_Query_Subquery {
				if ( $predicate instanceof WP_Markdown_Native_SQL_Scalar_Predicate ) { return $this->lower_scalar_predicate( $predicate, $base_source ); }
				if ( $predicate instanceof WP_Markdown_Native_SQL_Subquery_Predicate ) { return $this->lower_subquery( $predicate, $child_outer_sources ); }
				return $this->lower_predicate( $predicate, $base_source );
			}, $group );
		}, $ast->boolean_predicate()->groups() ) );
		$scalar_having = array_map( fn( WP_Markdown_Native_SQL_Scalar_Predicate $predicate ): WP_Markdown_Native_Query_Scalar_Predicate => $this->lower_scalar_predicate( $predicate, null ), $ast->scalar_having() );
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
		foreach ( $scalar_projection as $scalar ) {
			if ( isset( $seen[ '.' . $scalar['alias'] ] ) ) {
				return $this->failure( 'duplicate_projection', 'mdi-native projections must not repeat columns.', $ast->table()->sql_offset() );
			}
			$seen[ '.' . $scalar['alias'] ] = true;
		}

		$predicates = array();
		$subqueries = array();
		foreach ( $ast->subqueries() as $subquery_predicate ) { $subqueries[] = $this->lower_subquery( $subquery_predicate, $child_outer_sources ); }
		foreach ( $ast->predicates() as $predicate ) {
			$predicates[] = $this->lower_predicate( $predicate, $base_source );
		}
		$joins = array();
		foreach ( $ast->joins() as $join ) {
			$join_derived = null === $join->derived() ? null : $this->lower( $join->derived() );
			if ( null !== $join_derived && ! $join_derived instanceof WP_Markdown_Native_Query_Plan ) {
				return $join_derived;
			}
			$joins[] = new WP_Markdown_Native_Query_Join(
				$join->table()->name(),
				$join->alias()->name(),
				$join->left()?->qualifier(),
				$join->left()?->name(),
				$join->right()?->qualifier(),
				$join->right()?->name(),
				$join->is_outer(),
				array_map(
					fn( WP_Markdown_Native_SQL_Predicate $predicate ): WP_Markdown_Native_Query_Predicate => $this->lower_predicate( $predicate, $join->alias()->name() ),
					$join->on_predicates()
				),
				$join_derived
			);
		}
		$referenced_columns = $this->referenced_columns( $ast );
		if ( array() === $joins ) {
			$source = $ast->alias()?->name() ?? $ast->table()->name();
			foreach ( $referenced_columns as $column ) {
				if ( null !== $column->qualifier() && $source !== $column->qualifier() && ! isset( $outer_sources[ $column->qualifier() ] ) ) {
					return $this->failure( 'unsupported_qualifier', 'mdi-native single-table columns must use the selected table qualifier.', $column->sql_offset() );
				}
			}
		}
		if ( array() !== $joins ) {
			$base_alias = $ast->alias()?->name();
			if ( $ast->selects_all() ) {
				return $this->failure( 'unsupported_join_shape', 'mdi-native JOIN projections must name their source columns.', $ast->table()->sql_offset() );
			}
			$base_alias ??= $ast->table()->name();
			$available = array( $base_alias => true );
			foreach ( $ast->joins() as $join ) {
				$alias = $join->alias()->name();
				if ( isset( $available[ $alias ] ) ) {
					return $this->failure( 'unsupported_join_shape', 'mdi-native JOINs must extend the bounded source chain.', $join->table()->sql_offset() );
				}
				$available[ $alias ] = true;
			}
			foreach ( $referenced_columns as $column ) {
				if ( ! isset( $available[ $column->qualifier() ?? $base_alias ] ) && ! isset( $outer_sources[ $column->qualifier() ?? '' ] ) ) {
					return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested qualified column.', $column->sql_offset() );
				}
			}
		}

		$aggregate_aliases = array_column( $ast->aggregates(), 'alias' );
		$order_by = array_map(
			fn( array $item ): array => array(
				'column'     => $item['column']->name(),
				'descending' => $item['descending'],
				'source'     => in_array( $item['column']->name(), $aggregate_aliases, true ) ? null : ( $item['column']->qualifier() ?? $base_source ),
				'numeric'    => $item['numeric'] ?? false,
				'like'       => $item['like'] ?? null,
				'field'      => $item['field'] ?? null,
				'case'       => null === ( $item['case'] ?? null ) ? null : array(
					'branches' => array_map(
						fn( array $branch ): array => array(
							'predicates' => array_map( fn( WP_Markdown_Native_SQL_Predicate $predicate ): WP_Markdown_Native_Query_Predicate => $this->lower_predicate( $predicate, $base_source ), $branch['predicates'] ),
							'value'      => $branch['value'],
						),
						$item['case']['branches']
					),
					'else' => $item['case']['else'],
				),
				'expression' => null === ( $item['expression'] ?? null ) ? null : $this->lower_scalar_expression( $item['expression'], $base_source ),
			),
			$ast->orders()
		);
		$having = array_map( fn( WP_Markdown_Native_SQL_Predicate $predicate ): WP_Markdown_Native_Query_Predicate => $this->lower_predicate( $predicate, null ), $ast->having() );
		$union = null;
		if ( null !== $ast->union() ) {
			$union = $this->lower( $ast->union() );
			if ( ! $union instanceof WP_Markdown_Native_Query_Plan ) {
				return $union;
			}
		}
		$derived = null === $ast->derived() ? null : $this->lower( $ast->derived() );
		if ( null !== $derived && ! $derived instanceof WP_Markdown_Native_Query_Plan ) {
			return $derived;
		}
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
			$ast->group_by()?->name(),
			array_map(
				static fn( array $aggregate ): array => array(
					'function' => $aggregate['function'],
					'column'   => $aggregate['column']?->name(),
					'source'   => $aggregate['column']?->qualifier() ?? $base_source,
					'alias'    => $aggregate['alias'],
				),
				$ast->aggregates()
			),
			$scalar_projection
			,
			$having,
			$subqueries,
			$union,
			$scalar_predicates,
			$scalar_having,
			null === $ast->group_expression() ? null : $this->lower_scalar_expression( $ast->group_expression(), $base_source ),
			$boolean_predicate,
			$derived,
			$ast->union_all(),
			array_map( fn( array $item ): array => array( 'column' => $item['column']->name(), 'descending' => $item['descending'], 'numeric' => str_starts_with( $item['column']->name(), '__union_ordinal_' ) ), $ast->union_orders() ),
			$ast->union_limit(),
			$ast->union_limit_offset()
		);
	}

	/** @param array<string,true> $outer_sources */
	private function lower_subquery( WP_Markdown_Native_SQL_Subquery_Predicate $predicate, array $outer_sources = array() ): WP_Markdown_Native_Query_Subquery {
		$subquery = $this->lower( $predicate->query(), $outer_sources );
		if ( ! $subquery instanceof WP_Markdown_Native_Query_Plan ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_subquery_shape', $predicate->column()?->sql_offset() ?? 0, 'mdi-native could not lower the requested subquery.' );
		}
		return new WP_Markdown_Native_Query_Subquery( $predicate->operator(), $predicate->column()?->name(), $subquery, $predicate->column()?->qualifier() );
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
			array_map( fn( WP_Markdown_Native_SQL_Predicate $alternative ): WP_Markdown_Native_Query_Predicate => $this->lower_predicate( $alternative, $base_source ), $predicate->any() ),
			$predicate->cast(),
			$predicate->comparison()?->name(),
			$predicate->comparison()?->qualifier()
		);
	}

	private function lower_scalar_expression( WP_Markdown_Native_SQL_Scalar_Expression $expression, ?string $base_source ): WP_Markdown_Native_Query_Scalar_Expression {
		return new WP_Markdown_Native_Query_Scalar_Expression(
			$expression->kind(),
			$expression->identifier()?->name(),
			$expression->literal(),
			array_map(
				fn( WP_Markdown_Native_SQL_Scalar_Expression $argument ): WP_Markdown_Native_Query_Scalar_Expression => $this->lower_scalar_expression( $argument, $base_source ),
				$expression->arguments()
			),
			array_map(
				fn( array $branch ): array => array(
					'predicates' => array_map(
						fn( WP_Markdown_Native_SQL_Predicate $predicate ): WP_Markdown_Native_Query_Predicate => $this->lower_predicate( $predicate, $base_source ),
						$branch['predicates']
					),
					'value' => $this->lower_scalar_expression( $branch['value'], $base_source ),
			),
			$expression->branches()
		),
			null === $expression->else() ? null : $this->lower_scalar_expression( $expression->else(), $base_source ),
			$expression->identifier()?->qualifier() ?? $base_source
		);
	}

	private function lower_scalar_predicate( WP_Markdown_Native_SQL_Scalar_Predicate $predicate, ?string $base_source ): WP_Markdown_Native_Query_Scalar_Predicate {
		return new WP_Markdown_Native_Query_Scalar_Predicate( $this->lower_scalar_expression( $predicate->left(), $base_source ), $predicate->operator(), $this->lower_scalar_expression( $predicate->right(), $base_source ) );
	}

	/** @return array<int,WP_Markdown_Native_SQL_Identifier> */
	private function referenced_columns( WP_Markdown_Native_SQL_Select $ast ): array {
		$columns = $ast->projection();
		foreach ( $ast->scalar_projection() as $scalar ) {
			$columns = array_merge( $columns, $scalar['expression']->columns() );
			foreach ( $scalar['expression']->branches() as $branch ) {
				foreach ( $branch['predicates'] as $predicate ) {
					$columns = array_merge( $columns, $this->predicate_columns( $predicate ) );
				}
			}
		}
		foreach ( $ast->predicates() as $predicate ) {
			if ( $predicate instanceof WP_Markdown_Native_SQL_Subquery_Predicate ) {
				if ( null !== $predicate->column() ) { $columns[] = $predicate->column(); }
				continue;
			}
			$columns = array_merge( $columns, $this->predicate_columns( $predicate ) );
		}
		foreach ( $ast->scalar_predicates() as $predicate ) { $columns = array_merge( $columns, $predicate->columns() ); }
		if ( null !== $ast->boolean_predicate() ) {
			$columns = array_merge( $columns, $ast->boolean_predicate()->columns() );
		}
		foreach ( $ast->scalar_having() as $predicate ) { $columns = array_merge( $columns, $predicate->columns() ); }
		if ( null !== $ast->group_expression() ) { $columns = array_merge( $columns, $ast->group_expression()->columns() ); }
		foreach ( $ast->orders() as $item ) {
			if ( null !== ( $item['expression'] ?? null ) ) { $columns = array_merge( $columns, $item['expression']->columns() ); continue; }
			if ( null === ( $item['case'] ?? null ) ) {
				$columns[] = $item['column'];
				continue;
			}
			foreach ( $item['case']['branches'] as $branch ) {
				foreach ( $branch['predicates'] as $predicate ) {
					$columns = array_merge( $columns, $this->predicate_columns( $predicate ) );
				}
			}
		}
		foreach ( $ast->aggregates() as $aggregate ) {
			if ( null !== $aggregate['column'] ) {
				$columns[] = $aggregate['column'];
			}
		}
		foreach ( $ast->joins() as $join ) {
			if ( null !== $join->left() ) { $columns[] = $join->left(); }
			if ( null !== $join->right() ) { $columns[] = $join->right(); }
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
		$result = WP_Markdown_Native_SQL_Token::LEFT_PAREN === $this->current()->type()
			? $this->parenthesized_query_expression()
			: $this->select( false );
		$this->expect_type( WP_Markdown_Native_SQL_Token::END );
		return $result;
	}

	/** Parse grouped UNION operands while retaining branch-local ORDER BY and LIMIT. */
	private function parenthesized_query_expression(): WP_Markdown_Native_SQL_Select {
		$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$expression = WP_Markdown_Native_SQL_Token::LEFT_PAREN === $this->current()->type()
			? $this->parenthesized_query_expression()
			: $this->select( true );
		$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		$has_union = false;
		while ( $this->match_keyword( 'UNION' ) ) {
			$has_union = true;
			$all = $this->match_keyword( 'ALL' );
			$unparenthesized = WP_Markdown_Native_SQL_Token::LEFT_PAREN !== $this->current()->type();
			$branch = ! $unparenthesized
				? $this->parenthesized_query_expression()
				: $this->select( true );
			if ( ! $branch instanceof WP_Markdown_Native_SQL_Select ) {
				$this->unsupported( $this->current() );
			}
			$expression = $expression->append_union( $branch, $all );
			if ( $unparenthesized ) {
				$orders = array() !== $branch->union_orders() ? $branch->union_orders() : $branch->orders();
				$limit = null !== $branch->union_limit() ? $branch->union_limit() : $branch->limit();
				$offset = null !== $branch->union_limit() ? $branch->union_limit_offset() : $branch->limit_offset();
				return $expression->with_union_tail( $orders, $limit, $offset );
			}
		}
		if ( ! $has_union ) { return $expression; }
		$orders = array();
		if ( $this->match_keyword( 'ORDER' ) ) {
			$this->expect_keyword( 'BY' );
			do {
				$column = WP_Markdown_Native_SQL_Token::INTEGER === $this->current()->type()
					? new WP_Markdown_Native_SQL_Identifier( '__union_ordinal_' . $this->integer( 'overflow_order', 'mdi-native cannot decode an overflowing UNION ORDER BY ordinal.' ), $this->current()->sql_offset() )
					: $this->identifier();
				$descending = ! $this->match_keyword( 'ASC' ) && $this->match_keyword( 'DESC' );
				$orders[] = array( 'column' => $column, 'descending' => $descending );
			} while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) );
		}
		$limit = null;
		$offset = 0;
		if ( $this->match_keyword( 'LIMIT' ) ) {
			$limit = $this->integer( 'overflow_limit', 'mdi-native cannot apply the requested LIMIT.' );
			if ( $this->match_keyword( 'OFFSET' ) ) { $offset = $this->integer( 'overflow_limit', 'mdi-native cannot apply the requested LIMIT.' ); }
		}
		return $expression->with_union_tail( $orders, $limit, $offset );
	}

	private function select( bool $nested ): WP_Markdown_Native_SQL_Select|WP_Markdown_Native_SQL_Found_Rows {
		$this->expect_keyword( 'SELECT' );
		if ( $this->matches_function( 'FOUND_ROWS' ) ) {
			$this->unqualified_identifier();
			$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return new WP_Markdown_Native_SQL_Found_Rows();
		}
		$calculate_found_rows = $this->match_keyword( 'SQL_CALC_FOUND_ROWS' );
		$distinct = $this->match_keyword( 'DISTINCT' );
		$select_all = $this->match_type( WP_Markdown_Native_SQL_Token::STAR );
		$count_all = false;
		$group = null;
		$aggregates = array();
		$projection = array();
		$scalar_projection = array();
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
				if ( $this->matches_scalar_expression() ) {
					$scalar_projection[] = array(
						'expression' => $this->scalar_expression(),
						'alias'      => $this->scalar_alias(),
						'position'   => count( $projection ) + count( $scalar_projection ),
					);
					continue;
				}
				if ( in_array( $this->current()->type(), array( WP_Markdown_Native_SQL_Token::INTEGER, WP_Markdown_Native_SQL_Token::DECIMAL, WP_Markdown_Native_SQL_Token::STRING ), true ) ) {
					$literal = $this->literal();
					$scalar_projection[] = array(
						'expression' => new WP_Markdown_Native_SQL_Scalar_Expression( 'literal', null, $literal->value() ),
						'alias' => (string) $literal->value(),
						'position' => count( $projection ) + count( $scalar_projection ),
					);
					continue;
				}
				$projection[] = $this->identifier();
			} while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) );
		}

		$this->expect_keyword( 'FROM' );
		list( $table, $alias, $derived ) = $this->source( true );
		$joins = array();
		while ( null !== ( $join_kind = $this->match_join_kind() ) ) {
			list( $join_table, $join_alias, $join_derived ) = $this->source( false );
			$this->expect_keyword( 'ON' );
			$on_predicates = $this->disjunction( true );
			$equality = null;
			$equality_index = null;
			foreach ( $on_predicates as $index => $predicate ) {
				if ( '=' === $predicate->operator() && null !== $predicate->comparison() ) {
					$equality = $predicate;
					$equality_index = $index;
					break;
				}
			}
			$left = null === $equality ? null : $equality->column();
			$right = null === $equality ? null : $equality->comparison();
			$base_source = $alias ?? $table;
			if ( null !== $left && null === $left->qualifier() ) {
				$left = new WP_Markdown_Native_SQL_Identifier( $left->name(), $left->sql_offset(), $base_source->name() );
			}
			if ( null !== $right && null === $right->qualifier() ) {
				$right = new WP_Markdown_Native_SQL_Identifier( $right->name(), $right->sql_offset(), $base_source->name() );
			}
			if ( null !== $equality_index && null !== $left && null !== $right ) {
				$on_predicates[ $equality_index ] = new WP_Markdown_Native_SQL_Predicate( $left, '=', array(), array(), null, $right );
			}
			$joins[] = new WP_Markdown_Native_SQL_Join( $join_table, $join_alias, $left, $right, 'left' === $join_kind, array_values( $on_predicates ), $join_derived );
		}
		if ( null === $alias && array() !== $joins ) {
			$alias = $table;
		}
		$predicates = array();
		$scalar_predicates = array();
		$boolean_predicate = null;
		$subqueries = array();
		if ( $this->match_keyword( 'WHERE' ) ) {
			$where_groups = $this->boolean_disjunction();
			$has_scalar = false;
			$has_subquery = false;
			foreach ( $where_groups as $where_group ) { foreach ( $where_group as $predicate ) { $has_scalar = $has_scalar || $predicate instanceof WP_Markdown_Native_SQL_Scalar_Predicate; $has_subquery = $has_subquery || $predicate instanceof WP_Markdown_Native_SQL_Subquery_Predicate; } }
			if ( $has_scalar || $this->requires_boolean_plan( $where_groups ) ) {
				$boolean_predicate = new WP_Markdown_Native_SQL_Boolean_Predicate( $where_groups );
			} else foreach ( $this->coalesce_boolean_groups( $where_groups, $this->current()->sql_offset() ) as $predicate ) {
				if ( $predicate instanceof WP_Markdown_Native_SQL_Subquery_Predicate ) {
					$subqueries[] = $predicate;
				} elseif ( $predicate instanceof WP_Markdown_Native_SQL_Scalar_Predicate ) {
					$scalar_predicates[] = $predicate;
				} else {
					$predicates[] = $predicate;
				}
			}
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
			$group_expression = $this->scalar_value();
			$group = $group_expression->identifier();
			if ( null === $group ) {
				$group = new WP_Markdown_Native_SQL_Identifier( '__scalar_group', $this->current()->sql_offset() );
			}
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
				if ( array() === $aggregates ) {
					$distinct = true;
				}
			}
		}
		$having = array();
		$scalar_having = array();
		if ( $this->match_keyword( 'HAVING' ) ) {
			foreach ( $this->conjunction() as $predicate ) {
				if ( $predicate instanceof WP_Markdown_Native_SQL_Scalar_Predicate ) { $scalar_having[] = $predicate; } else { $having[] = $predicate; }
			}
			if ( array() !== $having && ( ! $grouped || array() === $aggregates ) ) { $this->unsupported( $this->current() ); }
			$aggregate_aliases = array_column( $aggregates, 'alias' );
			foreach ( $having as $predicate ) {
				if ( ! in_array( $predicate->column()->name(), $aggregate_aliases, true ) || ! in_array( $predicate->operator(), array( '=', '<>', '<', '<=', '>', '>=' ), true ) ) {
					$this->unsupported( $this->current() );
				}
			}
		}
		$orders = array();
		if ( $this->match_keyword( 'ORDER' ) ) {
			$this->expect_keyword( 'BY' );
			do {
				$expression = null;
				$parenthesized = $this->match_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
				$case = $this->match_keyword( 'CASE' ) ? $this->searched_case() : null;
				$field = null;
				if ( null !== $case ) {
					$column = $case['branches'][0]['predicates'][0]->column();
					if ( $parenthesized ) {
						$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
					}
				} elseif ( $this->matches_function( 'FIELD' ) ) {
					$this->unqualified_identifier();
					$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
					$column = $this->identifier();
					$field = array();
					while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) ) {
						$field[] = $this->literal()->value();
					}
					$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
					if ( array() === $field ) {
						$this->unsupported( $this->current() );
					}
				} elseif ( $parenthesized ) {
					$expression = $this->scalar_value();
					$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
					$columns = $expression->columns();
					$column = $columns[0] ?? new WP_Markdown_Native_SQL_Identifier( '__scalar_order', $this->current()->sql_offset() );
				} elseif ( $this->matches_scalar_expression() ) {
					$expression = $this->scalar_expression();
					$columns = $expression->columns();
					$column = $columns[0] ?? new WP_Markdown_Native_SQL_Identifier( '__scalar_order', $this->current()->sql_offset() );
				} else {
					if ( $parenthesized ) {
						$this->unsupported( $this->current() );
					}
					if ( WP_Markdown_Native_SQL_Token::INTEGER === $this->current()->type() ) {
						$ordinal_offset = $this->current()->sql_offset();
						$ordinal = $this->integer( 'overflow_order', 'mdi-native cannot decode an overflowing UNION ORDER BY ordinal.' );
						if ( 1 > $ordinal ) { $this->unsupported( $this->current() ); }
						$column = new WP_Markdown_Native_SQL_Identifier( '__union_ordinal_' . $ordinal, $ordinal_offset );
					} else {
						$column = $this->identifier();
					}
				}
				$numeric = false;
				if ( null === $case && $this->match_type( WP_Markdown_Native_SQL_Token::PLUS ) ) {
					$this->integer( 'overflow_scalar', 'mdi-native cannot decode an overflowing integer literal.' );
					$numeric = true;
				}
				// WordPress ranks search results by ORDER BY <column> LIKE
				// <pattern>, which sorts on whether the row matched.
				$like = null;
				if ( null === $case && $this->match_keyword( 'LIKE' ) ) {
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
					'field'      => $field,
					'case'       => $case,
					'expression' => $expression ?? null,
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
		$union = null;
		$union_all = false;
		$union_orders = array();
		$union_limit = null;
		$union_limit_offset = 0;
		if ( $this->match_keyword( 'UNION' ) ) {
			$union_all = $this->match_keyword( 'ALL' );
			$union = WP_Markdown_Native_SQL_Token::LEFT_PAREN === $this->current()->type()
				? $this->parenthesized_query_expression()
				: $this->select( $nested );
			if ( ! $union instanceof WP_Markdown_Native_SQL_Select ) {
				$this->unsupported( $this->current() );
			}
			// An unparenthesized final branch owns syntactic ORDER/LIMIT tokens, but
			// MySQL applies them to the complete UNION result. Parenthesized sources
			// are parsed through source() and keep their branch-local clauses.
			$union_orders = array() !== $union->union_orders() ? $union->union_orders() : $union->orders();
			$union_limit = null !== $union->union_limit() ? $union->union_limit() : $union->limit();
			$union_limit_offset = null !== $union->union_limit() ? $union->union_limit_offset() : $union->limit_offset();
			$union = $union->without_order_limit();
		}
		// The native backend has one writer, so row locks have no additional
		// effect. Accept this common lock-then-write hint without weakening the
		// grammar for other trailing clauses.
		if ( ! $nested && $this->match_keyword( 'FOR' ) ) {
			$this->expect_keyword( 'UPDATE' );
		}
		return new WP_Markdown_Native_SQL_Select( $select_all, $count_all, $projection, $table, $predicates, $orders, $limit, $alias, $joins, $calculate_found_rows, $limit_offset, $distinct, $this->contradiction, $group, $aggregates, $scalar_projection, $having, $subqueries, $union, $scalar_predicates, $scalar_having, $grouped ? $group_expression : null, $boolean_predicate, $derived, $union_all, $union_orders, $union_limit, $union_limit_offset );
	}

	/** @return array{WP_Markdown_Native_SQL_Identifier,?WP_Markdown_Native_SQL_Identifier,?WP_Markdown_Native_SQL_Select} */
	private function source( bool $base ): array {
		if ( ! $this->match_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN ) ) {
			$table = $this->unqualified_identifier();
			$alias = null;
			if ( $this->match_keyword( 'AS' ) ) {
				$alias = $this->unqualified_identifier();
			} elseif ( $this->matches_identifier() && ( ! $base || ! $this->is_on() ) ) {
				$alias = $this->unqualified_identifier();
			}
			if ( ! $base && null === $alias ) {
				$alias = $table;
			}
			return array( $table, $alias, null );
		}
		$derived = $this->select( true );
		if ( ! $derived instanceof WP_Markdown_Native_SQL_Select ) {
			$this->unsupported( $this->current() );
		}
		$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		$this->match_keyword( 'AS' );
		$alias = $this->unqualified_identifier();
		return array( $alias, $alias, $derived );
	}

	private function matches_scalar_expression(): bool {
		return WP_Markdown_Native_SQL_Token::LEFT_PAREN === $this->current()->type()
			|| in_array( strtoupper( (string) $this->current()->value() ), array( 'CONCAT', 'COALESCE', 'SUBSTRING', 'CAST', 'YEAR', 'MONTH', 'DATE_FORMAT', 'DATE', 'TIME', 'NOW', 'UTC_TIMESTAMP', 'CURDATE', 'UNIX_TIMESTAMP', 'FROM_UNIXTIME', 'DATEDIFF', 'TIMESTAMPDIFF', 'DATE_ADD', 'DATE_SUB', 'DAY', 'DAYOFMONTH', 'DAYOFYEAR', 'WEEKDAY', 'WEEK', 'SECOND', 'HOUR', 'MINUTE', 'DAYOFWEEK', 'GREATEST', 'LEAST', 'IF', 'IFNULL', 'NULLIF', 'LOWER', 'UPPER', 'TRIM', 'LENGTH', 'CHAR_LENGTH', 'REPLACE', 'LEFT', 'RIGHT', 'LOCATE', 'MD5', 'SHA1', 'ABS', 'ROUND', 'FLOOR', 'CEIL', 'MOD', 'POW', 'SQRT', 'RADIANS', 'DEGREES', 'SIN', 'COS', 'TAN', 'ACOS', 'ASIN', 'ATAN', 'ATAN2', 'RAND' ), true )
			&& WP_Markdown_Native_SQL_Token::LEFT_PAREN === ( $this->tokens[ $this->current + 1 ] ?? null )?->type()
			|| ( WP_Markdown_Native_SQL_Token::KEYWORD === $this->current()->type() && 0 === strcasecmp( 'CASE', (string) $this->current()->value() ) );
	}

	private function scalar_alias(): string {
		$this->expect_keyword( 'AS' );
		return $this->unqualified_identifier()->name();
	}

	private function scalar_expression(): WP_Markdown_Native_SQL_Scalar_Expression {
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN ) ) {
			$expression = $this->scalar_value();
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return $expression;
		}
		if ( $this->match_keyword( 'CASE' ) ) {
			$case = $this->searched_case();
			return new WP_Markdown_Native_SQL_Scalar_Expression(
				'CASE',
				null,
				null,
				array(),
				array_map( static fn( array $branch ): array => array( 'predicates' => $branch['predicates'], 'value' => new WP_Markdown_Native_SQL_Scalar_Expression( 'literal', null, $branch['value'] ) ), $case['branches'] ),
				new WP_Markdown_Native_SQL_Scalar_Expression( 'literal', null, $case['else'] )
			);
		}
		$function = strtoupper( (string) $this->unqualified_identifier()->name() );
		$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		if ( 'CAST' === $function ) {
			$argument = $this->scalar_value();
			$this->expect_keyword( 'AS' );
			$this->expect_keyword( 'UNSIGNED' );
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return new WP_Markdown_Native_SQL_Scalar_Expression( 'CAST_UNSIGNED', null, null, array( $argument ) );
		}
		if ( in_array( $function, array( 'DATE_ADD', 'DATE_SUB' ), true ) ) {
			$arguments = array( $this->scalar_value() );
			$this->expect_type( WP_Markdown_Native_SQL_Token::COMMA );
			$this->expect_keyword( 'INTERVAL' );
			$value = $this->scalar_value();
			$unit = strtoupper( $this->unqualified_identifier()->name() );
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return new WP_Markdown_Native_SQL_Scalar_Expression( $function, null, null, array( $arguments[0], $value, new WP_Markdown_Native_SQL_Scalar_Expression( 'literal', null, $unit ) ) );
		}
		if ( 'TIMESTAMPDIFF' === $function ) {
			$unit = strtoupper( $this->unqualified_identifier()->name() );
			$this->expect_type( WP_Markdown_Native_SQL_Token::COMMA );
			$arguments = array( new WP_Markdown_Native_SQL_Scalar_Expression( 'literal', null, $unit ), $this->scalar_value() );
			$this->expect_type( WP_Markdown_Native_SQL_Token::COMMA );
			$arguments[] = $this->scalar_value();
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return new WP_Markdown_Native_SQL_Scalar_Expression( $function, null, null, $arguments );
		}
		$arguments = array();
		if ( ! $this->match_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN ) ) {
			$arguments[] = $this->scalar_value();
			while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) ) {
				$arguments[] = $this->scalar_value();
			}
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		}
		$valid = match ( $function ) {
			'CONCAT', 'COALESCE' => 2 <= count( $arguments ),
			'SUBSTRING' => 3 === count( $arguments ),
			'YEAR', 'MONTH', 'DATE', 'TIME', 'FROM_UNIXTIME', 'DAY', 'DAYOFMONTH', 'DAYOFYEAR', 'WEEKDAY', 'SECOND', 'HOUR', 'MINUTE', 'DAYOFWEEK', 'LOWER', 'UPPER', 'TRIM', 'LENGTH', 'CHAR_LENGTH', 'MD5', 'SHA1', 'ABS', 'FLOOR', 'CEIL', 'SQRT', 'RADIANS', 'DEGREES', 'SIN', 'COS', 'TAN', 'ACOS', 'ASIN', 'ATAN' => 1 === count( $arguments ),
			'UNIX_TIMESTAMP', 'RAND' => 0 === count( $arguments ) || 1 === count( $arguments ),
			'WEEK' => 2 === count( $arguments ) && 1 === (int) $arguments[1]->literal(),
			'DATE_FORMAT', 'DATEDIFF', 'IFNULL', 'NULLIF', 'LEFT', 'RIGHT', 'LOCATE', 'MOD', 'POW', 'ATAN2' => 2 === count( $arguments ),
			'ROUND' => in_array( count( $arguments ), array( 1, 2 ), true ),
			'GREATEST', 'LEAST' => 2 <= count( $arguments ),
			'IF', 'REPLACE' => 3 === count( $arguments ),
			'NOW', 'UTC_TIMESTAMP', 'CURDATE' => 0 === count( $arguments ),
			default => false,
		};
		if ( ! $valid ) { $this->unsupported( $this->current() ); }
		return new WP_Markdown_Native_SQL_Scalar_Expression( $function, null, null, $arguments );
	}

	private function scalar_value(): WP_Markdown_Native_SQL_Scalar_Expression {
		$value = $this->scalar_term();
		while ( in_array( $this->current()->type(), array( WP_Markdown_Native_SQL_Token::PLUS, WP_Markdown_Native_SQL_Token::MINUS ), true ) ) {
			$operator = $this->current()->type(); ++$this->current;
			$value = new WP_Markdown_Native_SQL_Scalar_Expression( WP_Markdown_Native_SQL_Token::PLUS === $operator ? 'ADD' : 'SUBTRACT', null, null, array( $value, $this->scalar_term() ) );
		}
		return $value;
	}

	private function scalar_term(): WP_Markdown_Native_SQL_Scalar_Expression {
		$value = $this->scalar_primary();
		while ( in_array( $this->current()->type(), array( WP_Markdown_Native_SQL_Token::STAR, WP_Markdown_Native_SQL_Token::SLASH ), true ) ) {
			$operator = $this->current()->type(); ++$this->current;
			$value = new WP_Markdown_Native_SQL_Scalar_Expression( WP_Markdown_Native_SQL_Token::STAR === $operator ? 'MULTIPLY' : 'DIVIDE', null, null, array( $value, $this->scalar_primary() ) );
		}
		return $value;
	}

	private function scalar_primary(): WP_Markdown_Native_SQL_Scalar_Expression {
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN ) ) { $value = $this->scalar_value(); $this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN ); return $value; }
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::MINUS ) ) {
			return new WP_Markdown_Native_SQL_Scalar_Expression( 'SUBTRACT', null, null, array( new WP_Markdown_Native_SQL_Scalar_Expression( 'literal', null, 0 ), $this->scalar_primary() ) );
		}
		if ( $this->match_keyword( 'NULL' ) ) {
			return new WP_Markdown_Native_SQL_Scalar_Expression( 'literal', null, null );
		}
		if ( in_array( $this->current()->type(), array( WP_Markdown_Native_SQL_Token::STRING, WP_Markdown_Native_SQL_Token::INTEGER, WP_Markdown_Native_SQL_Token::DECIMAL ), true ) ) {
			$literal = $this->literal();
			return new WP_Markdown_Native_SQL_Scalar_Expression( 'literal', null, $literal->value() );
		}
		if ( $this->matches_scalar_expression() && ! $this->matches_function( 'CAST' ) ) {
			return $this->scalar_expression();
		}
		return new WP_Markdown_Native_SQL_Scalar_Expression( 'column', $this->identifier() );
	}

	/** @return array{branches:array<int,array{predicates:array<int,WP_Markdown_Native_SQL_Predicate>,value:int}>,else:int} */
	private function searched_case(): array {
		$branches = array();
		while ( $this->match_keyword( 'WHEN' ) ) {
			$predicates = $this->disjunction();
			if ( array() === $predicates ) {
				$this->unsupported( $this->current() );
			}
			$this->expect_keyword( 'THEN' );
			$branches[] = array(
				'predicates' => $predicates,
				'value'      => $this->integer( 'overflow_scalar', 'mdi-native cannot decode an overflowing integer literal.' ),
			);
		}
		if ( array() === $branches ) {
			$this->unsupported( $this->current() );
		}
		$this->expect_keyword( 'ELSE' );
		$else = $this->integer( 'overflow_scalar', 'mdi-native cannot decode an overflowing integer literal.' );
		$this->expect_keyword( 'END' );
		return array( 'branches' => $branches, 'else' => $else );
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
	private function disjunction( bool $arbitrary = false ): array {
		$groups = array( $this->conjunction( $arbitrary ) );
		$offset = $this->current()->sql_offset();
		while ( $this->match_keyword( 'OR' ) ) {
			$groups[] = $this->conjunction( $arbitrary );
		}
		if ( $arbitrary && 1 < count( $groups ) ) {
			$alternatives = array_map( static fn( array $group ): WP_Markdown_Native_SQL_Predicate => 1 === count( $group ) ? $group[0] : new WP_Markdown_Native_SQL_Predicate( $group[0]->column(), 'AND', array(), $group ), $groups );
			return array( new WP_Markdown_Native_SQL_Predicate( $alternatives[0]->column(), 'OR', array(), $alternatives ) );
		}
		return $this->coalesce_disjunction( $groups, $offset );
	}

	/** @return array<int,array<int,WP_Markdown_Native_SQL_Predicate|WP_Markdown_Native_SQL_Scalar_Predicate>> */
	private function boolean_disjunction(): array {
		$groups = $this->boolean_conjunction();
		while ( $this->match_keyword( 'OR' ) ) { $groups = array_merge( $groups, $this->boolean_conjunction() ); }
		return $groups;
	}

	/** @return array<int,array<int,WP_Markdown_Native_SQL_Predicate|WP_Markdown_Native_SQL_Scalar_Predicate>> */
	private function boolean_conjunction(): array {
		$groups = $this->boolean_predicate_term();
		while ( $this->match_keyword( 'AND' ) ) {
			$right = $this->boolean_predicate_term();
			$combined = array();
			foreach ( $groups as $left_group ) { foreach ( $right as $right_group ) { $combined[] = array_merge( $left_group, $right_group ); } }
			$groups = $combined;
		}
		return $groups;
	}

	/** @return array<int,array<int,WP_Markdown_Native_SQL_Predicate|WP_Markdown_Native_SQL_Scalar_Predicate>> */
	private function boolean_predicate_term(): array {
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN ) ) {
			$groups = $this->boolean_disjunction();
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return $groups;
		}
		if ( $this->match_keyword( 'EXISTS' ) ) {
			$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
			$query = $this->select( true );
			if ( ! $query instanceof WP_Markdown_Native_SQL_Select ) {
				$this->unsupported( $this->current() );
			}
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return array( array( new WP_Markdown_Native_SQL_Subquery_Predicate( 'EXISTS', null, $query ) ) );
		}
		if ( WP_Markdown_Native_SQL_Token::INTEGER === $this->current()->type() && WP_Markdown_Native_SQL_Token::EQUALS === ( $this->tokens[ $this->current + 1 ] ?? null )?->type() ) {
			$left = $this->integer( 'overflow_scalar', 'mdi-native cannot decode an overflowing integer literal.' );
			$this->expect_type( WP_Markdown_Native_SQL_Token::EQUALS );
			$right = $this->integer( 'overflow_scalar', 'mdi-native cannot decode an overflowing integer literal.' );
			if ( $left !== $right ) {
				$this->contradiction = true;
			}
			return array( array() );
		}
		return array( array( $this->predicate() ) );
	}

	/** Factor conjuncts shared by every OR branch before preserving the residual OR. */
	private function coalesce_boolean_groups( array $groups, int $sql_offset ): array {
		if ( 1 >= count( $groups ) ) {
			return $this->coalesce_disjunction( $groups, $sql_offset );
		}
		$common = array();
		foreach ( $groups[0] as $candidate ) {
			$key = serialize( $candidate );
			if ( array_reduce( $groups, static fn( bool $present, array $group ): bool => $present && in_array( $key, array_map( 'serialize', $group ), true ), true ) ) {
				$common[ $key ] = $candidate;
			}
		}
		if ( array() === $common ) {
			return $this->coalesce_disjunction( $groups, $sql_offset );
		}
		$remainders = array_map( static fn( array $group ): array => array_values( array_filter( $group, static fn( object $predicate ): bool => ! isset( $common[ serialize( $predicate ) ] ) ) ), $groups );
		return array_merge( array_values( $common ), $this->coalesce_disjunction( $remainders, $sql_offset ) );
	}

	/** Keep large nested WordPress boolean trees intact instead of lossy OR coalescing. */
	private function requires_boolean_plan( array $groups ): bool {
		if ( 2 >= count( $groups ) ) {
			return false;
		}
		$common = array_intersect( ...array_map( static fn( array $group ): array => array_map( 'serialize', $group ), $groups ) );
		foreach ( $groups as $group ) {
			if ( 1 < count( array_filter( $group, static fn( object $predicate ): bool => ! in_array( serialize( $predicate ), $common, true ) ) ) ) {
				return true;
			}
		}
		return false;
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
		foreach ( $groups as $group ) {
			foreach ( $group as $predicate ) {
				if ( $predicate instanceof WP_Markdown_Native_SQL_Subquery_Predicate ) {
					throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_subquery_shape', $sql_offset, 'mdi-native supports subqueries only as conjunctive predicates.' );
				}
				if ( $predicate instanceof WP_Markdown_Native_SQL_Scalar_Predicate ) {
					throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_or', $sql_offset, 'mdi-native scalar predicates are supported only as conjunctive filters.' );
				}
			}
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
				if ( null !== $conjunct->cast() ) {
					throw new WP_Markdown_Native_SQL_Parse_Error(
						'unsupported_or',
						$sql_offset,
						'mdi-native supports OR only over uncast equality or LIKE alternatives.'
					);
				}
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
	private function conjunction( bool $arbitrary = false ): array {
		$predicates = $this->predicate_term( $arbitrary );
		while ( $this->match_keyword( 'AND' ) ) {
			$predicates = array_merge( $predicates, $this->predicate_term( $arbitrary ) );
		}
		return $predicates;
	}

	/** @return array<int,WP_Markdown_Native_SQL_Predicate> */
	private function predicate_term( bool $arbitrary = false ): array {
		// A numeric parenthesized expression is a scalar left-hand side rather
		// than a boolean group, for example HAVING (6371 * ACOS(...)) < 10.
		if ( WP_Markdown_Native_SQL_Token::LEFT_PAREN === $this->current()->type()
			&& in_array( ( $this->tokens[ $this->current + 1 ] ?? null )?->type(), array( WP_Markdown_Native_SQL_Token::INTEGER, WP_Markdown_Native_SQL_Token::DECIMAL ), true ) ) {
			return array( $this->predicate() );
		}
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN ) ) {
			$predicates = $this->disjunction( $arbitrary );
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return $predicates;
		}
		if ( $this->match_keyword( 'EXISTS' ) ) {
			$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
			$query = $this->select( true );
			if ( ! $query instanceof WP_Markdown_Native_SQL_Select ) {
				$this->unsupported( $this->current() );
			}
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return array( new WP_Markdown_Native_SQL_Subquery_Predicate( 'EXISTS', null, $query ) );
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
		foreach ( array( 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'GROUP_CONCAT' ) as $function ) {
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
			$alias = $function . '(' . ( null === $column ? '*' : $column->name() ) . ')';
			if ( $this->match_keyword( 'AS' ) ) {
				$alias = $this->unqualified_identifier()->name();
			} elseif ( 'MIN' !== $function ) {
				// Existing native aggregate support requires an explicit result
				// name. The calendar derived-source query is the bounded exception.
				$this->unsupported( $this->current() );
			}
			return array(
				'function' => $function,
				'column'   => $column,
				'alias'    => $alias,
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

	private function predicate(): WP_Markdown_Native_SQL_Predicate|WP_Markdown_Native_SQL_Subquery_Predicate|WP_Markdown_Native_SQL_Scalar_Predicate {
		// Keep the established indexed forms on their schema pushdown path.
		if ( $this->matches_function( 'LOWER' ) ) {
			return $this->lower_equality_predicate();
		}
		if ( $this->matches_function( 'CAST' ) ) {
			return $this->signed_cast_predicate();
		}
		if ( $this->matches_scalar_expression() || ( in_array( $this->current()->type(), array( WP_Markdown_Native_SQL_Token::INTEGER, WP_Markdown_Native_SQL_Token::DECIMAL ), true ) && WP_Markdown_Native_SQL_Token::EQUALS !== ( $this->tokens[ $this->current + 1 ] ?? null )?->type() ) ) {
			$left = $this->scalar_value();
			foreach ( array( WP_Markdown_Native_SQL_Token::EQUALS => '=', WP_Markdown_Native_SQL_Token::NOT_EQUALS => '<>', WP_Markdown_Native_SQL_Token::LESS_EQUALS => '<=', WP_Markdown_Native_SQL_Token::GREATER_EQUALS => '>=', WP_Markdown_Native_SQL_Token::LESS_THAN => '<', WP_Markdown_Native_SQL_Token::GREATER_THAN => '>' ) as $type => $operator ) {
				if ( $this->match_type( $type ) ) { return new WP_Markdown_Native_SQL_Scalar_Predicate( $left, $operator, $this->scalar_value() ); }
			}
			$this->unsupported( $this->current() );
		}
		$column = $this->identifier();
		if ( $this->match_keyword( 'IS' ) ) {
			$operator = $this->match_keyword( 'NOT' ) ? 'IS NOT NULL' : 'IS NULL';
			$this->expect_keyword( 'NULL' );
			return new WP_Markdown_Native_SQL_Predicate( $column, $operator, array() );
		}
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::EQUALS ) ) {
			if ( $this->matches_identifier() ) {
				return new WP_Markdown_Native_SQL_Predicate( $column, '=', array(), array(), null, $this->identifier() );
			}
			return new WP_Markdown_Native_SQL_Predicate( $column, '=', array( $this->literal() ) );
		}
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::NOT_EQUALS ) ) {
			return $this->comparison_predicate( $column, '<>' );
		}
		foreach ( array(
			WP_Markdown_Native_SQL_Token::LESS_EQUALS => '<=',
			WP_Markdown_Native_SQL_Token::GREATER_EQUALS => '>=',
			WP_Markdown_Native_SQL_Token::LESS_THAN => '<',
			WP_Markdown_Native_SQL_Token::GREATER_THAN => '>',
		) as $type => $operator ) {
			if ( $this->match_type( $type ) ) {
				return $this->comparison_predicate( $column, $operator );
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
		if ( $this->match_keyword( 'REGEXP' ) ) {
			return $this->regexp_predicate( $column );
		}
		if ( $this->match_keyword( 'NOT' ) ) {
			if ( $this->match_keyword( 'LIKE' ) ) {
				return $this->like_predicate( $column, 'NOT LIKE' );
			}
			$this->expect_keyword( 'IN' );
			return $this->in_predicate( $column, 'NOT IN' );
		}
		$this->expect_keyword( 'IN' );
		return $this->in_predicate( $column, 'IN' );
	}

	private function comparison_predicate( WP_Markdown_Native_SQL_Identifier $column, string $operator ): WP_Markdown_Native_SQL_Predicate {
		if ( $this->matches_identifier() ) {
			return new WP_Markdown_Native_SQL_Predicate( $column, $operator, array(), array(), null, $this->identifier() );
		}
		return new WP_Markdown_Native_SQL_Predicate( $column, $operator, array( $this->literal() ) );
	}

	private function in_predicate( WP_Markdown_Native_SQL_Identifier $column, string $operator ): WP_Markdown_Native_SQL_Predicate|WP_Markdown_Native_SQL_Subquery_Predicate {
		$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		if ( $this->match_keyword( 'SELECT' ) ) {
			--$this->current;
			$query = $this->select( true );
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return new WP_Markdown_Native_SQL_Subquery_Predicate( $operator, $column, $query );
		}
		$values = array( $this->literal() );
		while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) ) { $values[] = $this->literal(); }
		$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		return new WP_Markdown_Native_SQL_Predicate( $column, $operator, $values );
	}

	private function signed_cast_predicate(): WP_Markdown_Native_SQL_Predicate {
		$this->unqualified_identifier();
		$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$column = $this->identifier();
		$this->expect_keyword( 'AS' );
		$this->expect_keyword( 'SIGNED' );
		$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		foreach ( array(
			WP_Markdown_Native_SQL_Token::EQUALS => '=',
			WP_Markdown_Native_SQL_Token::NOT_EQUALS => '<>',
			WP_Markdown_Native_SQL_Token::LESS_EQUALS => '<=',
			WP_Markdown_Native_SQL_Token::GREATER_EQUALS => '>=',
			WP_Markdown_Native_SQL_Token::LESS_THAN => '<',
			WP_Markdown_Native_SQL_Token::GREATER_THAN => '>',
		) as $type => $operator ) {
			if ( $this->match_type( $type ) ) {
				return new WP_Markdown_Native_SQL_Predicate( $column, $operator, array( $this->literal() ), array(), 'SIGNED' );
			}
		}
		$this->unsupported( $this->current() );
	}

	private function lower_equality_predicate(): WP_Markdown_Native_SQL_Predicate|WP_Markdown_Native_SQL_Scalar_Predicate {
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
		if ( WP_Markdown_Native_SQL_Token::STRING !== $this->current()->type() ) {
			$value = $this->identifier();
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return new WP_Markdown_Native_SQL_Scalar_Predicate(
				new WP_Markdown_Native_SQL_Scalar_Expression( 'LOWER', null, null, array( new WP_Markdown_Native_SQL_Scalar_Expression( 'column', $column ) ) ),
				'=',
				new WP_Markdown_Native_SQL_Scalar_Expression( 'LOWER', null, null, array( new WP_Markdown_Native_SQL_Scalar_Expression( 'column', $value ) ) )
			);
		}
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

	private function regexp_predicate( WP_Markdown_Native_SQL_Identifier $column ): WP_Markdown_Native_SQL_Predicate {
		$pattern = $this->literal();
		if ( ! is_string( $pattern->value() ) || 1 === preg_match( '/[^\\x00-\\x7F]/', $pattern->value() ) || 1 !== preg_match( '/^\\^?[A-Za-z0-9 _.-]+\\$?$/D', $pattern->value() ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_literal', $pattern->sql_offset(), 'mdi-native REGEXP supports ASCII literal patterns with optional anchors.' );
		}
		return new WP_Markdown_Native_SQL_Predicate( $column, 'REGEXP', array( $pattern ) );
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
