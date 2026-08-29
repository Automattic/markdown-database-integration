<?php
/** Bounded INSERT parsing for generic table snapshots. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Table_Insert_Parser {
	/** @var array<int,WP_Markdown_Native_SQL_Token> */
	private array $tokens = array();
	private int $position = 0;

	/** Parse the single row a caller requires, rejecting a multi-row statement. */
	public function parse( WP_Markdown_Query_Request $request ): WP_Markdown_Native_Table_Insert|WP_Markdown_Query_Result {
		$rows = $this->parse_rows( $request );
		if ( $rows instanceof WP_Markdown_Query_Result ) {
			return $rows;
		}
		return 1 === count( $rows )
			? $rows[0]
			: $this->failure( 'unsupported_grammar', 'mdi-native requires one INSERT row for this table.' );
	}

	/** @return array<int,WP_Markdown_Native_Table_Insert>|WP_Markdown_Query_Result */
	public function parse_rows( WP_Markdown_Query_Request $request ): array|WP_Markdown_Query_Result {
		$sql = trim( $request->sql() );
		if ( str_ends_with( $sql, ';' ) ) {
			$sql = rtrim( substr( $sql, 0, -1 ) );
		}
		if ( '' === $sql || WP_Markdown_Native_SQL_Tokenizer::contains_statement_separator( $sql ) ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native requires one INSERT statement.' );
		}

		try {
			$this->tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $sql );
			$this->position = 0;
			$replace = 0 === strcasecmp( 'REPLACE', (string) $this->current()->value() );
			$this->word( $replace ? 'REPLACE' : 'INSERT' );
			$ignore_duplicate = 0 === strcasecmp( 'IGNORE', (string) $this->current()->value() );
			if ( $ignore_duplicate ) {
				if ( $replace ) {
					throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $this->current()->sql_offset(), 'mdi-native does not combine REPLACE with IGNORE.' );
				}
				++$this->position;
			}
			if ( $replace && ! $this->is_word( 'INTO' ) ) {
				// MySQL permits the INTO keyword to be omitted for REPLACE.
			} else {
				$this->word( 'INTO' );
			}
			$table = $this->identifier();
			$columns = $this->identifier_list();
			if ( 0 === strcasecmp( 'SELECT', (string) $this->current()->value() ) ) {
				$this->word( 'SELECT' );
				$rows = array( $this->select_literals() );
				$this->word( 'FROM' );
				$this->word( 'DUAL' );
				$unless_exists = $this->dual_absent_predicates( $table );
			} else {
				$this->word( 'VALUES' );
				// One statement may carry many rows, which is how WordPress
				// writes a fresh site's options in a single INSERT.
				$rows = array();
				do {
					$rows[] = $this->literal_list();
					if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
						break;
					}
					++$this->position;
				} while ( true );
				$unless_exists = null;
			}
			$upsert_columns = null;
			if ( 0 === strcasecmp( 'ON', (string) $this->current()->value() ) ) {
				if ( $replace || $ignore_duplicate || null !== $unless_exists ) {
					throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $this->current()->sql_offset(), 'mdi-native cannot combine INSERT IGNORE or INSERT SELECT FROM DUAL with ON DUPLICATE KEY UPDATE.' );
				}
				$upsert_columns = $this->upsert_assignments( $columns );
			}
			$this->type( WP_Markdown_Native_SQL_Token::END );
			$inserts = array();
			foreach ( $rows as $values ) {
				if ( count( $columns ) !== count( $values ) ) {
					return $this->failure( 'invalid_insert_row', 'mdi-native requires one value for every INSERT column.' );
				}
				$row = array_combine( $columns, $values );
				if ( false === $row ) {
					return $this->failure( 'invalid_insert_row', 'mdi-native requires one nonempty INSERT row.' );
				}
				$inserts[] = new WP_Markdown_Native_Table_Insert( $table, $row, $unless_exists, $ignore_duplicate, $upsert_columns, $replace );
			}
			return $inserts;
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return WP_Markdown_Query_Result::failure(
				array(
					'code'       => 'markdown_db_native_unsupported_query',
					'reason'     => $error->reason(),
					'message'    => 'mdi-native supports one typed generic INSERT row.',
					'sql_offset' => $error->sql_offset(),
				)
			);
		}
	}

	/** Parse one generic UPDATE or DELETE against a persisted snapshot table. */
	public function parse_write( WP_Markdown_Query_Request $request ): WP_Markdown_Native_Table_Write|WP_Markdown_Query_Result {
		$sql = trim( $request->sql() );
		if ( str_ends_with( $sql, ';' ) ) {
			$sql = rtrim( substr( $sql, 0, -1 ) );
		}
		if ( '' === $sql || WP_Markdown_Native_SQL_Tokenizer::contains_statement_separator( $sql ) ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native requires one UPDATE or DELETE statement.' );
		}

		try {
			$this->tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $sql );
			$this->position = 0;
			$kind = 0 === strcasecmp( 'DELETE', (string) $this->current()->value() ) ? 'delete' : 'update';
			$values = array();
			if ( 'delete' === $kind ) {
				$this->word( 'DELETE' );
				$this->word( 'FROM' );
				$table = $this->identifier();
			} else {
				$this->word( 'UPDATE' );
				$table = $this->identifier();
				$this->word( 'SET' );
				$values = $this->assignments();
			}
			$predicates = $this->where_predicates();
			$this->type( WP_Markdown_Native_SQL_Token::END );
			return new WP_Markdown_Native_Table_Write( $kind, $table, $values, $predicates );
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return WP_Markdown_Query_Result::failure(
				array(
					'code'       => 'markdown_db_native_unsupported_query',
					'reason'     => $error->reason(),
					'message'    => 'mdi-native supports one bounded generic UPDATE or DELETE.',
					'sql_offset' => $error->sql_offset(),
				)
			);
		}
	}

	/** @return array<string,int|string|null> */
	private function assignments(): array {
		$values = array();
		do {
			$column = $this->identifier();
			$this->type( WP_Markdown_Native_SQL_Token::EQUALS );
			$values[ $column ] = $this->literal();
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		return $values;
	}

	/**
	 * Parse a bounded WHERE clause into conjunctive per-column predicates.
	 *
	 * Disjunctions must stay within one column so the restriction keeps the
	 * engine's existing predicate semantics instead of a second evaluator.
	 *
	 * @return array<int,WP_Markdown_Native_Table_Predicate>
	 */
	/**
	 * Parse a bounded WHERE clause with SQL precedence: AND binds tighter than OR.
	 *
	 * @return array<int,WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group>
	 */
	private function where_predicates(): array {
		if ( WP_Markdown_Native_SQL_Token::END === $this->current()->type()
			|| 0 !== strcasecmp( 'WHERE', (string) $this->current()->value() ) ) {
			return array();
		}
		$this->word( 'WHERE' );

		$predicates = array( $this->where_disjunction() );
		while ( $this->is_word( 'AND' ) ) {
			++$this->position;
			$predicates[] = $this->where_disjunction();
		}
		return array_filter( $predicates );
	}

	/** @return WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group|null */
	private function where_disjunction() {
		$alternatives = array( $this->where_factor() );
		while ( $this->is_word( 'OR' ) ) {
			++$this->position;
			$alternatives[] = $this->where_factor();
		}
		$alternatives = array_values( array_filter( $alternatives ) );
		if ( array() === $alternatives ) {
			return null;
		}
		return 1 === count( $alternatives )
			? $alternatives[0]
			: new WP_Markdown_Native_Table_Predicate_Group( $alternatives );
	}

	/** @return WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group|null */
	private function where_factor() {
		if ( WP_Markdown_Native_SQL_Token::LEFT_PAREN === $this->current()->type() ) {
			++$this->position;
			$group = $this->where_disjunction();
			$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			return $group;
		}
		$column = $this->identifier();
		$token = $this->current();
		if ( 0 === strcasecmp( 'IS', (string) $token->value() ) ) {
			++$this->position;
			if ( 0 === strcasecmp( 'NOT', (string) $this->current()->value() ) ) {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $token->sql_offset(), 'Unsupported IS NOT restriction.' );
			}
			$this->word( 'NULL' );
			return new WP_Markdown_Native_Table_Predicate( $column, array(), true );
		}
		$comparison = $this->comparison_operator();
		if ( null !== $comparison ) {
			$value = $this->literal();
			if ( null === $value ) {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $token->sql_offset(), 'Comparisons with NULL never match.' );
			}
			return new WP_Markdown_Native_Table_Predicate( $column, array( $value ), false, $comparison );
		}
		if ( 0 === strcasecmp( 'IN', (string) $token->value() ) ) {
			++$this->position;
			$values = array();
			foreach ( $this->literal_list() as $value ) {
				if ( null === $value ) {
					throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $token->sql_offset(), 'Unsupported NULL IN member.' );
				}
				$values[] = $value;
			}
			return new WP_Markdown_Native_Table_Predicate( $column, $values, false );
		}
		if ( WP_Markdown_Native_SQL_Token::EQUALS !== $token->type() ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $token->sql_offset(), 'Unsupported WHERE restriction.' );
		}
		++$this->position;
		$value = $this->literal();
		if ( null === $value ) {
			// `column = NULL` never matches in MySQL.
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $token->sql_offset(), 'Unsupported NULL equality restriction.' );
		}
		return new WP_Markdown_Native_Table_Predicate( $column, array( $value ), false );
	}

	/** @return '<'|'<='|'>'|'>='|null */
	private function comparison_operator(): ?string {
		$type = $this->current()->type();
		if ( in_array( $type, array( WP_Markdown_Native_SQL_Token::LESS_THAN, WP_Markdown_Native_SQL_Token::LESS_EQUALS, WP_Markdown_Native_SQL_Token::GREATER_THAN, WP_Markdown_Native_SQL_Token::GREATER_EQUALS ), true ) ) {
			++$this->position;
			return match ( $type ) {
				WP_Markdown_Native_SQL_Token::LESS_THAN => '<',
				WP_Markdown_Native_SQL_Token::LESS_EQUALS => '<=',
				WP_Markdown_Native_SQL_Token::GREATER_THAN => '>',
				default => '>=',
			};
		}
		return null;
	}

	private function is_word( string $expected ): bool {
		return 0 === strcasecmp( $expected, (string) $this->current()->value() );
	}

	private function literal(): int|string|null {
		$token = $this->current();
		if ( WP_Markdown_Native_SQL_Token::STRING === $token->type() || WP_Markdown_Native_SQL_Token::INTEGER === $token->type() || WP_Markdown_Native_SQL_Token::DECIMAL === $token->type() ) {
			++$this->position;
			return (string) $token->value();
		}
		if ( 0 === strcasecmp( 'NULL', (string) $token->value() ) ) {
			++$this->position;
			return null;
		}
		throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_literal', $token->sql_offset(), 'Unsupported literal.' );
	}

	/** @return array<int,string> */
	private function identifier_list(): array {
		$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$columns = array();
		do {
			$column = $this->identifier();
			if ( isset( $columns[ $column ] ) ) {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'duplicate_mutation_column', $this->current()->sql_offset(), 'Duplicate INSERT column.' );
			}
			$columns[ $column ] = $column;
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		return array_values( $columns );
	}

	/** @param array<int,string> $columns @return array<int,string> */
	private function upsert_assignments( array $columns ): array {
		$this->word( 'ON' );
		$this->word( 'DUPLICATE' );
		$this->word( 'KEY' );
		$this->word( 'UPDATE' );
		$assignments = array();
		$available = array_fill_keys( $columns, true );
		do {
			$target = $this->identifier();
			$this->type( WP_Markdown_Native_SQL_Token::EQUALS );
			$this->word( 'VALUES' );
			$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
			$source = $this->identifier();
			$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			if ( $target !== $source || isset( $assignments[ $target ] ) || ! isset( $available[ $target ] ) ) {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $this->current()->sql_offset(), 'mdi-native requires deterministic VALUES assignments for ON DUPLICATE KEY UPDATE.' );
			}
			$assignments[ $target ] = $target;
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		return array_values( $assignments );
	}

	/** @return array<int,int|string|null> */
	private function select_literals(): array {
		$values = array();
		do {
			$values[] = $this->literal();
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		return $values;
	}

	/** @return array<int,WP_Markdown_Native_Table_Predicate>|null */
	private function dual_absent_predicates( string $table ): ?array {
		$this->word( 'WHERE' );
		$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$this->word( 'SELECT' );
		if ( 0 === strcasecmp( 'NULL', (string) $this->current()->value() ) ) {
			$this->word( 'NULL' );
			$this->word( 'FROM' );
			$this->word( 'DUAL' );
			$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			$this->word( 'IS' );
			$this->word( 'NULL' );
			return null;
		}
		$this->identifier();
		$this->word( 'FROM' );
		$from = $this->identifier();
		if ( 0 !== strcasecmp( $from, $table ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $this->current()->sql_offset(), 'INSERT SELECT FROM DUAL can only absent-check the target table.' );
		}
		$predicates = $this->where_predicates();
		if ( 0 === strcasecmp( 'LIMIT', (string) $this->current()->value() ) ) {
			$this->word( 'LIMIT' );
			$this->type( WP_Markdown_Native_SQL_Token::INTEGER );
		}
		$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		$this->word( 'IS' );
		$this->word( 'NULL' );
		return $predicates;
	}

	/** @return array<int,int|string|null> */
	private function literal_list(): array {
		$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$values = array();
		do {
			$token = $this->current();
			if ( WP_Markdown_Native_SQL_Token::STRING === $token->type() ) {
				$values[] = (string) $token->value();
				++$this->position;
			} elseif ( WP_Markdown_Native_SQL_Token::INTEGER === $token->type() || WP_Markdown_Native_SQL_Token::DECIMAL === $token->type() ) {
				$values[] = (string) $token->value();
				++$this->position;
			} elseif ( 0 === strcasecmp( 'NULL', (string) $token->value() ) ) {
				$values[] = null;
				++$this->position;
			} else {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_literal', $token->sql_offset(), 'Unsupported INSERT literal.' );
			}
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		return $values;
	}

	private function identifier(): string {
		$token = $this->current();
		if ( ! in_array( $token->type(), array( WP_Markdown_Native_SQL_Token::WORD, WP_Markdown_Native_SQL_Token::KEYWORD, WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER ), true ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Expected an identifier.' );
		}
		++$this->position;
		return (string) $token->value();
	}

	private function word( string $expected ): void {
		$token = $this->current();
		if ( 0 !== strcasecmp( $expected, (string) $token->value() ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Unexpected SQL token.' );
		}
		++$this->position;
	}

	private function type( string $expected ): WP_Markdown_Native_SQL_Token {
		$token = $this->current();
		if ( $expected !== $token->type() ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Unexpected SQL token.' );
		}
		++$this->position;
		return $token;
	}

	private function current(): WP_Markdown_Native_SQL_Token {
		return $this->tokens[ $this->position ];
	}

	private function failure( string $reason, string $message ): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure(
			array(
				'code'    => 'markdown_db_native_unsupported_query',
				'reason'  => $reason,
				'message' => $message,
			)
		);
	}
}
