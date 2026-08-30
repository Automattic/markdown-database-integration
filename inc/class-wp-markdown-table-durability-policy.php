<?php
/** Resolve one backend-neutral durability policy for a database table. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Table_Durability_Policy {
	public const CANONICAL       = 'canonical';
	public const RECONSTRUCTIBLE = 'reconstructible';
	public const EPHEMERAL       = 'ephemeral';

	/**
	 * @return array{durability:string,projection:array<string,mixed>}
	 */
	public static function resolve( string $table, string $prefix = 'wp_' ): array {
		$table_suffix = str_starts_with( $table, $prefix ) ? substr( $table, strlen( $prefix ) ) : $table;
		$policy       = array(
			'durability' => self::CANONICAL,
			'projection' => array(),
		);
		if ( defined( 'MARKDOWN_DB_TABLE_DURABILITY_POLICY' ) && is_array( MARKDOWN_DB_TABLE_DURABILITY_POLICY ) && array_key_exists( $table_suffix, MARKDOWN_DB_TABLE_DURABILITY_POLICY ) ) {
			$policy = self::merge_table_policy( $policy, MARKDOWN_DB_TABLE_DURABILITY_POLICY[ $table_suffix ] );
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
			$policy['durability'] = self::EPHEMERAL;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$legacy = apply_filters( 'markdown_db_table_persistence_policy', array() );
			if ( is_array( $legacy ) && array_key_exists( $table_suffix, $legacy ) ) {
				$table_policy = $legacy[ $table_suffix ];
				if ( false === $table_policy ) {
					$policy['durability'] = self::EPHEMERAL;
				} elseif ( is_array( $table_policy ) ) {
					$policy['projection'] = $table_policy;
				}
			}

			/**
			 * Filters the complete durability policy for one database table.
			 *
			 * `durability` accepts `canonical`, `reconstructible`, or `ephemeral`.
			 * `projection` is passed to backend-neutral table snapshot readers and
			 * may contain generic options such as `query`, `limit`, or `partition_by`.
			 *
			 * @param array  $policy       Normalized durability policy.
			 * @param string $table_suffix Table name without the WordPress prefix.
			 * @param string $table        Full database table name.
			 */
			$policy = self::merge_table_policy( $policy, apply_filters( 'markdown_db_table_durability_policy', $policy, $table_suffix, $table ) );
		}

		if ( ! in_array( $policy['durability'] ?? null, array( self::CANONICAL, self::RECONSTRUCTIBLE, self::EPHEMERAL ), true ) ) {
			$policy['durability'] = self::CANONICAL;
		}
		if ( ! is_array( $policy['projection'] ?? null ) ) {
			$policy['projection'] = array();
		}

		return array(
			'durability' => $policy['durability'],
			'projection' => $policy['projection'],
		);
	}

	public static function persists( string $table, string $prefix = 'wp_' ): bool {
		return self::EPHEMERAL !== self::resolve( $table, $prefix )['durability'];
	}

	/** @param array{durability:string,projection:array<string,mixed>} $policy */
	private static function merge_table_policy( array $policy, mixed $table_policy ): array {
		if ( is_string( $table_policy ) ) {
			$policy['durability'] = $table_policy;
			return $policy;
		}
		return is_array( $table_policy ) ? array_merge( $policy, $table_policy ) : $policy;
	}
}
