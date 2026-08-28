<?php
/** Atomic INSERT mutations for persisted generic table snapshots. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Table_Insert {
	/**
	 * @param array<string,int|string|null> $values
	 * @param array<int,WP_Markdown_Native_Table_Predicate>|null $unless_exists
	 */
	public function __construct(
		private readonly string $table,
		private readonly array $values,
		private readonly ?array $unless_exists = null
	) {}

	public function table(): string {
		return $this->table;
	}

	/** @return array<string,int|string|null> */
	public function values(): array {
		return $this->values;
	}

	/** @return array<int,WP_Markdown_Native_Table_Predicate>|null */
	public function unless_exists(): ?array {
		return $this->unless_exists;
	}
}

/**
 * One column restriction in a generic DML statement.
 *
 * Values are disjunctive, matching the engine's SELECT predicate model, so
 * `column IS NULL OR column = ''` is one predicate over a single column.
 */
final class WP_Markdown_Native_Table_Predicate {

	/** @param array<int,int|string> $values */
	public function __construct(
		private string $column,
		private array $values,
		private bool $matches_null
	) {}

	public function column(): string {
		return $this->column;
	}

	/** @return array<int,int|string> */
	public function values(): array {
		return $this->values;
	}

	public function matches_null(): bool {
		return $this->matches_null;
	}
}

/** One generic UPDATE or DELETE against a persisted snapshot table. */
final class WP_Markdown_Native_Table_Write {

	/**
	 * @param array<string,int|string|null>              $values     Assignments for an UPDATE.
	 * @param array<int,WP_Markdown_Native_Table_Predicate> $predicates Conjunctive restrictions.
	 */
	public function __construct(
		private string $kind,
		private string $table,
		private array $values,
		private array $predicates
	) {}

	public function is_update(): bool {
		return 'update' === $this->kind;
	}

	public function table(): string {
		return $this->table;
	}

	/** @return array<string,int|string|null> */
	public function values(): array {
		return $this->values;
	}

	/** @return array<int,WP_Markdown_Native_Table_Predicate> */
	public function predicates(): array {
		return $this->predicates;
	}
}

final class WP_Markdown_Native_Table_Insert_Parser {
	/** @var array<int,WP_Markdown_Native_SQL_Token> */
	private array $tokens = array();
	private int $position = 0;

	public function parse( WP_Markdown_Query_Request $request ): WP_Markdown_Native_Table_Insert|WP_Markdown_Query_Result {
		$sql = trim( $request->sql() );
		if ( str_ends_with( $sql, ';' ) ) {
			$sql = rtrim( substr( $sql, 0, -1 ) );
		}
		if ( '' === $sql || WP_Markdown_Native_SQL_Tokenizer::contains_statement_separator( $sql ) ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native requires one INSERT statement.' );
		}

		try {
			$this->tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $sql );
			$this->position = 0;
			$this->word( 'INSERT' );
			$this->word( 'INTO' );
			$table = $this->identifier();
			$columns = $this->identifier_list();
			if ( 0 === strcasecmp( 'SELECT', (string) $this->current()->value() ) ) {
				$this->word( 'SELECT' );
				$values = $this->select_literals();
				$this->word( 'FROM' );
				$this->word( 'DUAL' );
				$unless_exists = $this->dual_absent_predicates( $table );
			} else {
				$this->word( 'VALUES' );
				$values = $this->literal_list();
				$unless_exists = null;
			}
			$this->type( WP_Markdown_Native_SQL_Token::END );
			if ( count( $columns ) !== count( $values ) ) {
				return $this->failure( 'invalid_insert_row', 'mdi-native requires one value for every INSERT column.' );
			}
			$row = array_combine( $columns, $values );
			return false === $row
				? $this->failure( 'invalid_insert_row', 'mdi-native requires one nonempty INSERT row.' )
				: new WP_Markdown_Native_Table_Insert( $table, $row, $unless_exists );
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return WP_Markdown_Query_Result::failure(
				array(
					'code'       => 'markdown_db_native_unsupported_query',
					'reason'     => $error->reason(),
					'message'    => 'mdi-native supports one typed generic INSERT row.',
					'sql_offset' => $error->sql_offset(),
				)
			);
		}
	}

	/** Parse one generic UPDATE or DELETE against a persisted snapshot table. */
	public function parse_write( WP_Markdown_Query_Request $request ): WP_Markdown_Native_Table_Write|WP_Markdown_Query_Result {
		$sql = trim( $request->sql() );
		if ( str_ends_with( $sql, ';' ) ) {
			$sql = rtrim( substr( $sql, 0, -1 ) );
		}
		if ( '' === $sql || WP_Markdown_Native_SQL_Tokenizer::contains_statement_separator( $sql ) ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native requires one UPDATE or DELETE statement.' );
		}

		try {
			$this->tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $sql );
			$this->position = 0;
			$kind = 0 === strcasecmp( 'DELETE', (string) $this->current()->value() ) ? 'delete' : 'update';
			$values = array();
			if ( 'delete' === $kind ) {
				$this->word( 'DELETE' );
				$this->word( 'FROM' );
				$table = $this->identifier();
			} else {
				$this->word( 'UPDATE' );
				$table = $this->identifier();
				$this->word( 'SET' );
				$values = $this->assignments();
			}
			$predicates = $this->where_predicates();
			$this->type( WP_Markdown_Native_SQL_Token::END );
			return new WP_Markdown_Native_Table_Write( $kind, $table, $values, $predicates );
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return WP_Markdown_Query_Result::failure(
				array(
					'code'       => 'markdown_db_native_unsupported_query',
					'reason'     => $error->reason(),
					'message'    => 'mdi-native supports one bounded generic UPDATE or DELETE.',
					'sql_offset' => $error->sql_offset(),
				)
			);
		}
	}

	/** @return array<string,int|string|null> */
	private function assignments(): array {
		$values = array();
		do {
			$column = $this->identifier();
			$this->type( WP_Markdown_Native_SQL_Token::EQUALS );
			$values[ $column ] = $this->literal();
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		return $values;
	}

	/**
	 * Parse a bounded WHERE clause into conjunctive per-column predicates.
	 *
	 * Disjunctions must stay within one column so the restriction keeps the
	 * engine's existing predicate semantics instead of a second evaluator.
	 *
	 * @return array<int,WP_Markdown_Native_Table_Predicate>
	 */
	private function where_predicates(): array {
		if ( WP_Markdown_Native_SQL_Token::END === $this->current()->type()
			|| 0 !== strcasecmp( 'WHERE', (string) $this->current()->value() ) ) {
			return array();
		}
		$this->word( 'WHERE' );

		$columns = array();
		$nulls = array();
		$order = array();
		$conjunction = null;
		do {
			$column = $this->identifier();
			if ( ! isset( $columns[ $column ] ) ) {
				$columns[ $column ] = array();
				$nulls[ $column ] = false;
				$order[] = $column;
			}
			$token = $this->current();
			if ( 0 === strcasecmp( 'IS', (string) $token->value() ) ) {
				++$this->position;
				if ( 0 === strcasecmp( 'NOT', (string) $this->current()->value() ) ) {
					throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $token->sql_offset(), 'Unsupported IS NOT restriction.' );
				}
				$this->word( 'NULL' );
				$nulls[ $column ] = true;
			} elseif ( WP_Markdown_Native_SQL_Token::EQUALS === $token->type() ) {
				++$this->position;
				$value = $this->literal();
				if ( null === $value ) {
					// `column = NULL` never matches in MySQL.
					throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $token->sql_offset(), 'Unsupported NULL equality restriction.' );
				}
				$columns[ $column ][] = $value;
			} elseif ( 0 === strcasecmp( 'IN', (string) $token->value() ) ) {
				++$this->position;
				foreach ( $this->literal_list() as $value ) {
					if ( null === $value ) {
						throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $token->sql_offset(), 'Unsupported NULL IN member.' );
					}
					$columns[ $column ][] = $value;
				}
			} else {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $token->sql_offset(), 'Unsupported WHERE restriction.' );
			}

			$next = $this->current();
			$keyword = strtoupper( (string) $next->value() );
			if ( 'AND' !== $keyword && 'OR' !== $keyword ) {
				break;
			}
			if ( null !== $conjunction && $conjunction !== $keyword ) {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $next->sql_offset(), 'Unsupported mixed WHERE conjunction.' );
			}
			$conjunction = $keyword;
			++$this->position;
		} while ( true );

		if ( 'OR' === $conjunction && count( $order ) > 1 ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_predicate', $this->current()->sql_offset(), 'Unsupported OR restriction across columns.' );
		}

		$predicates = array();
		foreach ( $order as $column ) {
			$predicates[] = new WP_Markdown_Native_Table_Predicate( $column, $columns[ $column ], $nulls[ $column ] );
		}
		return $predicates;
	}

	private function literal(): int|string|null {
		$token = $this->current();
		if ( WP_Markdown_Native_SQL_Token::STRING === $token->type() || WP_Markdown_Native_SQL_Token::INTEGER === $token->type() || WP_Markdown_Native_SQL_Token::DECIMAL === $token->type() ) {
			++$this->position;
			return (string) $token->value();
		}
		if ( 0 === strcasecmp( 'NULL', (string) $token->value() ) ) {
			++$this->position;
			return null;
		}
		throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_literal', $token->sql_offset(), 'Unsupported literal.' );
	}

	/** @return array<int,string> */
	private function identifier_list(): array {
		$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$columns = array();
		do {
			$column = $this->identifier();
			if ( isset( $columns[ $column ] ) ) {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'duplicate_mutation_column', $this->current()->sql_offset(), 'Duplicate INSERT column.' );
			}
			$columns[ $column ] = $column;
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		return array_values( $columns );
	}

	/** @return array<int,int|string|null> */
	private function select_literals(): array {
		$values = array();
		do {
			$values[] = $this->literal();
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		return $values;
	}

	/** @return array<int,WP_Markdown_Native_Table_Predicate>|null */
	private function dual_absent_predicates( string $table ): ?array {
		$this->word( 'WHERE' );
		$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$this->word( 'SELECT' );
		if ( 0 === strcasecmp( 'NULL', (string) $this->current()->value() ) ) {
			$this->word( 'NULL' );
			$this->word( 'FROM' );
			$this->word( 'DUAL' );
			$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
			$this->word( 'IS' );
			$this->word( 'NULL' );
			return null;
		}
		$this->identifier();
		$this->word( 'FROM' );
		$from = $this->identifier();
		if ( 0 !== strcasecmp( $from, $table ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $this->current()->sql_offset(), 'INSERT SELECT FROM DUAL can only absent-check the target table.' );
		}
		$predicates = $this->where_predicates();
		if ( 0 === strcasecmp( 'LIMIT', (string) $this->current()->value() ) ) {
			$this->word( 'LIMIT' );
			$this->type( WP_Markdown_Native_SQL_Token::INTEGER );
		}
		$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		$this->word( 'IS' );
		$this->word( 'NULL' );
		return $predicates;
	}

	/** @return array<int,int|string|null> */
	private function literal_list(): array {
		$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$values = array();
		do {
			$token = $this->current();
			if ( WP_Markdown_Native_SQL_Token::STRING === $token->type() ) {
				$values[] = (string) $token->value();
				++$this->position;
			} elseif ( WP_Markdown_Native_SQL_Token::INTEGER === $token->type() || WP_Markdown_Native_SQL_Token::DECIMAL === $token->type() ) {
				$values[] = (string) $token->value();
				++$this->position;
			} elseif ( 0 === strcasecmp( 'NULL', (string) $token->value() ) ) {
				$values[] = null;
				++$this->position;
			} else {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_literal', $token->sql_offset(), 'Unsupported INSERT literal.' );
			}
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		return $values;
	}

	private function identifier(): string {
		$token = $this->current();
		if ( ! in_array( $token->type(), array( WP_Markdown_Native_SQL_Token::WORD, WP_Markdown_Native_SQL_Token::KEYWORD, WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER ), true ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Expected an identifier.' );
		}
		++$this->position;
		return (string) $token->value();
	}

	private function word( string $expected ): void {
		$token = $this->current();
		if ( 0 !== strcasecmp( $expected, (string) $token->value() ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Unexpected SQL token.' );
		}
		++$this->position;
	}

	private function type( string $expected ): WP_Markdown_Native_SQL_Token {
		$token = $this->current();
		if ( $expected !== $token->type() ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Unexpected SQL token.' );
		}
		++$this->position;
		return $token;
	}

	private function current(): WP_Markdown_Native_SQL_Token {
		return $this->tokens[ $this->position ];
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

final class WP_Markdown_Native_Table_Mutation_Runtime {
	private string $state_root;
	private WP_Markdown_Native_Table_Index $index;

	public function __construct(
		string $state_root,
		private WP_Markdown_Native_Table_Registry $registry,
		private WP_Markdown_Native_Table_Insert_Parser $parser = new WP_Markdown_Native_Table_Insert_Parser(),
		private ?WP_Markdown_Native_Transaction_Journal $transactions = null
	) {
		$root = realpath( $state_root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new InvalidArgumentException( 'The canonical state root must be an existing directory.' );
		}
		$this->state_root = rtrim( $root, DIRECTORY_SEPARATOR );
		$this->index = new WP_Markdown_Native_Table_Index( $this->state_root . DIRECTORY_SEPARATOR . '_tables' );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		if ( 1 === preg_match( '/^\s*(?:UPDATE|DELETE)\b/i', $request->sql() ) ) {
			return $this->execute_write( $request );
		}
		$insert = $this->parser->parse( $request );
		if ( $insert instanceof WP_Markdown_Query_Result ) {
			return $insert;
		}
		$prefix = $request->table_prefix();
		if ( ! str_starts_with( $insert->table(), $prefix ) ) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native requires a table in the active prefix.' );
		}
		$suffix = substr( $insert->table(), strlen( $prefix ) );
		$table = $this->registry->table( $insert->table() );
		$definition = $this->registry->definition( $insert->table() );
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $suffix )
			|| null === $table
			|| ! $table['provider'] instanceof WP_Markdown_Native_JSON_Snapshot_Provider
			|| ! is_array( $definition )
			|| ! $this->is_authoritative_definition( $suffix, $definition, $prefix )
		) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native can insert only into a persisted generic snapshot table.' );
		}

		$directory = $this->tables_directory();
		if ( $directory instanceof WP_Markdown_Query_Result ) {
			return $directory;
		}
		$lock = @fopen( $directory . '/.mdi-native.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return $this->failure( 'mutation_lock_failed', 'The canonical table mutation lock could not be acquired.' );
		}

		try {
			$schema = $table['schema'];
			if ( ! $this->supports_unique_indexes( $definition ) ) {
				return $this->failure( 'unsupported_unique_collation', 'mdi-native cannot enforce a persisted string or prefix unique key without its exact collation.' );
			}
			if ( null !== $insert->unless_exists() ) {
				$existing = $table['provider']->read( new WP_Markdown_Native_Table_Access( $schema->column_names(), null, $schema->natural_order(), PHP_INT_MAX ) );
				if ( $existing instanceof WP_Markdown_Query_Result ) {
					return $existing;
				}
				$existing = is_array( $existing ) ? $existing : iterator_to_array( $existing, false );
				foreach ( $existing as $row ) {
					if ( is_array( $row ) && $this->restricts( $row, $insert->unless_exists(), $schema ) ) {
						return WP_Markdown_Query_Result::mutated( 0 );
					}
				}
			}
			$path = $directory . '/' . $suffix . '.json';
			$index = WP_Markdown_Native_Table_Index::supplies_identity( $insert->values(), $definition )
				? null
				: $this->index->load( $suffix, $path );
			if ( null !== $index ) {
				// The index answers identity and uniqueness, so the snapshot is
				// appended to rather than read, decoded, and republished.
				$row = $this->complete_row( $insert->values(), $definition, array(), $index['max'] );
				if ( $row instanceof WP_Markdown_Query_Result ) {
					return $row;
				}
				if ( true !== $schema->validate_row( $row ) ) {
					return $this->failure( 'invalid_insert_row', 'The INSERT row is outside the persisted table schema.' );
				}
				if ( ! $this->unique_values_enforceable( $row, $definition ) ) {
					return $this->failure( 'unsupported_unique_collation', 'mdi-native cannot enforce a unique key that is not exact ASCII or integer identity.' );
				}
				if ( WP_Markdown_Native_Table_Index::duplicates( $index, $row, $definition, $schema ) ) {
					return $this->failure( 'duplicate_key', 'The INSERT row duplicates a persisted unique key.' );
				}
				$appended = $this->append_row( $path, $row, 0 === $index['row_count'] );
				if ( $appended instanceof WP_Markdown_Query_Result ) {
					return $appended;
				}
				if ( ! $this->index->save( $suffix, $path, WP_Markdown_Native_Table_Index::with_row( $index, $row, $definition, $schema ), $this->transactions ) ) {
					// A snapshot without a current index stays correct and simply
					// costs a rebuild on the next insert.
					$this->index->forget( $suffix, $this->transactions );
				}
				return $this->insert_result( $row, $definition );
			}

			$rows = $table['provider']->read( new WP_Markdown_Native_Table_Access( $schema->column_names(), null, $schema->natural_order(), PHP_INT_MAX ) );
			if ( $rows instanceof WP_Markdown_Query_Result ) {
				return $rows;
			}
			$rows = is_array( $rows ) ? $rows : iterator_to_array( $rows, false );
			$row = $this->complete_row( $insert->values(), $definition, $rows );
			if ( $row instanceof WP_Markdown_Query_Result ) {
				return $row;
			}
			if ( true !== $schema->validate_row( $row ) ) {
				return $this->failure( 'invalid_insert_row', 'The INSERT row is outside the persisted table schema.' );
			}
			if ( ! $this->unique_values_enforceable( $row, $definition ) ) {
				return $this->failure( 'unsupported_unique_collation', 'mdi-native cannot enforce a unique key that is not exact ASCII or integer identity.' );
			}
			if ( $this->duplicates_unique_index( $row, $rows, $definition, $schema ) ) {
				return $this->failure( 'duplicate_key', 'The INSERT row duplicates a persisted unique key.' );
			}
			$rows[] = $row;
			$written = $this->write( $path, $rows );
			if ( $written instanceof WP_Markdown_Query_Result ) {
				return $written;
			}
			$this->index->save( $suffix, $path, WP_Markdown_Native_Table_Index::build( $rows, $definition, $schema ), $this->transactions );
			$insert_id = 0;
			foreach ( $definition['columns'] as $name => $column ) {
				if ( true === ( $column['auto_increment'] ?? false ) ) {
					$insert_id = (int) $row[ $name ];
					break;
				}
			}
			return WP_Markdown_Query_Result::mutated( 1, $insert_id );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * Resolve the auto-increment identifier assigned to a completed row.
	 *
	 * @param array<string,mixed> $row        Completed row.
	 * @param array<string,mixed> $definition Compiled definition.
	 */
	private function insert_result( array $row, array $definition ): WP_Markdown_Query_Result {
		foreach ( $definition['columns'] as $name => $column ) {
			if ( true === ( $column['auto_increment'] ?? false ) ) {
				return WP_Markdown_Query_Result::mutated( 1, (int) $row[ $name ] );
			}
		}
		return WP_Markdown_Query_Result::mutated( 1, 0 );
	}

	/**
	 * Append one encoded row inside the snapshot's JSON array.
	 *
	 * The existing rows are never decoded or re-encoded, so the cost of an
	 * insert does not grow with the size of the snapshot.
	 *
	 * @param array<string,mixed> $row Row to append.
	 */
	private function append_row( string $path, array $row, bool $empty ): true|WP_Markdown_Query_Result {
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return $this->failure( 'unsafe_table_file', 'The canonical table file is unavailable or unsafe.' );
		}
		if ( null !== $this->transactions ) {
			$recorded = $this->transactions->record( $path );
			if ( true !== $recorded ) {
				return $this->failure( 'transaction_journal_failed', $recorded );
			}
		}
		try {
			$encoded = json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		} catch ( Throwable ) {
			return $this->failure( 'table_encoding_failed', 'The canonical table row could not be encoded.' );
		}

		$handle = @fopen( $path, 'c+b' );
		if ( false === $handle ) {
			return $this->failure( 'table_append_failed', 'The canonical table file could not be opened for append.' );
		}
		try {
			$size = fstat( $handle )['size'] ?? 0;
			$window = (int) min( $size, 4096 );
			if ( 0 === $window || -1 === fseek( $handle, $size - $window ) ) {
				return $this->failure( 'table_append_failed', 'The canonical table file could not be positioned.' );
			}
			$tail = (string) fread( $handle, $window );
			$close = strrpos( $tail, ']' );
			if ( false === $close ) {
				return $this->failure( 'table_append_failed', 'The canonical table file has no array terminator.' );
			}
			$offset = $size - $window + $close;
			if ( -1 === fseek( $handle, $offset ) ) {
				return $this->failure( 'table_append_failed', 'The canonical table file could not be positioned.' );
			}
			$payload = ( $empty ? '' : ',' ) . $encoded . ']';
			if ( strlen( $payload ) !== fwrite( $handle, $payload ) ) {
				return $this->failure( 'table_append_failed', 'The canonical table row could not be appended.' );
			}
			if ( ! ftruncate( $handle, $offset + strlen( $payload ) ) ) {
				return $this->failure( 'table_append_failed', 'The canonical table file could not be truncated.' );
			}
			if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) {
				return $this->failure( 'table_append_failed', 'The canonical table row could not be flushed.' );
			}
		} finally {
			fclose( $handle );
		}
		return true;
	}

	/**
	 * @param array<string,int|string|null>   $provided   Supplied columns.
	 * @param array<string,mixed>             $definition Compiled definition.
	 * @param array<int,array<string,mixed>>  $rows       Snapshot rows, empty when maxima are supplied.
	 * @param array<string,int>|null          $maxima     Known auto-increment maxima.
	 */
	private function complete_row( array $provided, array $definition, array $rows, ?array $maxima = null ): array|WP_Markdown_Query_Result {
		if ( array_diff_key( $provided, $definition['columns'] ) ) {
			return $this->failure( 'unsupported_column', 'The INSERT references an undeclared column.' );
		}
		$row = array();
		foreach ( $definition['columns'] as $name => $column ) {
			$generate_identity = true === ( $column['auto_increment'] ?? false )
				&& ( ! array_key_exists( $name, $provided ) || null === $provided[ $name ] || '0' === (string) $provided[ $name ] );
			if ( array_key_exists( $name, $provided ) && ! $generate_identity ) {
				$row[ $name ] = $provided[ $name ];
				continue;
			}
			if ( $generate_identity ) {
				$maximum = $maxima[ $name ] ?? null;
				if ( null === $maximum ) {
					$maximum = 0;
					foreach ( $rows as $existing ) {
						$maximum = max( $maximum, (int) $existing[ $name ] );
					}
				}
				if ( PHP_INT_MAX === $maximum ) {
					return $this->failure( 'auto_increment_exhausted', 'The persisted auto-increment range is exhausted.' );
				}
				$row[ $name ] = (string) ( $maximum + 1 );
				continue;
			}
			$default = $column['default'] ?? null;
			if ( null !== $default ) {
				if ( 1 === preg_match( '/^(?:CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME)(?:\(\))?$/i', (string) $default ) ) {
					return $this->failure( 'unsupported_default', 'mdi-native cannot evaluate a dynamic column default.' );
				}
				$row[ $name ] = (string) $default;
				continue;
			}
			if ( true === ( $column['nullable'] ?? false ) ) {
				$row[ $name ] = null;
				continue;
			}
			return $this->failure( 'missing_required_column', 'The INSERT omits a required column without a deterministic default.' );
		}
		return $row;
	}

	/** @param array<string,mixed> $row @param array<int,array<string,mixed>> $rows @param array<string,mixed> $definition */
	private function duplicates_unique_index( array $row, array $rows, array $definition, WP_Markdown_Native_Table_Schema $schema ): bool {
		foreach ( $definition['indexes'] as $index ) {
			if ( true !== ( $index['unique'] ?? false ) ) {
				continue;
			}
			$columns = $index['columns'];
			$names   = array_column( $columns, 'name' );
			if ( array() === $names || array_filter( $names, static fn( string $column ): bool => null === $row[ $column ] ) ) {
				continue;
			}
			foreach ( $rows as $existing ) {
				$matches = true;
				foreach ( $columns as $column ) {
					$name   = (string) ( $column['name'] ?? '' );
					$length = $column['length'] ?? null;
					$matches = $matches && $schema->values_match(
						$name,
						$this->unique_index_value( $existing[ $name ] ?? null, $length ),
						$this->unique_index_value( $row[ $name ] ?? null, $length )
					);
				}
				if ( $matches ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @param array<string,mixed> $definition */
	/** Apply one generic UPDATE or DELETE to a persisted snapshot table. */
	private function execute_write( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$write = $this->parser->parse_write( $request );
		if ( $write instanceof WP_Markdown_Query_Result ) {
			return $write;
		}

		$prefix = $request->table_prefix();
		if ( ! str_starts_with( $write->table(), $prefix ) ) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native requires a table in the active prefix.' );
		}
		$suffix = substr( $write->table(), strlen( $prefix ) );
		$table = $this->registry->table( $write->table() );
		$definition = $this->registry->definition( $write->table() );
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $suffix )
			|| null === $table
			|| ! $table['provider'] instanceof WP_Markdown_Native_JSON_Snapshot_Provider
			|| ! is_array( $definition )
			|| ! $this->is_authoritative_definition( $suffix, $definition, $prefix )
		) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native can mutate only a persisted generic snapshot table.' );
		}

		$schema = $table['schema'];
		if ( ! $this->supports_unique_indexes( $definition ) ) {
			return $this->failure( 'unsupported_unique_collation', 'mdi-native cannot enforce a persisted string or prefix unique key without its exact collation.' );
		}
		foreach ( $write->predicates() as $predicate ) {
			if ( ! $schema->has_column( $predicate->column() ) ) {
				return $this->failure( 'unsupported_mutation_column', 'The WHERE restriction names a column outside the persisted table schema.' );
			}
		}
		foreach ( array_keys( $write->values() ) as $column ) {
			if ( ! $schema->has_column( (string) $column ) ) {
				return $this->failure( 'unsupported_mutation_column', 'The assignment names a column outside the persisted table schema.' );
			}
		}

		$directory = $this->tables_directory();
		if ( $directory instanceof WP_Markdown_Query_Result ) {
			return $directory;
		}
		$lock = @fopen( $directory . '/.mdi-native.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return $this->failure( 'mutation_lock_failed', 'The canonical table mutation lock could not be acquired.' );
		}

		try {
			$rows = $table['provider']->read( new WP_Markdown_Native_Table_Access( $schema->column_names(), null, $schema->natural_order(), PHP_INT_MAX ) );
			if ( $rows instanceof WP_Markdown_Query_Result ) {
				return $rows;
			}
			$rows = is_array( $rows ) ? $rows : iterator_to_array( $rows, false );

			$retained = array();
			$affected = 0;
			foreach ( $rows as $row ) {
				if ( ! $this->restricts( $row, $write->predicates(), $schema ) ) {
					$retained[] = $row;
					continue;
				}
				++$affected;
				if ( ! $write->is_update() ) {
					continue;
				}
				$updated = array_merge( $row, $write->values() );
				if ( true !== $schema->validate_row( $updated ) ) {
					return $this->failure( 'invalid_update_row', 'The UPDATE row is outside the persisted table schema.' );
				}
				$retained[] = $updated;
			}

			if ( 0 === $affected ) {
				return WP_Markdown_Query_Result::mutated( 0 );
			}
			$violation = $this->unique_set_violation( $retained, $definition, $schema );
			if ( $violation instanceof WP_Markdown_Query_Result ) {
				return $violation;
			}
			$path = $directory . '/' . $suffix . '.json';
			$written = $this->write( $path, $retained );
			if ( $written instanceof WP_Markdown_Query_Result ) {
				return $written;
			}
			// The republished snapshot invalidates the previous index, so it is
			// refreshed from the rows already in memory.
			$this->index->save( $suffix, $path, WP_Markdown_Native_Table_Index::build( $retained, $definition, $schema ), $this->transactions );
			return WP_Markdown_Query_Result::mutated( $affected );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * Decide whether one row satisfies every conjunctive restriction.
	 *
	 * @param array<string,mixed>                          $row        Canonical row.
	 * @param array<int,WP_Markdown_Native_Table_Predicate> $predicates Restrictions.
	 */
	private function restricts( array $row, array $predicates, WP_Markdown_Native_Table_Schema $schema ): bool {
		foreach ( $predicates as $predicate ) {
			$value = $row[ $predicate->column() ] ?? null;
			$matched = $predicate->matches_null() && null === $value;
			if ( ! $matched ) {
				foreach ( $predicate->values() as $candidate ) {
					if ( $schema->values_match( $predicate->column(), $value, $candidate ) ) {
						$matched = true;
						break;
					}
				}
			}
			if ( ! $matched ) {
				return false;
			}
		}
		return true;
	}

	private function supports_unique_indexes( array $definition ): bool {
		foreach ( $definition['indexes'] as $index ) {
			if ( true !== ( $index['unique'] ?? false ) ) {
				continue;
			}
			foreach ( $index['columns'] as $column ) {
				$name   = $column['name'] ?? '';
				$type   = (string) ( $definition['columns'][ $name ]['type'] ?? '' );
				$length = $column['length'] ?? null;
				if ( ! $this->unique_column_type_supported( $type ) ) {
					return false;
				}
				if ( null !== $length && ( ! is_int( $length ) || $length < 1 || WP_Markdown_Native_Schema_Catalog::is_integer( $type ) ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private function unique_index_value( mixed $value, mixed $length ): mixed {
		if ( null === $value || ! is_int( $length ) ) {
			return $value;
		}
		return is_string( $value ) ? substr( $value, 0, $length ) : $value;
	}

	private function unique_column_type_supported( string $type ): bool {
		return WP_Markdown_Native_Schema_Catalog::is_integer( $type ) || in_array( $type, array( 'char', 'varchar' ), true );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $definition */
	private function unique_values_enforceable( array $row, array $definition ): bool {
		foreach ( $definition['indexes'] as $index ) {
			if ( true !== ( $index['unique'] ?? false ) ) {
				continue;
			}
			foreach ( $index['columns'] as $column ) {
				$name  = (string) ( $column['name'] ?? '' );
				$value = $row[ $name ] ?? null;
				$type  = (string) ( $definition['columns'][ $name ]['type'] ?? '' );
				if ( null === $value || WP_Markdown_Native_Schema_Catalog::is_integer( $type ) ) {
					continue;
				}
				if ( ! is_string( $value ) || 1 === preg_match( '/[^\x00-\x7F]/', $value ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed>            $definition
	 */
	private function unique_set_violation( array $rows, array $definition, WP_Markdown_Native_Table_Schema $schema ): ?WP_Markdown_Query_Result {
		$seen = array();
		foreach ( $rows as $row ) {
			if ( ! $this->unique_values_enforceable( $row, $definition ) ) {
				return $this->failure( 'unsupported_unique_collation', 'mdi-native cannot enforce a unique key that is not exact ASCII or integer identity.' );
			}
			if ( $this->duplicates_unique_index( $row, $seen, $definition, $schema ) ) {
				return $this->failure( 'duplicate_key', 'The UPDATE row duplicates a persisted unique key.' );
			}
			$seen[] = $row;
		}
		return null;
	}

	/** @param array<string,mixed> $definition */
	/**
	 * Report whether a definition is authoritative enough to mutate.
	 *
	 * A plugin table earns trust from its persisted schema file. A core table
	 * carries no such file because its definition is generated from WordPress
	 * core itself and verified against a recorded hash, which is the stronger
	 * provenance of the two.
	 *
	 * @param array<string,mixed> $definition
	 */
	private function is_authoritative_definition( string $suffix, array $definition, string $prefix ): bool {
		return $this->is_persisted_definition( $suffix, $definition, $prefix )
			|| $this->is_generated_core_definition( $suffix, $definition );
	}

	/** @param array<string,mixed> $definition */
	private function is_generated_core_definition( string $suffix, array $definition ): bool {
		foreach ( array( false, true ) as $multisite ) {
			$definitions = WP_Markdown_Native_Schema_Catalog::definitions( $multisite );
			if ( isset( $definitions[ $suffix ] ) && $definitions[ $suffix ] === $definition ) {
				return true;
			}
		}
		return false;
	}

	private function is_persisted_definition( string $suffix, array $definition, string $prefix ): bool {
		$directory = realpath( $this->state_root . '/_schema' );
		$path = false === $directory ? '' : $directory . '/' . $suffix . '.sql';
		if ( false === $directory || is_link( $this->state_root . '/_schema' ) || ! is_file( $path ) || is_link( $path ) ) {
			return false;
		}
		try {
			$compiled = WP_Markdown_Native_Schema_Catalog::compile( (string) file_get_contents( $path ), array( $prefix ) );
			return array( $suffix => $definition ) === $compiled;
		} catch ( Throwable ) {
			return false;
		}
	}

	private function tables_directory(): string|WP_Markdown_Query_Result {
		$path = $this->state_root . '/_tables';
		if ( ! file_exists( $path ) && ! @mkdir( $path, 0755 ) && ! is_dir( $path ) ) {
			return $this->failure( 'tables_directory_failed', 'The canonical tables directory could not be created.' );
		}
		$root = realpath( $path );
		if ( false === $root || ! is_dir( $root ) || is_link( $path ) || dirname( $root ) !== $this->state_root ) {
			return $this->failure( 'unsafe_tables_directory', 'The canonical tables directory is unavailable or unsafe.' );
		}
		return $root;
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function write( string $path, array $rows ): true|WP_Markdown_Query_Result {
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			return $this->failure( 'unsafe_table_file', 'The canonical table file is unavailable or unsafe.' );
		}
		if ( null !== $this->transactions ) {
			$recorded = $this->transactions->record( $path );
			if ( true !== $recorded ) {
				return $this->failure( 'transaction_journal_failed', $recorded );
			}
		}
		try {
			$json = json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
			$temp = $path . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 8 ) );
		} catch ( Throwable ) {
			return $this->failure( 'table_encoding_failed', 'The canonical table rows could not be encoded.' );
		}
		$handle = @fopen( $temp, 'x+b' );
		if ( false === $handle ) {
			return $this->failure( 'table_temp_failed', 'The canonical table temporary file could not be created.' );
		}
		$error = null;
		try {
			$length = strlen( $json );
			$offset = 0;
			while ( $offset < $length ) {
				$written = fwrite( $handle, substr( $json, $offset ) );
				if ( false === $written || 0 === $written ) {
					$error = $this->failure( 'table_write_failed', 'The canonical table rows could not be written.' );
					break;
				}
				$offset += $written;
			}
			if ( null === $error && ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) ) {
				$error = $this->failure( 'table_flush_failed', 'The canonical table rows could not be flushed.' );
			}
		} finally {
			fclose( $handle );
		}
		if ( null !== $error ) {
			@unlink( $temp );
			return $error;
		}
		if ( ! @rename( $temp, $path ) ) {
			@unlink( $temp );
			return $this->failure( 'table_publish_failed', 'The canonical table rows could not be atomically published.' );
		}
		return true;
	}

	private function failure( string $reason, string $message ): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure(
			array(
				'code'    => 'markdown_db_native_table_mutation_failed',
				'reason'  => $reason,
				'message' => $message,
			)
		);
	}
}
