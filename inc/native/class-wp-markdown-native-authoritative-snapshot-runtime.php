<?php
/** Bounded in-memory source snapshots for independent native SQL comparisons. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Authoritative_Snapshot_Runtime implements WP_Markdown_Query_Runtime {
	private const MAX_TABLES = 16;
	private const MAX_ROWS_PER_TABLE = 10000;
	private const MAX_BYTES_PER_TABLE = 8388608;

	/** @param array<int,array{table:string,rows:int,sha256:string}> $provenance */
	public function __construct( private WP_Markdown_Query_Runtime $runtime, private array $provenance ) {}

	public static function capture( object $database, string $sql, string $prefix ): self {
		$connection = method_exists( $database, 'markdown_db_mysql_connection' )
			? $database->markdown_db_mysql_connection()
			: ( $database->dbh ?? null );
		if ( ! is_object( $connection ) || ! method_exists( $connection, 'query' ) ) {
			throw new RuntimeException( 'The SQL snapshot input mode requires the authoritative MySQL connection.' );
		}
		$tables = self::tables_in( $sql );
		if ( array() === $tables || count( $tables ) > self::MAX_TABLES ) {
			throw new RuntimeException( 'The SQL snapshot input mode could not bound the source tables.' );
		}

		$registry = new WP_Markdown_Native_Table_Registry();
		$provenance = array();
		foreach ( $tables as $table ) {
			$quoted = '`' . str_replace( '`', '``', $table ) . '`';
			$ddl = self::one_row( $connection, 'SHOW CREATE TABLE ' . $quoted );
			$definition = is_array( $ddl ) ? (string) ( array_values( $ddl )[1] ?? '' ) : '';
			$compiled = '' === $definition ? array() : WP_Markdown_Native_Schema_Catalog::compile( $definition, array( $prefix ) );
			$schema_definition = $compiled[ substr( $table, strlen( $prefix ) ) ] ?? null;
			$schema = is_array( $schema_definition ) ? WP_Markdown_Native_Schema_Catalog::indexed_snapshot_schema( $schema_definition ) : null;
			if ( ! $schema instanceof WP_Markdown_Native_Table_Schema ) {
				throw new RuntimeException( 'The SQL snapshot input mode could not compile a source table schema.' );
			}
			$rows = self::rows( $connection, 'SELECT * FROM ' . $quoted );
			$registry->register( $table, $schema, new WP_Markdown_Native_Authoritative_Snapshot_Provider( $rows ) );
			$provenance[] = array( 'table' => $table, 'rows' => count( $rows ), 'sha256' => hash( 'sha256', json_encode( $rows, JSON_THROW_ON_ERROR ) ) );
		}
		return new self( new WP_Markdown_Native_Query_Runtime( $registry ), $provenance );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		return $this->runtime->execute( $request );
	}

	/** @return array{read_connection:string,tables:array<int,array{table:string,rows:int,sha256:string}>} */
	public function provenance(): array {
		return array( 'read_connection' => 'authoritative_mysql_connection_pre_query', 'tables' => $this->provenance );
	}

	/** @return array<int,string> */
	private static function tables_in( string $sql ): array {
		if ( 1 !== preg_match_all( '/\b(?:FROM|JOIN)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $sql, $matches ) ) {
			return array();
		}
		return array_values( array_unique( $matches[1] ) );
	}

	/** @return array<string,mixed>|null */
	private static function one_row( object $connection, string $sql ): ?array {
		$result = $connection->query( $sql );
		if ( ! is_object( $result ) || ! method_exists( $result, 'fetch_assoc' ) ) {
			return null;
		}
		$row = $result->fetch_assoc();
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	private static function rows( object $connection, string $sql ): array {
		$result = $connection->query( $sql );
		if ( ! is_object( $result ) || ! method_exists( $result, 'fetch_assoc' ) ) {
			throw new RuntimeException( 'The SQL snapshot input mode could not read a source table.' );
		}
		$rows = array();
		$bytes = 0;
		while ( null !== ( $row = $result->fetch_assoc() ) ) {
			if ( ! is_array( $row ) || count( $rows ) >= self::MAX_ROWS_PER_TABLE ) {
				throw new RuntimeException( 'The SQL snapshot input mode exceeded its source row bound.' );
			}
			$bytes += strlen( json_encode( $row, JSON_THROW_ON_ERROR ) );
			if ( $bytes > self::MAX_BYTES_PER_TABLE ) {
				throw new RuntimeException( 'The SQL snapshot input mode exceeded its source byte bound.' );
			}
			$rows[] = $row;
		}
		return $rows;
	}
}

final class WP_Markdown_Native_Authoritative_Snapshot_Provider implements WP_Markdown_Native_Table_Provider {
	/** @param array<int,array<string,mixed>> $rows */
	public function __construct( private array $rows ) {}

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		return $this->rows;
	}
}
