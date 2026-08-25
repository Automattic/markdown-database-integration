<?php
/** Typed parser and query-plan lowering for bounded native SELECT queries. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Query_Parser {
	public function __construct(
		private WP_Markdown_Native_SQL_Tokenizer $tokenizer = new WP_Markdown_Native_SQL_Tokenizer()
	) {}

	public function parse( string $sql ): WP_Markdown_Native_Query_Plan|WP_Markdown_Query_Result {
		$ast = $this->parse_ast( $sql );
		return $ast instanceof WP_Markdown_Query_Result ? $ast : $this->lower( $ast );
	}

	public function parse_ast( string $sql ): WP_Markdown_Native_SQL_Select|WP_Markdown_Query_Result {
		try {
			return ( new WP_Markdown_Native_Select_AST_Parser( $this->tokenizer->tokenize( $sql ) ) )->parse();
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return $this->failure( $error->reason(), $error->getMessage(), $error->sql_offset() );
		}
	}

	public function lower( WP_Markdown_Native_SQL_Select $ast ): WP_Markdown_Native_Query_Plan|WP_Markdown_Query_Result {
		$projection = $ast->selects_all()
			? array( '*' )
			: array_map( static fn( WP_Markdown_Native_SQL_Identifier $column ): string => $column->name(), $ast->projection() );
		$seen = array();
		foreach ( $ast->projection() as $column ) {
			if ( isset( $seen[ $column->name() ] ) ) {
				return $this->failure(
					'duplicate_projection',
					'mdi-native projections must not repeat columns.',
					$column->sql_offset()
				);
			}
			$seen[ $column->name() ] = true;
		}

		$predicate = $ast->predicate();
		$values    = null === $predicate
			? array()
			: array_map( static fn( WP_Markdown_Native_SQL_Literal $literal ): int|string => $literal->value(), $predicate->values() );
		if ( null !== $predicate && 'IN' === $predicate->operator() ) {
			$values = array_values( array_unique( $values, SORT_REGULAR ) );
		}

		return new WP_Markdown_Native_Query_Plan(
			$ast->table()->name(),
			$projection,
			null === $predicate ? null : new WP_Markdown_Native_Query_Predicate(
				$predicate->column()->name(),
				$predicate->operator(),
				$values
			),
			$ast->order()?->name(),
			$ast->limit() ?? PHP_INT_MAX
		);
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

	/** @param array<int,WP_Markdown_Native_SQL_Token> $tokens */
	public function __construct( private readonly array $tokens ) {}

	public function parse(): WP_Markdown_Native_SQL_Select {
		$this->expect_keyword( 'SELECT' );
		$select_all = $this->match_type( WP_Markdown_Native_SQL_Token::STAR );
		$projection = array();
		if ( ! $select_all ) {
			$projection[] = $this->identifier();
			while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) ) {
				$projection[] = $this->identifier();
			}
		}

		$this->expect_keyword( 'FROM' );
		$table     = $this->identifier();
		$predicate = $this->match_keyword( 'WHERE' ) ? $this->predicate() : null;
		$order     = null;
		if ( $this->match_keyword( 'ORDER' ) ) {
			$this->expect_keyword( 'BY' );
			$order = $this->identifier();
			$this->expect_keyword( 'ASC' );
		}
		$limit = null;
		if ( $this->match_keyword( 'LIMIT' ) ) {
			$limit = $this->integer( 'overflow_limit', 'mdi-native cannot apply the requested LIMIT.' );
		}
		$this->expect_type( WP_Markdown_Native_SQL_Token::END );

		return new WP_Markdown_Native_SQL_Select( $select_all, $projection, $table, $predicate, $order, $limit );
	}

	private function predicate(): WP_Markdown_Native_SQL_Predicate {
		$column = $this->identifier();
		if ( $this->match_type( WP_Markdown_Native_SQL_Token::EQUALS ) ) {
			return new WP_Markdown_Native_SQL_Predicate( $column, '=', array( $this->literal() ) );
		}
		$this->expect_keyword( 'IN' );
		$this->expect_type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$values = array( $this->literal() );
		while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) ) {
			$values[] = $this->literal();
		}
		$this->expect_type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		return new WP_Markdown_Native_SQL_Predicate( $column, 'IN', $values );
	}

	private function identifier(): WP_Markdown_Native_SQL_Identifier {
		$token = $this->current();
		if ( ! in_array( $token->type(), array( WP_Markdown_Native_SQL_Token::WORD, WP_Markdown_Native_SQL_Token::KEYWORD, WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER ), true ) ) {
			$this->unsupported( $token );
		}
		++$this->current;
		return new WP_Markdown_Native_SQL_Identifier( (string) $token->value(), $token->sql_offset() );
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
