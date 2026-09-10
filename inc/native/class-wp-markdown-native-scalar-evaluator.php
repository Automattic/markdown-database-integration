<?php
/** Shared evaluator for bounded row-local scalar expressions. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Scalar_Evaluator {
	public static function supports( WP_Markdown_Native_Query_Scalar_Expression $expression ): bool {
		if ( ! in_array( $expression->kind(), array( 'literal', 'column', 'COALESCE', 'ADD', 'SUBTRACT', 'MULTIPLY', 'DIVIDE' ), true ) ) {
			return false;
		}
		foreach ( $expression->arguments() as $argument ) {
			if ( ! self::supports( $argument ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string,mixed> $row */
	public static function evaluate( WP_Markdown_Native_Query_Scalar_Expression $expression, array $row ): int|string|null {
		if ( ! self::supports( $expression ) ) {
			throw new LogicException( 'Unsupported shared scalar expression.' );
		}
		$values = array_map( static fn( WP_Markdown_Native_Query_Scalar_Expression $argument ): int|string|null => self::evaluate( $argument, $row ), $expression->arguments() );
		return match ( $expression->kind() ) {
			'literal' => $expression->literal(),
			'column' => null === $expression->source()
				? ( $row[ (string) $expression->column() ] ?? null )
				: ( $row[ $expression->source() ][ (string) $expression->column() ] ?? null ),
			'COALESCE' => self::first_non_null( $values ),
			'ADD' => in_array( null, $values, true ) ? null : self::number( self::number( $values[0] ) + self::number( $values[1] ) ),
			'SUBTRACT' => in_array( null, $values, true ) ? null : self::number( self::number( $values[0] ) - self::number( $values[1] ) ),
			'MULTIPLY' => in_array( null, $values, true ) ? null : self::number( self::number( $values[0] ) * self::number( $values[1] ) ),
			'DIVIDE' => in_array( null, $values, true ) || 0.0 === (float) self::number( $values[1] ) ? null : self::number( self::number( $values[0] ) / self::number( $values[1] ) ),
		};
	}

	/** @param array<int,int|string|null> $values */
	private static function first_non_null( array $values ): int|string|null {
		foreach ( $values as $value ) {
			if ( null !== $value ) {
				return $value;
			}
		}
		return null;
	}

	private static function number( int|float|string|null $value ): int|string|null|float {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) || ( is_string( $value ) && (string) (int) $value === $value ) ) {
			return (int) $value;
		}
		$number = (float) $value;
		return floor( $number ) === $number ? (int) $number : (string) $number;
	}
}
