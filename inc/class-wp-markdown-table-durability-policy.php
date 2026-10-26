<?php
/** Decide whether a database table persists to canonical state. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Table_Durability_Policy {
	public const CANONICAL = 'canonical';
	public const EPHEMERAL = 'ephemeral';

	public static function persists( string $table, string $prefix = 'wp_' ): bool {
		return self::EPHEMERAL !== self::resolve( $table, $prefix );
	}

	private static function resolve( string $table, string $prefix ): string {
		$table_suffix = str_starts_with( $table, $prefix ) ? substr( $table, strlen( $prefix ) ) : $table;
		$durability   = self::CANONICAL;
		if ( defined( 'MARKDOWN_DB_TABLE_DURABILITY_POLICY' ) && is_array( MARKDOWN_DB_TABLE_DURABILITY_POLICY ) && array_key_exists( $table_suffix, MARKDOWN_DB_TABLE_DURABILITY_POLICY ) ) {
			$durability = self::durability_of( MARKDOWN_DB_TABLE_DURABILITY_POLICY[ $table_suffix ], $durability );
		}

		$ephemeral = array();
		if ( defined( 'MARKDOWN_DB_EPHEMERAL_TABLES' ) ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) MARKDOWN_DB_EPHEMERAL_TABLES ) ) ) as $suffix ) {
				$ephemeral[] = $prefix . $suffix;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered_ephemeral = apply_filters( 'markdown_db_ephemeral_tables', $ephemeral );
			$ephemeral          = is_array( $filtered_ephemeral ) ? $filtered_ephemeral : $ephemeral;
		}
		if ( in_array( $table, $ephemeral, true ) ) {
			$durability = self::EPHEMERAL;
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the durability policy for one database table.
			 *
			 * `durability` accepts `canonical` or `ephemeral`. Ephemeral tables are
			 * excluded from canonical state and native table registration.
			 *
			 * @param array  $policy       Policy with a `durability` key.
			 * @param string $table_suffix Table name without the WordPress prefix.
			 * @param string $table        Full database table name.
			 */
			$durability = self::durability_of( apply_filters( 'markdown_db_table_durability_policy', array( 'durability' => $durability ), $table_suffix, $table ), $durability );
		}

		return $durability;
	}

	private static function durability_of( mixed $policy, string $current ): string {
		$durability = is_array( $policy ) ? ( $policy['durability'] ?? $current ) : $policy;
		return in_array( $durability, array( self::CANONICAL, self::EPHEMERAL ), true ) ? $durability : $current;
	}
}
