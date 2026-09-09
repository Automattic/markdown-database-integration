<?php
/** Bounded in-memory source snapshots for independent native SQL comparisons. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Authoritative_Snapshot_Runtime implements WP_Markdown_Query_Runtime {
	private const MAX_TABLES = 16;
	private const MAX_ROWS_PER_TABLE = 10000;
	private const MAX_BYTES_PER_TABLE = 8388608;

	/** @param array<int,array{table:string,exists:bool,rows?:int,sha256?:string,schema_sha256?:string}> $provenance */
	public function __construct( private WP_Markdown_Query_Runtime $runtime, private array $provenance ) {}

	public static function capture( object $database, string $sql, string $prefix ): self {
		self::trace_runtime_phase( 'capture', $sql );
		$connection = method_exists( $database, 'markdown_db_mysql_connection' )
			? $database->markdown_db_mysql_connection()
			: ( $database->dbh ?? null );
		if ( ! is_object( $connection ) || ! method_exists( $connection, 'query' ) ) {
			throw new RuntimeException( 'The SQL snapshot input mode requires the authoritative MySQL connection.' );
		}
		$tables = self::tables_in( $sql );
		if ( array() === $tables || count( $tables ) > self::MAX_TABLES ) {
			throw new WP_Markdown_Native_Snapshot_Input_Exception( 'markdown_db_native_snapshot_input_unavailable', 'unbounded_or_tableless_source' );
		}

		$prefixes = self::schema_prefixes( $database, $prefix );
		$registry = new WP_Markdown_Native_Table_Registry();
		$provenance = array();
		foreach ( $tables as $table ) {
			$quoted = '`' . str_replace( '`', '``', $table ) . '`';
			$ddl = self::one_row( $connection, 'SHOW CREATE TABLE ' . $quoted );
			if ( null === $ddl && 1146 === self::error_code( $connection ) ) {
				// An absent source is a fact usable for an independent native error run.
				$provenance[] = array( 'table' => $table, 'exists' => false );
				continue;
			}
			$definition = is_array( $ddl ) ? (string) ( array_values( $ddl )[1] ?? '' ) : '';
			$compiled = '' === $definition ? array() : WP_Markdown_Native_Schema_Catalog::compile( $definition, $prefixes, array( $table ) );
			$schema_definition = 1 === count( $compiled ) ? reset( $compiled ) : null;
			$schema = is_array( $schema_definition ) ? WP_Markdown_Native_Schema_Catalog::indexed_snapshot_schema( $schema_definition ) : null;
			if ( ! $schema instanceof WP_Markdown_Native_Table_Schema ) {
				throw new WP_Markdown_Native_Snapshot_Input_Exception( 'markdown_db_native_snapshot_input_unavailable', 'source_schema_unavailable' );
			}
			$rows = self::rows( $connection, 'SELECT * FROM ' . $quoted . ' LIMIT ' . ( self::MAX_ROWS_PER_TABLE + 1 ) );
			$registry->register( $table, $schema, new WP_Markdown_Native_Authoritative_Snapshot_Provider( $rows, $schema ) );
			$provenance[] = array( 'table' => $table, 'exists' => true, 'rows' => count( $rows ), 'sha256' => hash( 'sha256', self::encode_rows( $rows ) ), 'schema_sha256' => hash( 'sha256', $definition ) );
		}
		return new self( new WP_Markdown_Native_Query_Runtime( $registry ), $provenance );
	}

	private static function trace_runtime_phase( string $phase, ?string $sql = null ): void {
		$path = defined( 'MARKDOWN_DB_NATIVE_SHADOW_TRACE_PATH' ) ? MARKDOWN_DB_NATIVE_SHADOW_TRACE_PATH : getenv( 'MARKDOWN_DB_NATIVE_SHADOW_TRACE_PATH' );
		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}
		$event = array( 'phase' => $phase, 'file_sha256' => hash_file( 'sha256', __FILE__ ) );
		if ( null !== $sql ) {
			try {
				$event['sql_sha256'] = hash( 'sha256', $sql );
				$event['token_types'] = array_map( static fn( WP_Markdown_Native_SQL_Token $token ): string => $token->type(), ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $sql ) );
				$event['table_count'] = count( self::tables_in( $sql ) );
			} catch ( WP_Markdown_Native_Snapshot_Input_Exception|WP_Markdown_Native_SQL_Parse_Error ) {
				$event['token_types'] = array( 'parse_error' );
			}
		}
		file_put_contents( $path, json_encode( $event, JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND | LOCK_EX );
	}

	/** @return array<int,string> */
	private static function schema_prefixes( object $database, string $prefix ): array {
		$prefixes = array( $prefix );
		if ( isset( $database->base_prefix ) && is_string( $database->base_prefix ) ) {
			$prefixes[] = $database->base_prefix;
		}
		return array_values( array_unique( array_filter( $prefixes, static fn( string $candidate ): bool => '' !== $candidate ) ) );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$result = $this->runtime->execute( $request );
		$diagnostic = $result->diagnostic() ?? array();
		if ( 'unsupported_table' !== ( $diagnostic['reason'] ?? null ) || ! $this->has_explicitly_absent_source( $request->sql() ) ) {
			return $result;
		}
		return WP_Markdown_Query_Result::failure(
			array(
				'code'    => 1146,
				'reason'  => 'missing_table',
				'message' => 'The requested table does not exist.',
			)
		);
	}

	/** Only snapshot discovery, never an unregistered native table, proves absence. */
	private function has_explicitly_absent_source( string $sql ): bool {
		$absent = array();
		foreach ( $this->provenance as $table ) {
			if ( is_array( $table ) && false === ( $table['exists'] ?? null ) && is_string( $table['table'] ?? null ) ) {
				$absent[] = $table['table'];
			}
		}
		return array() !== array_intersect( self::tables_in( $sql ), $absent );
	}

	/** @return array{read_connection:string,tables:array<int,array{table:string,exists:bool,rows?:int,sha256?:string,schema_sha256?:string}>} */
	public function provenance(): array {
		return array( 'read_connection' => 'authoritative_mysql_connection_pre_query', 'tables' => $this->provenance );
	}

	/** @return array<int,string> */
	private static function tables_in( string $sql ): array {
		$plan = ( new WP_Markdown_Native_Query_Parser() )->parse( $sql );
		if ( $plan instanceof WP_Markdown_Query_Result ) {
			$catalog_tables = WP_Markdown_Native_Schema_Introspection::requested_information_schema_tables( $sql );
			if ( null !== $catalog_tables ) {
				return $catalog_tables;
			}
			$diagnostic = $plan->diagnostic() ?? array();
			throw new WP_Markdown_Native_Snapshot_Input_Exception(
				(string) ( $diagnostic['code'] ?? 'markdown_db_native_unsupported_query' ),
				(string) ( $diagnostic['reason'] ?? 'unsupported_sql_grammar' )
			);
		}
		if ( ! $plan instanceof WP_Markdown_Native_Query_Plan ) {
			return array();
		}
		$tables = array();
		self::collect_tables( $plan, $tables );
		return array_values( array_unique( $tables ) );
	}

	/** @param array<int,string> $tables */
	private static function collect_tables( WP_Markdown_Native_Query_Plan $plan, array &$tables ): void {
		if ( null === $plan->derived() ) {
			$tables[] = $plan->table();
		}
		foreach ( $plan->joins() as $join ) {
			if ( null !== $join->derived() ) {
				self::collect_tables( $join->derived(), $tables );
			} else {
				$tables[] = $join->table();
			}
		}
		foreach ( $plan->subqueries() as $subquery ) {
			self::collect_tables( $subquery->query(), $tables );
		}
		if ( null !== $plan->boolean_predicate() ) {
			foreach ( $plan->boolean_predicate()->groups() as $group ) {
				foreach ( $group as $predicate ) {
					if ( $predicate instanceof WP_Markdown_Native_Query_Subquery ) {
						self::collect_tables( $predicate->query(), $tables );
					}
				}
			}
		}
		if ( null !== $plan->derived() ) {
			self::collect_tables( $plan->derived(), $tables );
		}
		if ( null !== $plan->union() ) {
			self::collect_tables( $plan->union(), $tables );
		}
	}

	/** @return array<string,mixed>|null */
	private static function one_row( object $connection, string $sql ): ?array {
		$result = self::query( $connection, $sql );
		if ( ! is_object( $result ) || ! method_exists( $result, 'fetch_assoc' ) ) {
			return null;
		}
		try {
			$row = $result->fetch_assoc();
			return is_array( $row ) ? $row : null;
		} finally {
			self::free( $result );
		}
	}

	/** @return array<int,array<string,mixed>> */
	private static function rows( object $connection, string $sql ): array {
		$result = self::query( $connection, $sql );
		if ( ! is_object( $result ) || ! method_exists( $result, 'fetch_assoc' ) ) {
			throw new RuntimeException( 'The SQL snapshot input mode could not read a source table.' );
		}
		$rows = array();
		$bytes = 0;
		try {
			while ( null !== ( $row = $result->fetch_assoc() ) ) {
				if ( ! is_array( $row ) || count( $rows ) >= self::MAX_ROWS_PER_TABLE ) {
					throw new RuntimeException( 'The SQL snapshot input mode exceeded its source row bound.' );
				}
				$bytes += strlen( self::encode_row( $row ) );
				if ( $bytes > self::MAX_BYTES_PER_TABLE ) {
					throw new RuntimeException( 'The SQL snapshot input mode exceeded its source byte bound.' );
				}
				$rows[] = $row;
			}
		} finally {
			self::free( $result );
		}
		return $rows;
	}

	/**
	 * Length-prefixed type tags preserve raw mysqli bytes without requiring UTF-8.
	 * Column order comes from mysqli's associative row shape and is part of the receipt.
	 */
	private static function encode_rows( array $rows ): string {
		$encoded = 'rows:' . count( $rows ) . ';';
		foreach ( $rows as $row ) {
			$encoded .= self::encode_row( $row );
		}
		return $encoded;
	}

	/** @param array<string,mixed> $row */
	private static function encode_row( array $row ): string {
		$encoded = 'row:' . count( $row ) . ';';
		foreach ( $row as $name => $value ) {
			$name = (string) $name;
			$encoded .= 'k' . strlen( $name ) . ':' . $name . ';';
			if ( null === $value ) {
				$encoded .= 'n;';
				continue;
			}
			if ( is_string( $value ) ) {
				$encoded .= 's' . strlen( $value ) . ':' . $value . ';';
				continue;
			}
			$value = (string) $value;
			$encoded .= 'x' . strlen( $value ) . ':' . $value . ';';
		}
		return $encoded;
	}

	private static function query( object $connection, string $sql ): mixed {
		if ( $connection instanceof mysqli && defined( 'MYSQLI_USE_RESULT' ) ) {
			return $connection->query( $sql, MYSQLI_USE_RESULT );
		}
		return $connection->query( $sql );
	}

	private static function error_code( object $connection ): int {
		if ( isset( $connection->errno ) ) {
			return (int) $connection->errno;
		}
		return method_exists( $connection, 'errno' ) ? (int) $connection->errno() : 0;
	}

	private static function free( object $result ): void {
		if ( method_exists( $result, 'free' ) ) {
			$result->free();
		} elseif ( method_exists( $result, 'free_result' ) ) {
			$result->free_result();
		}
	}
}

final class WP_Markdown_Native_Snapshot_Input_Exception extends RuntimeException {
	public function __construct( private string $diagnostic_code, private string $diagnostic_reason ) {
		parent::__construct( $diagnostic_reason );
	}

	/** @return array{code:string,reason:string} */
	public function diagnostic(): array {
		return array( 'code' => $this->diagnostic_code, 'reason' => $this->diagnostic_reason );
	}
}

final class WP_Markdown_Native_Authoritative_Snapshot_Provider implements WP_Markdown_Native_Table_Provider {
	/** @param array<int,array<string,mixed>> $rows */
	public function __construct( private array $rows, private WP_Markdown_Native_Table_Schema $schema ) {}

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$predicates = $access->predicates();
		if ( array() === $predicates && null !== $access->predicate() ) {
			$predicates[] = $access->predicate();
		}
		$rows = array_values( array_filter( $this->rows, fn( array $row ): bool => $this->schema->matches( $row, $predicates ) ) );
		$rows = $this->schema->ordered_rows( $rows, $access->order_by() );
		if ( null === $rows ) {
			return WP_Markdown_Query_Result::failure( array( 'code' => 'markdown_db_native_unsupported_query', 'reason' => 'unsupported_order', 'message' => 'mdi-native cannot apply the requested ordering collation.' ) );
		}
		$selected = array();
		foreach ( $rows as $row ) {
			if ( count( $selected ) >= $access->limit() ) {
				break;
			}
			$selected[] = array_replace( array_flip( $access->projection() ), array_intersect_key( $row, array_flip( $access->projection() ) ) );
		}
		return $selected;
	}
}
