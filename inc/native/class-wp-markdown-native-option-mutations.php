<?php
/** Typed atomic mutations for canonical WordPress options. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Option_Mutation {
	/** @param array{option_value?:string,autoload?:string} $values */
	public function __construct(
		private readonly string $operation,
		private readonly string $option_name,
		private readonly array $values
	) {
		if ( ! in_array( $operation, array( 'insert', 'upsert', 'update', 'delete' ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported option mutation operation.' );
		}
	}

	public function is_insert(): bool {
		return 'insert' === $this->operation;
	}

	public function is_upsert(): bool {
		return 'upsert' === $this->operation;
	}

	public function is_delete(): bool {
		return 'delete' === $this->operation;
	}

	public function option_name(): string {
		return $this->option_name;
	}

	/** @return array<string,string> */
	public function values(): array {
		return $this->values;
	}
}

final class WP_Markdown_Native_Option_Mutation_Parser {
	/** @var array<int,WP_Markdown_Native_SQL_Token> */
	private array $tokens = array();
	private int $position = 0;

	public function parse( WP_Markdown_Query_Request $request ): WP_Markdown_Native_Option_Mutation|WP_Markdown_Query_Result {
		try {
			$this->tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $request->sql() );
			$this->position = 0;
			if ( 0 === strcasecmp( 'UPDATE', (string) $this->current()->value() ) ) {
				return $this->parse_update( $request );
			}
			if ( 0 === strcasecmp( 'DELETE', (string) $this->current()->value() ) ) {
				return $this->parse_delete( $request );
			}
			$this->word( 'INSERT' );
			$this->word( 'INTO' );
			$table = $this->identifier();
			if ( $request->table_prefix() . 'options' !== $table ) {
				return $this->failure( 'unsupported_mutation_table', 'mdi-native can mutate only the active canonical options table.' );
			}

			$columns = $this->identifier_list();
			$this->word( 'VALUES' );
			$values = $this->literal_list();
			if ( count( $columns ) !== count( $values ) || array( 'autoload', 'option_name', 'option_value' ) !== $this->set( $columns ) ) {
				return $this->failure( 'unsupported_option_upsert', 'mdi-native requires one complete canonical option row.' );
			}
			$row = array_combine( $columns, $values );
			if ( false === $row ) {
				return $this->failure( 'unsupported_option_upsert', 'mdi-native requires one complete canonical option row.' );
			}
			/** @var array{option_name:string,option_value:string,autoload:string} $row */
			$option_name = $row['option_name'];
			unset( $row['option_name'] );
			if ( WP_Markdown_Native_SQL_Token::END === $this->current()->type() ) {
				++$this->position;
				return new WP_Markdown_Native_Option_Mutation( 'insert', $option_name, $row );
			}

			$this->word( 'ON' );
			$this->word( 'DUPLICATE' );
			$this->word( 'KEY' );
			$this->word( 'UPDATE' );
			$assignments = array();
			do {
				$target = $this->identifier();
				$this->type( WP_Markdown_Native_SQL_Token::EQUALS );
				$this->word( 'VALUES' );
				$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
				$source = $this->identifier();
				$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
				if ( $target !== $source || isset( $assignments[ $target ] ) ) {
					return $this->failure( 'unsupported_option_upsert', 'mdi-native requires deterministic VALUES assignments for an option upsert.' );
				}
				$assignments[ $target ] = true;
				if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
					break;
				}
				++$this->position;
			} while ( true );
			$this->type( WP_Markdown_Native_SQL_Token::END );
			if ( array( 'autoload', 'option_name', 'option_value' ) !== $this->set( array_keys( $assignments ) ) ) {
				return $this->failure( 'unsupported_option_upsert', 'mdi-native requires deterministic VALUES assignments for an option upsert.' );
			}

			return new WP_Markdown_Native_Option_Mutation( 'upsert', $option_name, $row );
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return WP_Markdown_Query_Result::failure(
				array(
					'code'       => 'markdown_db_native_unsupported_query',
					'reason'     => $error->reason(),
					'message'    => 'mdi-native supports typed canonical option mutations and bounded SELECT queries only.',
					'sql_offset' => $error->sql_offset(),
				)
			);
		}
	}

	private function parse_update( WP_Markdown_Query_Request $request ): WP_Markdown_Native_Option_Mutation|WP_Markdown_Query_Result {
		$this->word( 'UPDATE' );
		$table = $this->identifier();
		if ( $request->table_prefix() . 'options' !== $table ) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native can mutate only the active canonical options table.' );
		}
		$this->word( 'SET' );
		$changes = array();
		do {
			$column = $this->identifier();
			if ( ! in_array( $column, array( 'option_value', 'autoload' ), true ) || isset( $changes[ $column ] ) ) {
				return $this->failure( 'unsupported_option_update', 'mdi-native option updates may set option_value and autoload once each.' );
			}
			$this->type( WP_Markdown_Native_SQL_Token::EQUALS );
			$changes[ $column ] = (string) $this->type( WP_Markdown_Native_SQL_Token::STRING )->value();
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		$this->word( 'WHERE' );
		if ( 'option_name' !== $this->identifier() ) {
			return $this->failure( 'unsupported_option_update', 'mdi-native option updates require one exact option_name identity.' );
		}
		$this->type( WP_Markdown_Native_SQL_Token::EQUALS );
		$option_name = (string) $this->type( WP_Markdown_Native_SQL_Token::STRING )->value();
		$this->type( WP_Markdown_Native_SQL_Token::END );
		return new WP_Markdown_Native_Option_Mutation( 'update', $option_name, $changes );
	}

	private function parse_delete( WP_Markdown_Query_Request $request ): WP_Markdown_Native_Option_Mutation|WP_Markdown_Query_Result {
		$this->word( 'DELETE' );
		$this->word( 'FROM' );
		if ( $request->table_prefix() . 'options' !== $this->identifier() ) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native can mutate only the active canonical options table.' );
		}
		$this->word( 'WHERE' );
		if ( 'option_name' !== $this->identifier() ) {
			return $this->failure( 'unsupported_option_delete', 'mdi-native option deletes require one exact option_name identity.' );
		}
		$this->type( WP_Markdown_Native_SQL_Token::EQUALS );
		$option_name = (string) $this->type( WP_Markdown_Native_SQL_Token::STRING )->value();
		$this->type( WP_Markdown_Native_SQL_Token::END );
		return new WP_Markdown_Native_Option_Mutation( 'delete', $option_name, array() );
	}

	/** @return array<int,string> */
	private function identifier_list(): array {
		$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$values = array();
		do {
			$value = $this->identifier();
			if ( isset( $values[ $value ] ) ) {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'duplicate_mutation_column', $this->current()->sql_offset(), 'Duplicate mutation column.' );
			}
			$values[ $value ] = $value;
			if ( WP_Markdown_Native_SQL_Token::COMMA !== $this->current()->type() ) {
				break;
			}
			++$this->position;
		} while ( true );
		$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
		return array_values( $values );
	}

	/** @return array<int,string> */
	private function literal_list(): array {
		$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$values = array();
		do {
			$token = $this->current();
			if ( ! in_array( $token->type(), array( WP_Markdown_Native_SQL_Token::STRING, WP_Markdown_Native_SQL_Token::INTEGER ), true ) ) {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_option_literal', $token->sql_offset(), 'Expected a string or non-negative integer option value.' );
			}
			++$this->position;
			$values[] = (string) $token->value();
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

	/** @param array<int,string> $values @return array<int,string> */
	private function set( array $values ): array {
		sort( $values, SORT_STRING );
		return $values;
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

final class WP_Markdown_Native_Option_Mutation_Runtime {
	private string $state_root;
	private WP_Markdown_Native_Table_Schema $schema;
	private WP_Markdown_Native_Option_Provider $provider;

	public function __construct(
		string $state_root,
		private WP_Markdown_Native_Option_Mutation_Parser $parser = new WP_Markdown_Native_Option_Mutation_Parser(),
		private ?WP_Markdown_Native_Transaction_Journal $transactions = null
	) {
		$root = realpath( $state_root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new InvalidArgumentException( 'The canonical state root must be an existing directory.' );
		}
		$this->state_root = rtrim( $root, DIRECTORY_SEPARATOR );
		$this->schema = WP_Markdown_Native_Runtime_Factory::options_schema();
		$this->provider = new WP_Markdown_Native_Option_Provider( $this->state_root, $this->schema );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$mutation = $this->parser->parse( $request );
		if ( $mutation instanceof WP_Markdown_Query_Result ) {
			return $mutation;
		}
		return $this->mutate( $mutation );
	}

	private function mutate( WP_Markdown_Native_Option_Mutation $mutation ): WP_Markdown_Query_Result {
		$directory = $this->options_directory();
		if ( $directory instanceof WP_Markdown_Query_Result ) {
			return $directory;
		}
		$lock = @fopen( $directory . '/.mdi-native.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return $this->failure( 'mutation_lock_failed', 'The canonical option mutation lock could not be acquired.' );
		}

		try {
			$identity = $this->schema->value_key( 'option_name', $mutation->option_name() );
			if ( null === $identity ) {
				return $this->failure( 'unsupported_option_collation', 'The option mutation requires a deterministic ASCII identity.' );
			}

			// A canonical option resolves to a deterministic path, so the common
			// mutation reads one row rather than the whole options directory.
			$existing = $this->existing_at_canonical_path( $directory, $mutation->option_name(), $identity );
			$maximum_id = 0;
			if ( null === $existing ) {
				// A miss still has to prove the identity is absent under any other
				// canonical filename, and creating a row needs the next identifier.
				$rows = $this->provider->read(
					new WP_Markdown_Native_Table_Access(
						$this->schema->column_names(),
						null,
						$this->schema->natural_order(),
						PHP_INT_MAX
					)
				);
				if ( $rows instanceof WP_Markdown_Query_Result ) {
					return $rows;
				}
				foreach ( $rows as $candidate ) {
					$maximum_id = max( $maximum_id, (int) $candidate['option_id'] );
					if ( $identity === $this->schema->value_key( 'option_name', $candidate['option_name'] ) ) {
						if ( null !== $existing ) {
							return $this->failure( 'duplicate_collated_identity', 'Canonical option files contain duplicate collated identities.' );
						}
						$existing = array(
							'path' => $directory . '/' . WP_Markdown_Canonical_Option_Path::filename( (string) $candidate['option_name'] ),
							'row'  => $candidate,
						);
					}
				}
			}

			if ( ! $mutation->is_insert() && ! $mutation->is_upsert() && null === $existing ) {
				return WP_Markdown_Query_Result::mutated( 0 );
			}
			if ( $mutation->is_insert() && null !== $existing ) {
				return $this->failure( 'duplicate_key', 'The canonical option identity already exists.' );
			}
			if ( $mutation->is_delete() ) {
				$journaled = $this->journal( $existing['path'] );
				if ( true !== $journaled ) {
					return $journaled;
				}
				if ( ! @unlink( $existing['path'] ) ) {
					return $this->failure( 'option_delete_failed', 'The canonical option row could not be deleted.' );
				}
				return WP_Markdown_Query_Result::mutated( 1 );
			}
			$is_insert = null === $existing;
			if ( $is_insert && PHP_INT_MAX === $maximum_id ) {
				return $this->failure( 'option_id_exhausted', 'The canonical option ID range is exhausted.' );
			}
			$option_id = $is_insert ? $maximum_id + 1 : (int) $existing['row']['option_id'];
			$values = $mutation->values();
			$row = ! $mutation->is_insert() && ! $mutation->is_upsert()
				? array_merge( $existing['row'], $values )
				: array(
					'option_id'    => $option_id,
					'option_name'  => $mutation->option_name(),
					'option_value' => $values['option_value'],
					'autoload'     => $values['autoload'],
				);
			if ( ! $this->schema->validate_row( $row ) ) {
				return $this->failure( 'invalid_option_row', 'The option mutation is outside the canonical WordPress schema.' );
			}
			if ( null !== $existing && $existing['row'] === $row ) {
				return WP_Markdown_Query_Result::mutated( 0 );
			}

			$path = ! $mutation->is_insert() && ! $mutation->is_upsert()
				? $existing['path']
				: $directory . '/' . WP_Markdown_Canonical_Option_Path::filename( $mutation->option_name() );
			if ( $mutation->is_upsert() && null !== $existing && $existing['path'] !== $path ) {
				return $this->failure( 'noncanonical_option_identity', 'The existing option identity does not use its canonical filename.' );
			}
			$written = $this->write( $path, $row );
			if ( $written instanceof WP_Markdown_Query_Result ) {
				return $written;
			}
			$rows_affected = $mutation->is_upsert() ? ( $is_insert ? 1 : 2 ) : 1;
			return WP_Markdown_Query_Result::mutated( $rows_affected, $is_insert ? $option_id : 0 );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function options_directory(): string|WP_Markdown_Query_Result {
		$path = $this->state_root . '/_options';
		// The first option written to a site creates its store, which is how
		// an installation takes hold in an empty directory.
		if ( ! file_exists( $path ) && ! @mkdir( $path, 0755 ) && ! is_dir( $path ) ) {
			return $this->failure( 'options_directory_failed', 'The canonical options directory could not be created.' );
		}
		$root = realpath( $path );
		if ( false === $root || ! is_dir( $root ) || is_link( $path ) || dirname( $root ) !== $this->state_root ) {
			return $this->failure( 'unsafe_options_directory', 'The canonical options directory is unavailable or unsafe.' );
		}
		return $root;
	}

	/** @param array<string,mixed> $row */
	/**
	 * Resolve one canonical option row by its deterministic path.
	 *
	 * @return array{path:string,row:array<string,mixed>}|null
	 */
	private function existing_at_canonical_path( string $directory, string $name, string $identity ): ?array {
		$path = $directory . '/' . WP_Markdown_Canonical_Option_Path::filename( $name );
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return null;
		}
		$contents = @file_get_contents( $path );
		if ( false === $contents ) {
			return null;
		}
		$row = json_decode( $contents, true );
		if ( ! is_array( $row ) || ! isset( $row['option_name'] ) ) {
			return null;
		}
		if ( $identity !== $this->schema->value_key( 'option_name', $row['option_name'] ) ) {
			return null;
		}
		foreach ( $this->schema->column_names() as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				return null;
			}
		}
		return array( 'path' => $path, 'row' => $row );
	}

	/** Journal a canonical path so an open transaction can restore it. */
	private function journal( string $path ): true|WP_Markdown_Query_Result {
		if ( null === $this->transactions ) {
			return true;
		}
		$recorded = $this->transactions->record( $path );
		return true === $recorded ? true : $this->failure( 'transaction_journal_failed', $recorded );
	}

	private function write( string $path, array $row ): true|WP_Markdown_Query_Result {
		$journaled = $this->journal( $path );
		if ( true !== $journaled ) {
			return $journaled;
		}
		try {
			$json = json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
			$temp = $path . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 8 ) );
		} catch ( Throwable ) {
			return $this->failure( 'option_encoding_failed', 'The canonical option row could not be encoded.' );
		}
		$handle = @fopen( $temp, 'x+b' );
		if ( false === $handle ) {
			return $this->failure( 'option_temp_failed', 'The canonical option temporary file could not be created.' );
		}
		$error = null;
		try {
			$length = strlen( $json );
			$offset = 0;
			while ( $offset < $length ) {
				$written = fwrite( $handle, substr( $json, $offset ) );
				if ( false === $written || 0 === $written ) {
					$error = $this->failure( 'option_write_failed', 'The canonical option row could not be written.' );
					break;
				}
				$offset += $written;
			}
			if ( null === $error && ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) ) {
				$error = $this->failure( 'option_flush_failed', 'The canonical option row could not be flushed.' );
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
			return $this->failure( 'option_publish_failed', 'The canonical option row could not be atomically published.' );
		}
		return true;
	}

	private function failure( string $reason, string $message ): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure(
			array(
				'code'    => 'markdown_db_native_mutation_failed',
				'reason'  => $reason,
				'message' => $message,
			)
		);
	}
}
