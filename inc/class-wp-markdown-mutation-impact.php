<?php
/** Database-neutral mutation impact extraction. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Mutation_Impact {
	/** @return array<int,array<string,mixed>> */
	public static function for_query( string $query, array $operation, string $table, int $insert_id = 0, ?callable $term_objects = null, bool $conservative = true ): array {
		$op        = (string) ( $operation['op'] ?? '' );
		$ambiguous = 'REPLACE' === $op || (bool) preg_match( '/\bON\s+(?:DUPLICATE\s+KEY\s+UPDATE|CONFLICT\b.*\bDO\s+UPDATE)\b/is', $query );
		$multi_row = in_array( $op, array( 'INSERT', 'REPLACE' ), true ) && (bool) preg_match( '/\)\s*,\s*\(/', $query );
		$where     = preg_match( '/\bWHERE\b(.*?)(?:\bORDER\s+BY\b|\bLIMIT\b|$)/is', $query, $match ) ? $match[1] : '';
		$ambiguous = $ambiguous || ( '' !== $where && (bool) preg_match( '/\bOR\b/i', $where ) );
		$repeated_identity = ! $ambiguous && ! $multi_row && self::has_repeated_predicate( $op, $where );
		$identities = ! $ambiguous && ! $multi_row && ! $repeated_identity ? self::identities( $query, $op, $where ) : array();

		if ( $conservative ) {
			return self::conservative_intents( $query, $table, $operation, $identities, $insert_id, $ambiguous || $multi_row || $repeated_identity );
		}

		// Callers retain their pre-existing intent shape and fallback policy.
		return self::legacy_intents( $query, $operation, $table, $insert_id, $term_objects, $identities, $ambiguous || $multi_row || $repeated_identity );
	}

	private static function has_repeated_predicate( string $operation, string $where ): bool {
		if ( in_array( $operation, array( 'INSERT', 'REPLACE' ), true ) || '' === $where || ! preg_match_all( '/(?:^|\bAND\b)\s*`?([A-Za-z_][A-Za-z0-9_]*)`?\s*(?:=|IN\s*\()/i', $where, $matches ) ) {
			return false;
		}
		$columns = array_count_values( array_map( 'strtolower', $matches[1] ) );
		return max( $columns ) > 1;
	}

	/** @return array<int,array{column:string,values:array<int,string>}> */
	private static function identities( string $query, string $operation, string $where ): array {
		$source = in_array( $operation, array( 'INSERT', 'REPLACE' ), true ) ? self::insert_values( $query ) : self::predicate_values( $where );
		$out    = array();
		foreach ( $source as $column => $values ) {
			if ( $values ) {
				$out[] = array( 'column' => $column, 'values' => array_values( $values ) );
			}
		}
		return $out;
	}

	/** @return array<string,array<string,string>> */
	private static function predicate_values( string $where ): array {
		$values = array();
		if ( '' === $where || ! preg_match_all( '/(?:^|\bAND\b)\s*`?([A-Za-z_][A-Za-z0-9_]*)`?\s*(?:=\s*(?:\'([^\']*)\'|"([^"]*)"|(-?\d+(?:\.\d+)?))|IN\s*\(([^)]*)\))/i', $where, $matches, PREG_SET_ORDER ) ) {
			return $values;
		}
		foreach ( $matches as $match ) {
			$column = strtolower( $match[1] );
			$items  = isset( $match[5] ) && '' !== $match[5] ? str_getcsv( $match[5], ',', "'", '\\' ) : array( $match[2] ?: ( $match[3] ?: $match[4] ) );
			foreach ( $items as $item ) {
				$item = trim( (string) $item, " \t\n\r\0\x0B'\"`" );
				if ( '' !== $item && preg_match( '/^(?:-?\d+(?:\.\d+)?|[^\s(),]+)$/', $item ) ) {
					$values[ $column ][ $item ] = $item;
				}
			}
		}
		return $values;
	}

	/** @return array<string,array<string,string>> */
	private static function insert_values( string $query ): array {
		if ( ! preg_match( '/\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/is', $query, $match ) ) {
			return array();
		}
		$columns = str_getcsv( $match[1], ',', '`', '\\' );
		$items   = str_getcsv( $match[2], ',', "'", '\\' );
		$values  = array();
		foreach ( $columns as $index => $column ) {
			$value = isset( $items[ $index ] ) ? stripslashes( trim( $items[ $index ], " \t\n\r\0\x0B'\"" ) ) : '';
			$column = strtolower( trim( $column, " \t\n\r\0\x0B`\"'" ) );
			if ( '' !== $column && '' !== $value && ! in_array( strtoupper( $value ), array( 'NULL', 'DEFAULT' ), true ) ) {
				$values[ $column ][ $value ] = $value;
			}
		}
		return $values;
	}

	/** @return array<int,array<string,mixed>> */
	private static function conservative_intents( string $query, string $table, array $operation, array $identities, int $insert_id, bool $fallback ): array {
		if ( ! $fallback && in_array( $operation['op'], array( 'INSERT', 'REPLACE' ), true ) && $insert_id > 0 ) {
			$has_stable_insert_identity = false;
			foreach ( $identities as $identity ) { if ( 'option_name' === $identity['column'] || 'id' === $identity['column'] || str_ends_with( $identity['column'], '_id' ) ) { $has_stable_insert_identity = true; break; } }
			if ( ! $has_stable_insert_identity ) { $identities[] = array( 'column' => 'id', 'values' => array( (string) $insert_id ) ); }
		}
		if ( $fallback || ! $identities ) {
			return array( self::intent( $query, $table, $operation, '*', array(), true ) );
		}
		$identity = self::preferred_identity( $table, $identities );
		$out      = array();
		foreach ( $identity['values'] as $value ) {
			$out[] = self::intent( $query, $table, $operation, $value, array( $identity['column'] => array( $value ) ), false, $identity['column'] );
		}
		return $out;
	}

	/** @return array{column:string,values:array<int,string>} */
	private static function preferred_identity( string $table, array $identities ): array {
		$suffix = preg_replace( '/^.*_/', '', $table );
		$preferred = array( 'posts' => 'id', 'comments' => 'comment_id', 'users' => 'id', 'options' => 'option_name' )[ $suffix ] ?? '';
		foreach ( $identities as $identity ) {
			if ( $preferred === $identity['column'] || str_ends_with( $identity['column'], '_id' ) || 'id' === $identity['column'] ) {
				return $identity;
			}
		}
		return $identities[0];
	}

	/** @return array<string,mixed> */
	private static function intent( string $query, string $table, array $operation, string $value, array $by_column, bool $fallback, string $column = '' ): array {
		$scope = array( 'resource_ids_by_column' => $by_column, 'assigned_columns' => self::assigned_columns( $query, $operation, $fallback ) );
		if ( $fallback ) {
			$scope['conservative'] = true;
		} else {
			$scope['identity'] = array( 'column' => $column, 'values' => array( $value ) );
		}
		return array( 'stable_id' => $table . ':' . ( $fallback ? '*' : $column . ':' . $value ), 'kind' => 'DDL' === ( $operation['type'] ?? '' ) ? 'schema' : 'table', 'operation' => $operation['op'], 'table' => $table, 'resource_ids' => array( $value ), 'scope' => $scope );
	}

	private static function assigned_columns( string $query, array $operation, bool $fallback ): array {
		if ( $fallback || 'UPDATE' !== ( $operation['op'] ?? '' ) || ! preg_match( '/\bSET\b(.*?)(?:\bWHERE\b|\bORDER\s+BY\b|\bLIMIT\b|$)/is', $query, $set ) || ! preg_match_all( '/(?:^|,)\s*`?([A-Za-z_][A-Za-z0-9_]*)`?\s*=/', $set[1], $columns ) ) { return array(); }
		return array_values( array_unique( array_map( 'strtolower', $columns[1] ) ) );
	}

	/** @return array<int,array<string,mixed>> */
	private static function legacy_intents( string $query, array $operation, string $table, int $insert_id, ?callable $term_objects, array $identities, bool $fallback ): array {
		$ids = array();
		if ( ! $fallback && preg_match_all( '/\b(?:ID|post_id|object_id|option_name)\b\s*(?:=|IN\s*\()\s*(?:[\'\"]?([^\s,\)\'\"]+)|([^\)]*))/i', $query, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) { foreach ( explode( ',', $match[1] ?: $match[2] ) as $id ) { $id = trim( $id, " \t\n\r\0\x0B'\""); if ( '' !== $id ) { $ids[ $id ] = true; } } }
		}
		$suffix = ''; foreach ( array( 'term_relationships', 'postmeta', 'options', 'posts', 'comments', 'users' ) as $candidate ) { if ( str_ends_with( $table, $candidate ) ) { $suffix = $candidate; break; } }
		// Relationship INSERTs historically invalidate the inserted object, not the rowid.
		if ( empty( $ids ) && 'term_relationships' === $suffix && in_array( $operation['op'], array( 'INSERT', 'REPLACE' ), true ) ) { foreach ( $identities as $identity ) { if ( 'object_id' === $identity['column'] ) { foreach ( $identity['values'] as $id ) { $ids[ $id ] = true; } } } }
		if ( empty( $ids ) && 'term_relationships' === $suffix && $term_objects && preg_match( '/\bterm_taxonomy_id\b\s*=\s*(\d+)/i', $query, $match ) ) { foreach ( $term_objects( (int) $match[1] ) as $id ) { $ids[ (string) $id ] = true; } }
		if ( empty( $ids ) && 'options' === $suffix ) { foreach ( $identities as $identity ) { if ( 'option_name' === $identity['column'] ) { foreach ( $identity['values'] as $value ) { $ids[ $value ] = true; } } } }
		if ( empty( $ids ) && ! $fallback && in_array( $operation['op'], array( 'INSERT', 'REPLACE' ), true ) && $insert_id > 0 ) { $ids[ (string) $insert_id ] = true; }
		if ( empty( $ids ) ) { $ids['*'] = true; }
		$assigned = array(); if ( 'UPDATE' === $operation['op'] && preg_match( '/\bSET\b(.*?)(?:\bWHERE\b|\bORDER\s+BY\b|\bLIMIT\b|$)/is', $query, $set ) && preg_match_all( '/(?:^|,)\s*`?([A-Za-z_][A-Za-z0-9_]*)`?\s*=/',$set[1],$columns ) ) { $assigned = array_values( array_unique( array_map( 'strtolower', $columns[1] ) ) ); }
		$by_column = $fallback ? array() : array_column( $identities, 'values', 'column' );
		$scope = array( 'resource_ids_by_column' => $by_column, 'assigned_columns' => $assigned );
		return array_map( static fn( string $id ): array => array( 'stable_id' => $table . ':' . $id, 'kind' => 'DDL' === $operation['type'] ? 'schema' : 'table', 'operation' => $operation['op'], 'table' => $table, 'resource_ids' => array( $id ), 'scope' => $scope ), array_keys( $ids ) );
	}
}
