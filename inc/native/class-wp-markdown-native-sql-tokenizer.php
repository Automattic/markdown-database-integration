<?php
/** Pure-PHP tokenizer for the native SQL grammar. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_SQL_Parse_Error extends RuntimeException {
	public function __construct(
		private readonly string $reason,
		private readonly int $sql_offset,
		string $message
	) {
		parent::__construct( $message );
	}

	public function reason(): string {
		return $this->reason;
	}

	public function sql_offset(): int {
		return $this->sql_offset;
	}
}

final class WP_Markdown_Native_SQL_Token {
	public const WORD = 'word';
	public const KEYWORD = 'keyword';
	public const QUOTED_IDENTIFIER = 'quoted_identifier';
	public const STRING = 'string';
	public const INTEGER = 'integer';
	public const DECIMAL = 'decimal';
	public const STAR = 'star';
	public const COMMA = 'comma';
	public const LEFT_PAREN = 'left_paren';
	public const RIGHT_PAREN = 'right_paren';
	public const EQUALS = 'equals';
	public const NOT_EQUALS = 'not_equals';
	public const LESS_THAN = 'less_than';
	public const LESS_EQUALS = 'less_equals';
	public const GREATER_THAN = 'greater_than';
	public const GREATER_EQUALS = 'greater_equals';
	public const DOT = 'dot';
	public const PLUS = 'plus';
	public const END = 'end';

	public function __construct(
		private readonly string $type,
		private readonly string $lexeme,
		private readonly int|string|null $value,
		private readonly int $sql_offset
	) {}

	public function type(): string {
		return $this->type;
	}

	public function lexeme(): string {
		return $this->lexeme;
	}

	public function value(): int|string|null {
		return $this->value;
	}

	public function sql_offset(): int {
		return $this->sql_offset;
	}
}

final class WP_Markdown_Native_SQL_Tokenizer {
	/** @return array<int,WP_Markdown_Native_SQL_Token> */
	public function tokenize( string $sql ): array {
		$tokens = array();
		$length = strlen( $sql );
		for ( $offset = 0; $offset < $length; ) {
			$character = $sql[ $offset ];
			if ( str_contains( " \t\n\r\v\f", $character ) ) {
				++$offset;
				continue;
			}

			if ( '<' === $character && $offset + 1 < $length && '>' === $sql[ $offset + 1 ] ) {
				$tokens[] = new WP_Markdown_Native_SQL_Token( WP_Markdown_Native_SQL_Token::NOT_EQUALS, '<>', '<>', $offset );
				$offset  += 2;
				continue;
			}
			foreach ( array( '<=', '>=' ) as $pair ) {
				if ( $character === $pair[0] && $offset + 1 < $length && $pair[1] === $sql[ $offset + 1 ] ) {
					$type = '<=' === $pair ? WP_Markdown_Native_SQL_Token::LESS_EQUALS : WP_Markdown_Native_SQL_Token::GREATER_EQUALS;
					$tokens[] = new WP_Markdown_Native_SQL_Token( $type, $pair, $pair, $offset );
					$offset  += 2;
					continue 2;
				}
			}
			if ( '<' === $character ) {
				$tokens[] = new WP_Markdown_Native_SQL_Token( WP_Markdown_Native_SQL_Token::LESS_THAN, '<', '<', $offset++ );
				continue;
			}
			if ( '>' === $character ) {
				$tokens[] = new WP_Markdown_Native_SQL_Token( WP_Markdown_Native_SQL_Token::GREATER_THAN, '>', '>', $offset++ );
				continue;
			}
			if ( '!' === $character && $offset + 1 < $length && '=' === $sql[ $offset + 1 ] ) {
				$tokens[] = new WP_Markdown_Native_SQL_Token( WP_Markdown_Native_SQL_Token::NOT_EQUALS, '!=', '<>', $offset );
				$offset  += 2;
				continue;
			}

			$punctuation = match ( $character ) {
				'*' => WP_Markdown_Native_SQL_Token::STAR,
				',' => WP_Markdown_Native_SQL_Token::COMMA,
				'(' => WP_Markdown_Native_SQL_Token::LEFT_PAREN,
				')' => WP_Markdown_Native_SQL_Token::RIGHT_PAREN,
				'=' => WP_Markdown_Native_SQL_Token::EQUALS,
				'.' => WP_Markdown_Native_SQL_Token::DOT,
				'+' => WP_Markdown_Native_SQL_Token::PLUS,
				default => null,
			};
			if ( null !== $punctuation ) {
				$tokens[] = new WP_Markdown_Native_SQL_Token( $punctuation, $character, $character, $offset++ );
				continue;
			}

			if ( "'" === $character ) {
				$tokens[] = $this->string( $sql, $offset );
				continue;
			}
			if ( '`' === $character ) {
				$tokens[] = $this->quoted_identifier( $sql, $offset );
				continue;
			}
			if ( $character >= '0' && $character <= '9' ) {
				$start = $offset;
				while ( $offset < $length && $sql[ $offset ] >= '0' && $sql[ $offset ] <= '9' ) {
					++$offset;
				}
				$type = WP_Markdown_Native_SQL_Token::INTEGER;
				if ( $offset + 1 < $length && '.' === $sql[ $offset ] && $sql[ $offset + 1 ] >= '0' && $sql[ $offset + 1 ] <= '9' ) {
					++$offset;
					while ( $offset < $length && $sql[ $offset ] >= '0' && $sql[ $offset ] <= '9' ) {
						++$offset;
					}
					$type = WP_Markdown_Native_SQL_Token::DECIMAL;
				}
				$lexeme = substr( $sql, $start, $offset - $start );
				$tokens[] = new WP_Markdown_Native_SQL_Token( $type, $lexeme, $lexeme, $start );
				continue;
			}
			if ( $this->is_identifier_start( $character ) ) {
				$start = $offset++;
				while ( $offset < $length && $this->is_identifier_part( $sql[ $offset ] ) ) {
					++$offset;
				}
				$lexeme = substr( $sql, $start, $offset - $start );
				$type = in_array( strtoupper( $lexeme ), array( 'SELECT', 'DISTINCT', 'SQL_CALC_FOUND_ROWS', 'FROM', 'AS', 'INNER', 'LEFT', 'OUTER', 'JOIN', 'ON', 'WHERE', 'IN', 'LIKE', 'NOT', 'AND', 'OR', 'IS', 'NULL', 'BETWEEN', 'GROUP', 'ORDER', 'BY', 'ASC', 'DESC', 'LIMIT', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END' ), true )
					? WP_Markdown_Native_SQL_Token::KEYWORD
					: WP_Markdown_Native_SQL_Token::WORD;
				$tokens[] = new WP_Markdown_Native_SQL_Token( $type, $lexeme, $lexeme, $start );
				continue;
			}

			throw new WP_Markdown_Native_SQL_Parse_Error(
				'unsupported_grammar',
				$offset,
				'mdi-native supports bounded single-table SELECT queries only.'
			);
		}

		$tokens[] = new WP_Markdown_Native_SQL_Token( WP_Markdown_Native_SQL_Token::END, '', null, $length );
		return $tokens;
	}

	private function string( string $sql, int &$offset ): WP_Markdown_Native_SQL_Token {
		$start   = $offset++;
		$length  = strlen( $sql );
		$decoded = '';
		while ( $offset < $length ) {
			$character = $sql[ $offset++ ];
			if ( "'" === $character ) {
				return new WP_Markdown_Native_SQL_Token(
					WP_Markdown_Native_SQL_Token::STRING,
					substr( $sql, $start, $offset - $start ),
					$decoded,
					$start
				);
			}
			if ( '\\' !== $character ) {
				$decoded .= $character;
				continue;
			}
			if ( $offset >= $length ) {
				break;
			}
			$escape_offset = $offset - 1;
			$escaped       = $sql[ $offset++ ];
			$escape        = match ( $escaped ) {
				'0' => "\0",
				'b' => "\x08",
				'n' => "\n",
				'r' => "\r",
				't' => "\t",
				'Z' => "\x1a",
				'\\', "'", '"' => $escaped,
				'%', '_' => '\\' . $escaped,
				default => null,
			};
			if ( null === $escape ) {
				throw new WP_Markdown_Native_SQL_Parse_Error(
					'unsupported_literal',
					$escape_offset,
					'mdi-native cannot decode the requested scalar literal.'
				);
			}
			$decoded .= $escape;
		}

		throw new WP_Markdown_Native_SQL_Parse_Error(
			'unsupported_grammar',
			$start,
			'mdi-native supports bounded single-table SELECT queries only.'
		);
	}

	/**
	 * Report whether a statement separator appears outside a quoted literal.
	 *
	 * Serialized values carry semicolons, so a raw substring test rejects an
	 * ordinary single statement. Quoting is honoured here so only a real
	 * separator between statements is refused.
	 */
	public static function contains_statement_separator( string $sql ): bool {
		$length = strlen( $sql );
		$quote  = null;
		for ( $offset = 0; $offset < $length; $offset++ ) {
			$character = $sql[ $offset ];
			if ( null !== $quote ) {
				if ( '\\' === $character && '`' !== $quote ) {
					++$offset;
					continue;
				}
				if ( $character === $quote ) {
					// A doubled quote escapes itself and stays inside the literal.
					if ( $offset + 1 < $length && $sql[ $offset + 1 ] === $quote ) {
						++$offset;
						continue;
					}
					$quote = null;
				}
				continue;
			}
			if ( "'" === $character || '"' === $character || '`' === $character ) {
				$quote = $character;
				continue;
			}
			if ( ';' === $character ) {
				return true;
			}
		}
		return false;
	}

	private function quoted_identifier( string $sql, int &$offset ): WP_Markdown_Native_SQL_Token {
		$start  = $offset++;
		$length = strlen( $sql );
		if ( $offset >= $length || ! $this->is_identifier_start( $sql[ $offset ] ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $start, 'mdi-native supports bounded single-table SELECT queries only.' );
		}
		$identifier_start = $offset++;
		while ( $offset < $length && $this->is_identifier_part( $sql[ $offset ] ) ) {
			++$offset;
		}
		if ( $offset >= $length || '`' !== $sql[ $offset ] ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $start, 'mdi-native supports bounded single-table SELECT queries only.' );
		}
		$identifier = substr( $sql, $identifier_start, $offset - $identifier_start );
		++$offset;
		return new WP_Markdown_Native_SQL_Token(
			WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER,
			substr( $sql, $start, $offset - $start ),
			$identifier,
			$start
		);
	}

	private function is_identifier_start( string $character ): bool {
		return '_' === $character || ( $character >= 'A' && $character <= 'Z' ) || ( $character >= 'a' && $character <= 'z' );
	}

	private function is_identifier_part( string $character ): bool {
		return $this->is_identifier_start( $character ) || ( $character >= '0' && $character <= '9' );
	}
}
