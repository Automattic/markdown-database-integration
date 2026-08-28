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
			return ( new WP_Markdown_Native_Select_AST_Parser( $this->tokenizer->tokenize( $sql ) ) )->parse();
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return $this->failure( $error->reason(), $error->getMessage(), $error->sql_offset() );
		}
	}

	public function lower( WP_Markdown_Native_SQL_Select|WP_Markdown_Native_SQL_Found_Rows $ast ): WP_Markdown_Native_Query_Plan|WP_Markdown_Native_Found_Rows_Plan|WP_Markdown_Query_Result {
		if ( $ast instanceof WP_Markdown_Native_SQL_Found_Rows ) {
			return new WP_Markdown_Native_Found_Rows_Plan();
		}
		if ( $ast->calculates_found_rows() && $ast->counts_all() ) {
			return $this->failure( 'unsupported_select_modifier', 'SQL_CALC_FOUND_ROWS requires a row projection.', $ast->table()->sql_offset() );
		}
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

		$predicates = array();
		foreach ( $ast->predicates() as $predicate ) {
			$predicates[] = $this->lower_predicate( $predicate );
		}
		$joins = array_map(
			static fn( WP_Markdown_Native_SQL_Join $join ): WP_Markdown_Native_Query_Join => new WP_Markdown_Native_Query_Join(
				$join->table()->name(),
				$join->alias()->name(),
				(string) $join->left()->qualifier(),
				$join->left()->name(),
				(string) $join->right()->qualifier(),
				$join->right()->name()
			),
			$ast->joins()
		);
		$referenced_columns = $this->referenced_columns( $ast );
		if ( array() === $joins ) {
			if ( $ast->is_distinct() ) {
				return $this->failure( 'unsupported_select_modifier', 'mdi-native supports DISTINCT on bounded JOIN projections only.', $ast->table()->sql_offset() );
			}
			$source = $ast->alias()?->name() ?? $ast->table()->name();
			foreach ( $referenced_columns as $column ) {
				if ( null !== $column->qualifier() && $source !== $column->qualifier() ) {
					return $this->failure( 'unsupported_qualifier', 'mdi-native single-table columns must use the selected table qualifier.', $column->sql_offset() );
				}
			}
		}
		if ( array() !== $joins ) {
			$base_alias = $ast->alias()?->name();
			if ( null === $base_alias || $ast->selects_all() || $ast->counts_all() ) {
				return $this->failure( 'unsupported_join_shape', 'mdi-native supports retained bounded equality JOIN queries only.', $ast->table()->sql_offset() );
			}
			foreach ( $referenced_columns as $column ) {
				if ( null === $column->qualifier() ) {
					return $this->failure( 'unsupported_join_shape', 'mdi-native JOIN columns must be qualified.', $column->sql_offset() );
				}
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
				if ( ! isset( $available[ (string) $column->qualifier() ] ) ) {
					return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested qualified column.', $column->sql_offset() );
				}
			}
		}

		$order_by = array_map(
			static fn( array $item ): array => array(
				'column'     => $item['column']->name(),
				'descending' => $item['descending'],
				'source'     => $item['column']->qualifier(),
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
			array_map( static fn( WP_Markdown_Native_SQL_Identifier $column ): ?string => $column->qualifier(), $ast->projection() ),
			$joins,
			$ast->calculates_found_rows(),
			$ast->order_descending(),
			$ast->limit_offset(),
			$ast->is_distinct(),
			$ast->order()?->qualifier(),
			$order_by,
			$ast->is_contradiction()
		);
	}

	private function lower_predicate( WP_Markdown_Native_SQL_Predicate $predicate ): WP_Markdown_Native_Query_Predicate {
		$values = array_map( static fn( WP_Markdown_Native_SQL_Literal $literal ): int|string => $literal->value(), $predicate->values() );
		if ( 'IN' === $predicate->operator() || 'NOT IN' === $predicate->operator() ) {
			$values = array_values( array_unique( $values, SORT_REGULAR ) );
		}
		return new WP_Markdown_Native_Query_Predicate(
			$predicate->column()->name(),
			$predicate->operator(),
			$values,
			$predicate->column()->qualifier(),
			array_map( $this->lower_predicate( ... ), $predicate->any() )
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
		$projection = array();
		if ( ! $select_all && $this->matches_function( 'COUNT' ) ) {
			$count_all = true;
			$this->identifier();
			$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
			$this->expect_type( WP_Markdown_Native_SQL_Token::STAR );
			$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		} elseif ( ! $select_all ) {
			$projection[] = $this->identifier();
			while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) ) {
				$projection[] = $this->identifier();
			}
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
		while ( $this->match_join() ) {
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
			if ( $wrapped ) {
				$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			}
			if ( null === $left->qualifier() || null === $right->qualifier() ) {
				throw new WP_Markdown_Native_SQL_Parse_Error(
					'unsupported_join_shape',
					$left->sql_offset(),
					'mdi-native JOIN equality columns must be qualified.'
				);
			}
			$joins[] = new WP_Markdown_Native_SQL_Join( $join_table, $join_alias, $left, $right );
		}
		if ( null === $alias && array() !== $joins ) {
			$alias = $table;
		}
		$predicates = array();
		if ( $this->match_keyword( 'WHERE' ) ) {
			$predicates = $this->disjunction();
		}
		if ( WP_Markdown_Native_SQL_Token::KEYWORD === $this->current()->type()
			&& 0 === strcasecmp( 'GROUP', (string) $this->current()->value() ) ) {
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
				if ( 1 !== count( $projection )
					|| $projection[0]->name() !== $group->name()
					|| $projection[0]->qualifier() !== $group->qualifier() ) {
					throw new WP_Markdown_Native_SQL_Parse_Error(
						'unsupported_group',
						$group->sql_offset(),
						'mdi-native supports GROUP BY only as identity grouping of the selected column.'
					);
				}
				if ( array() !== $joins ) {
					$distinct = true;
				}
			}
		}
		$orders = array();
		if ( $this->match_keyword( 'ORDER' ) ) {
			$this->expect_keyword( 'BY' );
			do {
				$column = $this->identifier();
				$descending = false;
				if ( ! $this->match_keyword( 'ASC' ) ) {
					$this->expect_keyword( 'DESC' );
					$descending = true;
				}
				$orders[] = array(
					'column'     => $column,
					'descending' => $descending,
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

		return new WP_Markdown_Native_SQL_Select( $select_all, $count_all, $projection, $table, $predicates, $orders, $limit, $alias, $joins, $calculate_found_rows, $limit_offset, $distinct, $this->contradiction );
	}

	private function match_join(): bool {
		if ( $this->match_keyword( 'JOIN' ) ) {
			return true;
		}
		if ( $this->match_keyword( 'INNER' ) ) {
			$this->expect_keyword( 'JOIN' );
			return true;
		}
		if ( ! $this->match_keyword( 'LEFT' ) ) {
			return false;
		}
		$this->match_keyword( 'OUTER' );
		$this->expect_keyword( 'JOIN' );
		return true;
	}

	private function is_on(): bool {
		return WP_Markdown_Native_SQL_Token::KEYWORD === $this->current()->type()
			&& 0 === strcasecmp( 'ON', (string) $this->current()->value() );
	}

	/**
	 * Parse a WHERE expression.
	 *
	 * AND binds tighter than OR, matching SQL. A disjunction is accepted only
	 * when every alternative is equality on the same column, which is the
	 * membership shape WordPress uses for `post_status` lists. Cross-column
	 * OR and inequality OR stay fail-closed.
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
		$column     = null;
		$qualifier  = null;
		$values     = array();
		$identifier = null;
		foreach ( $groups as $group ) {
			if ( 1 !== count( $group ) ) {
				throw new WP_Markdown_Native_SQL_Parse_Error(
					'unsupported_or',
					$sql_offset,
					'mdi-native supports OR only as same-column equality or LIKE alternatives.'
				);
			}
			$predicate = $group[0];
			if ( ! in_array( $predicate->operator(), array( '=', 'IN' ), true ) ) {
				throw new WP_Markdown_Native_SQL_Parse_Error(
					'unsupported_or',
					$sql_offset,
					'mdi-native supports OR only as same-column equality or LIKE alternatives.'
				);
			}
			$name = $predicate->column()->name();
			$qual = $predicate->column()->qualifier();
			if ( null === $column ) {
				$column     = $name;
				$qualifier  = $qual;
				$identifier = $predicate->column();
			} elseif ( $column !== $name || $qualifier !== $qual ) {
				throw new WP_Markdown_Native_SQL_Parse_Error(
					'unsupported_or',
					$sql_offset,
					'mdi-native supports OR only as same-column equality or LIKE alternatives.'
				);
			}
			$values = array_merge( $values, $predicate->values() );
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

	private function matches_function( string $function ): bool {
		$token = $this->current();
		return in_array( $token->type(), array( WP_Markdown_Native_SQL_Token::WORD, WP_Markdown_Native_SQL_Token::KEYWORD ), true )
			&& 0 === strcasecmp( $function, (string) $token->value() )
			&& WP_Markdown_Native_SQL_Token::LEFT_PAREN === ( $this->tokens[ $this->current + 1 ] ?? null )?->type();
	}

	private function predicate(): WP_Markdown_Native_SQL_Predicate {
		$column = $this->identifier();
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::EQUALS ) ) {
			return new WP_Markdown_Native_SQL_Predicate( $column, '=', array( $this->literal() ) );
		}
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::NOT_EQUALS ) ) {
			return new WP_Markdown_Native_SQL_Predicate( $column, '<>', array( $this->literal() ) );
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
