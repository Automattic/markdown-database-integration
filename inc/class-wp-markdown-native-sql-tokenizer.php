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
	public const STAR = 'star';
	public const COMMA = 'comma';
	public const LEFT_PAREN = 'left_paren';
	public const RIGHT_PAREN = 'right_paren';
	public const EQUALS = 'equals';
	public const DOT = 'dot';
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

			$punctuation = match ( $character ) {
				'*' => WP_Markdown_Native_SQL_Token::STAR,
				',' => WP_Markdown_Native_SQL_Token::COMMA,
				'(' => WP_Markdown_Native_SQL_Token::LEFT_PAREN,
				')' => WP_Markdown_Native_SQL_Token::RIGHT_PAREN,
				'=' => WP_Markdown_Native_SQL_Token::EQUALS,
				'.' => WP_Markdown_Native_SQL_Token::DOT,
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
				$lexeme = substr( $sql, $start, $offset - $start );
				$tokens[] = new WP_Markdown_Native_SQL_Token( WP_Markdown_Native_SQL_Token::INTEGER, $lexeme, $lexeme, $start );
				continue;
			}
			if ( $this->is_identifier_start( $character ) ) {
				$start = $offset++;
				while ( $offset < $length && $this->is_identifier_part( $sql[ $offset ] ) ) {
					++$offset;
				}
				$lexeme = substr( $sql, $start, $offset - $start );
				$type = in_array( strtoupper( $lexeme ), array( 'SELECT', 'DISTINCT', 'SQL_CALC_FOUND_ROWS', 'FROM', 'AS', 'INNER', 'JOIN', 'ON', 'WHERE', 'IN', 'AND', 'ORDER', 'BY', 'ASC', 'DESC', 'LIMIT' ), true )
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
