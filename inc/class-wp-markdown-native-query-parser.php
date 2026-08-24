<?php
/** Deliberately bounded, table-neutral SELECT parser. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Query_Parser {
	public function parse( string $sql ): WP_Markdown_Native_Query_Plan|WP_Markdown_Query_Result {
		$identifier = '`?[A-Za-z_][A-Za-z0-9_]*`?';
		$string = "'(?:[^'\\\\]|\\\\.)*'";
		$scalar = '(?:' . $string . '|[0-9]+)';
		$predicate = '(?:'
			. '(?<equals_column>' . $identifier . ')\s*=\s*(?<equals_value>' . $scalar . ')'
			. '|(?<in_column>' . $identifier . ')\s+IN\s*\(\s*(?<in_values>' . $scalar
			. '(?:\s*,\s*' . $scalar . ')*)\s*\)'
			. ')';
		$pattern = '/\A\s*SELECT\s+(?<projection>\*|' . $identifier
			. '(?:\s*,\s*' . $identifier . ')*)\s+FROM\s+(?<table>' . $identifier . ')'
			. '(?:\s+WHERE\s+' . $predicate . ')?'
			. '(?:\s+ORDER\s+BY\s+(?<order>' . $identifier . ')\s+ASC)?'
			. '(?:\s+LIMIT\s+(?<limit>[0-9]+))?\s*\z/isD';

		if ( 1 !== preg_match( $pattern, $sql, $matches ) ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native supports bounded single-table SELECT queries only.' );
		}

		$projection = '*' === $matches['projection']
			? array( '*' )
			: array_map( fn( string $item ): string => $this->identifier( trim( $item ) ), explode( ',', $matches['projection'] ) );
		if ( count( $projection ) !== count( array_unique( $projection ) ) ) {
			return $this->failure( 'duplicate_projection', 'mdi-native projections must not repeat columns.' );
		}

		$values = array();
		$predicate_column = $matches['equals_column'] ?? '';
		$operator         = '=';
		$raw              = $matches['equals_value'] ?? '';
		if ( '' !== ( $matches['in_column'] ?? '' ) ) {
			$predicate_column = $matches['in_column'];
			$operator         = 'IN';
			$raw              = $matches['in_values'];
		}
		if ( '' !== $predicate_column ) {
			preg_match_all( '/' . $scalar . '/sD', $raw, $literals );
			foreach ( $literals[0] as $literal ) {
				if ( "'" !== $literal[0] && ! $this->fits_int( $literal ) ) {
					return $this->failure( 'overflow_scalar', 'mdi-native cannot decode an overflowing integer literal.' );
				}
				$value = $this->literal( $literal );
				if ( null === $value ) {
					return $this->failure( 'unsupported_literal', 'mdi-native cannot decode the requested scalar literal.' );
				}
				$values[] = $value;
			}
			if ( 'IN' === $operator ) {
				$values = array_values( array_unique( $values, SORT_REGULAR ) );
			}
		}

		$limit = PHP_INT_MAX;
		if ( '' !== ( $matches['limit'] ?? '' ) ) {
			if ( ! $this->fits_int( $matches['limit'] ) ) {
				return $this->failure( 'overflow_limit', 'mdi-native cannot apply the requested LIMIT.' );
			}
			$limit = (int) $matches['limit'];
		}

		return new WP_Markdown_Native_Query_Plan(
			$this->identifier( $matches['table'] ),
			$projection,
			'' === $predicate_column ? null : new WP_Markdown_Native_Query_Predicate(
				$this->identifier( $predicate_column ),
				$operator,
				$values
			),
			'' === ( $matches['order'] ?? '' ) ? null : $this->identifier( $matches['order'] ),
			$limit
		);
	}

	private function fits_int( string $value ): bool {
		$value = ltrim( $value, '0' );
		$value = '' === $value ? '0' : $value;
		$maximum = (string) PHP_INT_MAX;
		return strlen( $value ) < strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) <= 0 );
	}

	private function identifier( string $identifier ): string {
		return '`' === $identifier[0] ? substr( $identifier, 1, -1 ) : $identifier;
	}

	private function literal( string $literal ): int|string|null {
		if ( "'" !== $literal[0] ) {
			return (int) $literal;
		}
		$value = substr( $literal, 1, -1 );
		$decoded = '';
		for ( $i = 0, $length = strlen( $value ); $i < $length; ++$i ) {
			if ( '\\' !== $value[ $i ] ) {
				$decoded .= $value[ $i ];
				continue;
			}
			if ( ++$i >= $length ) {
				return null;
			}
			$escape = match ( $value[ $i ] ) { '0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a", '\\', "'", '"' => $value[ $i ], default => null };
			if ( null === $escape ) {
				return null;
			}
			$decoded .= $escape;
		}
		return $decoded;
	}

	private function failure( string $reason, string $message ): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure( array( 'code' => 'markdown_db_native_unsupported_query', 'reason' => $reason, 'message' => $message ) );
	}
}
