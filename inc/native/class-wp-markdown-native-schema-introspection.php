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

			$full_columns = false;
			if ( $this->is_word( 'FULL' ) ) {
				$this->word( 'FULL' );
				$full_columns = true;
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
				return new WP_Markdown_Native_Schema_Query( $full_columns ? 'full_columns' : 'columns', $table, $pattern );
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
	private const MAX_INFORMATION_SCHEMA_PROJECTIONS = 32;
	private const MAX_INFORMATION_SCHEMA_VALUES = 100;
	private const MAX_INFORMATION_SCHEMA_ROWS = 1000;
	public function __construct(
		private readonly WP_Markdown_Native_Table_Registry $registry,
		private readonly WP_Markdown_Native_Schema_Introspection_Parser $parser = new WP_Markdown_Native_Schema_Introspection_Parser(),
		private readonly ?string $database_name = null
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
		return in_array( $query->operation(), array( 'columns', 'full_columns' ), true )
			? $this->columns( (string) $query->table(), $definition, $query->pattern(), 'full_columns' === $query->operation() )
			: $this->indexes( (string) $query->table(), $definition, $query->predicates() );
	}

	/**
	 * Answer bounded information_schema catalog reads from registered native DDL.
	 *
	 * This is deliberately separate from physical-table SELECT planning: a native
	 * directory has no server catalog to scan, so callers must name the requested
	 * tables before catalog rows are materialized.
	 */
	public function select_information_schema( WP_Markdown_Query_Request $request ): ?WP_Markdown_Query_Result {
		try {
			$tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( rtrim( trim( $request->sql() ), ';' ) );
			$position = 0;
			$word = static function ( string $expected ) use ( &$tokens, &$position ): bool {
				if ( 0 !== strcasecmp( $expected, (string) ( $tokens[ $position ] ?? null )?->value() ) ) {
					return false;
				}
				++$position;
				return true;
			};
			$identifier = static function () use ( &$tokens, &$position ): ?string {
				$token = $tokens[ $position ] ?? null;
				if ( ! $token instanceof WP_Markdown_Native_SQL_Token || ! in_array( $token->type(), array( WP_Markdown_Native_SQL_Token::WORD, WP_Markdown_Native_SQL_Token::KEYWORD, WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER ), true ) ) {
					return null;
				}
				++$position;
				return (string) $token->value();
			};
			if ( ! $word( 'SELECT' ) ) {
				return null;
			}
			$projection = array();
			do {
				if ( count( $projection ) >= self::MAX_INFORMATION_SCHEMA_PROJECTIONS ) {
					return $this->failure( 'resource_limit', 'mdi-native limits information_schema projection cardinality.' );
				}
				$name = $identifier();
				if ( null === $name ) {
					return null;
				}
				$alias = $name;
				if ( $word( 'AS' ) ) {
					$alias = $identifier();
					if ( null === $alias ) {
						return null;
					}
				}
				$projection[] = array( 'name' => strtoupper( $name ), 'alias' => $alias );
			} while ( WP_Markdown_Native_SQL_Token::COMMA === ( $tokens[ $position ] ?? null )?->type() && ++$position );
			if ( ! $word( 'FROM' ) || 0 !== strcasecmp( 'information_schema', (string) $identifier() ) || WP_Markdown_Native_SQL_Token::DOT !== ( $tokens[ $position ] ?? null )?->type() ) {
				return null;
			}
			++$position;
			$catalog = strtoupper( (string) $identifier() );
			if ( ! in_array( $catalog, array( 'COLUMNS', 'TABLES' ), true ) ) {
				return null;
			}
			if ( ! $word( 'WHERE' ) ) {
				return $this->failure( 'unsupported_lookup', 'mdi-native requires a bounded information_schema table lookup.' );
			}
			$predicates = array();
			do {
				$column = strtoupper( (string) $identifier() );
				$values = array();
				if ( ! in_array( $column, array( 'TABLE_SCHEMA', 'TABLE_NAME', 'COLUMN_NAME' ), true ) ) {
					return null;
				}
				if ( 'TABLE_SCHEMA' === $column && WP_Markdown_Native_SQL_Token::EQUALS === ( $tokens[ $position ] ?? null )?->type() ) {
					++$position;
					if ( $word( 'DATABASE' ) && WP_Markdown_Native_SQL_Token::LEFT_PAREN === ( $tokens[ $position ] ?? null )?->type() && WP_Markdown_Native_SQL_Token::RIGHT_PAREN === ( $tokens[ $position + 1 ] ?? null )?->type() ) {
						$values[] = $this->database_name();
						$position += 2;
					} elseif ( WP_Markdown_Native_SQL_Token::STRING === ( $tokens[ $position ] ?? null )?->type() ) {
						$values[] = (string) $tokens[ $position++ ]->value();
					} else {
						return null;
					}
				} elseif ( ( 'TABLE_NAME' === $column || 'COLUMN_NAME' === $column ) && ( $word( 'IN' ) || WP_Markdown_Native_SQL_Token::EQUALS === ( $tokens[ $position ] ?? null )?->type() ) ) {
					if ( WP_Markdown_Native_SQL_Token::EQUALS === ( $tokens[ $position ] ?? null )?->type() ) {
						++$position;
						$token = $tokens[ $position++ ] ?? null;
						if ( ! $token instanceof WP_Markdown_Native_SQL_Token || WP_Markdown_Native_SQL_Token::STRING !== $token->type() ) { return null; }
						$values[] = (string) $token->value();
					} else {
						if ( WP_Markdown_Native_SQL_Token::LEFT_PAREN !== ( $tokens[ $position ] ?? null )?->type() ) { return null; }
						++$position;
						do {
							if ( count( $values ) >= self::MAX_INFORMATION_SCHEMA_VALUES ) {
								return $this->failure( 'resource_limit', 'mdi-native limits information_schema predicate cardinality.' );
							}
							$token = $tokens[ $position++ ] ?? null;
							if ( ! $token instanceof WP_Markdown_Native_SQL_Token || WP_Markdown_Native_SQL_Token::STRING !== $token->type() ) { return null; }
							$values[] = (string) $token->value();
						} while ( WP_Markdown_Native_SQL_Token::COMMA === ( $tokens[ $position ] ?? null )?->type() && ++$position );
						if ( WP_Markdown_Native_SQL_Token::RIGHT_PAREN !== ( $tokens[ $position ] ?? null )?->type() ) { return null; }
						++$position;
					}
				} else {
					return null;
				}
				$predicates[ $column ] = isset( $predicates[ $column ] ) ? array_values( array_intersect( $predicates[ $column ], $values ) ) : array_values( array_unique( $values ) );
			} while ( $word( 'AND' ) );
			if ( ! isset( $predicates['TABLE_SCHEMA'], $predicates['TABLE_NAME'] ) || WP_Markdown_Native_SQL_Token::END !== ( $tokens[ $position ] ?? null )?->type() ) {
				return $this->failure( 'unsupported_lookup', 'mdi-native requires a bounded information_schema table lookup.' );
			}
			$schema = $this->database_name();
			if ( ! in_array( $schema, $predicates['TABLE_SCHEMA'], true ) || array() === $predicates['TABLE_NAME'] ) {
				return WP_Markdown_Query_Result::selected( array(), $this->information_schema_metadata( $projection, $catalog ) );
			}
			$rows = array();
			foreach ( $predicates['TABLE_NAME'] as $table ) {
				$definition = $this->registry->definition( $table );
				if ( null === $definition || array() === $definition ) {
					continue;
				}
				$catalog_rows = 'COLUMNS' === $catalog ? $this->information_schema_columns( $table, $definition ) : array( $this->information_schema_table( $table ) );
				foreach ( $catalog_rows as $catalog_row ) {
					if ( count( $rows ) >= self::MAX_INFORMATION_SCHEMA_ROWS ) {
						return $this->failure( 'resource_limit', 'mdi-native limits information_schema result cardinality.' );
					}
					if ( isset( $predicates['COLUMN_NAME'] ) && ! in_array( $catalog_row['COLUMN_NAME'] ?? null, $predicates['COLUMN_NAME'], true ) ) {
						continue;
					}
					$row = array();
					foreach ( $projection as $column ) {
						if ( ! array_key_exists( $column['name'], $catalog_row ) ) {
							return $this->failure( 'unsupported_column', 'mdi-native cannot report the requested information_schema column.' );
						}
						$row[ $column['alias'] ] = $catalog_row[ $column['name'] ];
					}
					$rows[] = $row;
				}
			}
			if ( 'COLUMNS' === $catalog && 1 === count( $predicates['TABLE_NAME'] ) && in_array( 'COLUMN_NAME', array_column( $projection, 'name' ), true ) ) {
				usort( $rows, static fn( array $left, array $right ): int => strcmp( (string) ( $left['COLUMN_NAME'] ?? '' ), (string) ( $right['COLUMN_NAME'] ?? '' ) ) );
			}
			return WP_Markdown_Query_Result::selected( $rows, $this->information_schema_metadata( $projection, $catalog ) );
		} catch ( WP_Markdown_Native_SQL_Parse_Error ) {
			return null;
		}
	}

	/**
	 * Discover the real tables needed to answer a bounded catalog request. This
	 * intentionally recognizes only the literal TABLE_NAME predicates accepted
	 * by the catalog executor; virtual catalog tables are never snapshotted.
	 *
	 * @return array<int,string>|null
	 */
	public static function requested_information_schema_tables( string $sql ): ?array {
		try {
			$tokens = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( rtrim( trim( $sql ), ';' ) );
		} catch ( WP_Markdown_Native_SQL_Parse_Error ) {
			return null;
		}
		$position = 0;
		$word = static function ( string $expected ) use ( &$tokens, &$position ): bool {
			if ( 0 !== strcasecmp( $expected, (string) ( $tokens[ $position ] ?? null )?->value() ) ) {
				return false;
			}
			++$position;
			return true;
		};
		$identifier = static function () use ( &$tokens, &$position ): ?string {
			$token = $tokens[ $position ] ?? null;
			if ( ! $token instanceof WP_Markdown_Native_SQL_Token || ! in_array( $token->type(), array( WP_Markdown_Native_SQL_Token::WORD, WP_Markdown_Native_SQL_Token::KEYWORD, WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER ), true ) ) {
				return null;
			}
			++$position;
			return (string) $token->value();
		};
		if ( ! $word( 'SELECT' ) ) {
			return null;
		}
		while ( ! $word( 'FROM' ) ) {
			if ( WP_Markdown_Native_SQL_Token::END === ( $tokens[ $position ] ?? null )?->type() ) {
				return null;
			}
			++$position;
		}
		if ( 0 !== strcasecmp( 'information_schema', (string) $identifier() ) || WP_Markdown_Native_SQL_Token::DOT !== ( $tokens[ $position ] ?? null )?->type() ) {
			return null;
		}
		++$position;
		$catalog = strtoupper( (string) $identifier() );
		if ( ! in_array( $catalog, array( 'COLUMNS', 'TABLES' ), true ) || ! $word( 'WHERE' ) ) {
			return null;
		}
		$tables = null;
		do {
			$column = strtoupper( (string) $identifier() );
			if ( 'TABLE_NAME' !== $column ) {
				while ( WP_Markdown_Native_SQL_Token::END !== ( $tokens[ $position ] ?? null )?->type() && 0 !== strcasecmp( 'AND', (string) ( $tokens[ $position ] ?? null )?->value() ) ) {
					++$position;
				}
				continue;
			}
			$values = array();
			if ( WP_Markdown_Native_SQL_Token::EQUALS === ( $tokens[ $position ] ?? null )?->type() ) {
				++$position;
				$token = $tokens[ $position++ ] ?? null;
				if ( ! $token instanceof WP_Markdown_Native_SQL_Token || WP_Markdown_Native_SQL_Token::STRING !== $token->type() ) { return null; }
				$values[] = (string) $token->value();
			} elseif ( $word( 'IN' ) && WP_Markdown_Native_SQL_Token::LEFT_PAREN === ( $tokens[ $position ] ?? null )?->type() ) {
				++$position;
				do {
					$token = $tokens[ $position++ ] ?? null;
					if ( ! $token instanceof WP_Markdown_Native_SQL_Token || WP_Markdown_Native_SQL_Token::STRING !== $token->type() || count( $values ) >= self::MAX_INFORMATION_SCHEMA_VALUES ) { return null; }
					$values[] = (string) $token->value();
				} while ( WP_Markdown_Native_SQL_Token::COMMA === ( $tokens[ $position ] ?? null )?->type() && ++$position );
				if ( WP_Markdown_Native_SQL_Token::RIGHT_PAREN !== ( $tokens[ $position ] ?? null )?->type() ) { return null; }
				++$position;
			} else {
				return null;
			}
			$tables = null === $tables ? $values : array_values( array_intersect( $tables, $values ) );
		} while ( $word( 'AND' ) );
		return WP_Markdown_Native_SQL_Token::END === ( $tokens[ $position ] ?? null )?->type() && is_array( $tables ) && array() !== $tables
			? array_values( array_unique( $tables ) )
			: null;
	}

	/** @param array{columns:array<string,array<string,mixed>>,indexes:array<int,array<string,mixed>>} $definition @return array<int,array<string,int|string|null>> */
	private function information_schema_columns( string $table, array $definition ): array {
		$rows = array();
		foreach ( $definition['columns'] as $position => $column ) {
			$rows[] = array(
				'TABLE_SCHEMA' => $this->database_name(),
				'TABLE_NAME' => $table,
				'COLUMN_NAME' => $position,
				'ORDINAL_POSITION' => (string) ( count( $rows ) + 1 ),
				'COLUMN_DEFAULT' => $column['default'],
				'IS_NULLABLE' => $column['nullable'] ? 'YES' : 'NO',
				'DATA_TYPE' => strtolower( (string) $column['type'] ),
				'COLUMN_TYPE' => $this->column_type( $column ),
				'COLUMN_KEY' => $this->column_key( $position, $definition['indexes'] ),
				'EXTRA' => $column['auto_increment'] ? 'auto_increment' : '',
				'CHARACTER_MAXIMUM_LENGTH' => null === $this->character_maximum_length( $column ) ? null : (string) $this->character_maximum_length( $column ),
			);
		}
		return $rows;
	}

	/** @return array<string,string> */
	private function information_schema_table( string $table ): array {
		return array( 'TABLE_SCHEMA' => $this->database_name(), 'TABLE_NAME' => $table, 'TABLE_TYPE' => 'BASE TABLE' );
	}

	/** @param array<string,mixed> $column */
	private function character_maximum_length( array $column ): ?int {
		$type = strtolower( (string) $column['type'] );
		if ( in_array( $type, array( 'char', 'varchar', 'binary', 'varbinary' ), true ) && is_int( $column['length'] ) ) {
			return $column['length'];
		}
		return match ( $type ) {
			'tinytext', 'tinyblob' => 255,
			'text', 'blob' => 65535,
			'mediumtext', 'mediumblob' => 16777215,
			'longtext', 'longblob' => 4294967295,
			default => null,
		};
	}

	/** @param array<int,array{name:string,alias:string}> $projection @return array<int,array{name:string,type:int,table:string}> */
	private function information_schema_metadata( array $projection, string $catalog ): array {
		return array_map(
			static fn( array $column ): array => array(
				'name' => $column['alias'],
				'table' => $catalog,
				'type' => match ( $column['name'] ) {
					'ORDINAL_POSITION', 'CHARACTER_MAXIMUM_LENGTH' => 8,
					'DATA_TYPE' => 251,
					default => 253,
				},
			),
			$projection
		);
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
		$column = 'Tables_in_' . $this->database_name();
		foreach ( $this->registry->table_names() as $table ) {
			if ( null === $pattern || $this->matches( $table, $pattern ) ) {
				$rows[] = array( $column => $table );
			}
		}
		sort( $rows, SORT_REGULAR );
		return WP_Markdown_Query_Result::selected( $rows, array( array( 'name' => $column, 'type' => 253, 'table' => '' ) ) );
	}

	/** @param array{columns:array<string,array<string,mixed>>,indexes:array<int,array<string,mixed>>} $definition */
	private function columns( string $table, array $definition, ?string $pattern, bool $full = false ): WP_Markdown_Query_Result {
		$rows = array();
		foreach ( $definition['columns'] as $name => $column ) {
			if ( null !== $pattern && ! $this->matches( $name, $pattern ) ) {
				continue;
			}
			$row = array(
				'Field'   => $name,
				'Type'    => $this->column_type( $column ),
				'Null'    => $column['nullable'] ? 'YES' : 'NO',
				'Key'     => $this->column_key( $name, $definition['indexes'] ),
				'Default' => $column['default'],
				'Extra'   => $column['auto_increment'] ? 'auto_increment' : '',
			);
			if ( $full ) {
				$row = array_merge(
					array_slice( $row, 0, 2, true ),
					array( 'Collation' => $this->column_collation( $column ) ),
					array_slice( $row, 2, null, true ),
					array( 'Privileges' => 'select,insert,update,references', 'Comment' => '' )
				);
			}
			$rows[] = $row;
		}
		return WP_Markdown_Query_Result::selected( $rows, $this->metadata( $full ? array( 'Field', 'Type', 'Collation', 'Null', 'Key', 'Default', 'Extra', 'Privileges', 'Comment' ) : array( 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra' ), $table ) );
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

	/** @param array<string,mixed> $column */
	private function column_collation( array $column ): ?string {
		return in_array( strtolower( (string) $column['type'] ), array( 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set' ), true )
			? 'utf8mb4_general_ci'
			: null;
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

	private function database_name(): string {
		return $this->database_name ?? ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' );
	}
}
