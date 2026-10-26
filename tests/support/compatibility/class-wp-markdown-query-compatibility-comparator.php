<?php
/** Exact structural comparison for query compatibility corpora. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Query_Compatibility_Comparator {
	/** @return array{compatible:bool,mismatches:array<int,array<string,mixed>>} */
	public static function compare( array $expected, array $actual ): array {
		$mismatches = array();
		self::compare_value( '$', $expected, $actual, $mismatches );
		return array(
			'compatible' => ! $mismatches,
			'mismatches' => $mismatches,
		);
	}

	private static function compare_value( string $path, mixed $expected, mixed $actual, array &$mismatches ): void {
		if ( gettype( $expected ) !== gettype( $actual ) ) {
			$mismatches[] = self::mismatch( $path, $expected, $actual );
			return;
		}
		if ( ! is_array( $expected ) || ! is_array( $actual ) ) {
			if ( $expected !== $actual ) {
				$mismatches[] = self::mismatch( $path, $expected, $actual );
			}
			return;
		}
		if ( array_keys( $expected ) !== array_keys( $actual ) ) {
			$mismatches[] = self::mismatch( $path . '.__keys', array_keys( $expected ), array_keys( $actual ) );
		}
		$keys = array_values( array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ), SORT_REGULAR ) );
		foreach ( $keys as $key ) {
			$child = $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $key );
			$expected_present = array_key_exists( $key, $expected );
			$actual_present = array_key_exists( $key, $actual );
			if ( ! $expected_present || ! $actual_present ) {
				$mismatches[] = self::mismatch( $child, $expected_present ? $expected[ $key ] : null, $actual_present ? $actual[ $key ] : null, $expected_present, $actual_present );
				continue;
			}
			self::compare_value( $child, $expected[ $key ], $actual[ $key ], $mismatches );
		}
	}

	private static function mismatch( string $path, mixed $expected, mixed $actual, bool $expected_present = true, bool $actual_present = true ): array {
		return array(
			'path' => $path,
			'expected' => $expected,
			'actual' => $actual,
			'expected_present' => $expected_present,
			'actual_present' => $actual_present,
		);
	}
}
