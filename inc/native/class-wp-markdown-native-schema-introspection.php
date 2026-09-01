<?php
/** Typed MySQL schema introspection over the registered native table catalog. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Schema_Query {
	/** @param array<string,string> $predicates */
	public function __construct(
		private readonly string $operation,
		private readonly ?string $table = null,
		private readonly ?string $pattern = null,
		private readonly array $predicates = array(),
		private readonly array $names = array()
	) {}

	/** @return array<int,string> */
	public function names(): array {
		return $this->names;
	}

	public function operation(): string {
		return $this->operation;
	}

	public function table(): ?string {
		return $this->table;
	}

	public function pattern(): ?string {
		return $this->pattern;
	}

	/** @return array<string,string> */
	public function predicates(): array {
		return $this->predicates;
	}
}

final class WP_Markdown_Native_Schema_Introspection_Parser {
	/** @var array<int,WP_Markdown_Native_SQL_Token> */
	private array $tokens = array();
	private int $position = 0;

	public function parse( string $sql ): WP_Markdown_Native_Schema_Query|WP_Markdown_Query_Result {
		try {
			$this->tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( rtrim( trim( $sql ), ';' ) );
			$this->position = 0;
			if ( $this->is_word( 'DESCRIBE' ) ) {
				$this->word( 'DESCRIBE' );
				$table = $this->identifier();
				$this->end();
				return new WP_Markdown_Native_Schema_Query( 'columns', $table );
			}

			$this->word( 'SHOW' );
			// Scope qualifiers do not change what a file-backed engine reports.
			if ( $this->is_word( 'GLOBAL' ) || $this->is_word( 'SESSION' ) ) {
				++$this->position;
			}
			if ( $this->is_word( 'VARIABLES' ) || $this->is_word( 'STATUS' ) ) {
				$operation = $this->is_word( 'VARIABLES' ) ? 'variables' : 'status';
				++$this->position;
				$names = array();
				$pattern = null;
				if ( $this->is_word( 'LIKE' ) ) {
					$this->word( 'LIKE' );
					$pattern = $this->string();
				} elseif ( $this->is_word( 'WHERE' ) ) {
					$this->word( 'WHERE' );
					$this->identifier();
					$this->word( 'IN' );
					$this->type( WP_Markdown_Native_SQL_Token::LEFT_PAREN );
					do {
						$names[] = $this->string();
					} while ( $this->match_type( WP_Markdown_Native_SQL_Token::COMMA ) );
					$this->type( WP_Markdown_Native_SQL_Token::RIGHT_PAREN );
				}
				$this->end();
				return new WP_Markdown_Native_Schema_Query( $operation, null, $pattern, array(), $names );
			}
			if ( $this->is_word( 'TABLES' ) ) {
				$this->word( 'TABLES' );
				$pattern = null;
				if ( $this->is_word( 'LIKE' ) ) {
					$this->word( 'LIKE' );
					$pattern = $this->string();
				}
				$this->end();
				return new WP_Markdown_Native_Schema_Query( 'tables', null, $pattern );
			}

			if ( $this->is_word( 'COLUMNS' ) ) {
				$this->word( 'COLUMNS' );
				$this->word( 'FROM' );
				$table = $this->identifier();
				$pattern = null;
				if ( $this->is_word( 'LIKE' ) ) {
					$this->word( 'LIKE' );
					$pattern = $this->string();
				}
				$this->end();
				return new WP_Markdown_Native_Schema_Query( 'columns', $table, $pattern );
			}

			if ( $this->is_word( 'INDEX' ) ) {
				$this->word( 'INDEX' );
			} elseif ( $this->is_word( 'KEYS' ) ) {
				$this->word( 'KEYS' );
			} else {
				$this->word( 'KEY' );
			}
			$this->word( 'FROM' );
			$table = $this->identifier();
			$pattern = null;
			if ( $this->is_word( 'LIKE' ) ) {
				$this->word( 'LIKE' );
				$pattern = $this->string();
			}
			$predicates = array();
			if ( $this->is_word( 'WHERE' ) ) {
				$this->word( 'WHERE' );
				$predicates = $this->index_predicates();
			}
			$this->end();
			return new WP_Markdown_Native_Schema_Query( 'indexes', $table, $pattern, $predicates );
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return WP_Markdown_Query_Result::failure(
				array(
					'code'       => 'markdown_db_native_unsupported_query',
					'reason'     => $error->reason(),
					'message'    => 'mdi-native cannot execute the requested schema introspection statement.',
					'sql_offset' => $error->sql_offset(),
				)
			);
		}
	}

	/** @return array<string,string> */
	private function index_predicates(): array {
		$predicates = array();
		do {
			$column = strtolower( $this->identifier() );
			if ( ! in_array( $column, array( 'key_name', 'column_name' ), true ) ) {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $this->current()->sql_offset(), 'Expected Key_name or Column_name.' );
			}
			$token = $this->current();
			if ( WP_Markdown_Native_SQL_Token::EQUALS !== $token->type() ) {
				throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Expected = in SHOW INDEX WHERE.' );
			}
			++$this->position;
			$predicates[ $column ] = $this->string();
			if ( ! $this->is_word( 'AND' ) ) {
				break;
			}
			$this->word( 'AND' );
		} while ( true );
		return $predicates;
	}

	private function identifier(): string {
		$token = $this->current();
		if ( ! in_array( $token->type(), array( WP_Markdown_Native_SQL_Token::WORD, WP_Markdown_Native_SQL_Token::KEYWORD, WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER ), true ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Expected a table identifier.' );
		}
		++$this->position;
		return (string) $token->value();
	}

	private function string(): string {
		$token = $this->current();
		if ( WP_Markdown_Native_SQL_Token::STRING !== $token->type() ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Expected a LIKE string.' );
		}
		++$this->position;
		return (string) $token->value();
	}

	private function type( string $expected ): WP_Markdown_Native_SQL_Token {
		$token = $this->current();
		if ( $expected !== $token->type() ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Unexpected schema introspection token.' );
		}
		++$this->position;
		return $token;
	}

	private function match_type( string $expected ): bool {
		if ( $expected !== $this->current()->type() ) {
			return false;
		}
		++$this->position;
		return true;
	}

	private function word( string $expected ): void {
		$token = $this->current();
		if ( 0 !== strcasecmp( $expected, (string) $token->value() ) ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Unexpected schema introspection token.' );
		}
		++$this->position;
	}

	private function is_word( string $expected ): bool {
		return 0 === strcasecmp( $expected, (string) $this->current()->value() );
	}

	private function end(): void {
		$token = $this->current();
		if ( WP_Markdown_Native_SQL_Token::END !== $token->type() ) {
			throw new WP_Markdown_Native_SQL_Parse_Error( 'unsupported_grammar', $token->sql_offset(), 'Unexpected trailing schema introspection token.' );
		}
	}

	private function current(): WP_Markdown_Native_SQL_Token {
		return $this->tokens[ $this->position ];
	}
}

final class WP_Markdown_Native_Schema_Introspection {
	public function __construct(
		private readonly WP_Markdown_Native_Table_Registry $registry,
		private readonly WP_Markdown_Native_Schema_Introspection_Parser $parser = new WP_Markdown_Native_Schema_Introspection_Parser()
	) {}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$query = $this->parser->parse( $request->sql() );
		if ( $query instanceof WP_Markdown_Query_Result ) {
			return $query;
		}
		if ( 'tables' === $query->operation() ) {
			return $this->tables( $query->pattern() );
		}
		if ( 'variables' === $query->operation() || 'status' === $query->operation() ) {
			return $this->server_values( $query->operation(), $query->pattern(), $query->names() );
		}

		$definition = $this->registry->definition( (string) $query->table() );
		if ( null === $definition || array() === $definition ) {
			return $this->failure( 'unsupported_table', 'mdi-native cannot inspect the requested table.' );
		}
		return 'columns' === $query->operation()
			? $this->columns( (string) $query->table(), $definition, $query->pattern() )
			: $this->indexes( (string) $query->table(), $definition, $query->predicates() );
	}

	/**
	 * Report the server variables a file-backed engine can answer honestly.
	 *
	 * A tuning knob that describes a client/server database has no meaning
	 * here, so only the settings that remain true are reported. Anything else
	 * is absent rather than invented.
	 *
	 * @param array<int,string> $names
	 */
	private function server_values( string $operation, ?string $pattern, array $names ): WP_Markdown_Query_Result {
		$values = 'variables' === $operation
			? array(
				'version' => WP_Markdown_Native_Schema_Catalog::SERVER_VERSION,
				'version_comment' => 'Markdown Database Integration native engine',
				'sql_mode' => '',
				'character_set_server' => 'utf8mb4',
				'collation_server' => 'utf8mb4_general_ci',
				'foreign_key_checks' => 'ON',
				'autocommit' => 'ON',
			)
			: array( 'Uptime' => '0', 'Threads_connected' => '1', 'Queries' => '0' );
		$wanted = array_map( 'strtolower', $names );
		$rows = array();
		foreach ( $values as $name => $value ) {
			if ( array() !== $wanted && ! in_array( strtolower( $name ), $wanted, true ) ) {
				continue;
			}
			if ( null !== $pattern && ! $this->matches( $name, $pattern ) ) {
				continue;
			}
			$rows[] = array( 'Variable_name' => $name, 'Value' => $value );
		}
		return WP_Markdown_Query_Result::selected(
			$rows,
			$this->metadata( array( 'Variable_name', 'Value' ), '' )
		);
	}

	private function tables( ?string $pattern ): WP_Markdown_Query_Result {
		$rows = array();
		$column = 'Tables_in_' . ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' );
		foreach ( $this->registry->table_names() as $table ) {
			if ( null === $pattern || $this->matches( $table, $pattern ) ) {
				$rows[] = array( $column => $table );
			}
		}
		sort( $rows, SORT_REGULAR );
		return WP_Markdown_Query_Result::selected( $rows, array( array( 'name' => $column, 'type' => 253, 'table' => '' ) ) );
	}

	/** @param array{columns:array<string,array<string,mixed>>,indexes:array<int,array<string,mixed>>} $definition */
	private function columns( string $table, array $definition, ?string $pattern ): WP_Markdown_Query_Result {
		$rows = array();
		foreach ( $definition['columns'] as $name => $column ) {
			if ( null !== $pattern && ! $this->matches( $name, $pattern ) ) {
				continue;
			}
			$rows[] = array(
				'Field'   => $name,
				'Type'    => $this->column_type( $column ),
				'Null'    => $column['nullable'] ? 'YES' : 'NO',
				'Key'     => $this->column_key( $name, $definition['indexes'] ),
				'Default' => $column['default'],
				'Extra'   => $column['auto_increment'] ? 'auto_increment' : '',
			);
		}
		return WP_Markdown_Query_Result::selected( $rows, $this->metadata( array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ), $table ) );
	}

	/**
	 * @param array{columns:array<string,array<string,mixed>>,indexes:array<int,array<string,mixed>>} $definition
	 * @param array<string,string> $predicates
	 */
	private function indexes( string $table, array $definition, array $predicates ): WP_Markdown_Query_Result {
		$rows = array();
		foreach ( $definition['indexes'] as $index ) {
			foreach ( $index['columns'] as $offset => $column ) {
				$row = array(
					'Table'        => $table,
					'Non_unique'   => $index['unique'] ? '0' : '1',
					'Key_name'     => $index['name'],
					'Seq_in_index' => (string) ( $offset + 1 ),
					'Column_name'  => $column['name'],
					'Sub_part'     => null === $column['length'] ? null : (string) $column['length'],
					'Index_type'   => 'BTREE',
				);
				if ( isset( $predicates['key_name'] ) && 0 !== strcasecmp( (string) $row['Key_name'], $predicates['key_name'] ) ) {
					continue;
				}
				if ( isset( $predicates['column_name'] ) && 0 !== strcasecmp( (string) $row['Column_name'], $predicates['column_name'] ) ) {
					continue;
				}
				$rows[] = $row;
			}
		}
		return WP_Markdown_Query_Result::selected( $rows, $this->metadata( array( 'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Sub_part', 'Index_type' ), $table ) );
	}

	/** @param array<string,mixed> $column */
	private function column_type( array $column ): string {
		$type = strtolower( (string) $column['type'] );
		if ( null !== $column['length'] ) {
			$type .= '(' . $column['length'] . ')';
		}
		return $type . ( $column['unsigned'] ? ' unsigned' : '' );
	}

	/** @param array<int,array<string,mixed>> $indexes */
	private function column_key( string $column, array $indexes ): string {
		foreach ( $indexes as $index ) {
			if ( $column !== ( $index['columns'][0]['name'] ?? null ) ) {
				continue;
			}
			if ( 'PRIMARY' === $index['name'] ) {
				return 'PRI';
			}
			return $index['unique'] && 1 === count( $index['columns'] ) ? 'UNI' : 'MUL';
		}
		return '';
	}

	private function matches( string $value, string $pattern ): bool {
		$expression = '';
		$escaped = false;
		foreach ( str_split( $pattern ) as $character ) {
			if ( $escaped ) {
				$expression .= preg_quote( $character, '/' );
				$escaped = false;
			} elseif ( '\\' === $character ) {
				$escaped = true;
			} elseif ( '%' === $character ) {
				$expression .= '.*';
			} elseif ( '_' === $character ) {
				$expression .= '.';
			} else {
				$expression .= preg_quote( $character, '/' );
			}
		}
		if ( $escaped ) {
			$expression .= '\\\\';
		}
		return 1 === preg_match( '/^' . $expression . '$/D', $value );
	}

	/** @param array<int,string> $names @return array<int,array{name:string,type:int,table:string}> */
	private function metadata( array $names, string $table ): array {
		return array_map( static fn( string $name ): array => array( 'name' => $name, 'type' => 253, 'table' => $table ), $names );
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
