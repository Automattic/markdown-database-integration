<?php
/** Opt-in request-local operation measurements; durations are inclusive. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Operation_Profile {
	private static bool $enabled = false;
	private static array $metrics = array();
	private static array $queries = array();
	private static array $exact_queries = array();

	public static function start(): void {
		self::$metrics = array();
		self::$queries = array();
		self::$exact_queries = array();
		self::$enabled = true;
	}

	public static function stop(): array {
		self::$enabled = false;
		return self::$metrics;
	}

	public static function begin(): ?int {
		return self::$enabled ? hrtime( true ) : null;
	}

	public static function end( string $operation, ?int $start ): void {
		if ( null === $start || ! self::$enabled ) {
			return;
		}
		$key = $operation . '_ms';
		self::$metrics[ $key ] = ( self::$metrics[ $key ] ?? 0 ) + ( hrtime( true ) - $start ) / 1e6;
		self::count( $operation . '_calls' );
	}

	public static function count( string $name ): void {
		if ( self::$enabled ) {
			self::$metrics[ $name ] = ( self::$metrics[ $name ] ?? 0 ) + 1;
		}
	}

	/** Store bounded shape aggregates; neither SQL literals nor exact-query hashes are exported. */
	public static function query( string $shape, string $hash, float $milliseconds ): void {
		if ( ! self::$enabled ) {
			return;
		}
		if ( ! isset( self::$queries[ $shape ] ) && count( self::$queries ) >= 64 ) {
			self::count( 'query_shape_overflow' );
			return;
		}
		self::$queries[ $shape ] ??= array( 'calls' => 0, 'ms' => 0.0, 'exact_repeats' => 0, 'distinct_tracked' => 0 );
		$entry = &self::$queries[ $shape ];
		++$entry['calls'];
		$entry['ms'] += $milliseconds;
		if ( isset( self::$exact_queries[ $hash ] ) ) {
			++$entry['exact_repeats'];
		} elseif ( count( self::$exact_queries ) < 4096 ) {
			self::$exact_queries[ $hash ] = true;
			++$entry['distinct_tracked'];
		} else {
			self::count( 'exact_query_tracking_overflow' );
		}
	}

	public static function query_shapes(): array {
		$result = self::$queries;
		uasort( $result, static fn( array $a, array $b ): int => $b['ms'] <=> $a['ms'] );
		return $result;
	}
}
