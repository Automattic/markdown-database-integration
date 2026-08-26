<?php
/** Atomic INSERT mutations for persisted generic table snapshots. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Table_Insert {
	/** @param array<string,int|string|null> $values */
	public function __construct(
		private readonly string $table,
		private readonly array $values
	) {}

	public function table(): string {
		return $this->table;
	}

	/** @return array<string,int|string|null> */
	public function values(): array {
		return $this->values;
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
		if ( '' === $sql || str_contains( $sql, ';' ) ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native requires one INSERT statement.' );
		}

		try {
			$this->tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $sql );
			$this->position = 0;
			$this->word( 'INSERT' );
			$this->word( 'INTO' );
			$table = $this->identifier();
			$columns = $this->identifier_list();
			$this->word( 'VALUES' );
			$values = $this->literal_list();
			$this->type( WP_Markdown_Native_SQL_Token::END );
			if ( count( $columns ) !== count( $values ) ) {
				return $this->failure( 'invalid_insert_row', 'mdi-native requires one value for every INSERT column.' );
			}
			$row = array_combine( $columns, $values );
			return false === $row
				? $this->failure( 'invalid_insert_row', 'mdi-native requires one nonempty INSERT row.' )
				: new WP_Markdown_Native_Table_Insert( $table, $row );
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
	private function literal_list(): array {
		$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$values = array();
		do {
			$token = $this->current();
			if ( WP_Markdown_Native_SQL_Token::STRING === $token->type() ) {
				$values[] = (string) $token->value();
				++$this->position;
			} elseif ( WP_Markdown_Native_SQL_Token::INTEGER === $token->type() ) {
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

	public function __construct(
		string $state_root,
		private WP_Markdown_Native_Table_Registry $registry,
		private WP_Markdown_Native_Table_Insert_Parser $parser = new WP_Markdown_Native_Table_Insert_Parser()
	) {
		$root = realpath( $state_root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new InvalidArgumentException( 'The canonical state root must be an existing directory.' );
		}
		$this->state_root = rtrim( $root, DIRECTORY_SEPARATOR );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
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
			|| ! $this->is_persisted_definition( $suffix, $definition, $prefix )
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
			if ( $this->duplicates_unique_index( $row, $rows, $definition, $schema ) ) {
				return $this->failure( 'duplicate_key', 'The INSERT row duplicates a persisted unique key.' );
			}
			$rows[] = $row;
			$written = $this->write( $directory . '/' . $suffix . '.json', $rows );
			if ( $written instanceof WP_Markdown_Query_Result ) {
				return $written;
			}
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

	/** @param array<string,int|string|null> $provided @param array<string,mixed> $definition @param array<int,array<string,mixed>> $rows */
	private function complete_row( array $provided, array $definition, array $rows ): array|WP_Markdown_Query_Result {
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
				$maximum = 0;
				foreach ( $rows as $existing ) {
					$maximum = max( $maximum, (int) $existing[ $name ] );
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
			$columns = array_column( $index['columns'], 'name' );
			if ( array() === $columns || array_filter( $columns, static fn( string $column ): bool => null === $row[ $column ] ) ) {
				continue;
			}
			foreach ( $rows as $existing ) {
				$matches = true;
				foreach ( $columns as $column ) {
					$matches = $matches && $schema->values_match( $column, $existing[ $column ], $row[ $column ] );
				}
				if ( $matches ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @param array<string,mixed> $definition */
	private function supports_unique_indexes( array $definition ): bool {
		$integer_types = array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint' );
		foreach ( $definition['indexes'] as $index ) {
			if ( true !== ( $index['unique'] ?? false ) ) {
				continue;
			}
			foreach ( $index['columns'] as $column ) {
				$name = $column['name'] ?? '';
				if ( null !== ( $column['length'] ?? null )
					|| ! in_array( $definition['columns'][ $name ]['type'] ?? '', $integer_types, true )
				) {
					return false;
				}
			}
		}
		return true;
	}

	/** @param array<string,mixed> $definition */
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
