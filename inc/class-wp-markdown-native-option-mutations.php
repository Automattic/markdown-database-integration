<?php
/** Typed atomic mutations for canonical WordPress options. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Option_Upsert {
	/** @param array{option_name:string,option_value:string,autoload:string} $row */
	public function __construct(
		private readonly string $table,
		private readonly array $row
	) {}

	public function table(): string {
		return $this->table;
	}

	/** @return array{option_name:string,option_value:string,autoload:string} */
	public function row(): array {
		return $this->row;
	}
}

final class WP_Markdown_Native_Option_Update {
	/** @param array<string,string> $changes */
	public function __construct(
		private readonly string $table,
		private readonly string $option_name,
		private readonly array $changes
	) {}

	public function table(): string {
		return $this->table;
	}

	public function option_name(): string {
		return $this->option_name;
	}

	/** @return array<string,string> */
	public function changes(): array {
		return $this->changes;
	}
}

final class WP_Markdown_Native_Option_Mutation_Parser {
	/** @var array<int,WP_Markdown_Native_SQL_Token> */
	private array $tokens = array();
	private int $position = 0;

	public function parse( WP_Markdown_Query_Request $request ): WP_Markdown_Native_Option_Upsert|WP_Markdown_Native_Option_Update|WP_Markdown_Query_Result {
		try {
			$this->tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( $request->sql() );
			$this->position = 0;
			if ( 0 === strcasecmp( 'UPDATE', (string) $this->current()->value() ) ) {
				return $this->parse_update( $request );
			}
			$this->word( 'INSERT' );
			$this->word( 'INTO' );
			$table = $this->identifier();
			if ( $request->table_prefix() . 'options' !== $table ) {
				return $this->failure( 'unsupported_mutation_table', 'mdi-native can mutate only the active canonical options table.' );
			}

			$columns = $this->identifier_list();
			$this->word( 'VALUES' );
			$values = $this->string_list();
			if ( count( $columns ) !== count( $values ) || array( 'autoload', 'option_name', 'option_value' ) !== $this->set( $columns ) ) {
				return $this->failure( 'unsupported_option_upsert', 'mdi-native requires one complete canonical option row.' );
			}
			$row = array_combine( $columns, $values );
			if ( false === $row ) {
				return $this->failure( 'unsupported_option_upsert', 'mdi-native requires one complete canonical option row.' );
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

			/** @var array{option_name:string,option_value:string,autoload:string} $row */
			return new WP_Markdown_Native_Option_Upsert( $table, $row );
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return WP_Markdown_Query_Result::failure(
				array(
					'code'       => 'markdown_db_native_unsupported_query',
					'reason'     => $error->reason(),
					'message'    => 'mdi-native supports typed canonical option upserts and bounded SELECT queries only.',
					'sql_offset' => $error->sql_offset(),
				)
			);
		}
	}

	private function parse_update( WP_Markdown_Query_Request $request ): WP_Markdown_Native_Option_Update|WP_Markdown_Query_Result {
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
		return new WP_Markdown_Native_Option_Update( $table, $option_name, $changes );
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
	private function string_list(): array {
		$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
		$values = array();
		do {
			$token = $this->type( WP_Markdown_Native_SQL_Token::STRING );
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

	public function __construct(
		string $state_root,
		private WP_Markdown_Native_Option_Mutation_Parser $parser = new WP_Markdown_Native_Option_Mutation_Parser()
	) {
		$root = realpath( $state_root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new InvalidArgumentException( 'The canonical state root must be an existing directory.' );
		}
		$this->state_root = rtrim( $root, DIRECTORY_SEPARATOR );
		$this->schema = WP_Markdown_Native_Runtime_Factory::options_schema();
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$mutation = $this->parser->parse( $request );
		if ( $mutation instanceof WP_Markdown_Query_Result ) {
			return $mutation;
		}
		return $this->upsert( $mutation );
	}

	private function upsert( WP_Markdown_Native_Option_Upsert|WP_Markdown_Native_Option_Update $mutation ): WP_Markdown_Query_Result {
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
			$rows = $this->rows( $directory );
			if ( $rows instanceof WP_Markdown_Query_Result ) {
				return $rows;
			}
			$input = $mutation instanceof WP_Markdown_Native_Option_Upsert ? $mutation->row() : null;
			$option_name = null === $input ? $mutation->option_name() : $input['option_name'];
			$identity = $this->identity( $option_name );
			if ( null === $identity ) {
				return $this->failure( 'unsupported_option_collation', 'The option mutation requires a deterministic ASCII identity.' );
			}
			$existing = null;
			$maximum_id = 0;
			$option_ids = array();
			foreach ( $rows as $candidate ) {
				$candidate_id = (int) $candidate['row']['option_id'];
				if ( isset( $option_ids[ $candidate_id ] ) ) {
					return $this->failure( 'duplicate_option_id', 'Canonical option files contain duplicate option IDs.' );
				}
				$option_ids[ $candidate_id ] = true;
				$maximum_id = max( $maximum_id, $candidate_id );
				if ( $identity === $this->identity( (string) $candidate['row']['option_name'] ) ) {
					if ( null !== $existing ) {
						return $this->failure( 'duplicate_collated_identity', 'Canonical option files contain duplicate collated identities.' );
					}
					$existing = $candidate;
				}
			}

			if ( $mutation instanceof WP_Markdown_Native_Option_Update && null === $existing ) {
				return WP_Markdown_Query_Result::mutated( 0 );
			}
			$is_insert = null === $existing;
			if ( $is_insert && PHP_INT_MAX === $maximum_id ) {
				return $this->failure( 'option_id_exhausted', 'The canonical option ID range is exhausted.' );
			}
			$option_id = $is_insert ? $maximum_id + 1 : (int) $existing['row']['option_id'];
			$row = $mutation instanceof WP_Markdown_Native_Option_Update
				? array_merge( $existing['row'], $mutation->changes() )
				: array(
					'option_id'    => $option_id,
					'option_name'  => $input['option_name'],
					'option_value' => $input['option_value'],
					'autoload'     => $input['autoload'],
				);
			if ( ! $this->schema->validate_row( $row ) ) {
				return $this->failure( 'invalid_option_row', 'The option mutation is outside the canonical WordPress schema.' );
			}
			if ( null !== $existing && $existing['row'] === $row ) {
				return WP_Markdown_Query_Result::mutated( 0 );
			}

			$path = $mutation instanceof WP_Markdown_Native_Option_Update
				? $existing['path']
				: $directory . '/' . WP_Markdown_Canonical_Option_Path::filename( $input['option_name'] );
			if ( $mutation instanceof WP_Markdown_Native_Option_Upsert && null !== $existing && $existing['path'] !== $path ) {
				return $this->failure( 'noncanonical_option_identity', 'The existing option identity does not use its canonical filename.' );
			}
			$written = $this->write( $path, $row );
			if ( $written instanceof WP_Markdown_Query_Result ) {
				return $written;
			}
			$rows_affected = $mutation instanceof WP_Markdown_Native_Option_Update ? 1 : ( $is_insert ? 1 : 2 );
			return WP_Markdown_Query_Result::mutated( $rows_affected, $is_insert ? $option_id : 0 );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function options_directory(): string|WP_Markdown_Query_Result {
		$path = $this->state_root . '/_options';
		$root = realpath( $path );
		if ( false === $root || ! is_dir( $root ) || is_link( $path ) || dirname( $root ) !== $this->state_root ) {
			return $this->failure( 'unsafe_options_directory', 'The canonical options directory is unavailable or unsafe.' );
		}
		return $root;
	}

	/** @return array<int,array{path:string,row:array<string,mixed>}>|WP_Markdown_Query_Result */
	private function rows( string $directory ): array|WP_Markdown_Query_Result {
		$rows = array();
		try {
			$entries = new FilesystemIterator( $directory, FilesystemIterator::SKIP_DOTS );
		} catch ( UnexpectedValueException ) {
			return $this->failure( 'unreadable_options_directory', 'The canonical options directory cannot be enumerated.' );
		}
		foreach ( $entries as $entry ) {
			if ( ! str_ends_with( $entry->getFilename(), '.json' ) ) {
				continue;
			}
			$path = $directory . '/' . $entry->getFilename();
			$stat = @lstat( $path );
			if ( is_link( $path ) || false === $stat || ! is_file( $path ) || 1 !== ( $stat['nlink'] ?? 1 ) ) {
				return $this->failure( 'unsafe_option_file', 'A canonical option file is unsafe to mutate.' );
			}
			try {
				$row = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
			} catch ( JsonException ) {
				return $this->failure( 'invalid_option_json', 'A canonical option file contains invalid JSON.' );
			}
			if ( ! is_array( $row ) || ! $this->schema->validate_row( $row ) || $entry->getFilename() !== WP_Markdown_Canonical_Option_Path::filename( (string) $row['option_name'] ) ) {
				return $this->failure( 'invalid_option_row', 'A canonical option file contains an invalid row.' );
			}
			$rows[] = array( 'path' => $path, 'row' => $row );
		}
		return $rows;
	}

	/** @param array<string,mixed> $row */
	private function write( string $path, array $row ): true|WP_Markdown_Query_Result {
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

	private function identity( string $name ): ?string {
		return 1 === preg_match( '/^[\x00-\x7F]*$/D', $name ) ? strtolower( $name ) : null;
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
