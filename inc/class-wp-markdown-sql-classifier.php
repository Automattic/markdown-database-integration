<?php
/** Backend-neutral SQL mutation and transaction-control classification. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_SQL_Classifier {

	/** @return array{type:string,op:string,table?:string,tables?:string[]}|null */
	public static function mutation( string $query ): ?array {
		$drop_query = preg_replace( '/(?:\s*(?:--[^\r\n]*|#[^\r\n]*|\/\*.*?\*\/)\s*)+$/s', '', $query );
		if ( preg_match( '/^\s*(INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`?(\w+)`?/i', $query, $match ) ) {
			return array( 'type' => 'DML', 'op' => str_contains( strtoupper( $match[1] ), 'REPLACE' ) ? 'REPLACE' : 'INSERT', 'table' => $match[2] );
		}
		if ( preg_match( '/^\s*UPDATE\s+`?(\w+)`?/i', $query, $match ) ) {
			return array( 'type' => 'DML', 'op' => 'UPDATE', 'table' => $match[1] );
		}
		if ( preg_match( '/^\s*DELETE\s+FROM\s+`?(\w+)`?/i', $query, $match ) ) {
			return array( 'type' => 'DML', 'op' => 'DELETE', 'table' => $match[1] );
		}
		if ( preg_match( '/^\s*DELETE\s+(.+?)\s+FROM\s+(.+?)\s+WHERE\b/is', $query, $match ) ) {
			$aliases = array();
			foreach ( preg_split( '/\s*,\s*/', trim( $match[2] ) ) ?: array() as $reference ) {
				if ( ! preg_match( '/^`?(\w+)`?(?:\s+(?:AS\s+)?`?(\w+)`?)?$/i', $reference, $reference_match ) ) {
					return null;
				}
				$aliases[ $reference_match[2] ?? $reference_match[1] ] = $reference_match[1];
			}
			$tables = array();
			foreach ( preg_split( '/\s*,\s*/', trim( $match[1] ) ) ?: array() as $target ) {
				$target = trim( $target, " `" );
				if ( ! isset( $aliases[ $target ] ) ) {
					return null;
				}
				$tables[ $aliases[ $target ] ] = true;
			}
			if ( $tables ) {
				return array( 'type' => 'DML', 'op' => 'DELETE', 'tables' => array_keys( $tables ) );
			}
		}
		if ( preg_match( '/^\s*TRUNCATE(?:\s+TABLE)?\s+`?(\w+)`?/i', $query, $match ) ) {
			return array( 'type' => 'DDL', 'op' => 'TRUNCATE', 'table' => $match[1] );
		}
		if ( preg_match( '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $query, $match ) ) {
			return array( 'type' => 'DDL', 'op' => 'CREATE', 'table' => $match[1] );
		}
		if ( preg_match( '/^\s*CREATE\s+(?:(?:OR\s+REPLACE)\s+)?(?:(?:UNIQUE|FULLTEXT|SPATIAL|VECTOR)\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?`?\w+`?(?:\s+USING\s+\w+)?\s+ON\s+`?(\w+)`?/i', $query, $match ) ) {
			return array( 'type' => 'DDL', 'op' => 'ALTER', 'table' => $match[1] );
		}
		if ( preg_match( '/^\s*ALTER\s+TABLE\s+`?(\w+)`?/i', $query, $match ) ) {
			return array( 'type' => 'DDL', 'op' => 'ALTER', 'table' => $match[1] );
		}
		if ( preg_match( '/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(.+?)\s*;?\s*$/is', $drop_query, $match ) ) {
			$tables = array();
			foreach ( preg_split( '/\s*,\s*/', trim( $match[1] ) ) ?: array() as $operand ) {
				if ( ! preg_match( '/^`?([A-Za-z_][A-Za-z0-9_]*)`?$/', $operand, $table_match ) ) { return null; }
				$tables[ $table_match[1] ] = true;
			}
			if ( $tables ) { $tables = array_keys( $tables ); return 1 === count( $tables ) ? array( 'type' => 'DDL', 'op' => 'DROP', 'table' => $tables[0] ) : array( 'type' => 'DDL', 'op' => 'DROP', 'tables' => $tables ); }
		}
		if ( preg_match( '/^\s*DROP\s+INDEX\s+`?\w+`?\s+ON\s+`?(\w+)`?\s*;?\s*$/i', $query, $match ) ) {
			return array( 'type' => 'DDL', 'op' => 'ALTER', 'table' => $match[1] );
		}

		return null;
	}

	/** @return array{action:string,savepoint?:string}|null */
	public static function transaction_control( string $query ): ?array {
		if ( preg_match( '/^\s*(?:START\s+TRANSACTION(?:\s+(?:WITH\s+CONSISTENT\s+SNAPSHOT|READ\s+(?:ONLY|WRITE)))?|BEGIN(?:\s+WORK)?)\s*;?\s*$/i', $query ) ) {
			return array( 'action' => 'begin' );
		}
		if ( preg_match( '/^\s*COMMIT(?:\s+WORK)?(?:\s+AND\s+(NO\s+)?CHAIN)?(?:\s+(?:NO\s+)?RELEASE)?\s*;?\s*$/i', $query, $match ) ) {
			return array( 'action' => str_contains( strtoupper( $query ), 'AND CHAIN' ) ? 'commit_chain' : 'commit' );
		}
		if ( preg_match( '/^\s*ROLLBACK(?:\s+WORK)?(?:\s+AND\s+(NO\s+)?CHAIN)?(?:\s+(?:NO\s+)?RELEASE)?\s*;?\s*$/i', $query, $match ) ) {
			return array( 'action' => str_contains( strtoupper( $query ), 'AND CHAIN' ) ? 'rollback_chain' : 'rollback' );
		}
		if ( preg_match( '/^\s*SAVEPOINT\s+`?([A-Za-z_][A-Za-z0-9_$]*)`?\s*;?\s*$/i', $query, $match ) ) {
			return array( 'action' => 'savepoint', 'savepoint' => $match[1] );
		}
		if ( preg_match( '/^\s*ROLLBACK\s+TO(?:\s+SAVEPOINT)?\s+`?([A-Za-z_][A-Za-z0-9_$]*)`?\s*;?\s*$/i', $query, $match ) ) {
			return array( 'action' => 'rollback_to', 'savepoint' => $match[1] );
		}
		if ( preg_match( '/^\s*RELEASE\s+SAVEPOINT\s+`?([A-Za-z_][A-Za-z0-9_$]*)`?\s*;?\s*$/i', $query, $match ) ) {
			return array( 'action' => 'release_savepoint', 'savepoint' => $match[1] );
		}
		if ( preg_match( '/^\s*SET\s+(?:(?:SESSION|LOCAL)\s+|@@(?:SESSION\.)?)?AUTOCOMMIT\s*(?:=|:=)\s*([01])\s*;?\s*$/i', $query, $match ) ) {
			return array( 'action' => 'autocommit_' . $match[1] );
		}

		return null;
	}

	public static function unsupported_transaction_boundary( string $query ): bool {
		return self::unsupported_implicit_commit( $query ) || (bool) preg_match( '/^\s*(?:CALL\b|DO\b|HANDLER\b|UNLOCK\s+TABLES\b|XA\b)/i', $query );
	}

	public static function unsupported_implicit_commit( string $query ): bool {
		return (bool) preg_match(
			'/^\s*(?:(?:ALTER|CREATE|DROP)\s+(?:DATABASE|EVENT|FUNCTION|INSTANCE|LOGFILE\s+GROUP|PROCEDURE|ROLE|SEQUENCE|SERVER|SPATIAL\s+REFERENCE\s+SYSTEM|TABLESPACE|TRIGGER|USER|VIEW)\b|RENAME\s+(?:TABLE|USER)\b|GRANT\b|REVOKE\b|SET\s+PASSWORD\b|LOCK\s+TABLES\b|LOAD\s+DATA\b|(?:ANALYZE|CHECK|OPTIMIZE|REPAIR)\s+TABLE\b|CACHE\s+INDEX\b|FLUSH\b|LOAD\s+INDEX\s+INTO\s+CACHE\b|RESET(?:\s+(?:MASTER|REPLICA|SLAVE))?\b|INSTALL\s+(?:PLUGIN|COMPONENT)\b|UNINSTALL\s+(?:PLUGIN|COMPONENT)\b|CHANGE\s+(?:MASTER|REPLICATION\s+SOURCE)\b|START\s+(?:REPLICA|SLAVE)\b|STOP\s+(?:REPLICA|SLAVE)\b)/i',
			$query
		);
	}
}
