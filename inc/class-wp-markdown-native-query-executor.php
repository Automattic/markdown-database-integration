<?php
/** Generic bounded query executor over registered providers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Query_Runtime implements WP_Markdown_Query_Runtime {

	public function __construct(
		private WP_Markdown_Native_Table_Registry $registry,
		private WP_Markdown_Native_Query_Parser $parser = new WP_Markdown_Native_Query_Parser()
	) {}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$plan = $this->parser->parse( $request->sql() );
		if ( $plan instanceof WP_Markdown_Query_Result ) {
			return $plan;
		}
		if ( array() !== $plan->joins() ) {
			return $this->execute_join( $plan );
		}

		$table = $this->registry->table( $plan->table() );
		if ( null === $table ) {
			return $this->failure( 'unsupported_table', 'mdi-native cannot query the requested table.' );
		}

		$schema     = $table['schema'];
		$predicates = $plan->predicates();
		$projection = array( '*' ) === $plan->projection() ? $schema->column_names() : $plan->projection();
		$columns    = $projection;
		foreach ( $predicates as $predicate ) {
			$columns[] = $predicate->column();
		}
		if ( null !== $plan->order() ) {
			$columns[] = $plan->order();
		}
		foreach ( $columns as $column ) {
			if ( ! $schema->has_column( $column ) ) {
				return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested column.' );
			}
		}

		foreach ( $predicates as $predicate ) {
			if ( ! $schema->allows_lookup( $predicate->column(), $predicate->operator(), $predicate->values() )
				&& ! $schema->allows_filter( $predicate->column(), $predicate->operator(), $predicate->values() )
			) {
				return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested predicate.' );
			}
		}
		$pushdown = $this->pushdown( $predicates, $schema );
		if ( array() !== $predicates && null === $pushdown ) {
			return $this->failure( 'unsupported_lookup', 'mdi-native requires one indexable predicate for a filtered query.' );
		}
		if ( null !== $plan->order() && ! $schema->allows_order( $plan->order() ) ) {
			return $this->failure( 'unsupported_order', 'mdi-native cannot apply the requested ordering collation.' );
		}

		if ( 0 === $plan->limit() ) {
			return $plan->counts_all()
				? $this->count_result( 0, false )
				: $this->result( array(), $projection, $plan->table(), $schema );
		}

		$residual = array_values( array_filter( $predicates, static fn( WP_Markdown_Native_Query_Predicate $predicate ): bool => $predicate !== $pushdown ) );
		$provider_projection = $plan->counts_all() ? array() : $projection;
		foreach ( $residual as $predicate ) {
			$provider_projection[] = $predicate->column();
		}
		if ( array() === $provider_projection ) {
			$provider_projection[] = $schema->natural_order();
		}
		$provider_projection = array_values( array_unique( $provider_projection ) );
		$provided = $table['provider']->read(
			new WP_Markdown_Native_Table_Access(
				$provider_projection,
				$pushdown,
				$plan->order() ?? $schema->natural_order(),
				$plan->counts_all() || array() !== $residual ? PHP_INT_MAX : $plan->limit()
			)
		);
		if ( $provided instanceof WP_Markdown_Query_Result ) {
			return $provided;
		}

		$rows  = array();
		$count = 0;
		foreach ( $provided as $row ) {
			if ( ! $plan->counts_all() && count( $rows ) >= $plan->limit() ) {
				break;
			}
			if ( ! is_array( $row ) || true !== $schema->validate_projection( $row, $provider_projection ) ) {
				return $this->failure( 'invalid_provider_row', 'The native table provider returned a row outside its declared schema.' );
			}
			if ( $this->matches( $row, $residual, $schema ) ) {
				if ( $plan->counts_all() ) {
					++$count;
				} else {
					$rows[] = $this->string_row( $row, $projection );
				}
			}
		}

		return $plan->counts_all()
			? $this->count_result( $count, true )
			: $this->result( $rows, $projection, $plan->table(), $schema );
	}

	private function execute_join( WP_Markdown_Native_Query_Plan $plan ): WP_Markdown_Query_Result {
		$base_alias = $plan->table_alias();
		$base = $this->registry->table( $plan->table() );
		if ( null === $base_alias || null === $base || array() === $plan->predicates() ) {
			return $this->failure( 'unsupported_join_shape', 'mdi-native requires a registered, selectively bounded JOIN source.' );
		}

		$sources = array(
			$base_alias => array( 'table' => $plan->table(), 'schema' => $base['schema'], 'provider' => $base['provider'] ),
		);
		foreach ( $plan->joins() as $join ) {
			$table = $this->registry->table( $join->table() );
			if ( null === $table || isset( $sources[ $join->alias() ] ) ) {
				return $this->failure( 'unsupported_table', 'mdi-native cannot query the requested JOIN table.' );
			}
			$sources[ $join->alias() ] = array( 'table' => $join->table(), 'schema' => $table['schema'], 'provider' => $table['provider'] );
		}

		$needed = array_fill_keys( array_keys( $sources ), array() );
		foreach ( $plan->projection() as $index => $column ) {
			$source = $plan->projection_sources()[ $index ] ?? null;
			if ( null === $source || ! isset( $sources[ $source ] ) || ! $sources[ $source ]['schema']->has_column( $column ) ) {
				return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested qualified column.' );
			}
			$needed[ $source ][] = $column;
		}
		foreach ( $plan->predicates() as $predicate ) {
			if ( $base_alias !== $predicate->source()
				|| ! $base['schema']->has_column( $predicate->column() )
				|| ! $base['schema']->allows_lookup( $predicate->column(), $predicate->operator(), $predicate->values() )
			) {
				return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested JOIN predicate.' );
			}
			$needed[ $base_alias ][] = $predicate->column();
		}
		foreach ( $plan->joins() as $join ) {
			if ( ! isset( $sources[ $join->left_source() ], $sources[ $join->right_source() ] )
				|| $join->alias() !== $join->right_source()
				|| ! $sources[ $join->left_source() ]['schema']->has_column( $join->left_column() )
				|| ! $sources[ $join->right_source() ]['schema']->has_column( $join->right_column() )
			) {
				return $this->failure( 'unsupported_join_shape', 'mdi-native cannot apply the requested equality JOIN.' );
			}
			$needed[ $join->left_source() ][] = $join->left_column();
			$needed[ $join->right_source() ][] = $join->right_column();
		}
		foreach ( $needed as &$columns ) {
			$columns = array_values( array_unique( $columns ) );
		}
		unset( $columns );

		$pushdown = $this->pushdown( $plan->predicates(), $base['schema'] );
		if ( null === $pushdown ) {
			return $this->failure( 'unsupported_lookup', 'mdi-native requires an indexable base predicate for a JOIN query.' );
		}
		$provided = $base['provider']->read(
			new WP_Markdown_Native_Table_Access( $needed[ $base_alias ], $pushdown, $base['schema']->natural_order(), PHP_INT_MAX )
		);
		if ( $provided instanceof WP_Markdown_Query_Result ) {
			return $provided;
		}
		$residual = array_values( array_filter( $plan->predicates(), static fn( WP_Markdown_Native_Query_Predicate $predicate ): bool => $predicate !== $pushdown ) );
		$rows = array();
		foreach ( $provided as $row ) {
			if ( ! is_array( $row ) || true !== $base['schema']->validate_projection( $row, $needed[ $base_alias ] ) ) {
				return $this->failure( 'invalid_provider_row', 'The native JOIN provider returned a row outside its declared schema.' );
			}
			if ( $this->matches( $row, $residual, $base['schema'] ) ) {
				$rows[] = array( $base_alias => $row );
			}
		}

		foreach ( $plan->joins() as $join ) {
			$right = $sources[ $join->right_source() ];
			$values = array();
			foreach ( $rows as $row ) {
				$value = $row[ $join->left_source() ][ $join->left_column() ];
				$key = $right['schema']->value_key( $join->right_column(), $value );
				if ( null === $key ) {
					return $this->failure( 'unsupported_join_lookup', 'mdi-native cannot normalize the requested JOIN identity.' );
				}
				if ( ! isset( $values[ $key ] ) ) {
					$values[ $key ] = $value;
				}
			}
			if ( array() === $values ) {
				break;
			}
			$values = array_values( $values );
			$operator = 1 === count( $values ) ? '=' : 'IN';
			if ( ! $right['schema']->allows_lookup( $join->right_column(), $operator, $values ) ) {
				return $this->failure( 'unsupported_join_lookup', 'mdi-native requires an indexed equality key for each JOIN source.' );
			}
			$join_predicate = new WP_Markdown_Native_Query_Predicate( $join->right_column(), $operator, $values );
			$provided = $right['provider']->read(
				new WP_Markdown_Native_Table_Access( $needed[ $join->right_source() ], $join_predicate, $right['schema']->natural_order(), PHP_INT_MAX )
			);
			if ( $provided instanceof WP_Markdown_Query_Result ) {
				return $provided;
			}
			$right_rows = array();
			foreach ( $provided as $right_row ) {
				if ( ! is_array( $right_row ) || true !== $right['schema']->validate_projection( $right_row, $needed[ $join->right_source() ] ) ) {
					return $this->failure( 'invalid_provider_row', 'The native JOIN provider returned a row outside its declared schema.' );
				}
				$key = $right['schema']->value_key( $join->right_column(), $right_row[ $join->right_column() ] );
				if ( null === $key ) {
					return $this->failure( 'invalid_provider_row', 'The native JOIN provider returned an invalid JOIN identity.' );
				}
				$right_rows[ $key ][] = $right_row;
			}
			$joined = array();
			foreach ( $rows as $index => $row ) {
				unset( $rows[ $index ] );
				$left_value = $row[ $join->left_source() ][ $join->left_column() ];
				$key = $right['schema']->value_key( $join->right_column(), $left_value );
				foreach ( null === $key ? array() : ( $right_rows[ $key ] ?? array() ) as $right_row ) {
					$row[ $join->right_source() ] = $right_row;
					$joined[] = $row;
				}
			}
			$rows = $joined;
		}

		foreach ( $rows as $row_index => $row ) {
			$selected_row = array();
			foreach ( $plan->projection() as $column_index => $column ) {
				$source = $plan->projection_sources()[ $column_index ];
				$value = $row[ $source ][ $column ];
				$selected_row[ $column ] = null === $value ? null : (string) $value;
			}
			$rows[ $row_index ] = $selected_row;
		}
		$columns = array();
		foreach ( $plan->projection() as $index => $column ) {
			$source = $plan->projection_sources()[ $index ];
			$columns[] = array( 'name' => $column, 'table' => $sources[ $source ]['table'], 'type' => $sources[ $source ]['schema']->column( $column )->type() );
		}
		return WP_Markdown_Query_Result::selected( $rows, $columns );
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
		usort(
			$candidates,
			static function ( WP_Markdown_Native_Query_Predicate $left, WP_Markdown_Native_Query_Predicate $right ): int {
				$operator = ( '=' === $left->operator() ? 0 : 1 ) <=> ( '=' === $right->operator() ? 0 : 1 );
				if ( 0 !== $operator ) {
					return $operator;
				}
				$value_count = count( $left->values() ) <=> count( $right->values() );
				return 0 !== $value_count ? $value_count : strcmp( $left->column(), $right->column() );
			}
		);
		return $candidates[0] ?? null;
	}

	/** @param array<string,mixed> $row @param array<int,WP_Markdown_Native_Query_Predicate> $predicates */
	private function matches( array $row, array $predicates, WP_Markdown_Native_Table_Schema $schema ): bool {
		foreach ( $predicates as $predicate ) {
			$matched = false;
			foreach ( $predicate->values() as $value ) {
				if ( $schema->values_match( $predicate->column(), $row[ $predicate->column() ], $value ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<int,array<string,mixed>> $rows @param array<int,string> $projection */
	private function result(
		array $rows,
		array $projection,
		string $table,
		WP_Markdown_Native_Table_Schema $schema
	): WP_Markdown_Query_Result {
		$columns = array_map(
			static fn( string $column ): array => array(
				'name'  => $column,
				'table' => $table,
				'type'  => $schema->column( $column )->type(),
			),
			$projection
		);
		return WP_Markdown_Query_Result::selected( $rows, $columns );
	}

	/** @param array<string,mixed> $source @param array<int,string> $projection @return array<string,string|null> */
	private function string_row( array $source, array $projection ): array {
		$row = array();
		foreach ( $projection as $column ) {
			$row[ $column ] = null === $source[ $column ] ? null : (string) $source[ $column ];
		}
		return $row;
	}

	private function count_result( int $count, bool $include_row ): WP_Markdown_Query_Result {
		$rows = $include_row ? array( array( 'COUNT(*)' => (string) $count ) ) : array();
		return WP_Markdown_Query_Result::selected(
			$rows,
			array( array( 'name' => 'COUNT(*)', 'table' => '', 'type' => 8 ) )
		);
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
