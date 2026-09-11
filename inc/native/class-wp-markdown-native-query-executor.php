<?php
/** Generic bounded query executor over registered providers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../class-wp-markdown-operation-profile.php';
/** Materialized parenthesized SELECT source used only during its enclosing query. */
final class WP_Markdown_Native_Derived_Table_Provider implements WP_Markdown_Native_Table_Provider {
	/** @param array<int,array<string,mixed>> $rows */
	public function __construct(
		private readonly array $rows,
		private readonly WP_Markdown_Native_Table_Schema $schema
	) {}

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$rows = array();
		foreach ( $this->rows as $row ) {
			if ( null !== $access->predicate() && ! $this->schema->matches( $row, array( $access->predicate() ) ) ) {
				continue;
			}
			$selected = array();
			foreach ( $access->projection() as $column ) {
				$selected[ $column ] = $row[ $column ];
			}
			$rows[] = $selected;
		}
		return $rows;
	}
}

final class WP_Markdown_Native_Query_Runtime implements WP_Markdown_Query_Runtime, WP_Markdown_Native_Transactional_Table_Support {
	private const MAX_JOIN_CANDIDATE_PAIRS = 100000;
	private const MAX_CORRELATED_SUBQUERY_EVALUATIONS = 10000;
	/** The largest SQL request accepted by the native request boundary. */
	public const MAX_SQL_BYTES = 67108864;
	private ?int $last_found_rows = null;
	private ?string $statement_now = null;
	/** @var array<string,array{values:array<string,true>,has_null:bool}> */
	private array $correlated_subquery_cache = array();
	/** A child execution error must fail its enclosing statement, never filter a row. */
	private ?WP_Markdown_Query_Result $correlated_subquery_failure = null;
	/** @var array<string,array{seed1:int,seed2:int}> */
	private array $rand_states = array();
	private WP_Markdown_Native_Schema_Introspection $schema_introspection;
	private ?string $database_name;

	public function __construct(
		private WP_Markdown_Native_Table_Registry $registry,
		private WP_Markdown_Native_Query_Parser $parser = new WP_Markdown_Native_Query_Parser(),
		private ?WP_Markdown_Native_Option_Mutation_Runtime $option_mutations = null,
		private ?WP_Markdown_Native_Schema_Mutation_Runtime $schema_mutations = null,
		private ?WP_Markdown_Native_Table_Mutation_Runtime $table_mutations = null,
		private ?WP_Markdown_Native_Transaction_Journal $transactions = null,
		private ?WP_Markdown_Native_Post_Mutation_Runtime $post_mutations = null,
		private int $correlated_subquery_limit = self::MAX_CORRELATED_SUBQUERY_EVALUATIONS,
		private ?WP_Markdown_Native_Advisory_Locks $advisory_locks = null,
		?string $database_name = null,
		private WP_Markdown_Native_SQL_Session $session = new WP_Markdown_Native_SQL_Session()
	) {
		$this->database_name = $database_name;
		$this->schema_introspection = new WP_Markdown_Native_Schema_Introspection( $registry, database_name: $database_name, session: $this->session );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$start = WP_Markdown_Operation_Profile::begin();
		try {
			return $this->execute_request( $request );
		} finally {
			if ( null !== $start ) {
				$elapsed = ( hrtime( true ) - $start ) / 1e6;
				preg_match( '/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $request->sql(), $match );
				WP_Markdown_Operation_Profile::end( 'query_' . strtolower( $match[1] ?? 'other' ), $start );
				if ( 'SELECT' === strtoupper( $match[1] ?? '' ) ) {
					try {
						$shape = array();
						foreach ( ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $request->sql() ) as $token ) {
							if ( WP_Markdown_Native_SQL_Token::END === $token->type() ) {
								continue;
							}
							$shape[] = in_array( $token->type(), array( 'string', 'integer', 'decimal' ), true ) ? '?' : (string) $token->value();
						}
						WP_Markdown_Operation_Profile::query( implode( ' ', $shape ), hash( 'sha256', $request->sql() ), $elapsed );
					} catch ( WP_Markdown_Native_SQL_Parse_Error ) {
						WP_Markdown_Operation_Profile::count( 'query_shape_unavailable' );
					}
				}
			}
		}
	}

	/**
	 * Confirm that every exact table has a recognized canonical provider, its
	 * matching configured mutation runtime, and a factory-admitted journal root.
	 * This is an atomic-write guarantee, not an InnoDB or mysqli-session claim.
	 *
	 * @param string[] $tables
	 */
	public function supports_transactional_tables( array $tables ): bool {
		if ( null === $this->transactions || array() === $tables ) {
			return false;
		}

		foreach ( $tables as $table_name ) {
			if ( ! is_string( $table_name ) || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $table_name ) || $this->registry->is_shadowed( $table_name ) ) {
				return false;
			}
			$table = $this->registry->table( $table_name );
			if ( null === $table || ! $this->supports_transactional_provider( $table['provider'] ) ) {
				return false;
			}
		}

		return true;
	}

	private function supports_transactional_provider( WP_Markdown_Native_Table_Provider $provider ): bool {
		if ( ! $provider instanceof WP_Markdown_Native_Canonical_Table_Provider || ! $this->transactions->covers_root( $provider->canonical_root() ) ) {
			return false;
		}
		return ( $provider instanceof WP_Markdown_Native_Post_Provider && null !== $this->post_mutations )
			|| ( $provider instanceof WP_Markdown_Native_Option_Provider && null !== $this->option_mutations )
			|| ( $provider instanceof WP_Markdown_Native_JSON_Snapshot_Provider && null !== $this->table_mutations );
	}

	private function execute_request( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		self::trace_runtime_phase( 'executor', $request->sql() );
		if ( strlen( $request->sql() ) > self::MAX_SQL_BYTES ) {
			return $this->failure( 'request_too_large', 'mdi-native cannot execute a request larger than max_allowed_packet.' );
		}
		// Scalar functions share one statement scope, including tableless SELECTs.
		$this->rand_states = array();
		$this->correlated_subquery_cache = array();
		$this->correlated_subquery_failure = null;
		$this->statement_now = gmdate( 'Y-m-d H:i:s' );
		$session_result = $this->session->execute( $request->sql() );
		if ( null !== $session_result ) {
			return $session_result;
		}
		$transaction_control = WP_Markdown_SQL_Classifier::transaction_control( $request->sql() );
		if ( null !== $transaction_control ) {
			return $this->execute_transaction_control( $transaction_control );
		}
		// Advisory locks have their own root-scoped lock files. Holding the
		// transaction lock while waiting for one would invert their release order.
		$mutation = null !== WP_Markdown_SQL_Classifier::mutation( $request->sql() );
		$canonical_admitted = null !== $this->transactions && ! $this->is_advisory_lock_statement( $request->sql() );
		if ( $canonical_admitted ) {
			$transactional_view = $this->transactions->is_in_transaction();
			$locked = $this->transactions->begin_write();
			if ( true !== $locked ) {
				return $this->failure( $mutation ? 'transaction_write_lock_failed' : 'transaction_read_lock_failed', $locked );
			}
			if ( ! $transactional_view || $this->transactions->waited_for_write_lock() ) {
				// Autocommit requests start a fresh canonical view. A transaction that
				// waited also cannot retain snapshots from before the prior commit.
				$this->registry->forget_snapshots();
			}
		}
		try {
			return $this->execute_unlocked_request( $request );
		} finally {
			if ( $canonical_admitted ) {
				$this->transactions->finish_write();
			}
		}
	}

	private function execute_unlocked_request( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		if ( 1 === preg_match( '/^\s*(?:SHOW|DESCRIBE)\b/i', $request->sql() ) ) {
			return $this->schema_introspection->execute( $request );
		}
		$information_schema = $this->schema_introspection->select_information_schema( $request );
		if ( null !== $information_schema ) {
			return $information_schema;
		}
		$advisory_lock = $this->advisory_lock_query( $request->sql() );
		if ( null !== $advisory_lock ) {
			return $advisory_lock;
		}
		// The canonical store is a directory, not a named server database.
		if ( 1 === preg_match( '/^\s*SELECT\s+DATABASE\s*\(\s*\)\s*;?\s*$/i', $request->sql() ) ) {
			return WP_Markdown_Query_Result::selected(
				array( array( 'DATABASE()' => $this->database_name ?? ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) ) ),
				array( array( 'name' => 'DATABASE()', 'table' => '', 'type' => 253 ) )
			);
		}
		$tableless = $this->tableless_scalar_projection( $request->sql() );
		if ( null !== $tableless ) {
			return $tableless;
		}
		if ( 1 === preg_match( '/^\s*SELECT\s+(@@(?:SESSION\.)?(IN_TRANSACTION|AUTOCOMMIT|MAX_ALLOWED_PACKET))(?:\s+AS\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;?\s*$/i', $request->sql(), $match ) ) {
			$column = $match[3] ?? $match[1];
			$variable = strtolower( $match[2] );
			$value = 'in_transaction' === $variable
				? (string) (int) ( $this->transactions?->is_in_transaction() ?? false )
				: ( 'autocommit' === $variable
					? (string) (int) ( $this->transactions?->is_autocommit() ?? true )
					: (string) self::MAX_SQL_BYTES );
			return WP_Markdown_Query_Result::selected(
				array( array( $column => $value ) ),
				array( array( 'name' => $column, 'table' => '', 'type' => 8 ) )
			);
		}
		if ( 1 === preg_match( '/^\s*(?:CREATE|ALTER)\s+(?:TEMPORARY\s+)?TABLE\b/i', $request->sql() )
			|| 1 === preg_match( '/^\s*DROP\s+(?:TEMPORARY\s+)?TABLE\b/i', $request->sql() )
			|| 1 === preg_match( '/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\b/i', $request->sql() ) ) {
			return null === $this->schema_mutations
				? $this->failure( 'unsupported_grammar', 'mdi-native schema mutations are unavailable.' )
				: $this->schema_mutations->execute( $request );
		}
		$dml_table = $this->dml_table( $request );
		if ( null !== $dml_table && ( 0 !== strcasecmp( $request->table_prefix() . 'options', $dml_table ) || $this->registry->is_shadowed( $dml_table ) ) ) {
			return $this->execute_table_dml( $request, $dml_table );
		}
		if ( 1 !== preg_match( '/^\s*(?:SELECT\b|(?:\(\s*)+SELECT\b)/i', $request->sql() ) ) {
			return null === $this->option_mutations
				? $this->failure( 'unsupported_grammar', 'mdi-native supports bounded SELECT queries only.' )
				: $this->option_mutations->execute( $request );
		}
		$start = WP_Markdown_Operation_Profile::begin();
		try {
			$plan = $this->parser->parse( $request->sql(), fn( string $table ): array => array_keys( $this->registry->definition( $table )['columns'] ?? array() ) );
		} finally {
			WP_Markdown_Operation_Profile::end( 'select_parse', $start );
		}
		if ( $plan instanceof WP_Markdown_Query_Result ) {
			return $plan;
		}
		return $this->execute_plan( $plan );
	}

	/** Execute an already parsed SELECT plan for another native statement. */
	public function execute_plan( WP_Markdown_Native_Query_Plan|WP_Markdown_Native_Found_Rows_Plan $plan ): WP_Markdown_Query_Result {
		$start = WP_Markdown_Operation_Profile::begin();
		try {
			return $this->execute_select_plan( $plan );
		} finally {
			WP_Markdown_Operation_Profile::end( 'select_execute', $start );
		}
	}

	private function execute_select_plan( WP_Markdown_Native_Query_Plan|WP_Markdown_Native_Found_Rows_Plan $plan ): WP_Markdown_Query_Result {
		if ( $plan instanceof WP_Markdown_Native_Found_Rows_Plan ) {
			return null === $this->last_found_rows
				? $this->failure( 'missing_found_rows', 'FOUND_ROWS() requires a preceding successful SQL_CALC_FOUND_ROWS query.' )
				: WP_Markdown_Query_Result::selected(
					array( array( 'FOUND_ROWS()' => (string) $this->last_found_rows ) ),
					array( array( 'name' => 'FOUND_ROWS()', 'table' => '', 'type' => 8 ) )
				);
		}
		return $this->execute_query_plan( $plan );
	}

	private static function trace_runtime_phase( string $phase, ?string $sql = null ): void {
		$path = defined( 'MARKDOWN_DB_NATIVE_SHADOW_TRACE_PATH' ) ? MARKDOWN_DB_NATIVE_SHADOW_TRACE_PATH : getenv( 'MARKDOWN_DB_NATIVE_SHADOW_TRACE_PATH' );
		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}
		if ( ( is_file( $path ) ? (int) filesize( $path ) : 0 ) >= 65536 ) {
			return;
		}
		$event = array( 'phase' => $phase, 'file_sha256' => hash_file( 'sha256', __FILE__ ) );
		if ( null !== $sql && strlen( $sql ) <= 65536 ) {
			try {
				$event['sql_sha256'] = hash( 'sha256', $sql );
				$event['token_types'] = array_slice( array_map( static fn( WP_Markdown_Native_SQL_Token $token ): string => $token->type(), ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $sql ) ), 0, 128 );
			} catch ( WP_Markdown_Native_SQL_Parse_Error ) {
				$event['token_types'] = array( 'parse_error' );
			}
		}
		$encoded = json_encode( $event, JSON_UNESCAPED_SLASHES ) . "\n";
		if ( strlen( $encoded ) <= 4096 && false !== ( $trace = @fopen( $path, 'c' ) ) ) {
			try {
				if ( flock( $trace, LOCK_EX ) ) {
					$size = fstat( $trace )['size'] ?? 0;
					if ( $size + strlen( $encoded ) <= 65536 ) {
						fseek( $trace, 0, SEEK_END );
						fwrite( $trace, $encoded );
					}
					flock( $trace, LOCK_UN );
				}
			} finally {
				fclose( $trace );
			}
		}
	}

	/** Execute source-free typed scalar expressions as the one-row SQL result. */
	private function tableless_scalar_projection( string $sql ): ?WP_Markdown_Query_Result {
		$projection = $this->parser->parse_tableless_scalar_projection( $sql );
		if ( $projection instanceof WP_Markdown_Query_Result ) {
			return $this->tableless_json_valid( $sql );
		}
		// The evaluator accepts a schema for CASE predicates; this sentinel is
		// unreachable because tableless expressions have no column references.
		$schema = new WP_Markdown_Native_Table_Schema(
			array( '__mdi_native_tableless' => new WP_Markdown_Native_Column( 3, false ) ),
			'__mdi_native_tableless'
		);
		$row = array();
		$columns = array();
		foreach ( $projection as $scalar ) {
			if ( 'JSON_VALID' === $scalar['expression']->kind() && $this->json_depth_exceeded( $scalar['expression'] ) ) {
				return $this->mysql_json_depth_failure();
			}
			$value = $this->evaluate_scalar( $scalar['expression'], array(), $schema );
			$row[ $scalar['alias'] ] = $this->string_scalar( $value );
			$columns[] = array( 'name' => $scalar['alias'], 'table' => '', 'type' => $this->tableless_scalar_type( $scalar['expression'], $value ) );
		}
		return WP_Markdown_Query_Result::selected( array( $row ), $columns );
	}

	/** Preserve the legacy unaliased JSON column label while typed aliases use the shared evaluator. */
	private function tableless_json_valid( string $sql ): ?WP_Markdown_Query_Result {
		$literal = self::tableless_json_valid_literal( $sql );
		if ( null === $literal ) {
			return null;
		}
		$value = $literal['value'];
		$column = $literal['column'];
		if ( null !== $value && $this->json_depth_exceeded_value( (string) $value ) ) {
			return $this->mysql_json_depth_failure();
		}
		return WP_Markdown_Query_Result::selected(
			array( array( $column => null === $value ? null : $this->json_valid( (string) $value ) ) ),
			array( array( 'name' => $column, 'table' => '', 'type' => 8 ) )
		);
	}

	public static function supports_tableless_scalar_projection( string $sql ): bool {
		return null !== self::tableless_json_valid_literal( $sql )
			|| ! ( ( new WP_Markdown_Native_Query_Parser() )->parse_tableless_scalar_projection( $sql ) instanceof WP_Markdown_Query_Result );
	}

	/** @return array{value:?string,column:string}|null */
	private static function tableless_json_valid_literal( string $sql ): ?array {
		try {
			$tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( rtrim( trim( $sql ), ';' ) );
		} catch ( WP_Markdown_Native_SQL_Parse_Error ) {
			return null;
		}
		$end = count( $tokens ) - 1;
		if ( $end !== 5
			|| 0 !== strcasecmp( 'SELECT', (string) $tokens[0]->value() )
			|| 0 !== strcasecmp( 'JSON_VALID', (string) $tokens[1]->value() )
			|| WP_Markdown_Native_SQL_Token::LEFT_PAREN !== $tokens[2]->type()
			|| WP_Markdown_Native_SQL_Token::RIGHT_PAREN !== $tokens[4]->type()
			|| WP_Markdown_Native_SQL_Token::END !== $tokens[5]->type()
		) {
			return null;
		}
		$value = 0 === strcasecmp( 'NULL', (string) $tokens[3]->value() ) ? null : $tokens[3]->value();
		if ( null !== $value && WP_Markdown_Native_SQL_Token::STRING !== $tokens[3]->type() ) {
			return null;
		}
		return array( 'value' => $value, 'column' => 'JSON_VALID(' . $tokens[3]->lexeme() . ')' );
	}

	private function tableless_scalar_type( WP_Markdown_Native_Query_Scalar_Expression $expression, int|string|null $value ): int {
		if ( null === $value ) { return 6; }
		if ( 'literal' === $expression->kind() ) { return is_int( $value ) ? 3 : ( is_numeric( $value ) ? 246 : 253 ); }
		return 'JSON_VALID' === $expression->kind() ? 8 : 253;
	}

	/** Release this logical connection's root-scoped advisory locks. */
	public function close(): void {
		$this->advisory_locks?->close();
		$this->session->reset();
	}

	private function advisory_lock_query( string $sql ): ?WP_Markdown_Query_Result {
		if ( 1 !== preg_match( "/^\\s*SELECT\\s+((GET_LOCK|RELEASE_LOCK)\\s*\\(\\s*('(?:\\\\.|[^'])*')\\s*(?:,\\s*([0-9]+(?:\\.[0-9]+)?))?\\s*\\))\\s*;?\\s*$/i", $sql, $match ) ) {
			return null;
		}
		$function = strtoupper( $match[2] );
		if ( ( 'GET_LOCK' === $function && ! isset( $match[4] ) ) || ( 'RELEASE_LOCK' === $function && isset( $match[4] ) ) || null === $this->advisory_locks ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native advisory locks require a literal name and bounded timeout.' );
		}
		try {
			$literal = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $match[3] )[0];
			$name = $literal->value();
		} catch ( WP_Markdown_Native_SQL_Parse_Error ) {
			return $this->failure( 'unsupported_literal', 'mdi-native cannot decode the requested advisory lock name.' );
		}
		if ( ! is_string( $name ) || ( isset( $match[4] ) && (float) $match[4] > WP_Markdown_Native_Advisory_Locks::MAX_WAIT_SECONDS ) ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native advisory lock timeouts must be between 0 and 10 seconds.' );
		}
		$value = 'GET_LOCK' === $function
			? (int) $this->advisory_locks->acquire( $name, (float) $match[4] )
			: $this->advisory_locks->release( $name );
		$column = $match[1];
		return WP_Markdown_Query_Result::selected(
			array( array( $column => null === $value ? null : (string) $value ) ),
			array( array( 'name' => $column, 'table' => '', 'type' => 8 ) )
		);
	}

	private function is_advisory_lock_statement( string $sql ): bool {
		return 1 === preg_match( '/^\s*SELECT\s+(?:GET_LOCK|RELEASE_LOCK)\s*\(/i', $sql );
	}

	private function execute_query_plan( WP_Markdown_Native_Query_Plan $plan, bool $allow_union = true ): WP_Markdown_Query_Result {
		$hint_error = $this->validate_index_hints( $plan );
		if ( null !== $hint_error ) {
			return $hint_error;
		}
		if ( $allow_union && null !== $plan->union() ) {
			return $this->execute_union( $plan );
		}
		if ( $plan->is_unsatisfiable() ) {
			$table = null === $plan->derived()
				? $this->registry->table( $plan->table() )
				: $this->derived_source( $plan->derived(), $plan->table() );
			if ( $table instanceof WP_Markdown_Query_Result ) {
				return $table;
			}
			if ( null === $table ) {
				return $this->failure( 'unsupported_table', 'mdi-native cannot query the requested table.' );
			}
			$schema = $table['schema'];
			$projection = array( '*' ) === $plan->projection() ? $schema->column_names() : $plan->projection();
			$this->last_found_rows = 0;
			return $plan->counts_all()
				? $this->count_result( 0, false )
				: $this->result( array(), $projection, $plan->table(), $schema );
		}
		if ( array() !== $plan->joins() ) {
			return $this->execute_join( $plan );
		}

		$table = null === $plan->derived()
			? $this->registry->table( $plan->table() )
			: $this->derived_source( $plan->derived(), $plan->table() );
		if ( $table instanceof WP_Markdown_Query_Result ) {
			return $table;
		}
		if ( null === $table ) {
			return $this->failure( 'unsupported_table', 'mdi-native cannot query the requested table.' );
		}

		$schema     = $table['schema'];
		$predicates = $plan->predicates();
		$scalar_predicates = $plan->scalar_predicates();
		$boolean_predicate = $plan->boolean_predicate();
		$has_scalar_order = array() !== array_filter( $plan->order_by(), static fn( array $item ): bool => null !== ( $item['expression'] ?? null ) );
		$projection = array( '*' ) === $plan->projection() ? $schema->column_names() : $plan->projection();
		$scalar_projection = $plan->scalar_projection();
		$scalar_columns = array();
		foreach ( $scalar_projection as $scalar ) {
			$scalar_columns = array_merge( $scalar_columns, $scalar['expression']->columns() );
		}
		foreach ( $plan->aggregates() as $aggregate ) {
			if ( isset( $aggregate['expression'] ) ) {
				$expression = $aggregate['expression'];
				$scalar_columns = array_merge( $scalar_columns, $expression->columns() );
				foreach ( $expression->columns() as $column ) {
					if ( ! $schema->has_column( $column ) ) {
						return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested aggregate column.' );
					}
				}
				foreach ( $expression->predicates() as $predicate ) {
					if ( ! $schema->supports_predicate( $predicate ) ) {
						return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested aggregate predicate.' );
					}
				}
				if ( ! $this->is_numeric_expression( $expression, $schema ) ) {
					return $this->failure( 'unsupported_aggregate', 'mdi-native aggregates numeric scalar expressions only.' );
				}
			}
		}
		$columns = array_merge( $projection, $scalar_columns );
		foreach ( $scalar_predicates as $predicate ) { $columns = array_merge( $columns, $predicate->columns() ); }
		if ( null !== $boolean_predicate ) { $columns = array_merge( $columns, $boolean_predicate->columns() ); }
		if ( array() === $plan->aggregates() ) { foreach ( $plan->scalar_having() as $predicate ) { $columns = array_merge( $columns, $predicate->columns() ); } }
		foreach ( $plan->group_expressions() as $expression ) { $columns = array_merge( $columns, $expression->columns() ); }
		foreach ( $plan->subqueries() as $subquery ) {
			if ( null !== $subquery->column() ) {
				$columns[] = $subquery->column();
			}
		}
		foreach ( array_merge( $plan->subqueries(), $this->boolean_subqueries( $boolean_predicate ) ) as $subquery ) {
			foreach ( $this->outer_correlation_columns( $subquery->query() )[ $plan->table_alias() ?? $plan->table() ] ?? array() as $column ) {
				$columns[] = $column;
			}
		}
		foreach ( $predicates as $predicate ) {
			foreach ( $predicate->columns() as $column ) {
				$columns[] = $column;
			}
		}
		foreach ( $scalar_projection as $scalar ) {
			foreach ( $scalar['expression']->predicates() as $predicate ) {
				if ( ! $schema->supports_predicate( $predicate ) ) {
					return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested CASE predicate.' );
				}
			}
		}
		foreach ( $plan->order_by() as $item ) {
			if ( null !== ( $item['expression'] ?? null ) ) { $columns = array_merge( $columns, $item['expression']->columns() ); continue; }
			if ( in_array( $item['column'], array_column( $plan->aggregates(), 'alias' ), true ) ) { continue; }
			if ( null === ( $item['case'] ?? null ) ) {
				$columns[] = $item['column'];
				continue;
			}
			foreach ( $item['case']['branches'] as $branch ) {
				foreach ( $branch['predicates'] as $predicate ) {
					$columns = array_merge( $columns, $predicate->columns() );
				}
			}
		}
		foreach ( $columns as $column ) {
			if ( ! $schema->has_column( $column ) ) {
				return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested column.' );
			}
		}

		foreach ( $predicates as $predicate ) {
			if ( ! $schema->supports_predicate( $predicate ) ) {
				return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested predicate.' );
			}
		}
		$pushdown = $this->pushdown( $predicates, $schema );
		if ( array() !== $predicates
			&& null === $pushdown
			&& ! $table['provider'] instanceof WP_Markdown_Native_JSON_Partition_Provider
			&& ! $this->allows_residual_scan( $predicates, $schema, $table['provider'] instanceof WP_Markdown_Native_JSON_Snapshot_Provider )
		) {
			return $this->failure( 'unsupported_lookup', 'mdi-native requires one indexable predicate for a filtered query.' );
		}
		foreach ( $plan->order_by() as $item ) {
			if ( null !== ( $item['expression'] ?? null ) ) { continue; }
			if ( in_array( $item['column'], array_column( $plan->aggregates(), 'alias' ), true ) ) { continue; }
			if ( null !== ( $item['case'] ?? null ) ) {
				foreach ( $item['case']['branches'] as $branch ) {
					foreach ( $branch['predicates'] as $predicate ) {
						if ( ! $schema->supports_predicate( $predicate ) ) {
							return $this->failure( 'unsupported_order', 'mdi-native cannot rank rows by the requested CASE expression.' );
						}
					}
				}
				continue;
			}
			if ( null !== ( $item['field'] ?? null ) ) {
				continue;
			}
			$ranked = null !== ( $item['like'] ?? null );
			if ( $ranked && ! $schema->allows_filter( $item['column'], 'LIKE', array( $item['like'] ) ) ) {
				return $this->failure( 'unsupported_order', 'mdi-native cannot rank rows by the requested pattern.' );
			}
			if ( ! $ranked && ! $schema->allows_order( $item['column'] ) ) {
				return $this->failure( 'unsupported_order', 'mdi-native cannot apply the requested ordering collation.' );
			}
		}

		if ( 0 === $plan->limit() && ! $plan->calculates_found_rows() ) {
			return $plan->counts_all()
				? $this->count_result( 0, false )
				: $this->result( array(), $projection, $plan->table(), $schema, $scalar_projection );
		}

		$residual = $table['provider'] instanceof WP_Markdown_Native_JSON_Snapshot_Provider
			? array()
			: array_values( array_filter( $predicates, static fn( WP_Markdown_Native_Query_Predicate $predicate ): bool => $predicate !== $pushdown ) );
		$provider_projection = $plan->counts_all() ? array() : array_merge( $projection, $scalar_columns );
		// A residual predicate is matched here, after the provider read, so the
		// provider has to return the columns it reads. A provider that resolves
		// a column lazily returns it empty when it is absent from the
		// projection, and the residual then matches against that empty value.
		foreach ( $residual as $predicate ) { $provider_projection = array_merge( $provider_projection, $predicate->columns() ); }
		foreach ( $scalar_predicates as $predicate ) { $provider_projection = array_merge( $provider_projection, $predicate->columns() ); }
		if ( null !== $boolean_predicate ) { $provider_projection = array_merge( $provider_projection, $boolean_predicate->columns() ); }
		foreach ( array_merge( $plan->subqueries(), $this->boolean_subqueries( $boolean_predicate ) ) as $subquery ) {
			$provider_projection = array_merge( $provider_projection, $this->outer_correlation_columns( $subquery->query() )[ $plan->table_alias() ?? $plan->table() ] ?? array() );
		}
		if ( array() === $plan->aggregates() ) { foreach ( $plan->scalar_having() as $predicate ) { $provider_projection = array_merge( $provider_projection, $predicate->columns() ); } }
		foreach ( $plan->group_expressions() as $expression ) { $provider_projection = array_merge( $provider_projection, $expression->columns() ); }
		foreach ( $residual as $predicate ) {
			foreach ( $predicate->columns() as $column ) {
				$provider_projection[] = $column;
			}
		}
		foreach ( $plan->order_by() as $item ) {
			foreach ( $item['case']['branches'] ?? array() as $branch ) {
				foreach ( $branch['predicates'] as $predicate ) {
					$provider_projection = array_merge( $provider_projection, $predicate->columns() );
				}
			}
		}
		if ( array() === $provider_projection ) {
			$provider_projection[] = $schema->natural_order();
		}
		foreach ( $plan->aggregates() as $aggregate ) {
			$column = $aggregate['column'];
			if ( null === $column ) {
				continue;
			}
			if ( in_array( $aggregate['function'], array( 'SUM', 'AVG' ), true ) && ! $schema->is_numeric_column( $column ) ) {
				return $this->failure( 'unsupported_aggregate', 'mdi-native sums and averages numeric columns only.' );
			}
			if ( in_array( $aggregate['function'], array( 'MIN', 'MAX' ), true ) && ! $schema->is_comparable_column( $column ) ) {
				return $this->failure( 'unsupported_aggregate', 'mdi-native reports extremes over ordered columns only.' );
			}
			$provider_projection[] = $column;
		}
		$provider_projection = array_values( array_unique( $provider_projection ) );
		$order_by = array_values( array_filter( $plan->order_by(), fn( array $item ): bool => null === ( $item['expression'] ?? null ) && ! in_array( $item['column'], array_column( $plan->aggregates(), 'alias' ), true ) ) );
		if ( array() === $order_by ) {
			$order_by = array(
				array(
					'column'     => $schema->natural_order(),
					'descending' => false,
				),
			);
		}
		$provided = $this->read_provider(
			$table['provider'],
			$schema,
			new WP_Markdown_Native_Table_Access(
				$provider_projection,
				$pushdown,
				$order_by[0]['column'],
				// DISTINCT collapses rows after the source read, so a bounded
				// read would spend its bound on duplicates.
				$plan->counts_all() || $plan->calculates_found_rows() || null !== $plan->group_by() || array() !== $residual || array() !== $scalar_predicates || null !== $boolean_predicate || array() !== $plan->scalar_having() || $plan->is_distinct() || array() !== $plan->aggregates() || $has_scalar_order ? PHP_INT_MAX : $plan->limit_offset() + $plan->limit(),
				$order_by[0]['descending'],
				$order_by,
				$predicates,
				1 === $plan->limit()
					&& 0 === $plan->limit_offset()
					&& array() === $plan->order_by()
					&& ! $plan->counts_all()
					&& ! $plan->calculates_found_rows()
					&& null === $plan->group_by()
					&& ! $plan->is_distinct()
					&& array() === $plan->aggregates()
					&& array() === $plan->subqueries()
			)
		);
		if ( $provided instanceof WP_Markdown_Query_Result ) {
			return $provided;
		}
		if ( $has_scalar_order ) {
			$provided = is_array( $provided ) ? $provided : iterator_to_array( $provided );
			usort( $provided, function ( array $left, array $right ) use ( $plan, $schema ): int {
				foreach ( $plan->order_by() as $item ) {
					$comparison = null === ( $item['expression'] ?? null )
						? ( true === ( $item['numeric'] ?? false ) ? ( (float) ( $left[ $item['column'] ] ?? 0 ) <=> (float) ( $right[ $item['column'] ] ?? 0 ) ) : $schema->compare_rows( $item['column'], $left, $right ) )
						: $this->compare_scalar_values( $this->evaluate_scalar( $item['expression'], $left, $schema ), $this->evaluate_scalar( $item['expression'], $right, $schema ) );
					if ( 0 !== $comparison ) { return $item['descending'] ? -$comparison : $comparison; }
				}
				return 0;
			} );
		}
		$subqueries = array_merge( $plan->subqueries(), $this->boolean_subqueries( $boolean_predicate ) );
		$outer_alias = $plan->table_alias() ?? $plan->table();
		$outer_schemas = array( $outer_alias => $schema );
		$subquery_matchers = $this->prepare_subqueries( $subqueries, $outer_schemas );
		if ( $subquery_matchers instanceof WP_Markdown_Query_Result ) {
			return $subquery_matchers;
		}

		$rows  = array();
		$count = 0;
		$found_rows = 0;
		$matched_rows = 0;
		$groups = array();
		$seen = array();
		$distinct = $plan->is_distinct();
		$validated = $this->returns_validated_rows( $table['provider'] );
		$aggregates = $plan->aggregates();
		$aggregate_state = array();
		foreach ( $provided as $row ) {
			if ( null !== $this->correlated_subquery_failure ) {
				return $this->correlated_subquery_failure;
			}
			if ( ! $plan->counts_all() && ! $plan->calculates_found_rows() && null === $plan->group_by() && array() === $aggregates && ! $has_scalar_order && count( $rows ) >= $plan->limit() ) {
				break;
			}
			if ( ! $validated && ( ! is_array( $row ) || true !== $schema->validate_projection( $row, $provider_projection ) ) ) {
				return $this->failure( 'invalid_provider_row', 'The native table provider returned a row outside its declared schema.' );
			}
			if ( $this->matches( $row, $residual, $schema ) && $this->matches_scalar_predicates( $row, $scalar_predicates, $schema ) && $this->matches_boolean_predicate( $row, $boolean_predicate, $schema, $subquery_matchers, array( $outer_alias => $row ), $outer_schemas ) && ( array() !== $aggregates || $this->matches_scalar_predicates( $row, $plan->scalar_having(), $schema ) ) && $this->matches_subqueries( $row, $this->plain_subquery_matchers( $plan->subqueries(), $subquery_matchers ), $schema, array( $outer_alias => $row ), $outer_schemas ) ) {
				$selected = null;
				if ( $distinct && ! $plan->counts_all() ) {
					// DISTINCT resolves before the bound and before the count,
					// so a repeated row consumes neither.
					$selected = $this->string_row( $row, $projection, $scalar_projection, $schema );
					$key = serialize( $selected );
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
				}
				if ( $plan->calculates_found_rows() ) {
					++$found_rows;
				}
				if ( array() !== $aggregates ) {
					if ( null !== $plan->group_by() ) {
						$group_by = $plan->group_by();
						$expressions = $plan->group_expressions();
						$values = array() === $expressions ? array( $row[ $group_by ] ?? null ) : array_map( fn( WP_Markdown_Native_Query_Scalar_Expression $expression ): int|string|null => $this->evaluate_scalar( $expression, $row, $schema ), $expressions );
						$key = serialize( $values );
						$groups[ $key ] ??= array( 'value' => $values[0] ?? null, 'row' => $row, 'state' => array() );
						$this->accumulate_aggregates( $groups[ $key ]['state'], $row, $aggregates, $schema );
					} else {
						$this->accumulate_aggregates( $aggregate_state, $row, $aggregates, $schema );
					}
					continue;
				}
				if ( $plan->counts_all() ) {
					++$count;
				} elseif ( $matched_rows++ < $plan->limit_offset() ) {
					continue;
				} elseif ( count( $rows ) < $plan->limit() ) {
					$rows[] = $selected ?? $this->string_row( $row, $projection, $scalar_projection, $schema );
				}
			}
			if ( null !== $this->correlated_subquery_failure ) {
				return $this->correlated_subquery_failure;
			}
		}
		if ( $plan->calculates_found_rows() ) {
			$this->last_found_rows = $found_rows;
		}
		if ( array() !== $aggregates ) {
			if ( null !== $plan->group_by() ) {
				return $this->grouped_aggregate_result( $groups, $projection, $aggregates, $plan->having(), $plan->scalar_having(), $plan->table(), $schema, $plan->scalar_projection(), $plan->order_by(), $plan->limit_offset(), $plan->limit(), $plan->calculates_found_rows() );
			}
			return $this->aggregate_result( $aggregate_state, $aggregates );
		}

		return $plan->counts_all()
			? $this->count_result( $count, true )
			: $this->result( $rows, $projection, $plan->table(), $schema, $scalar_projection );
	}

	/**
	 * Fold one row into the running total of each ungrouped aggregate.
	 *
	 * @param array<int,array<string,mixed>> $state      Running totals, by aggregate.
	 * @param array<string,mixed>            $row        Source row.
	 * @param array<int,array<string,mixed>> $aggregates Declared aggregates.
	 */
	private function accumulate_aggregates( array &$state, array $row, array $aggregates, WP_Markdown_Native_Table_Schema $schema ): void {
		foreach ( $aggregates as $index => $aggregate ) {
			$current = $state[ $index ] ?? array( 'count' => 0, 'sum' => null, 'min' => null, 'max' => null, 'values' => array() );
			$column = $aggregate['column'];
			if ( null === $column && ! isset( $aggregate['expression'] ) ) {
				// COUNT(*) reports over rows, so a NULL column cannot skip one.
				++$current['count'];
				$state[ $index ] = $current;
				continue;
			}
			$value = isset( $aggregate['expression'] ) ? $this->evaluate_scalar( $aggregate['expression'], $row, $schema ) : ( $row[ $column ] ?? null );
			if ( null === $value ) {
				// SQL aggregates ignore NULL, and COUNT(column) counts values.
				$state[ $index ] = $current;
				continue;
			}
			++$current['count'];
			if ( is_numeric( $value ) ) {
				$current['sum'] = ( $current['sum'] ?? 0 ) + $value + 0;
			}
			if ( 'GROUP_CONCAT' === $aggregate['function'] ) {
				$current['values'][] = (string) $value;
			}
			if ( null !== $column && ( null === $current['min'] || 0 > ( $schema->ordered_comparison( $column, $value, $current['min'] ) ?? 0 ) ) ) {
				$current['min'] = $value;
			}
			if ( null !== $column && ( null === $current['max'] || 0 < ( $schema->ordered_comparison( $column, $value, $current['max'] ) ?? 0 ) ) ) {
				$current['max'] = $value;
			}
			$state[ $index ] = $current;
		}
	}

	/** Validate numeric expression types before reading rows, including empty sets. */
	private function is_numeric_expression( WP_Markdown_Native_Query_Scalar_Expression $expression, WP_Markdown_Native_Table_Schema $schema ): bool {
		if ( 'literal' === $expression->kind() ) {
			return null === $expression->literal() || is_int( $expression->literal() );
		}
		if ( 'column' === $expression->kind() ) {
			return $schema->is_numeric_column( $expression->column() );
		}
		if ( 'CASE' === $expression->kind() ) {
			foreach ( $expression->branches() as $branch ) {
				if ( ! $this->is_numeric_expression( $branch['value'], $schema ) ) { return false; }
			}
			return null === $expression->else() || $this->is_numeric_expression( $expression->else(), $schema );
		}
		if ( in_array( $expression->kind(), array( 'COALESCE', 'IFNULL', 'NULLIF', 'ABS', 'ROUND', 'FLOOR', 'CEIL' ), true ) ) {
			foreach ( $expression->arguments() as $argument ) {
				if ( ! $this->is_numeric_expression( $argument, $schema ) ) { return false; }
			}
			return true;
		}
		return false;
	}

	private function aggregate_result( array $state, array $aggregates ): WP_Markdown_Query_Result {
		$row = array();
		$columns = array();
		foreach ( $aggregates as $index => $aggregate ) {
			$value = $this->aggregate_value( $state[ $index ] ?? array(), $aggregate['function'] );
			$row[ $aggregate['alias'] ] = $value;
			$columns[] = array( 'name' => $aggregate['alias'], 'table' => '', 'type' => 8 );
		}
		return WP_Markdown_Query_Result::selected( array( $row ), $columns );
	}

	private function aggregate_value( array $totals, string $function ): ?string {
		$totals += array( 'count' => 0, 'sum' => null, 'min' => null, 'max' => null, 'values' => array() );
		return match ( $function ) {
			'COUNT' => (string) $totals['count'],
			'SUM' => null === $totals['sum'] ? null : (string) ( $totals['sum'] + 0 ),
			'AVG' => null === $totals['sum'] || 0 === $totals['count'] ? null : (string) ( $totals['sum'] / $totals['count'] ),
			'MIN' => null === $totals['min'] ? null : (string) $totals['min'],
			'MAX' => null === $totals['max'] ? null : (string) $totals['max'],
			default => array() === $totals['values'] ? null : implode( ',', $totals['values'] ),
		};
	}

	private function grouped_aggregate_result( array $groups, array $projection, array $aggregates, array $having, array $scalar_having, string $table, WP_Markdown_Native_Table_Schema $schema, array $scalar_projection, array $orders, int $offset, int $limit, bool $calculates_found_rows ): WP_Markdown_Query_Result {
		$rows = array();
		foreach ( $groups as $group ) {
			$row = $this->string_row( $group['row'], $projection, $scalar_projection, $schema );
			foreach ( $aggregates as $index => $aggregate ) {
				$row[ $aggregate['alias'] ] = $this->aggregate_value( $group['state'][ $index ] ?? array(), $aggregate['function'] );
			}
			if ( $this->matches_having( $row, $having ) && $this->matches_scalar_predicates( $row, $scalar_having, $schema ) ) {
				$rows[] = $row;
			}
		}
		if ( array() !== $orders ) {
			usort( $rows, function ( array $left, array $right ) use ( $orders ): int {
				foreach ( $orders as $order ) {
					$comparison = $this->compare_scalar_values( $left[ $order['column'] ] ?? null, $right[ $order['column'] ] ?? null );
					if ( 0 !== $comparison ) { return $order['descending'] ? -$comparison : $comparison; }
				}
				return 0;
			} );
		}
		if ( $calculates_found_rows ) { $this->last_found_rows = count( $rows ); }
		$rows = array_values( array_slice( $rows, $offset, PHP_INT_MAX === $limit ? null : $limit ) );
		$columns = array();
		foreach ( $projection as $column ) {
			$columns[] = array( 'name' => $column, 'table' => $table, 'type' => $schema->column( $column )->type() );
		}
		foreach ( $scalar_projection as $scalar ) {
			array_splice( $columns, $scalar['position'], 0, array( $this->scalar_projection_column( $scalar, $table, $schema ) ) );
		}
		foreach ( $aggregates as $aggregate ) { $columns[] = array( 'name' => $aggregate['alias'], 'table' => '', 'type' => 'GROUP_CONCAT' === $aggregate['function'] ? 253 : 8 ); }
		return WP_Markdown_Query_Result::selected( $rows, $columns );
	}

	private function matches_having( array $row, array $predicates ): bool {
		foreach ( $predicates as $predicate ) {
			$value = $row[ $predicate->column() ] ?? null;
			$target = $predicate->values()[0] ?? null;
			if ( null === $value || null === $target || ! is_numeric( $value ) || ! is_numeric( $target ) ) { return false; }
			$comparison = ( $value + 0 ) <=> ( $target + 0 );
			if ( ! match ( $predicate->operator() ) { '=' => 0 === $comparison, '<>' => 0 !== $comparison, '<' => $comparison < 0, '<=' => $comparison <= 0, '>' => $comparison > 0, default => $comparison >= 0 } ) { return false; }
		}
		return true;
	}

	/** Scalar conditions deliberately run after the schema-bounded read. */
	private function matches_scalar_predicates( array $row, array $predicates, WP_Markdown_Native_Table_Schema $schema ): bool {
		foreach ( $predicates as $predicate ) {
			$left = $this->evaluate_scalar( $predicate->left(), $row, $schema );
			$right = $this->evaluate_scalar( $predicate->right(), $row, $schema );
			if ( null === $left || null === $right ) { return false; }
			$comparison = $this->compare_scalar_values( $left, $right );
			if ( ! match ( $predicate->operator() ) { '=' => 0 === $comparison, '<>' => 0 !== $comparison, '<' => $comparison < 0, '<=' => $comparison <= 0, '>' => $comparison > 0, default => $comparison >= 0 } ) { return false; }
		}
		return true;
	}

	private function matches_boolean_predicate( array $row, ?WP_Markdown_Native_Query_Boolean_Predicate $predicate, WP_Markdown_Native_Table_Schema $schema, array $subquery_matchers = array(), array $outer_rows = array(), array $outer_schemas = array() ): bool {
		if ( null === $predicate ) { return true; }
		foreach ( $predicate->groups() as $group ) {
			$matches = true;
			foreach ( $group as $term ) {
				$matches = $matches && ( $term instanceof WP_Markdown_Native_Query_Scalar_Predicate
					? $this->matches_scalar_predicates( $row, array( $term ), $schema )
					: ( $term instanceof WP_Markdown_Native_Query_Subquery
						? isset( $subquery_matchers[ spl_object_id( $term ) ] ) && $this->matches_subqueries( $row, array( $subquery_matchers[ spl_object_id( $term ) ] ), $schema, $outer_rows, $outer_schemas )
						: $this->matches( $row, array( $term ), $schema ) ) );
			}
			if ( $matches ) { return true; }
		}
		return false;
	}

	/** @return array<int,WP_Markdown_Native_Query_Subquery> */
	private function boolean_subqueries( ?WP_Markdown_Native_Query_Boolean_Predicate $predicate ): array {
		if ( null === $predicate ) { return array(); }
		$subqueries = array();
		foreach ( $predicate->groups() as $group ) {
			foreach ( $group as $term ) { if ( $term instanceof WP_Markdown_Native_Query_Subquery ) { $subqueries[] = $term; } }
		}
		return $subqueries;
	}

	/** @param array<int,WP_Markdown_Native_Query_Subquery> $subqueries @param array<int,array<string,mixed>> $matchers */
	private function plain_subquery_matchers( array $subqueries, array $matchers ): array {
		return array_values( array_filter( $matchers, static fn( array $matcher ): bool => ! isset( $matcher['term'] ) || ! $matcher['term'] instanceof WP_Markdown_Native_Query_Subquery || in_array( $matcher['term'], $subqueries, true ) ) );
	}

	private function compare_scalar_values( int|string|null $left, int|string|null $right ): int {
		if ( null === $left || null === $right ) { return 0; }
		return is_numeric( $left ) && is_numeric( $right ) ? ( $left + 0 <=> $right + 0 ) : strcmp( (string) $left, (string) $right );
	}

	public function last_found_rows(): ?int {
		return $this->last_found_rows;
	}

	/** Materialize uncorrelated subqueries once; correlated plans retain typed outer bindings. */
	/** @param array<string,WP_Markdown_Native_Table_Schema> $outer_schemas */
	private function prepare_subqueries( array $subqueries, array $outer_schemas ): array|WP_Markdown_Query_Result {
		$matchers = array();
		foreach ( $subqueries as $subquery ) {
			$outer_source = $subquery->source() ?? array_key_first( $outer_schemas );
			$outer_schema = $outer_schemas[ $outer_source ] ?? null;
			if ( null === $outer_schema ) {
				return $this->failure( 'unsupported_subquery_shape', 'mdi-native cannot resolve the requested outer subquery source.' );
			}
			$query = $subquery->query();
			if ( $this->has_nested_outer_correlation( $query ) ) {
				return $this->failure( 'unsupported_subquery_correlation', 'mdi-native cannot bind the requested nested correlated subquery expression.' );
			}
			if ( ! $this->has_outer_correlation( $query ) && ! $this->has_outer_scalar_reference( $query ) ) {
				$matcher = $this->materialize_subquery_plan( $subquery, $outer_schema );
				if ( $matcher instanceof WP_Markdown_Query_Result ) {
					return $matcher;
				}
				$matchers[ spl_object_id( $subquery ) ] = $matcher;
				continue;
			}
			$matchers[ spl_object_id( $subquery ) ] = array( 'operator' => $subquery->operator(), 'column' => $subquery->column(), 'query' => $query, 'outer_schemas' => $outer_schemas, 'cache' => array(), 'evaluations' => 0, 'term' => $subquery );
		}
		return $matchers;
	}

	/** Use the normal plan executor for uncorrelated subqueries of every supported shape. */
	private function materialize_subquery_plan( WP_Markdown_Native_Query_Subquery $subquery, WP_Markdown_Native_Table_Schema $outer_schema ): array|WP_Markdown_Query_Result {
		$result = $this->execute_query_plan( $subquery->query() );
		if ( false === $result->return_value() ) {
			return $result;
		}
		$columns = $result->wpdb_state()['col_info'] ?? array();
		if ( 'EXISTS' !== $subquery->operator() && 1 !== count( $columns ) ) {
			return $this->failure( 'unsupported_subquery_shape', 'mdi-native IN subqueries must project exactly one column.' );
		}
		$values = array();
		$has_null = false;
		foreach ( $result->wpdb_state()['last_result'] ?? array() as $row ) {
			if ( 'EXISTS' === $subquery->operator() ) {
				$values['exists'] = true;
				break;
			}
			$value = get_object_vars( $row )[ $columns[0]->name ] ?? null;
			if ( null === $value ) {
				$has_null = true;
				continue;
			}
			if ( is_numeric( $value ) && in_array( $outer_schema->column( (string) $subquery->column() )->type(), array( 1, 2, 3, 4, 5, 8, 9, 246 ), true ) ) {
				$value += 0;
			}
			$key = $outer_schema->value_key( (string) $subquery->column(), $value );
			if ( null === $key ) {
				return $this->failure( 'unsupported_subquery_type', 'mdi-native cannot compare incompatible subquery values.' );
			}
			$values[ $key ] = true;
		}
		return array( 'operator' => $subquery->operator(), 'column' => $subquery->column(), 'values' => $values, 'has_null' => $has_null, 'correlation' => null, 'term' => $subquery );
	}

	/** A comparison source outside this plan's aliases is lexically enclosing. */
	private function has_outer_correlation( WP_Markdown_Native_Query_Plan $query ): bool {
		$sources = array_fill_keys( array_filter( array_merge( array( $query->table_alias() ?? $query->table() ), array_map( static fn( WP_Markdown_Native_Query_Join $join ): string => $join->alias(), $query->joins() ) ) ), true );
		$has_outer = function ( WP_Markdown_Native_Query_Predicate $predicate ) use ( $sources, &$has_outer ): bool {
			if ( null !== $predicate->comparison_column() && ! isset( $sources[ $predicate->comparison_source() ] ) ) {
				return true;
			}
			foreach ( $predicate->any() as $nested ) {
				if ( $has_outer( $nested ) ) {
					return true;
				}
			}
			return false;
		};
		foreach ( $query->predicates() as $predicate ) {
			if ( $has_outer( $predicate ) ) {
				return true;
			}
		}
		if ( null !== $query->boolean_predicate() ) {
			foreach ( $query->boolean_predicate()->groups() as $group ) {
				foreach ( $group as $term ) {
					if ( $term instanceof WP_Markdown_Native_Query_Predicate && $has_outer( $term ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/** Whether a plan's scalar expressions refer beyond its active lexical aliases. */
	private function has_outer_scalar_reference( WP_Markdown_Native_Query_Plan $query ): bool {
		$sources = array_fill_keys( array_merge( array( $query->table_alias() ?? $query->table() ), array_map( static fn( WP_Markdown_Native_Query_Join $join ): string => $join->alias(), $query->joins() ) ), true );
		$has_outer = function ( WP_Markdown_Native_Query_Scalar_Expression $expression ) use ( $sources, &$has_outer ): bool {
			if ( null !== $expression->source() && ! isset( $sources[ $expression->source() ] ) ) {
				return true;
			}
			foreach ( $expression->arguments() as $argument ) {
				if ( $has_outer( $argument ) ) { return true; }
			}
			foreach ( $expression->branches() as $branch ) {
				if ( $has_outer( $branch['value'] ) ) { return true; }
			}
			return null !== $expression->else() && $has_outer( $expression->else() );
		};
		foreach ( $query->scalar_projection() as $scalar ) { if ( $has_outer( $scalar['expression'] ) ) { return true; } }
		foreach ( array_merge( $query->scalar_predicates(), $query->scalar_having() ) as $predicate ) {
			if ( $has_outer( $predicate->left() ) || $has_outer( $predicate->right() ) ) { return true; }
		}
		foreach ( $query->group_expressions() as $expression ) { if ( $has_outer( $expression ) ) { return true; } }
		foreach ( $query->order_by() as $order ) { if ( null !== ( $order['expression'] ?? null ) && $has_outer( $order['expression'] ) ) { return true; } }
		if ( null !== $query->boolean_predicate() ) {
			foreach ( $query->boolean_predicate()->groups() as $group ) {
				foreach ( $group as $term ) {
					if ( $term instanceof WP_Markdown_Native_Query_Scalar_Predicate && ( $has_outer( $term->left() ) || $has_outer( $term->right() ) ) ) { return true; }
				}
			}
		}
		return false;
	}

	/** Nested child plans have an additional lexical scope and are rejected before execution. */
	private function has_nested_outer_correlation( WP_Markdown_Native_Query_Plan $query ): bool {
		$nested = $query->subqueries();
		if ( null !== $query->boolean_predicate() ) {
			foreach ( $query->boolean_predicate()->groups() as $group ) {
				foreach ( $group as $term ) { if ( $term instanceof WP_Markdown_Native_Query_Subquery ) { $nested[] = $term; } }
			}
		}
		foreach ( $nested as $term ) {
			if ( $this->has_outer_correlation( $term->query() ) || $this->has_outer_scalar_reference( $term->query() ) || $this->has_nested_outer_correlation( $term->query() ) ) {
				return true;
			}
		}
		return false;
	}

	private function matches_subqueries( array $row, array $matchers, WP_Markdown_Native_Table_Schema $schema, array $outer_rows = array(), array $outer_schemas = array() ): bool {
		foreach ( $matchers as $matcher ) {
			if ( isset( $matcher['query'] ) ) {
				if ( ! $this->matches_correlated_subquery( $row, $matcher, $schema, $outer_rows, $outer_schemas ) ) { return false; }
				continue;
			}
			if ( 'EXISTS' === $matcher['operator'] ) {
				if ( null === $matcher['correlation'] ) { if ( ! isset( $matcher['values']['exists'] ) ) { return false; } continue; }
				$column = $matcher['correlation']->comparison_column();
				if ( null === $column || ! $schema->has_column( $column ) ) { return false; }
				$key = $schema->value_key( $column, $row[ $column ] ?? null );
				$exists = null !== $key && isset( $matcher['values'][ $key ] );
				if ( ( 'EXISTS' === $matcher['operator'] && ! $exists ) || ( 'NOT EXISTS' === $matcher['operator'] && $exists ) ) { return false; }
				continue;
			}
			$key = $schema->value_key( (string) $matcher['column'], $row[ $matcher['column'] ] ?? null );
			$matched = null !== $key && isset( $matcher['values'][ $key ] );
			if ( 'NOT IN' === $matcher['operator'] ) {
				// A NULL on either side makes a non-match unknown, so WHERE retains
				// only non-NULL outer values absent from a NULL-free subquery set.
				if ( null === $key || $matched || $matcher['has_null'] ) {
					return false;
				}
				continue;
			}
			// SQL IN with NULL is unknown when no non-NULL member matches, and a
			// WHERE clause retains only true predicates.
			if ( ! $matched ) {
				return false;
			}
		}
		return true;
	}

	/** Execute a lexically-bound child plan and cache its typed materialization by outer row. */
	private function matches_correlated_subquery( array $row, array $matcher, WP_Markdown_Native_Table_Schema $schema, array $outer_rows, array $outer_schemas ): bool {
		if ( null !== $this->correlated_subquery_failure ) {
			return false;
		}
		$term = $matcher['term'];
		$source = $term->source() ?? array_key_first( $outer_rows );
		if ( null === $source || ! isset( $outer_rows[ $source ], $outer_schemas[ $source ] ) ) {
			$this->correlated_subquery_failure = $this->failure( 'unsupported_subquery_correlation', 'mdi-native cannot resolve the requested correlated outer source.' );
			return false;
		}
		$outer_rows[ $source ] = $row;
		$outer_schemas[ $source ] = $schema;
		$key = spl_object_id( $term ) . ':' . serialize( $outer_rows );
		if ( ! isset( $this->correlated_subquery_cache[ $key ] ) ) {
			if ( count( $this->correlated_subquery_cache ) >= $this->correlated_subquery_limit ) {
				$this->correlated_subquery_failure = $this->failure( 'correlated_subquery_cost', 'mdi-native cannot evaluate the requested correlated subquery within its bounded work budget.' );
				return false;
			}
			$bound = $this->bind_outer_plan( $matcher['query'], $outer_rows, $outer_schemas );
			if ( null === $bound ) {
				$this->correlated_subquery_failure = $this->failure( 'unsupported_subquery_correlation', 'mdi-native cannot bind the requested correlated subquery.' );
				return false;
			}
			$result = $this->execute_query_plan( $bound );
			if ( false === $result->return_value() ) {
				$this->correlated_subquery_failure = $result;
				return false;
			}
			$columns = $result->wpdb_state()['col_info'] ?? array();
			if ( ! in_array( $matcher['operator'], array( 'EXISTS', 'NOT EXISTS' ), true ) && 1 !== count( $columns ) ) {
				$this->correlated_subquery_failure = $this->failure( 'unsupported_subquery_shape', 'mdi-native IN subqueries must project exactly one column.' );
				return false;
			}
			$values = array();
			$has_null = false;
			foreach ( $result->wpdb_state()['last_result'] ?? array() as $result_row ) {
				if ( in_array( $matcher['operator'], array( 'EXISTS', 'NOT EXISTS' ), true ) ) { $values['exists'] = true; break; }
				$value = get_object_vars( $result_row )[ $columns[0]->name ] ?? null;
				if ( null === $value ) { $has_null = true; continue; }
				$typed = is_numeric( $value ) && in_array( $schema->column( (string) $matcher['column'] )->type(), array( 1, 2, 3, 4, 5, 8, 9, 246 ), true ) ? $value + 0 : $value;
				$value_key = $schema->value_key( (string) $matcher['column'], $typed );
				if ( null === $value_key ) {
					$this->correlated_subquery_failure = $this->failure( 'unsupported_subquery_type', 'mdi-native cannot compare incompatible correlated subquery values.' );
					return false;
				}
				$values[ $value_key ] = true;
			}
			$this->correlated_subquery_cache[ $key ] = array( 'values' => $values, 'has_null' => $has_null );
		}
		$materialized = $this->correlated_subquery_cache[ $key ];
		if ( in_array( $matcher['operator'], array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
			$exists = isset( $materialized['values']['exists'] );
			return 'EXISTS' === $matcher['operator'] ? $exists : ! $exists;
		}
		$row_key = $schema->value_key( (string) $matcher['column'], $row[ $matcher['column'] ] ?? null );
		$matched = null !== $row_key && isset( $materialized['values'][ $row_key ] );
		return 'NOT IN' === $matcher['operator']
			? null !== $row_key && ! $matched && ! $materialized['has_null']
			: $matched;
	}

	/** Bind outer aliases into predicate values without reserializing SQL. */
	private function bind_outer_plan( WP_Markdown_Native_Query_Plan $plan, array $outer_rows, array $outer_schemas ): ?WP_Markdown_Native_Query_Plan {
		$local_sources = array_fill_keys( array_merge( array( $plan->table_alias() ?? $plan->table() ), array_map( static fn( WP_Markdown_Native_Query_Join $join ): string => $join->alias(), $plan->joins() ) ), true );
		$single_source = array() === $plan->joins() ? ( $plan->table_alias() ?? $plan->table() ) : null;
		$bind = null;
		$bind_scalar = null;
		$bind_scalar_predicate = null;
		$bind = function ( WP_Markdown_Native_Query_Predicate $predicate ) use ( $outer_rows, $outer_schemas, $local_sources, &$bind ): ?WP_Markdown_Native_Query_Predicate {
			$any = array();
			foreach ( $predicate->any() as $nested ) { $bound = $bind( $nested ); if ( null === $bound ) { return null; } $any[] = $bound; }
			$source = $predicate->comparison_source();
			if ( null === $predicate->comparison_column() || null === $source || isset( $local_sources[ $source ] ) ) {
				return new WP_Markdown_Native_Query_Predicate( $predicate->column(), $predicate->operator(), $predicate->values(), $predicate->source(), $any, $predicate->cast(), $predicate->comparison_column(), $predicate->comparison_source() );
			}
			if ( ! isset( $outer_rows[ $source ], $outer_schemas[ $source ] ) ) { return null; }
			$value = $outer_rows[ $source ][ $predicate->comparison_column() ] ?? null;
			if ( null === $value ) {
				return new WP_Markdown_Native_Query_Predicate( $predicate->column(), 'FALSE', array(), $predicate->source() );
			}
			return new WP_Markdown_Native_Query_Predicate( $predicate->column(), $predicate->operator(), array( $value ), $predicate->source(), $any, $predicate->cast() );
		};
		$bind_scalar = function ( WP_Markdown_Native_Query_Scalar_Expression $expression ) use ( $outer_rows, $outer_schemas, $local_sources, $single_source, &$bind, &$bind_scalar ): ?WP_Markdown_Native_Query_Scalar_Expression {
			$arguments = array();
			foreach ( $expression->arguments() as $argument ) { $bound = $bind_scalar( $argument ); if ( null === $bound ) { return null; } $arguments[] = $bound; }
			$branches = array();
			foreach ( $expression->branches() as $branch ) {
				$predicates = array();
				foreach ( $branch['predicates'] as $predicate ) { $bound = $bind( $predicate ); if ( null === $bound ) { return null; } $predicates[] = $bound; }
				$value = $bind_scalar( $branch['value'] );
				if ( null === $value ) { return null; }
				$branches[] = array( 'predicates' => $predicates, 'value' => $value );
			}
			$else = null === $expression->else() ? null : $bind_scalar( $expression->else() );
			if ( null !== $expression->else() && null === $else ) { return null; }
			$source = $expression->source();
			if ( 'column' === $expression->kind() && null !== $source && ! isset( $local_sources[ $source ] ) ) {
				if ( ! isset( $outer_rows[ $source ], $outer_schemas[ $source ] ) || ! $outer_schemas[ $source ]->has_column( (string) $expression->column() ) ) { return null; }
				return new WP_Markdown_Native_Query_Scalar_Expression( 'literal', null, $outer_rows[ $source ][ (string) $expression->column() ] ?? null, $arguments, $branches, $else );
			}
			return new WP_Markdown_Native_Query_Scalar_Expression( $expression->kind(), $expression->column(), $expression->literal(), $arguments, $branches, $else, $source === $single_source ? null : $source );
		};
		$bind_scalar_predicate = function ( WP_Markdown_Native_Query_Scalar_Predicate $predicate ) use ( &$bind_scalar ): ?WP_Markdown_Native_Query_Scalar_Predicate {
			$left = $bind_scalar( $predicate->left() );
			$right = $bind_scalar( $predicate->right() );
			return null === $left || null === $right ? null : new WP_Markdown_Native_Query_Scalar_Predicate( $left, $predicate->operator(), $right );
		};
		$predicates = array();
		foreach ( $plan->predicates() as $predicate ) { $bound = $bind( $predicate ); if ( null === $bound ) { return null; } $predicates[] = $bound; }
		$boolean = null;
		if ( null !== $plan->boolean_predicate() ) {
			$groups = array();
			foreach ( $plan->boolean_predicate()->groups() as $group ) {
				$bound_group = array();
				foreach ( $group as $term ) {
					if ( $term instanceof WP_Markdown_Native_Query_Subquery ) { $bound_group[] = $term; continue; }
					$bound = $term instanceof WP_Markdown_Native_Query_Predicate ? $bind( $term ) : $bind_scalar_predicate( $term );
					if ( null === $bound ) { return null; } $bound_group[] = $bound;
				}
				$groups[] = $bound_group;
			}
			$boolean = new WP_Markdown_Native_Query_Boolean_Predicate( $groups );
		}
		$scalar_projection = array();
		foreach ( $plan->scalar_projection() as $scalar ) { $expression = $bind_scalar( $scalar['expression'] ); if ( null === $expression ) { return null; } $scalar_projection[] = array( 'expression' => $expression, 'alias' => $scalar['alias'], 'position' => $scalar['position'] ); }
		$scalar_predicates = array();
		foreach ( $plan->scalar_predicates() as $predicate ) { $bound = $bind_scalar_predicate( $predicate ); if ( null === $bound ) { return null; } $scalar_predicates[] = $bound; }
		$scalar_having = array();
		foreach ( $plan->scalar_having() as $predicate ) { $bound = $bind_scalar_predicate( $predicate ); if ( null === $bound ) { return null; } $scalar_having[] = $bound; }
		$group_expression = null === $plan->group_expression() ? null : $bind_scalar( $plan->group_expression() );
		if ( null !== $plan->group_expression() && null === $group_expression ) { return null; }
		$group_expressions = array();
		foreach ( $plan->group_expressions() as $expression ) { $bound = $bind_scalar( $expression ); if ( null === $bound ) { return null; } $group_expressions[] = $bound; }
		$orders = array();
		foreach ( $plan->order_by() as $order ) { if ( null !== ( $order['expression'] ?? null ) ) { $bound = $bind_scalar( $order['expression'] ); if ( null === $bound ) { return null; } $order['expression'] = $bound; } $orders[] = $order; }
		return new WP_Markdown_Native_Query_Plan( $plan->table(), $plan->projection(), $predicates, $plan->order(), $plan->limit(), $plan->counts_all(), $plan->table_alias(), $plan->projection_sources(), $plan->joins(), $plan->calculates_found_rows(), $plan->order_descending(), $plan->limit_offset(), $plan->is_distinct(), $plan->order_source(), $orders, $plan->is_unsatisfiable(), $plan->group_by(), $plan->aggregates(), $scalar_projection, $plan->having(), $plan->subqueries(), $plan->union(), $scalar_predicates, $scalar_having, $group_expression, $boolean, $plan->derived(), $plan->union_all(), $plan->union_order_by(), $plan->union_limit(), $plan->union_limit_offset(), $group_expressions );
	}

	private function execute_union( WP_Markdown_Native_Query_Plan $plan ): WP_Markdown_Query_Result {
		$branches = array( $plan );
		while ( null !== $branches[ count( $branches ) - 1 ]->union() ) { $branches[] = $branches[ count( $branches ) - 1 ]->union(); }
		$rows = array();
		$columns = null;
		$seen = array();
		foreach ( $branches as $branch_index => $branch ) {
			if ( 0 < $branch_index && ! $branches[ $branch_index - 1 ]->union_all() ) {
				// UNION DISTINCT de-duplicates its complete left-hand result, including
				// duplicates retained by any preceding UNION ALL.
				$rows = array_values( array_reduce( $rows, static function ( array $unique, array $row ): array {
					$unique[ serialize( array_values( $row ) ) ] = $row;
					return $unique;
				}, array() ) );
				$seen = array_fill_keys( array_map( static fn( array $row ): string => serialize( array_values( $row ) ), $rows ), true );
			}
			if ( array() === $branch->joins() && array() === $branch->subqueries() && array() === $branch->aggregates() && null === $branch->group_by() && array() === $branch->scalar_projection() && array() === $branch->scalar_predicates() && null === $branch->boolean_predicate() && array() === $branch->scalar_having() && ! $branch->counts_all() && null === $branch->derived() && array() === $branch->order_by() && PHP_INT_MAX === $branch->limit() && 0 === $branch->limit_offset() ) {
				// Keep simple UNION branches on the direct path: unlike a top-level
				// query, they have always supported bounded in-memory filtering.
				$table = $this->registry->table( $branch->table() );
				if ( null === $table ) { return $this->failure( 'unsupported_table', 'mdi-native cannot query the requested UNION table.' ); }
				$schema = $table['schema'];
				$projection = array( '*' ) === $branch->projection() ? $schema->column_names() : $branch->projection();
				foreach ( $projection as $column ) { if ( ! $schema->has_column( $column ) ) { return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested UNION column.' ); } }
				$types = array_map( static fn( string $column ): int => $schema->column( $column )->type(), $projection );
				if ( null === $columns ) { $columns = array_map( fn( string $column ): array => array( 'name' => $column, 'table' => $branch->table(), 'type' => $schema->column( $column )->type() ), $projection ); }
				elseif ( count( $columns ) !== count( $projection ) || $types !== array_column( $columns, 'type' ) ) { return $this->failure( 'unsupported_union_type', 'mdi-native UNION requires compatible projection types.' ); }
				$provided = $this->read_provider( $table['provider'], $schema, new WP_Markdown_Native_Table_Access( array_values( array_unique( array_merge( $projection, ...array_map( static fn( WP_Markdown_Native_Query_Predicate $p ): array => $p->columns(), $branch->predicates() ) ) ) ), null, $schema->natural_order(), PHP_INT_MAX ) );
				if ( $provided instanceof WP_Markdown_Query_Result ) { return $provided; }
				foreach ( $provided as $row ) {
					if ( ! is_array( $row ) || ! $this->matches( $row, $branch->predicates(), $schema ) ) { continue; }
					$selected = $this->union_row( $this->string_row( $row, $projection, array(), $schema ), $columns ?? array() );
					$key = serialize( array_values( $selected ) );
					if ( 0 === $branch_index || $branches[ $branch_index - 1 ]->union_all() || ! isset( $seen[ $key ] ) ) { $seen[ $key ] = true; $rows[] = $selected; }
				}
				continue;
			}
			$result = $this->execute_query_plan( $branch, false );
			if ( false === $result->return_value() ) {
				return $result;
			}
			$branch_columns = $result->wpdb_state()['col_info'] ?? array();
			if ( null === $columns ) {
				$columns = array_map( static fn( object $column ): array => array( 'name' => $column->name, 'table' => $column->table, 'type' => $column->type ), $branch_columns );
			} elseif ( count( $columns ) !== count( $branch_columns ) || array_column( $columns, 'type' ) !== array_map( static fn( object $column ): mixed => $column->type, $branch_columns ) ) {
				return $this->failure( 'unsupported_union_type', 'mdi-native UNION requires compatible projection types.' );
			}
			foreach ( $result->wpdb_state()['last_result'] ?? array() as $row ) {
				$selected = $this->union_row( get_object_vars( $row ), $columns ?? array() );
				$key = serialize( array_values( $selected ) );
				if ( 0 === $branch_index || $branches[ $branch_index - 1 ]->union_all() || ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$rows[] = $selected;
				}
			}
		}
		$orders = $plan->union_order_by();
		foreach ( $orders as $order ) {
			$column = $order['column'];
			if ( str_starts_with( $column, '__union_ordinal_' ) ) {
				$position = (int) substr( $column, strlen( '__union_ordinal_' ) ) - 1;
				if ( ! isset( $columns[ $position ] ) ) { return $this->failure( 'unsupported_union_order', 'mdi-native UNION ORDER BY ordinal must name a projected column.' ); }
				$orders[ array_search( $order, $orders, true ) ]['column'] = $columns[ $position ]['name'];
				continue;
			}
			if ( ! in_array( $column, array_column( $columns ?? array(), 'name' ), true ) ) { return $this->failure( 'unsupported_union_order', 'mdi-native UNION ORDER BY must name a first-branch output column.' ); }
		}
		if ( array() !== $orders ) {
			usort( $rows, function ( array $left, array $right ) use ( $orders, $columns ): int {
				foreach ( $orders as $order ) {
					$left_value = $left[ $order['column'] ] ?? null;
					$right_value = $right[ $order['column'] ] ?? null;
					if ( null === $left_value && null === $right_value ) { continue; }
					if ( null === $left_value ) { return $order['descending'] ? 1 : -1; }
					if ( null === $right_value ) { return $order['descending'] ? -1 : 1; }
					$type = $columns[ array_search( $order['column'], array_column( $columns, 'name' ), true ) ]['type'] ?? 253;
					$comparison = in_array( $type, array( 1, 2, 3, 4, 5, 8, 9, 246 ), true ) ? ( $left_value + 0 <=> $right_value + 0 ) : strcmp( (string) $left_value, (string) $right_value );
					if ( 0 !== $comparison ) { return $order['descending'] ? -$comparison : $comparison; }
				}
				return 0;
			} );
		}
		if ( $plan->calculates_found_rows() ) { $this->last_found_rows = count( $rows ); }
		if ( null !== $plan->union_limit() ) { $rows = array_values( array_slice( $rows, $plan->union_limit_offset(), $plan->union_limit() ) ); }
		return WP_Markdown_Query_Result::selected( $rows, $columns ?? array() );
	}

	/** Align later UNION branches to first-branch result names by position. */
	private function union_row( array $row, array $columns ): array {
		if ( array() === $columns ) { return $row; }
		$values = array_values( $row );
		$normalized = array();
		foreach ( $columns as $index => $column ) { $normalized[ $column['name'] ] = $values[ $index ] ?? null; }
		return $normalized;
	}

	private function execute_join( WP_Markdown_Native_Query_Plan $plan ): WP_Markdown_Query_Result {
		$base_alias = $plan->table_alias();
		$base = null === $plan->derived()
			? $this->registry->table( $plan->table() )
			: $this->derived_source( $plan->derived(), $plan->table() );
		if ( $base instanceof WP_Markdown_Query_Result ) {
			return $base;
		}
		if ( null === $base_alias || null === $base ) {
			return $this->failure( 'unsupported_join_shape', 'mdi-native requires a registered JOIN source.' );
		}

		$sources = array(
			$base_alias => array( 'table' => $plan->table(), 'schema' => $base['schema'], 'provider' => $base['provider'] ),
		);
		foreach ( $plan->joins() as $join ) {
			$table = null === $join->derived()
				? $this->registry->table( $join->table() )
				: $this->derived_source( $join->derived(), $join->table() );
			if ( $table instanceof WP_Markdown_Query_Result ) {
				return $table;
			}
			if ( null === $table || isset( $sources[ $join->alias() ] ) ) {
				return $this->failure( 'unsupported_table', 'mdi-native cannot query the requested JOIN table.' );
			}
			$sources[ $join->alias() ] = array( 'table' => $join->table(), 'schema' => $table['schema'], 'provider' => $table['provider'] );
		}

		$needed = array_fill_keys( array_keys( $sources ), array() );
		$projection = array();
		$projection_sources = array();
		foreach ( $plan->projection() as $index => $column ) {
			$source = $plan->projection_sources()[ $index ] ?? null;
			if ( null === $source || ! isset( $sources[ $source ] ) ) {
				return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested qualified column.' );
			}
			$columns = '*' === $column ? $sources[ $source ]['schema']->column_names() : array( $column );
			foreach ( $columns as $expanded ) {
				if ( ! $sources[ $source ]['schema']->has_column( $expanded ) ) {
					return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested qualified column.' );
				}
				$projection[] = $expanded;
				$projection_sources[] = $source;
				$needed[ $source ][] = $expanded;
			}
		}
		foreach ( $plan->scalar_projection() as $scalar ) {
			if ( ! $this->add_join_scalar_columns( $scalar['expression'], $sources, $needed ) ) {
				return $this->failure( 'unsupported_column', 'mdi-native cannot evaluate the requested JOIN scalar projection.' );
			}
		}
		$predicates = array_fill_keys( array_keys( $sources ), array() );
		foreach ( $plan->predicates() as $predicate ) {
			$source = $predicate->source();
			if ( null === $source || ! isset( $sources[ $source ] )
				|| ! $sources[ $source ]['schema']->has_column( $predicate->column() )
				|| ( null === $predicate->comparison_column() && ! $sources[ $source ]['schema']->supports_predicate( $predicate ) )
				|| ( null !== $predicate->comparison_column() && ( null === $predicate->comparison_source() || ! isset( $sources[ $predicate->comparison_source() ] ) || ! $sources[ $predicate->comparison_source() ]['schema']->has_column( $predicate->comparison_column() ) ) )
			) {
				return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested JOIN predicate.' );
			}
			if ( null === $predicate->comparison_column() ) {
				$predicates[ $source ][] = $predicate;
			}
			$needed[ $source ][] = $predicate->column();
			if ( null !== $predicate->comparison_column() ) {
				$needed[ $predicate->comparison_source() ][] = $predicate->comparison_column();
			}
		}
		foreach ( $plan->scalar_predicates() as $predicate ) {
			if ( ! $this->add_join_scalar_columns( $predicate->left(), $sources, $needed ) || ! $this->add_join_scalar_columns( $predicate->right(), $sources, $needed ) ) {
				return $this->failure( 'unsupported_column', 'mdi-native cannot evaluate the requested JOIN scalar predicate.' );
			}
		}
		if ( null !== $plan->boolean_predicate() ) {
			foreach ( $plan->boolean_predicate()->groups() as $group ) {
				foreach ( $group as $term ) {
					if ( $term instanceof WP_Markdown_Native_Query_Scalar_Predicate ) {
						if ( ! $this->add_join_scalar_columns( $term->left(), $sources, $needed ) || ! $this->add_join_scalar_columns( $term->right(), $sources, $needed ) ) {
							return $this->failure( 'unsupported_column', 'mdi-native cannot evaluate the requested JOIN scalar predicate.' );
						}
					} elseif ( $term instanceof WP_Markdown_Native_Query_Subquery ) {
						$source = $term->source() ?? $base_alias;
						if ( null !== $term->column() && ( ! isset( $sources[ $source ] ) || ! $sources[ $source ]['schema']->has_column( $term->column() ) ) ) {
							return $this->failure( 'unsupported_column', 'mdi-native cannot evaluate the requested JOIN subquery predicate.' );
						}
						if ( null !== $term->column() ) { $needed[ $source ][] = $term->column(); }
						foreach ( $this->outer_correlation_columns( $term->query() ) as $correlation_source => $correlation_columns ) {
							foreach ( $correlation_columns as $correlation_column ) {
								if ( ! isset( $sources[ $correlation_source ] ) || ! $sources[ $correlation_source ]['schema']->has_column( $correlation_column ) ) {
									return $this->failure( 'unsupported_column', 'mdi-native cannot load the requested correlated JOIN column.' );
								}
								$needed[ $correlation_source ][] = $correlation_column;
							}
						}
					} elseif ( ! $this->add_join_predicate_columns( $term, $sources, $needed ) ) {
						return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested JOIN predicate.' );
					}
				}
			}
		}
		foreach ( $plan->subqueries() as $subquery ) {
			foreach ( $this->outer_correlation_columns( $subquery->query() ) as $correlation_source => $correlation_columns ) {
				foreach ( $correlation_columns as $correlation_column ) {
					if ( ! isset( $sources[ $correlation_source ] ) || ! $sources[ $correlation_source ]['schema']->has_column( $correlation_column ) ) {
						return $this->failure( 'unsupported_column', 'mdi-native cannot load the requested correlated JOIN column.' );
					}
					$needed[ $correlation_source ][] = $correlation_column;
				}
			}
		}
		foreach ( $plan->aggregates() as $aggregate ) {
			if ( null === $aggregate['column'] ) {
				if ( isset( $aggregate['expression'] ) ) {
					return $this->failure( 'unsupported_aggregate', 'mdi-native does not yet aggregate scalar expressions across joins.' );
				}
				continue;
			}
			$aggregate_source = (string) $aggregate['source'];
			if ( ! isset( $sources[ $aggregate_source ] ) || ! $sources[ $aggregate_source ]['schema']->has_column( $aggregate['column'] ) ) {
				return $this->failure( 'unsupported_column', 'mdi-native cannot aggregate the requested qualified column.' );
			}
			$needed[ $aggregate_source ][] = $aggregate['column'];
		}
		foreach ( $plan->joins() as $join ) {
			foreach ( $join->on_filters() as $filter ) {
				if ( ! $this->add_join_predicate_columns( $filter, $sources, $needed ) ) {
					return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested JOIN ON predicate.' );
				}
			}
		}
		foreach ( $plan->order_by() as $item ) {
			$order_source = $item['source'] ?? $plan->order_source();
			$numeric = true === ( $item['numeric'] ?? false );
			if ( null === $order_source && in_array( $item['column'], array_column( $plan->aggregates(), 'alias' ), true ) ) {
				continue;
			}
			if ( null !== ( $item['expression'] ?? null ) ) {
				if ( ! $this->add_join_scalar_columns( $item['expression'], $sources, $needed ) ) {
					return $this->failure( 'unsupported_order', 'mdi-native cannot apply the requested JOIN scalar ordering.' );
				}
				continue;
			}
			if ( null === $order_source || ! isset( $sources[ $order_source ] )
				|| ( $numeric ? ! $sources[ $order_source ]['schema']->has_column( $item['column'] ) : ! $sources[ $order_source ]['schema']->allows_order( $item['column'] ) ) ) {
				return $this->failure( 'unsupported_order', 'mdi-native cannot apply the requested JOIN ordering collation.' );
			}
			$needed[ $order_source ][] = $item['column'];
		}
		foreach ( $needed as &$columns ) {
			$columns = array_values( array_unique( $columns ) );
		}
		unset( $columns );

		$seed_source = $base_alias;
		$seed_predicate = $this->pushdown( $predicates[ $seed_source ], $sources[ $seed_source ]['schema'] );
		$seed = $sources[ $seed_source ];
		$provided = $this->read_provider(
			$seed['provider'],
			$seed['schema'],
			new WP_Markdown_Native_Table_Access( $needed[ $seed_source ], $seed_predicate, $seed['schema']->natural_order(), PHP_INT_MAX )
		);
		if ( $provided instanceof WP_Markdown_Query_Result ) {
			return $provided;
		}
		$residual = array_values( array_filter( $predicates[ $seed_source ], static fn( WP_Markdown_Native_Query_Predicate $predicate ): bool => $predicate !== $seed_predicate ) );
		$rows = array();
		$seed_validated = $this->returns_validated_rows( $seed['provider'] );
		foreach ( $provided as $row ) {
			if ( ! $seed_validated && ( ! is_array( $row ) || true !== $seed['schema']->validate_projection( $row, $needed[ $seed_source ] ) ) ) {
				return $this->failure( 'invalid_provider_row', 'The native JOIN provider returned a row outside its declared schema.' );
			}
			if ( $this->matches( $row, $residual, $seed['schema'] ) ) {
				$rows[] = array( $seed_source => $row );
			}
		}

		$joined_sources = array( $seed_source => true );
		foreach ( $plan->joins() as $join ) {
			$target_source = $join->alias();
			if ( isset( $joined_sources[ $target_source ] ) ) {
				return $this->failure( 'unsupported_join_shape', 'mdi-native JOIN sources must extend the declared source chain.' );
			}
			$target = $sources[ $target_source ];
			$known_source = null;
			$known_column = null;
			$target_column = null;
			if ( null !== $join->left_source() && null !== $join->right_source() && null !== $join->left_column() && null !== $join->right_column() ) {
				if ( $target_source === $join->right_source() && isset( $joined_sources[ $join->left_source() ] ) ) {
					$known_source = $join->left_source(); $known_column = $join->left_column(); $target_column = $join->right_column();
				} elseif ( $target_source === $join->left_source() && isset( $joined_sources[ $join->right_source() ] ) ) {
					$known_source = $join->right_source(); $known_column = $join->right_column(); $target_column = $join->left_column();
				}
			}
			if ( null === $known_source || null === $known_column || null === $target_column ) {
				$provided = $this->read_provider(
					$target['provider'],
					$target['schema'],
					new WP_Markdown_Native_Table_Access( $needed[ $target_source ], null, $target['schema']->natural_order(), PHP_INT_MAX )
				);
			} else {
				$values = array();
				foreach ( $rows as $row ) {
					$value = $row[ $known_source ][ $known_column ] ?? null;
					$key = $target['schema']->value_key( $target_column, $value );
					if ( null === $key ) {
						// NULL cannot match an equality key. Leave this row in the
						// join stream so a later LEFT JOIN can null-extend it.
						continue;
					}
					if ( ! isset( $values[ $key ] ) ) {
						$values[ $key ] = $value;
					}
				}
				if ( array() === $values ) {
					$rows = array();
					$joined_sources[ $target_source ] = true;
					continue;
				}
				$values = array_values( $values );
				$operator = 1 === count( $values ) ? '=' : 'IN';
				if ( ! $target['schema']->allows_lookup( $target_column, $operator, $values ) ) {
					return $this->failure( 'unsupported_join_lookup', 'mdi-native requires an indexed equality key for each JOIN source.' );
				}
				$provided = $this->read_provider(
					$target['provider'],
					$target['schema'],
					new WP_Markdown_Native_Table_Access(
						$needed[ $target_source ],
						new WP_Markdown_Native_Query_Predicate( $target_column, $operator, $values ),
						$target['schema']->natural_order(),
						PHP_INT_MAX
					)
				);
			}
			if ( $provided instanceof WP_Markdown_Query_Result ) {
				return $provided;
			}
			$target_rows = array();
			$target_validated = $this->returns_validated_rows( $target['provider'] );
			foreach ( $provided as $target_row ) {
				if ( ! $target_validated && ( ! is_array( $target_row ) || true !== $target['schema']->validate_projection( $target_row, $needed[ $target_source ] ) ) ) {
					return $this->failure( 'invalid_provider_row', 'The native JOIN provider returned a row outside its declared schema.' );
				}
				if ( null !== $target_column ) {
					$key = $target['schema']->value_key( $target_column, $target_row[ $target_column ] );
					if ( null === $key ) { return $this->failure( 'invalid_provider_row', 'The native JOIN provider returned an invalid JOIN identity.' ); }
					$target_rows[ $key ][] = $target_row;
				} else { $target_rows[] = $target_row; }
			}
			$joined = array();
			$null_row = array_fill_keys( $needed[ $target_source ], null );
			$candidate_count = 0;
			$row_matches = array();
			foreach ( $rows as $row_index => $row ) {
				if ( null === $known_source ) {
					$row_matches[ $row_index ] = $target_rows;
					$candidate_count += count( $row_matches[ $row_index ] );
					continue;
				}
				$key = $target['schema']->value_key( (string) $target_column, $row[ $known_source ][ $known_column ] ?? null );
				$row_matches[ $row_index ] = null === $key ? array() : ( $target_rows[ $key ] ?? array() );
				$candidate_count += count( $row_matches[ $row_index ] );
			}
			if ( $candidate_count > self::MAX_JOIN_CANDIDATE_PAIRS ) {
				return $this->failure( 'unsupported_join_cost', 'mdi-native cannot evaluate the requested JOIN within its bounded row-pair cost.' );
			}
			foreach ( $rows as $row_index => $row ) {
				$matched = $row_matches[ $row_index ];
				$matched = array_values( array_filter( $matched, function ( array $target_row ) use ( $row, $target_source, $join, $sources, $joined_sources ): bool { $combined = $row; $combined[ $target_source ] = $target_row; return $this->matches_join_predicates( $combined, $join->on_filters(), $sources, $joined_sources + array( $target_source => true ) ); } ) );
				if ( array() === $matched && $join->is_outer() ) {
					$row[ $target_source ] = $null_row;
					$joined[] = $row;
					continue;
				}
				foreach ( $matched as $target_row ) {
					$row[ $target_source ] = $target_row;
					$joined[] = $row;
				}
			}
			$rows = $joined;
			$joined_sources[ $target_source ] = true;
		}
		$join_subqueries = array_merge( $plan->subqueries(), $this->boolean_subqueries( $plan->boolean_predicate() ) );
		$outer_schemas = array_map( static fn( array $source ): WP_Markdown_Native_Table_Schema => $source['schema'], $sources );
		$join_subquery_matchers = $this->prepare_subqueries( $join_subqueries, $outer_schemas );
		if ( $join_subquery_matchers instanceof WP_Markdown_Query_Result ) { return $join_subquery_matchers; }
		$rows = array_values( array_filter( $rows, fn( array $row ): bool => $this->matches_join_predicates( $row, $plan->predicates(), $sources, $joined_sources ) && $this->matches_join_scalar_predicates( $row, $plan->scalar_predicates() ) && $this->matches_join_boolean_predicate( $row, $plan->boolean_predicate(), $sources, $joined_sources, $join_subquery_matchers ) && $this->matches_join_subqueries( $row, $this->plain_subquery_matchers( $plan->subqueries(), $join_subquery_matchers ), $sources ) ) );
		if ( null !== $this->correlated_subquery_failure ) { return $this->correlated_subquery_failure; }

		if ( array() !== $plan->order_by() ) {
			foreach ( $plan->order_by() as $item ) {
				if ( null !== ( $item['expression'] ?? null ) ) {
					continue;
				}
				if ( true === ( $item['numeric'] ?? false ) ) {
					continue;
				}
				$order_source = (string) ( $item['source'] ?? $plan->order_source() );
				if ( '' === $order_source && in_array( $item['column'], array_column( $plan->aggregates(), 'alias' ), true ) ) {
					continue;
				}
				$extracted = array_map( static fn( array $row ): array => $row[ $order_source ], $rows );
				if ( null !== $sources[ $order_source ]['schema']->unsupported_order_reason( array( $item ), $extracted ) ) {
					return $this->failure( 'unsupported_order', 'mdi-native cannot apply the requested JOIN ordering collation.' );
				}
			}
			usort(
				$rows,
				function ( array $left, array $right ) use ( $plan, $sources ): int {
					foreach ( $plan->order_by() as $item ) {
						if ( null !== ( $item['expression'] ?? null ) ) {
							$comparison = $this->compare_scalar_values( $this->evaluate_scalar( $item['expression'], $left, $sources[ (string) ( $item['source'] ?? $plan->table_alias() ) ]['schema'] ), $this->evaluate_scalar( $item['expression'], $right, $sources[ (string) ( $item['source'] ?? $plan->table_alias() ) ]['schema'] ) );
							if ( 0 !== $comparison ) { return $item['descending'] ? -$comparison : $comparison; }
							continue;
						}
						if ( null === ( $item['source'] ?? null ) && in_array( $item['column'], array_column( $plan->aggregates(), 'alias' ), true ) ) {
							continue;
						}
						$source = (string) ( $item['source'] ?? $plan->order_source() );
						$column = $item['column'];
						$left_value = $left[ $source ][ $column ] ?? null;
						$right_value = $right[ $source ][ $column ] ?? null;
						if ( true === ( $item['numeric'] ?? false ) ) {
							if ( null === $left_value && null === $right_value ) {
								continue;
							}
							if ( null === $left_value ) {
								return $item['descending'] ? 1 : -1;
							}
							if ( null === $right_value ) {
								return $item['descending'] ? -1 : 1;
							}
							$comparison = ( (float) $left_value ) <=> ( (float) $right_value );
						} else {
							$comparison = $sources[ $source ]['schema']->compare_rows( $column, $left[ $source ], $right[ $source ] );
						}
						$comparison = ( $item['descending'] ? -1 : 1 ) * $comparison;
						if ( 0 !== $comparison ) {
							return $comparison;
						}
					}
					return 0;
				}
			);
		}
		if ( $plan->counts_all() ) {
			return $this->count_result( count( $rows ), true );
		}
		$selected_rows = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$regular = array();
			foreach ( $projection as $column_index => $column ) {
				$source = $projection_sources[ $column_index ];
				$value = $row[ $source ][ $column ];
				$regular[] = array( $column => null === $value ? null : (string) $value );
			}
			$selected_row = $this->interleave_scalar_projection( $regular, $plan->scalar_projection(), fn( array $scalar ): array => array( $scalar['alias'] => $this->string_scalar( $this->evaluate_scalar( $scalar['expression'], $row, $sources[ (string) ( $scalar['expression']->source() ?? $plan->table_alias() ) ]['schema'] ) ) ) );
			$key = serialize( $selected_row );
			if ( array() !== $plan->aggregates() || ! $plan->is_distinct() || ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$selected_rows[] = $selected_row;
			}
		}
		$aggregates = $plan->aggregates();
		if ( array() !== $aggregates ) {
			$grouped_rows = array();
			$totals = array();
			$distinct_values = array();
			foreach ( $rows as $index => $row ) {
				$key = serialize( $selected_rows[ $index ] ?? array() );
				if ( ! isset( $grouped_rows[ $key ] ) ) {
					$grouped_rows[ $key ] = $selected_rows[ $index ] ?? array();
					$totals[ $key ] = array_fill_keys( array_column( $aggregates, 'alias' ), null );
					$distinct_values[ $key ] = array_fill_keys( array_column( $aggregates, 'alias' ), array() );
				}
				foreach ( $aggregates as $aggregate ) {
					$alias = $aggregate['alias'];
					$value = null === $aggregate['column'] ? null : ( $row[ (string) $aggregate['source'] ][ $aggregate['column'] ] ?? null );
					if ( 'COUNT' === $aggregate['function'] ) {
						// COUNT(*) counts rows; COUNT(col) skips NULL, which is
						// how an unmatched outer row contributes nothing.
						if ( null !== $aggregate['column'] && true === ( $aggregate['distinct'] ?? false ) ) {
							if ( null === $value || isset( $distinct_values[ $key ][ $alias ][ serialize( $value ) ] ) ) {
								continue;
							}
							$distinct_values[ $key ][ $alias ][ serialize( $value ) ] = true;
						}
						if ( null === $aggregate['column'] || null !== $value ) {
							$totals[ $key ][ $alias ] = (int) ( $totals[ $key ][ $alias ] ?? 0 ) + 1;
						} elseif ( null === $totals[ $key ][ $alias ] ) {
							$totals[ $key ][ $alias ] = 0;
						}
						continue;
					}
					if ( null !== $value ) {
						$totals[ $key ][ $alias ] = ( $totals[ $key ][ $alias ] ?? 0 ) + ( is_numeric( $value ) ? $value + 0 : 0 );
					}
				}
			}
			$selected_rows = array();
			foreach ( $grouped_rows as $key => $grouped_row ) {
				foreach ( $aggregates as $aggregate ) {
					$total = $totals[ $key ][ $aggregate['alias'] ] ?? null;
					$grouped_row[ $aggregate['alias'] ] = null === $total ? null : (string) $total;
				}
				$selected_rows[] = $grouped_row;
			}
			$selected_rows = array_values( $selected_rows );
			$schema = new WP_Markdown_Native_Table_Schema( array( '__aggregate' => new WP_Markdown_Native_Column( 253, true ) ), '__aggregate' );
			$selected_rows = array_values( array_filter( $selected_rows, fn( array $row ): bool => $this->matches_having( $row, $plan->having() ) && $this->matches_scalar_predicates( $row, $plan->scalar_having(), $schema ) ) );
			$aggregate_orders = array_values( array_filter( $plan->order_by(), static fn( array $item ): bool => null === ( $item['source'] ?? null ) ) );
			if ( array() !== $aggregate_orders ) {
				usort( $selected_rows, function ( array $left, array $right ) use ( $aggregate_orders ): int {
					foreach ( $aggregate_orders as $item ) {
						$comparison = $this->compare_scalar_values( $left[ $item['column'] ] ?? null, $right[ $item['column'] ] ?? null );
						if ( 0 !== $comparison ) { return $item['descending'] ? -$comparison : $comparison; }
					}
					return 0;
				} );
			}
		}
		if ( $plan->calculates_found_rows() ) {
			$this->last_found_rows = count( $selected_rows );
		}
		if ( 0 < $plan->limit_offset() || PHP_INT_MAX !== $plan->limit() ) {
			$selected_rows = array_values( array_slice( $selected_rows, $plan->limit_offset(), PHP_INT_MAX === $plan->limit() ? null : $plan->limit() ) );
		}
		$columns = array();
		foreach ( $projection as $index => $column ) {
			$source = $projection_sources[ $index ];
			$columns[] = array( 'name' => $column, 'table' => $sources[ $source ]['table'], 'type' => $sources[ $source ]['schema']->column( $column )->type() );
		}
		foreach ( $plan->scalar_projection() as $scalar ) {
			$source = $scalar['expression']->source() ?? $plan->table_alias() ?? $plan->table();
			$metadata = $this->scalar_projection_column( $scalar, $sources[ $source ]['table'], $sources[ $source ]['schema'] );
			array_splice( $columns, $scalar['position'], 0, array( $metadata ) );
		}
		foreach ( $aggregates as $aggregate ) {
			$columns[] = array( 'name' => $aggregate['alias'], 'table' => '', 'type' => 8 );
		}
		return WP_Markdown_Query_Result::selected( $selected_rows, $columns );
	}

	/** @param array<string,array{schema:WP_Markdown_Native_Table_Schema}> $sources @param array<string,array<int,string>> $needed */
	private function add_join_predicate_columns( WP_Markdown_Native_Query_Predicate $predicate, array $sources, array &$needed ): bool {
		$source = $predicate->source();
		if ( null === $source || ! isset( $sources[ $source ] ) || ! $sources[ $source ]['schema']->has_column( $predicate->column() ) ) {
			return false;
		}
		$needed[ $source ][] = $predicate->column();
		if ( null !== $predicate->comparison_column() ) {
			$comparison_source = $predicate->comparison_source();
			if ( null === $comparison_source || ! isset( $sources[ $comparison_source ] ) || ! $sources[ $comparison_source ]['schema']->has_column( $predicate->comparison_column() ) || ! in_array( $predicate->operator(), array( '=', '<>', '<', '<=', '>', '>=' ), true ) ) {
				return false;
			}
			$needed[ $comparison_source ][] = $predicate->comparison_column();
		} elseif ( ! in_array( $predicate->operator(), array( 'AND', 'OR' ), true ) && ! $sources[ $source ]['schema']->supports_predicate( $predicate ) ) {
			return false;
		}
		foreach ( $predicate->any() as $nested ) {
			if ( ! $this->add_join_predicate_columns( $nested, $sources, $needed ) ) {
				return false;
			}
		}
		return true;
	}

	/** Add every qualified scalar input to its owning JOIN source. */
	private function add_join_scalar_columns( WP_Markdown_Native_Query_Scalar_Expression $expression, array $sources, array &$needed ): bool {
		if ( null !== $expression->column() ) {
			$source = $expression->source();
			if ( null === $source || ! isset( $sources[ $source ] ) || ! $sources[ $source ]['schema']->has_column( $expression->column() ) ) {
				return false;
			}
			$needed[ $source ][] = $expression->column();
		}
		foreach ( $expression->arguments() as $argument ) {
			if ( ! $this->add_join_scalar_columns( $argument, $sources, $needed ) ) {
				return false;
			}
		}
		return true;
	}

	private function matches_join_scalar_predicates( array $row, array $predicates ): bool {
		foreach ( $predicates as $predicate ) {
			$schema = new WP_Markdown_Native_Table_Schema( array( '__scalar' => new WP_Markdown_Native_Column( 253, true ) ), '__scalar' );
			$left = $this->evaluate_scalar( $predicate->left(), $row, $schema );
			$right = $this->evaluate_scalar( $predicate->right(), $row, $schema );
			if ( null === $left || null === $right ) {
				return false;
			}
			$comparison = $this->compare_scalar_values( $left, $right );
			if ( ! match ( $predicate->operator() ) { '=' => 0 === $comparison, '<>' => 0 !== $comparison, '<' => $comparison < 0, '<=' => $comparison <= 0, '>' => $comparison > 0, default => $comparison >= 0 } ) {
				return false;
			}
		}
		return true;
	}

	private function matches_join_boolean_predicate( array $row, ?WP_Markdown_Native_Query_Boolean_Predicate $predicate, array $sources, array $joined_sources, array $subquery_matchers = array() ): bool {
		if ( null === $predicate ) {
			return true;
		}
		foreach ( $predicate->groups() as $group ) {
			$matches = true;
			foreach ( $group as $term ) {
				$matches = $matches && ( $term instanceof WP_Markdown_Native_Query_Scalar_Predicate
					? $this->matches_join_scalar_predicates( $row, array( $term ) )
					: ( $term instanceof WP_Markdown_Native_Query_Subquery
						? isset( $subquery_matchers[ spl_object_id( $term ) ] ) && $this->matches_join_subqueries( $row, array( $subquery_matchers[ spl_object_id( $term ) ] ), $sources )
					: $this->matches_join_predicates( $row, array( $term ), $sources, $joined_sources ) ) );
			}
			if ( $matches ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string,array<int,string>> */
	private function outer_correlation_columns( WP_Markdown_Native_Query_Plan $plan ): array {
		$columns = array();
		$local_sources = array_fill_keys( array_merge( array( $plan->table_alias() ?? $plan->table() ), array_map( static fn( WP_Markdown_Native_Query_Join $join ): string => $join->alias(), $plan->joins() ) ), true );
		$collect = function ( WP_Markdown_Native_Query_Predicate $predicate ) use ( &$columns, $local_sources, &$collect ): void {
			if ( null !== $predicate->comparison_source() && null !== $predicate->comparison_column() && ! isset( $local_sources[ $predicate->comparison_source() ] ) ) {
				$columns[ $predicate->comparison_source() ][] = $predicate->comparison_column();
			}
			foreach ( $predicate->any() as $nested ) { $collect( $nested ); }
		};
		$collect_scalar = function ( WP_Markdown_Native_Query_Scalar_Expression $expression ) use ( &$columns, $local_sources, &$collect, &$collect_scalar ): void {
			if ( 'column' === $expression->kind() && null !== $expression->source() && ! isset( $local_sources[ $expression->source() ] ) ) {
				$columns[ $expression->source() ][] = (string) $expression->column();
			}
			foreach ( $expression->arguments() as $argument ) { $collect_scalar( $argument ); }
			foreach ( $expression->branches() as $branch ) {
				foreach ( $branch['predicates'] as $predicate ) { $collect( $predicate ); }
				$collect_scalar( $branch['value'] );
			}
			if ( null !== $expression->else() ) { $collect_scalar( $expression->else() ); }
		};
		$collect_scalar_predicate = function ( WP_Markdown_Native_Query_Scalar_Predicate $predicate ) use ( &$collect_scalar ): void {
			$collect_scalar( $predicate->left() );
			$collect_scalar( $predicate->right() );
		};
		foreach ( $plan->predicates() as $predicate ) { $collect( $predicate ); }
		foreach ( $plan->scalar_projection() as $scalar ) { $collect_scalar( $scalar['expression'] ); }
		foreach ( array_merge( $plan->scalar_predicates(), $plan->scalar_having() ) as $predicate ) { $collect_scalar_predicate( $predicate ); }
		if ( null !== $plan->group_expression() ) { $collect_scalar( $plan->group_expression() ); }
		foreach ( $plan->order_by() as $order ) { if ( null !== ( $order['expression'] ?? null ) ) { $collect_scalar( $order['expression'] ); } }
		if ( null !== $plan->boolean_predicate() ) {
			foreach ( $plan->boolean_predicate()->groups() as $group ) {
				foreach ( $group as $term ) {
					if ( $term instanceof WP_Markdown_Native_Query_Predicate ) { $collect( $term ); }
					if ( $term instanceof WP_Markdown_Native_Query_Scalar_Predicate ) { $collect_scalar_predicate( $term ); }
				}
			}
		}
		foreach ( $columns as &$source_columns ) { $source_columns = array_values( array_unique( $source_columns ) ); }
		unset( $source_columns );
		return $columns;
	}

	/** Apply each JOIN subquery matcher to the alias that owns its outer column. */
	private function matches_join_subqueries( array $row, array $matchers, array $sources ): bool {
		foreach ( $matchers as $matcher ) {
			if ( isset( $matcher['query'] ) ) {
				$source = $matcher['term']->source() ?? array_key_first( $row );
				$schemas = array_map( static fn( array $item ): WP_Markdown_Native_Table_Schema => $item['schema'], $sources );
				if ( null === $source || ! isset( $row[ $source ], $sources[ $source ] ) || ! $this->matches_subqueries( $row[ $source ], array( $matcher ), $sources[ $source ]['schema'], $row, $schemas ) ) { return false; }
				continue;
			}
			$source = $matcher['term']->source() ?? $matcher['correlation']?->comparison_source() ?? array_key_first( $row );
			if ( ! isset( $row[ $source ], $sources[ $source ] ) || ! $this->matches_subqueries( $row[ $source ], array( $matcher ), $sources[ $source ]['schema'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Evaluate an ON or post-join WHERE boolean expression against all aliases. */
	private function matches_join_predicates( array $row, array $predicates, array $sources, array $joined_sources ): bool {
		foreach ( $predicates as $predicate ) {
			$source = $predicate->source();
			if ( null === $source || ! isset( $joined_sources[ $source ], $sources[ $source ] ) ) {
				return false;
			}
			if ( 'AND' === $predicate->operator() ) {
				if ( ! $this->matches_join_predicates( $row, $predicate->any(), $sources, $joined_sources ) ) { return false; }
				continue;
			}
			if ( 'OR' === $predicate->operator() ) {
				$matched = false;
				foreach ( $predicate->any() as $alternative ) { if ( $this->matches_join_predicates( $row, array( $alternative ), $sources, $joined_sources ) ) { $matched = true; break; } }
				if ( ! $matched ) { return false; }
				continue;
			}
			if ( null === $predicate->comparison_column() ) {
				if ( ! $sources[ $source ]['schema']->matches( $row[ $source ], array( $predicate ) ) ) { return false; }
				continue;
			}
			$comparison_source = $predicate->comparison_source();
			if ( null === $comparison_source || ! isset( $joined_sources[ $comparison_source ], $sources[ $comparison_source ] ) ) { return false; }
			$left = $row[ $source ][ $predicate->column() ] ?? null;
			$right = $row[ $comparison_source ][ $predicate->comparison_column() ] ?? null;
			if ( null === $left || null === $right ) { return false; }
			$comparison = $sources[ $comparison_source ]['schema']->ordered_comparison( $predicate->comparison_column(), $left, $right );
			if ( '=' === $predicate->operator() || '<>' === $predicate->operator() ) {
				$left_key = $sources[ $comparison_source ]['schema']->value_key( $predicate->comparison_column(), $left );
				$right_key = $sources[ $comparison_source ]['schema']->value_key( $predicate->comparison_column(), $right );
				if ( null === $left_key || null === $right_key || ( '=' === $predicate->operator() ? $left_key !== $right_key : $left_key === $right_key ) ) { return false; }
			} elseif ( null === $comparison || ! match ( $predicate->operator() ) { '<' => $comparison < 0, '<=' => $comparison <= 0, '>' => $comparison > 0, '>=' => $comparison >= 0 } ) { return false; }
		}
		return true;
	}

	/** @return array{schema:WP_Markdown_Native_Table_Schema,provider:WP_Markdown_Native_Table_Provider}|WP_Markdown_Query_Result */
	private function derived_source( WP_Markdown_Native_Query_Plan $plan, string $name ): array|WP_Markdown_Query_Result {
		$result = $this->execute_query_plan( $plan );
		if ( false === $result->return_value() ) {
			return $result;
		}
		$columns = $result->wpdb_state()['col_info'] ?? array();
		if ( array() === $columns ) {
			return $this->failure( 'unsupported_derived_shape', 'mdi-native derived SELECTs must project at least one column.' );
		}
		$schema_columns = array();
		foreach ( $columns as $column ) {
			$schema_columns[ $column->name ] = new WP_Markdown_Native_Column(
				$column->type,
				true,
				static fn( mixed $value ): bool => is_int( $value ) || is_string( $value ),
				static fn( mixed $value ): ?string => null === $value ? null : (string) $value,
				array( '=', 'IN' )
			);
		}
		$rows = array_map( 'get_object_vars', $result->wpdb_state()['last_result'] ?? array() );
		$schema = new WP_Markdown_Native_Table_Schema( $schema_columns, (string) $columns[0]->name, array_keys( $schema_columns ) );
		return array(
			'table' => $name,
			'schema' => $schema,
			'provider' => new WP_Markdown_Native_Derived_Table_Provider( $rows, $schema ),
		);
	}

	/** @param array<int,WP_Markdown_Native_Query_Predicate> $predicates */
	private function allows_residual_scan( array $predicates, WP_Markdown_Native_Table_Schema $schema, bool $snapshot = false ): bool {
		$indexed = $this->indexed_columns( $schema );
		foreach ( $predicates as $predicate ) {
			if ( null !== $predicate->cast() ) {
				continue;
			}
			if ( in_array( $predicate->operator(), array( 'OR', 'LOWER =' ), true ) ) {
				continue;
			}
			if ( $this->predicate_uses_pattern( $predicate ) ) {
				continue;
			}
			if ( in_array( $predicate->operator(), array( 'IS NULL', 'IS NOT NULL' ), true ) ) {
				continue;
			}
			if ( WP_Markdown_Native_Table_Schema::is_range_operator( $predicate->operator() ) || 'BETWEEN' === $predicate->operator() ) {
				// A range has no equality to seek on, so the schema's own
				// ordering gate decides whether the scan is answerable.
				if ( ! $schema->allows_filter( $predicate->column(), $predicate->operator(), $predicate->values() ) ) {
					return false;
				}
				continue;
			}
			if ( ! in_array( $predicate->operator(), array( '=', 'IN', 'NOT IN', '<>' ), true ) ) {
				return false;
			}
			$column = $predicate->column();
			$type = $schema->column( $column )->type();
			if ( in_array( $type, array( 1, 2, 3, 4, 5, 8, 9, 246 ), true ) ) {
				continue;
			}
			// Snapshots already materialize rows; retain lookup validators even on scans.
			if ( ( $snapshot || isset( $indexed[ $column ] ) )
				&& ! $schema->is_lookup( $column )
				&& $schema->allows_filter( $column, $predicate->operator(), $predicate->values() ) ) {
				continue;
			}
			return false;
		}
		return array() !== $predicates;
	}

	/** @return array<string,true> */
	private function indexed_columns( WP_Markdown_Native_Table_Schema $schema ): array {
		$indexed = array();
		foreach ( $schema->definition()['indexes'] ?? array() as $index ) {
			foreach ( $index['columns'] ?? array() as $column ) {
				$name = $column['name'] ?? '';
				if ( '' !== $name ) {
					$indexed[ $name ] = true;
				}
			}
		}
		return $indexed;
	}

	/** @param array<int,WP_Markdown_Native_Query_Predicate> $predicates */
	private function pushdown( array $predicates, WP_Markdown_Native_Table_Schema $schema ): ?WP_Markdown_Native_Query_Predicate {
		$candidates = array_values(
			array_filter(
				$predicates,
				static fn( WP_Markdown_Native_Query_Predicate $predicate ): bool => $schema->allows_lookup(
					$predicate->column(),
					$predicate->operator(),
					$predicate->values()
				)
			)
		);
		foreach ( $predicates as $predicate ) {
			$candidates = array_merge( $candidates, $this->disjunction_pushdowns( $predicate, $schema ) );
		}
		usort(
			$candidates,
			$this->compare_pushdowns( ... )
		);
		return $candidates[0] ?? null;
	}

	/** @return array<int,WP_Markdown_Native_Query_Predicate> */
	private function disjunction_pushdowns( WP_Markdown_Native_Query_Predicate $predicate, WP_Markdown_Native_Table_Schema $schema ): array {
		if ( 'OR' !== $predicate->operator() || array() === $predicate->any() ) {
			return array();
		}
		$common = null;
		foreach ( $predicate->any() as $alternative ) {
			$conjuncts = 'AND' === $alternative->operator() ? $alternative->any() : array( $alternative );
			$branch = array();
			foreach ( $conjuncts as $conjunct ) {
				if ( ! in_array( $conjunct->operator(), array( '=', 'IN' ), true ) ) {
					continue;
				}
				$branch[ $conjunct->column() ] = $conjunct;
			}
			$common = null === $common ? $branch : array_intersect_key( $common, $branch );
			if ( array() === $common ) {
				return array();
			}
		}

		$pushdowns = array();
		foreach ( array_keys( $common ?? array() ) as $column ) {
			$values = array();
			$source = null;
			foreach ( $predicate->any() as $alternative ) {
				$conjuncts = 'AND' === $alternative->operator() ? $alternative->any() : array( $alternative );
				foreach ( $conjuncts as $conjunct ) {
					if ( $column === $conjunct->column() && in_array( $conjunct->operator(), array( '=', 'IN' ), true ) ) {
						$values = array_merge( $values, $conjunct->values() );
						$source ??= $conjunct->source();
						break;
					}
				}
			}
			$values = array_values( array_unique( $values, SORT_REGULAR ) );
			$operator = 1 === count( $values ) ? '=' : 'IN';
			if ( $schema->allows_lookup( $column, $operator, $values ) ) {
				$pushdowns[] = new WP_Markdown_Native_Query_Predicate( $column, $operator, $values, $source );
			}
		}
		return $pushdowns;
	}

	private function predicate_uses_pattern( WP_Markdown_Native_Query_Predicate $predicate ): bool {
		if ( in_array( $predicate->operator(), array( 'LIKE', 'REGEXP' ), true ) ) {
			return true;
		}
		foreach ( $predicate->any() as $alternative ) {
			if ( $this->predicate_uses_pattern( $alternative ) ) {
				return true;
			}
		}
		return false;
	}

	private function compare_pushdowns( WP_Markdown_Native_Query_Predicate $left, WP_Markdown_Native_Query_Predicate $right ): int {
		$operator = ( '=' === $left->operator() ? 0 : 1 ) <=> ( '=' === $right->operator() ? 0 : 1 );
		if ( 0 !== $operator ) {
			return $operator;
		}
		$value_count = count( $left->values() ) <=> count( $right->values() );
		return 0 !== $value_count ? $value_count : strcmp( $left->column(), $right->column() );
	}

	/**
	 * Report whether a provider's rows already satisfy its declared schema.
	 *
	 * A generic snapshot validates every row when the request loads it, and
	 * every mutation validates a row before publishing it, so the same rows do
	 * not need revalidating on each query. Any other provider stays behind the
	 * executor's boundary check.
	 */
	private function returns_validated_rows( WP_Markdown_Native_Table_Provider $provider ): bool {
		return $provider instanceof WP_Markdown_Native_JSON_Snapshot_Provider;
	}

	/**
	 * Generic snapshots expose validated source rows; relational work belongs
	 * here beside residual filtering. Storage-specific providers retain their
	 * own bounded reads so they can avoid opening unrelated canonical files.
	 *
	 * @return iterable<array<string,mixed>>|WP_Markdown_Query_Result
	 */
	private function read_provider(
		WP_Markdown_Native_Table_Provider $provider,
		WP_Markdown_Native_Table_Schema $schema,
		WP_Markdown_Native_Table_Access $access
	): iterable|WP_Markdown_Query_Result {
		if ( null !== $this->transactions && true !== ( $accessed = $this->transactions->access() ) ) {
			return $this->failure( 'transaction_access_failed', $accessed );
		}
		if ( ! $provider instanceof WP_Markdown_Native_JSON_Snapshot_Provider ) {
			return $provider->read( $access );
		}

		$predicates = $access->predicates();
		if ( array() === $predicates && null !== $access->predicate() ) {
			$predicates[] = $access->predicate();
		}
		$rows = $provider->equality_candidates( $predicates );
		if ( $rows instanceof WP_Markdown_Query_Result ) {
			return $rows;
		}
		if ( array() !== $predicates ) {
			$rows = array_values(
				array_filter(
					$rows,
					fn( array $row ): bool => $this->matches( $row, $predicates, $schema )
				)
			);
		}
		$rows = $schema->ordered_rows( $rows, $access->order_by() );
		if ( null === $rows ) {
			return $this->failure( 'unsupported_order', 'mdi-native cannot apply the requested ordering collation.' );
		}
		if ( $access->projection() === $schema->column_names() ) {
			return PHP_INT_MAX === $access->limit()
				? $rows
				: array_slice( $rows, 0, $access->limit() );
		}

		$selected = array();
		foreach ( $rows as $source ) {
			if ( count( $selected ) >= $access->limit() ) {
				break;
			}
			$row = array();
			foreach ( $access->projection() as $column ) {
				$row[ $column ] = $source[ $column ];
			}
			$selected[] = $row;
		}
		return $selected;
	}

	/** @param array<string,mixed> $row @param array<int,WP_Markdown_Native_Query_Predicate> $predicates */
	private function matches( array $row, array $predicates, WP_Markdown_Native_Table_Schema $schema ): bool {
		return $schema->matches( $row, $predicates );
	}

	/** @param array<int,array<string,mixed>> $rows @param array<int,string> $projection */
	private function result(
		array $rows,
		array $projection,
		string $table,
		WP_Markdown_Native_Table_Schema $schema,
		array $scalar_projection = array()
	): WP_Markdown_Query_Result {
		$regular = array_map(
			static fn( string $column ): array => array(
				'name'  => $column,
				'table' => $table,
				'type'  => $schema->column( $column )->type(),
			),
			$projection
		);
		$scalar_columns = array();
		foreach ( $scalar_projection as $scalar ) { $scalar_columns[ $scalar['position'] ] = $this->scalar_projection_column( $scalar, $table, $schema ); }
		$columns = array();
		$total_columns = count( $regular ) + count( $scalar_columns );
		for ( $position = 0; $position < $total_columns; ++$position ) {
			$columns[] = $scalar_columns[ $position ] ?? array_shift( $regular );
		}
		return WP_Markdown_Query_Result::selected( $rows, $columns );
	}

	/** A bare column alias keeps the source column's type and table metadata. */
	private function scalar_projection_column( array $scalar, string $table, WP_Markdown_Native_Table_Schema $schema ): array {
		$expression = $scalar['expression'];
		return array(
			'name' => $scalar['alias'],
			'table' => 'column' === $expression->kind() ? $table : '',
			'type' => 'column' === $expression->kind() ? $schema->column( $expression->column() )->type() : 253,
		);
	}

	/** @param array<string,mixed> $source @param array<int,string> $projection @return array<string,string|null> */
	private function string_row( array $source, array $projection, array $scalar_projection, WP_Markdown_Native_Table_Schema $schema ): array {
		$regular = array();
		foreach ( $projection as $column ) {
			$regular[] = array( $column => null === $source[ $column ] ? null : (string) $source[ $column ] );
		}
		return $this->interleave_scalar_projection(
			$regular,
			$scalar_projection,
			fn( array $scalar ): array => array( $scalar['alias'] => $this->string_scalar( $this->evaluate_scalar( $scalar['expression'], $source, $schema ) ) )
		);
	}

	/** @param array<int,array> $regular @param array<int,array{position:int}> $scalar_projection */
	private function interleave_scalar_projection( array $regular, array $scalar_projection, callable $scalar ): array {
		$by_position = array();
		foreach ( $scalar_projection as $item ) { $by_position[ $item['position'] ] = $item; }
		$result = array();
		$regular_index = 0;
		for ( $position = 0; $position < count( $regular ) + count( $scalar_projection ); ++$position ) {
			$result = array_merge( $result, isset( $by_position[ $position ] ) ? $scalar( $by_position[ $position ] ) : $regular[ $regular_index++ ] );
		}
		return $result;
	}

	/** Evaluate a lowered row-local scalar expression after filtering. */
	public function evaluate_scalar( WP_Markdown_Native_Query_Scalar_Expression $expression, array $row, WP_Markdown_Native_Table_Schema $schema ): int|string|null {
		$this->statement_now ??= gmdate( 'Y-m-d H:i:s' );
		if ( WP_Markdown_Native_Scalar_Evaluator::supports( $expression ) ) {
			return WP_Markdown_Native_Scalar_Evaluator::evaluate( $expression, $row );
		}
		$values = array_map(
			fn( WP_Markdown_Native_Query_Scalar_Expression $argument ): int|string|null => $this->evaluate_scalar( $argument, $row, $schema ),
			$expression->arguments()
		);
		return match ( $expression->kind() ) {
			'literal' => $expression->literal(),
			'column' => null === $expression->source()
				? ( $row[ (string) $expression->column() ] ?? null )
				: ( $row[ $expression->source() ][ (string) $expression->column() ] ?? null ),
			'CONCAT' => in_array( null, $values, true ) ? null : implode( '', $values ),
			'COALESCE' => $this->first_non_null( $values ),
			'SUBSTRING' => in_array( null, $values, true ) ? null : substr( (string) $values[0], max( 0, (int) $values[1] - 1 ), (int) $values[2] ),
			'SUBSTRING_INDEX' => in_array( null, $values, true ) ? null : $this->substring_index( (string) $values[0], (string) $values[1], (int) $values[2] ),
			'CAST_UNSIGNED' => null === $values[0] ? null : max( 0, (int) $values[0] ),
			'CAST_DECIMAL' => null === $values[0] ? null : $this->cast_decimal( $values[0], $values[1] ?? 10, $values[2] ?? 0 ),
			'YEAR' => null === $values[0] ? null : substr( (string) $values[0], 0, 4 ),
			'MONTH' => null === $values[0] ? null : substr( (string) $values[0], 5, 2 ),
			'DATE' => null === $values[0] ? null : substr( (string) $values[0], 0, 10 ),
			'TIME' => null === $values[0] ? null : substr( (string) $values[0], 11, 8 ),
			'DAY', 'DAYOFMONTH' => null === $values[0] ? null : substr( (string) $values[0], 8, 2 ),
			'DAYOFYEAR' => null === $values[0] ? null : $this->date_part( $values[0], 'z' ) + 1,
			'WEEKDAY' => null === $values[0] ? null : $this->date_part( $values[0], 'N' ) - 1,
			'WEEK' => $this->date_part( $values[0], 'W' ),
			'SECOND' => null === $values[0] ? null : substr( (string) $values[0], 17, 2 ),
			'HOUR' => null === $values[0] ? null : substr( (string) $values[0], 11, 2 ),
			'MINUTE' => null === $values[0] ? null : substr( (string) $values[0], 14, 2 ),
			'DAYOFWEEK' => null === $values[0] ? null : $this->date_part( $values[0], 'w' ) + 1,
			'DATE_FORMAT' => $this->date_format( $values[0], $values[1] ),
			'DATEDIFF' => $this->date_difference( $values[0], $values[1] ),
			'DATE_ADD' => $this->date_interval( $values[0], $values[1], $values[2], 1 ),
			'DATE_SUB' => $this->date_interval( $values[0], $values[1], $values[2], -1 ),
			'TIMESTAMPDIFF' => $this->timestamp_difference( $values[0], $values[1], $values[2] ),
			'UNIX_TIMESTAMP' => $this->unix_timestamp( $values[0] ?? null ),
			'FROM_UNIXTIME' => null === $values[0] ? null : gmdate( 'Y-m-d H:i:s', (int) $values[0] ),
			'NOW', 'UTC_TIMESTAMP' => $this->statement_now ?? gmdate( 'Y-m-d H:i:s' ),
			'CURDATE' => substr( $this->statement_now ?? gmdate( 'Y-m-d H:i:s' ), 0, 10 ),
			'GREATEST' => in_array( null, $values, true ) ? null : max( $values ),
			'LEAST' => in_array( null, $values, true ) ? null : min( $values ),
			'IF' => $this->scalar_number( $values[0] ) != 0.0 ? $values[1] : $values[2],
			'IFNULL' => $values[0] ?? $values[1],
			'NULLIF' => null !== $values[0] && null !== $values[1] && 0 === $this->compare_scalar_values( $values[0], $values[1] ) ? null : $values[0],
			'LOWER' => null === $values[0] ? null : ( function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $values[0], 'UTF-8' ) : strtolower( (string) $values[0] ) ),
			'UPPER' => null === $values[0] ? null : ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( (string) $values[0], 'UTF-8' ) : strtoupper( (string) $values[0] ) ),
			'TRIM' => null === $values[0] ? null : trim( (string) $values[0] ),
			'LENGTH' => null === $values[0] ? null : strlen( (string) $values[0] ),
			'CHAR_LENGTH' => null === $values[0] ? null : $this->character_length( (string) $values[0] ),
			'REPLACE' => in_array( null, $values, true ) ? null : str_replace( (string) $values[1], (string) $values[2], (string) $values[0] ),
			'LEFT' => in_array( null, $values, true ) ? null : $this->character_slice( (string) $values[0], 0, max( 0, (int) $values[1] ) ),
			'RIGHT' => in_array( null, $values, true ) ? null : $this->character_slice( (string) $values[0], -(int) $values[1] ),
			'LOCATE' => in_array( null, $values, true ) ? null : ( false === strpos( (string) $values[1], (string) $values[0] ) ? 0 : strpos( (string) $values[1], (string) $values[0] ) + 1 ),
			'MD5' => null === $values[0] ? null : md5( (string) $values[0] ),
			'SHA1' => null === $values[0] ? null : sha1( (string) $values[0] ),
			'JSON_VALID' => null === $values[0] ? null : $this->json_valid( (string) $values[0] ),
			'ABS' => null === $values[0] ? null : $this->scalar_number( abs( $this->scalar_number( $values[0] ) ) ),
			'ROUND' => null === $values[0] ? null : $this->scalar_number( round( $this->scalar_number( $values[0] ), (int) ( $values[1] ?? 0 ) ) ),
			'FLOOR' => null === $values[0] ? null : $this->scalar_number( floor( $this->scalar_number( $values[0] ) ) ),
			'CEIL' => null === $values[0] ? null : $this->scalar_number( ceil( $this->scalar_number( $values[0] ) ) ),
			'MOD' => in_array( null, $values, true ) || 0.0 === $this->scalar_number( $values[1] ) ? null : $this->scalar_number( fmod( $this->scalar_number( $values[0] ), $this->scalar_number( $values[1] ) ) ),
			'POW' => in_array( null, $values, true ) ? null : $this->scalar_number( pow( $this->scalar_number( $values[0] ), $this->scalar_number( $values[1] ) ) ),
			'SQRT' => null === $values[0] || 0 > (float) $this->scalar_number( $values[0] ) ? null : $this->scalar_number( sqrt( $this->scalar_number( $values[0] ) ) ),
			'RADIANS' => null === $values[0] ? null : $this->scalar_number( deg2rad( $this->scalar_number( $values[0] ) ) ),
			'DEGREES' => null === $values[0] ? null : $this->scalar_number( rad2deg( $this->scalar_number( $values[0] ) ) ),
			'SIN' => null === $values[0] ? null : $this->scalar_number( sin( $this->scalar_number( $values[0] ) ) ),
			'COS' => null === $values[0] ? null : $this->scalar_number( cos( $this->scalar_number( $values[0] ) ) ),
			'TAN' => null === $values[0] ? null : $this->scalar_number( tan( $this->scalar_number( $values[0] ) ) ),
			'ACOS' => null === $values[0] || abs( (float) $this->scalar_number( $values[0] ) ) > 1 ? null : $this->scalar_number( acos( $this->scalar_number( $values[0] ) ) ),
			'ASIN' => null === $values[0] || abs( (float) $this->scalar_number( $values[0] ) ) > 1 ? null : $this->scalar_number( asin( $this->scalar_number( $values[0] ) ) ),
			'ATAN' => null === $values[0] ? null : $this->scalar_number( atan( $this->scalar_number( $values[0] ) ) ),
			'ATAN2' => in_array( null, $values, true ) ? null : $this->scalar_number( atan2( $this->scalar_number( $values[0] ), $this->scalar_number( $values[1] ) ) ),
			'RAND' => null === ( $values[0] ?? null ) && array() !== $values ? null : $this->rand( $values[0] ?? null ),
			'ADD' => in_array( null, $values, true ) ? null : $this->scalar_number( $this->scalar_number( $values[0] ) + $this->scalar_number( $values[1] ) ),
			'SUBTRACT' => in_array( null, $values, true ) ? null : $this->scalar_number( $this->scalar_number( $values[0] ) - $this->scalar_number( $values[1] ) ),
			'MULTIPLY' => in_array( null, $values, true ) ? null : $this->scalar_number( $this->scalar_number( $values[0] ) * $this->scalar_number( $values[1] ) ),
			'DIVIDE' => in_array( null, $values, true ) || 0.0 === (float) $this->scalar_number( $values[1] ) ? null : $this->scalar_number( $this->scalar_number( $values[0] ) / $this->scalar_number( $values[1] ) ),
			'CASE' => $this->evaluate_case( $expression, $row, $schema ),
			default => throw new LogicException( 'Unsupported lowered scalar expression.' ),
		};
	}

	private function scalar_number( int|float|string|null $value ): int|string|null|float {
		if ( null === $value ) { return null; }
		if ( is_int( $value ) || ( is_string( $value ) && (string) (int) $value === $value ) ) {
			return (int) $value;
		}
		$number = (float) $value;
		return floor( $number ) === $number ? (int) $number : (string) $number;
	}

	private function json_valid( string $value ): string {
		try {
			// MySQL 8.4 accepts 100 containers and rejects the 101st. PHP counts
			// the scalar below those containers too, hence the decode depth of 101.
			json_decode( $value, true, 101, JSON_THROW_ON_ERROR );
			return '1';
		} catch ( JsonException ) {
			return '0';
		}
	}

	private function json_depth_exceeded( WP_Markdown_Native_Query_Scalar_Expression $expression ): bool {
		$arguments = $expression->arguments();
		if ( 1 !== count( $arguments ) || 'literal' !== $arguments[0]->kind() || ! is_string( $arguments[0]->literal() ) ) {
			return false;
		}
		return $this->json_depth_exceeded_value( $arguments[0]->literal() );
	}

	private function json_depth_exceeded_value( string $value ): bool {
		try {
			json_decode( $value, true, 101, JSON_THROW_ON_ERROR );
			return false;
		} catch ( JsonException $error ) {
			return JSON_ERROR_DEPTH === $error->getCode();
		}
	}

	private function mysql_json_depth_failure(): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure(
			array(
				'code'    => 3157,
				'reason'  => 'json_document_too_deep',
				'message' => 'The JSON document exceeds the maximum depth.',
			)
		);
	}

	/** Cast through decimal digits instead of PHP floats, which lose declared scale. */
	private function cast_decimal( int|string $value, int|string $precision, int|string $scale ): string {
		$precision = (int) $precision;
		$scale = (int) $scale;
		$input = trim( (string) $value );
		if ( 1 !== preg_match( '/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?/', $input, $match ) || ( '' === $match[2] && '' === ( $match[3] ?? '' ) ) ) {
			$match = array( '', '', '0', '', '0' );
		}
		$negative = '-' === $match[1];
		$digits = $match[2] . ( $match[3] ?? '' );
		$leading = strlen( $digits ) - strlen( ltrim( $digits, '0' ) );
		$digits = substr( $digits, $leading );
		if ( '' === $digits ) {
			return '0' . ( 0 === $scale ? '' : '.' . str_repeat( '0', $scale ) );
		}
		$decimal = strlen( $match[2] ) - $leading + $this->bounded_decimal_exponent( $match[4] ?? '0' );
		$cutoff = $decimal + $scale;
		if ( $cutoff > $precision + 1 ) {
			return $this->decimal_limit( $negative, $precision, $scale );
		}
		$rounded = $cutoff <= 0 ? '0' : substr( $digits, 0, $cutoff );
		$rounded = str_pad( $rounded, max( 1, $cutoff ), '0' );
		if ( $cutoff >= 0 && isset( $digits[ $cutoff ] ) && $digits[ $cutoff ] >= '5' ) {
			$rounded = $this->increment_decimal_digits( $rounded );
		}
		$rounded = ltrim( $rounded, '0' );
		if ( '' === $rounded ) {
			$rounded = '0';
		}
		if ( strlen( $rounded ) > $precision ) {
			return $this->decimal_limit( $negative, $precision, $scale );
		}
		$rounded = str_pad( $rounded, $scale + 1, '0', STR_PAD_LEFT );
		$whole = 0 === $scale ? $rounded : substr( $rounded, 0, -$scale );
		$fraction = 0 === $scale ? '' : substr( $rounded, -$scale );
		return ( $negative && '' !== ltrim( $rounded, '0' ) ? '-' : '' ) . $whole . ( 0 === $scale ? '' : '.' . $fraction );
	}

	/** Bound exponents before they can allocate beyond the declared DECIMAL domain. */
	private function bounded_decimal_exponent( string $value ): int {
		$negative = str_starts_with( $value, '-' );
		$digits = ltrim( $value, '+-' );
		if ( strlen( ltrim( $digits, '0' ) ) > 3 ) {
			return $negative ? -1000 : 1000;
		}
		return (int) $value;
	}

	private function decimal_limit( bool $negative, int $precision, int $scale ): string {
		$digits = str_repeat( '9', $precision );
		$whole = 0 === $scale ? $digits : substr( $digits, 0, -$scale );
		$fraction = 0 === $scale ? '' : substr( $digits, -$scale );
		return ( $negative ? '-' : '' ) . $whole . ( 0 === $scale ? '' : '.' . $fraction );
	}

	private function increment_decimal_digits( string $digits ): string {
		for ( $index = strlen( $digits ) - 1; $index >= 0; --$index ) {
			if ( '9' !== $digits[ $index ] ) {
				$digits[ $index ] = (string) ( (int) $digits[ $index ] + 1 );
				return $digits;
			}
			$digits[ $index ] = '0';
		}
		return '1' . $digits;
	}

	private function substring_index( string $value, string $delimiter, int $count ): string {
		if ( '' === $delimiter || 0 === $count ) {
			return '';
		}
		$parts = explode( $delimiter, $value );
		return $count > 0 ? implode( $delimiter, array_slice( $parts, 0, $count ) ) : implode( $delimiter, array_slice( $parts, $count ) );
	}

	private function character_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) { return mb_strlen( $value, 'UTF-8' ); }
		return preg_match_all( '/./us', $value, $matches ) ?: strlen( $value );
	}

	private function character_slice( string $value, int $offset, ?int $length = null ): string {
		if ( function_exists( 'mb_substr' ) ) { return mb_substr( $value, $offset, $length, 'UTF-8' ); }
		preg_match_all( '/./us', $value, $matches );
		$characters = $matches[0] ?? str_split( $value );
		return implode( '', array_slice( $characters, $offset, $length ) );
	}

	private function date_part( int|string|null $value, string $format ): ?int {
		if ( null === $value || '0000-00-00' === substr( (string) $value, 0, 10 ) ) { return null; }
		$date = date_create_immutable( (string) $value, new DateTimeZone( 'UTC' ) );
		return false === $date ? null : (int) $date->format( $format );
	}

	private function date_format( int|string|null $value, int|string|null $format ): ?string {
		if ( null === $value || null === $format || '0000-00-00' === substr( (string) $value, 0, 10 ) ) { return null; }
		$date = date_create_immutable( (string) $value, new DateTimeZone( 'UTC' ) );
		if ( false === $date ) { return null; }
		$days = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		$months = array( 1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' );
		$hour = (int) $date->format( 'G' );
		$week_sunday = $this->week_number( $date, false );
		$week_monday = $this->week_number( $date, true );
		$week_sunday_one = 0 === $week_sunday ? $this->week_number( $date->modify( 'last day of December last year' ), false ) : $week_sunday;
		$tokens = array(
			'a' => substr( $days[ (int) $date->format( 'w' ) ], 0, 3 ), 'b' => substr( $months[ (int) $date->format( 'n' ) ], 0, 3 ),
			'c' => $date->format( 'n' ), 'D' => $this->day_ordinal( (int) $date->format( 'j' ) ), 'd' => $date->format( 'd' ), 'e' => $date->format( 'j' ), 'f' => $date->format( 'u' ),
			'H' => $date->format( 'H' ), 'h' => $date->format( 'h' ), 'I' => $date->format( 'h' ), 'i' => $date->format( 'i' ), 'j' => str_pad( (string) ( $date->format( 'z' ) + 1 ), 3, '0', STR_PAD_LEFT ),
			'k' => (string) $hour, 'l' => (string) ( 0 === $hour % 12 ? 12 : $hour % 12 ), 'M' => $months[ (int) $date->format( 'n' ) ], 'm' => $date->format( 'm' ),
			'p' => 12 <= $hour ? 'PM' : 'AM', 'r' => $date->format( 'h:i:s A' ), 'S' => $date->format( 's' ), 's' => $date->format( 's' ), 'T' => $date->format( 'H:i:s' ),
			'U' => str_pad( (string) $week_sunday, 2, '0', STR_PAD_LEFT ), 'u' => str_pad( (string) $week_monday, 2, '0', STR_PAD_LEFT ), 'V' => str_pad( (string) $week_sunday_one, 2, '0', STR_PAD_LEFT ), 'v' => $date->format( 'W' ),
			'W' => $days[ (int) $date->format( 'w' ) ], 'w' => $date->format( 'w' ), 'X' => $date->format( 'o' ), 'x' => $date->format( 'o' ), 'Y' => $date->format( 'Y' ), 'y' => $date->format( 'y' ), '%' => '%',
		);
		return preg_replace_callback( '/%./', static fn( array $match ): string => (string) ( $tokens[ $match[0][1] ] ?? $match[0][1] ), (string) $format );
	}

	/** MySQL's %U/%u week number starts at zero before the first week day. */
	private function week_number( DateTimeImmutable $date, bool $monday ): int {
		$year_start = $date->setDate( (int) $date->format( 'Y' ), 1, 1 )->setTime( 0, 0 );
		$start_day = $monday ? 1 : 0;
		$first = ( $start_day - (int) $year_start->format( 'w' ) + 7 ) % 7;
		$day = (int) $date->format( 'z' );
		return $day < $first ? 0 : intdiv( $day - $first, 7 ) + 1;
	}

	private function day_ordinal( int $day ): string {
		$suffix = 11 <= $day % 100 && 13 >= $day % 100 ? 'th' : match ( $day % 10 ) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
		return $day . $suffix;
	}

	private function date_difference( int|string|null $left, int|string|null $right ): ?int {
		if ( null === $left || null === $right ) { return null; }
		$first = strtotime( substr( (string) $left, 0, 10 ) . ' UTC' );
		$second = strtotime( substr( (string) $right, 0, 10 ) . ' UTC' );
		if ( false === $first || false === $second ) { return null; }
		return (int) ( ( $first - $second ) / 86400 );
	}

	private function date_interval( int|string|null $date, int|string|null $amount, int|string|null $unit, int $direction ): ?string {
		if ( null === $date || null === $amount || null === $unit ) { return null; }
		$parsed = date_create_immutable( (string) $date, new DateTimeZone( 'UTC' ) );
		$units = array( 'SECOND' => 'seconds', 'MINUTE' => 'minutes', 'HOUR' => 'hours', 'DAY' => 'days', 'WEEK' => 'weeks', 'MONTH' => 'months', 'YEAR' => 'years' );
		if ( false === $parsed || ! isset( $units[ (string) $unit ] ) ) { return null; }
		if ( in_array( (string) $unit, array( 'MONTH', 'YEAR' ), true ) ) {
			$months = (int) $amount * ( 'YEAR' === $unit ? 12 : 1 ) * $direction;
			$year = (int) $parsed->format( 'Y' );
			$month = (int) $parsed->format( 'n' ) + $months;
			$year += (int) floor( ( $month - 1 ) / 12 );
			$month = ( ( $month - 1 ) % 12 + 12 ) % 12 + 1;
			$day = min( (int) $parsed->format( 'j' ), cal_days_in_month( CAL_GREGORIAN, $month, $year ) );
			return $parsed->setDate( $year, $month, $day )->format( str_contains( (string) $date, ' ' ) ? 'Y-m-d H:i:s' : 'Y-m-d' );
		}
		$amount = $direction * (int) $amount;
		return $parsed->modify( ( 0 <= $amount ? '+' : '' ) . $amount . ' ' . $units[ (string) $unit ] )?->format( str_contains( (string) $date, ' ' ) ? 'Y-m-d H:i:s' : 'Y-m-d' );
	}

	private function timestamp_difference( int|string|null $unit, int|string|null $left, int|string|null $right ): ?int {
		if ( null === $unit || null === $left || null === $right ) { return null; }
		$start = strtotime( (string) $left . ' UTC' ); $end = strtotime( (string) $right . ' UTC' );
		if ( false === $start || false === $end ) { return null; }
		$seconds = $end - $start;
		if ( in_array( (string) $unit, array( 'MONTH', 'QUARTER', 'YEAR' ), true ) ) {
			$start_date = date_create_immutable( (string) $left, new DateTimeZone( 'UTC' ) );
			$end_date = date_create_immutable( (string) $right, new DateTimeZone( 'UTC' ) );
			if ( false === $start_date || false === $end_date ) { return null; }
			$months = ( (int) $end_date->format( 'Y' ) - (int) $start_date->format( 'Y' ) ) * 12 + (int) $end_date->format( 'n' ) - (int) $start_date->format( 'n' );
			if ( $end_date->format( 'd H:i:s' ) < $start_date->format( 'd H:i:s' ) ) { $months -= 0 < $months ? 1 : -1; }
			return match ( (string) $unit ) { 'MONTH' => $months, 'QUARTER' => (int) ( $months / 3 ), default => (int) ( $months / 12 ) };
		}
		return match ( (string) $unit ) { 'SECOND' => $seconds, 'MINUTE' => (int) ( $seconds / 60 ), 'HOUR' => (int) ( $seconds / 3600 ), 'DAY' => (int) ( $seconds / 86400 ), 'WEEK' => (int) ( $seconds / 604800 ), default => null };
	}

	private function unix_timestamp( int|string|null $value ): ?int {
		if ( null === $value ) { return time(); }
		$timestamp = strtotime( (string) $value . ' UTC' );
		return false === $timestamp ? null : $timestamp;
	}

	/** Return a repeatable RAND(seed) value without mutating PHP's process-global RNG. */
	private function rand( int|string|null $seed ): string {
		if ( null === $seed ) {
			return (string) ( random_int( 0, PHP_INT_MAX ) / PHP_INT_MAX );
		}
		$maximum = 0x3fffffff;
		// RAND(seed) reseeds for each expression evaluation, so two occurrences
		// of the same seeded expression in one projection return the same value.
		$state = array( 'seed1' => ( (int) $seed * 0x10001 + 55555555 ) % $maximum, 'seed2' => ( (int) $seed * 0x10000001 ) % $maximum );
		$state['seed1'] = ( $state['seed1'] * 3 + $state['seed2'] ) % $maximum;
		$state['seed2'] = ( $state['seed1'] + $state['seed2'] + 33 ) % $maximum;
		return (string) ( $state['seed1'] / $maximum );
	}

	private function evaluate_case( WP_Markdown_Native_Query_Scalar_Expression $expression, array $row, WP_Markdown_Native_Table_Schema $schema ): int|string|null {
		foreach ( $expression->branches() as $branch ) {
			if ( $schema->matches( $row, $branch['predicates'] ) ) {
				return $this->evaluate_scalar( $branch['value'], $row, $schema );
			}
		}
		return null === $expression->else() ? null : $this->evaluate_scalar( $expression->else(), $row, $schema );
	}

	private function string_scalar( int|string|null $value ): ?string {
		return null === $value ? null : (string) $value;
	}

	/** @param array<int,int|string|null> $values */
	private function first_non_null( array $values ): int|string|null {
		foreach ( $values as $value ) {
			if ( null !== $value ) {
				return $value;
			}
		}
		return null;
	}

	private function count_result( int $count, bool $include_row ): WP_Markdown_Query_Result {
		$rows = $include_row ? array( array( 'COUNT(*)' => (string) $count ) ) : array();
		return WP_Markdown_Query_Result::selected(
			$rows,
			array( array( 'name' => 'COUNT(*)', 'table' => '', 'type' => 8 ) )
		);
	}

	/**
	 * Apply one MySQL transaction-control statement to the canonical journal.
	 *
	 * @param array{action:string,savepoint?:string} $control Classified statement.
	 */
	private function execute_transaction_control( array $control ): WP_Markdown_Query_Result {
		if ( null === $this->transactions ) {
			return $this->failure( 'unsupported_transaction', 'mdi-native transaction control is unavailable.' );
		}

		$savepoint = $control['savepoint'] ?? '';
		$outcome   = match ( $control['action'] ) {
			'begin' => $this->transactions->begin(),
			'commit', 'commit_chain' => $this->transactions->commit(),
			'rollback', 'rollback_chain' => $this->transactions->rollback(),
			'savepoint' => $this->transactions->savepoint( $savepoint ),
			'rollback_to' => $this->transactions->rollback_to( $savepoint ),
			'release_savepoint' => $this->transactions->release_savepoint( $savepoint ),
			'autocommit_0' => $this->transactions->set_autocommit( false ),
			'autocommit_1' => $this->transactions->set_autocommit( true ),
			default => 'mdi-native does not support the requested transaction control statement.',
		};
		if ( in_array( $control['action'], array( 'rollback', 'rollback_chain', 'rollback_to' ), true ) ) {
			$this->registry->forget_snapshots();
		}
		if ( true !== $outcome ) {
			return $this->failure( 'transaction_control_failed', $outcome );
		}
		if ( $this->transactions->waited_for_write_lock() || in_array( $control['action'], array( 'begin', 'autocommit_0' ), true ) ) {
			$this->registry->forget_snapshots();
		}
		if ( 'commit_chain' === $control['action'] || 'rollback_chain' === $control['action'] ) {
			$chained = $this->transactions->begin();
			if ( true !== $chained ) {
				return $this->failure( 'transaction_control_failed', $chained );
			}
		}

		return WP_Markdown_Query_Result::mutated( 0 );
	}

	/** Hints affect access strategy, not rows; validate names before using native planning. */
	private function validate_index_hints( WP_Markdown_Native_Query_Plan $plan ): ?WP_Markdown_Query_Result {
		foreach ( $plan->index_hints() as $hint ) {
			$definition = $this->registry->definition( $hint['table'] );
			if ( null === $definition ) {
				return $this->failure( 'unsupported_table', 'mdi-native cannot validate an index hint for an unknown table.' );
			}
			$names = array_map( static fn( array $index ): string => strtolower( $index['name'] ), $definition['indexes'] ?? array() );
			foreach ( $hint['indexes'] as $name ) {
				$name = strtolower( $name );
				$matches = in_array( $name, $names, true ) ? array( $name ) : array_values( array_filter( $names, static fn( string $index ): bool => str_starts_with( $index, $name ) ) );
				if ( 1 !== count( $matches ) ) {
					return $this->failure( 'unsupported_index_hint', 'mdi-native requires an existing, unambiguous index in a table hint.' );
				}
			}
		}
		return null === $plan->union() ? null : $this->validate_index_hints( $plan->union() );
	}

	private function dml_table( WP_Markdown_Query_Request $request ): ?string {
		if ( 1 === preg_match( '/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE(?:\s+INTO)?|UPDATE|DELETE\s+FROM)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $request->sql(), $match ) ) {
			return $match[1];
		}
		return null;
	}

	private function execute_table_dml( WP_Markdown_Query_Request $request, string $table ): WP_Markdown_Query_Result {
		if ( 0 === strcasecmp( $request->table_prefix() . 'posts', $table ) && ! $this->registry->is_shadowed( $table ) ) {
			return null === $this->post_mutations
				? $this->failure( 'unsupported_grammar', 'mdi-native post mutations are unavailable.' )
				: $this->post_mutations->execute( $request );
		}
		return null === $this->table_mutations
			? $this->failure( 'unsupported_grammar', 'mdi-native generic table mutations are unavailable.' )
			: $this->table_mutations->execute( $request );
	}

	private function failure( string $reason, string $message ): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure(
			array(
				'code'    => 'markdown_db_native_unsupported_query',
				'reason'  => $reason,
				'message' => $message,
			)
		);
	}
}
