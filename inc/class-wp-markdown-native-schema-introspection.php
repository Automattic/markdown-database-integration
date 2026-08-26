<?php
/** Typed MySQL schema introspection over the registered native table catalog. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Schema_Query {
	public function __construct(
		private readonly string $operation,
		private readonly ?string $table = null,
		private readonly ?string $pattern = null
	) {}

	public function operation(): string {
		return $this->operation;
	}

	public function table(): ?string {
		return $this->table;
	}

	public function pattern(): ?string {
		return $this->pattern;
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
			if ( $this->is_word( 'TABLES' ) ) {
				$this->word( 'TABLES' );
				$this->word( 'LIKE' );
				$pattern = $this->string();
				$this->end();
				return new WP_Markdown_Native_Schema_Query( 'tables', null, $pattern );
			}

			$operation = $this->is_word( 'COLUMNS' ) ? 'columns' : 'indexes';
			$this->word( 'columns' === $operation ? 'COLUMNS' : 'INDEX' );
			$this->word( 'FROM' );
			$table = $this->identifier();
			$pattern = null;
			if ( $this->is_word( 'LIKE' ) ) {
				$this->word( 'LIKE' );
				$pattern = $this->string();
			}
			$this->end();
			return new WP_Markdown_Native_Schema_Query( $operation, $table, $pattern );
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
			return $this->tables( (string) $query->pattern() );
		}

		$table = $this->registry->table( (string) $query->table() );
		if ( null === $table || array() === $table['schema']->definition() ) {
			return $this->failure( 'unsupported_table', 'mdi-native cannot inspect the requested table.' );
		}
		return 'columns' === $query->operation()
			? $this->columns( (string) $query->table(), $table['schema']->definition(), $query->pattern() )
			: $this->indexes( (string) $query->table(), $table['schema']->definition() );
	}

	private function tables( string $pattern ): WP_Markdown_Query_Result {
		$rows = array();
		foreach ( $this->registry->table_names() as $table ) {
			if ( $this->matches( $table, $pattern ) ) {
				$rows[] = array( 'Table' => $table );
			}
		}
		sort( $rows, SORT_REGULAR );
		return WP_Markdown_Query_Result::selected( $rows, array( array( 'name' => 'Table', 'type' => 253, 'table' => '' ) ) );
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

	/** @param array{columns:array<string,array<string,mixed>>,indexes:array<int,array<string,mixed>>} $definition */
	private function indexes( string $table, array $definition ): WP_Markdown_Query_Result {
		$rows = array();
		foreach ( $definition['indexes'] as $index ) {
			foreach ( $index['columns'] as $offset => $column ) {
				$rows[] = array(
					'Table'        => $table,
					'Non_unique'   => $index['unique'] ? '0' : '1',
					'Key_name'     => $index['name'],
					'Seq_in_index' => (string) ( $offset + 1 ),
					'Column_name'  => $column['name'],
					'Sub_part'     => null === $column['length'] ? null : (string) $column['length'],
					'Index_type'   => 'BTREE',
				);
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
