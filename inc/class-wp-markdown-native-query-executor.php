<?php
/** Generic bounded query executor over registered providers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Query_Runtime implements WP_Markdown_Query_Runtime {
	private ?int $last_found_rows = null;

	public function __construct(
		private WP_Markdown_Native_Table_Registry $registry,
		private WP_Markdown_Native_Query_Parser $parser = new WP_Markdown_Native_Query_Parser(),
		private ?WP_Markdown_Native_Option_Mutation_Runtime $option_mutations = null
	) {}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		if ( 1 !== preg_match( '/^\s*SELECT\b/i', $request->sql() ) ) {
			return null === $this->option_mutations
				? $this->failure( 'unsupported_grammar', 'mdi-native supports bounded SELECT queries only.' )
				: $this->option_mutations->execute( $request );
		}
		$plan = $this->parser->parse( $request->sql() );
		if ( $plan instanceof WP_Markdown_Query_Result ) {
			return $plan;
		}
		if ( $plan instanceof WP_Markdown_Native_Found_Rows_Plan ) {
			return null === $this->last_found_rows
				? $this->failure( 'missing_found_rows', 'FOUND_ROWS() requires a preceding successful SQL_CALC_FOUND_ROWS query.' )
				: WP_Markdown_Query_Result::selected(
					array( array( 'FOUND_ROWS()' => (string) $this->last_found_rows ) ),
					array( array( 'name' => 'FOUND_ROWS()', 'table' => '', 'type' => 8 ) )
				);
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

		if ( 0 === $plan->limit() && ! $plan->calculates_found_rows() ) {
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
				$plan->counts_all() || $plan->calculates_found_rows() || array() !== $residual ? PHP_INT_MAX : $plan->limit_offset() + $plan->limit(),
				$plan->order_descending()
			)
		);
		if ( $provided instanceof WP_Markdown_Query_Result ) {
			return $provided;
		}

		$rows  = array();
		$count = 0;
		$found_rows = 0;
		$matched_rows = 0;
		foreach ( $provided as $row ) {
			if ( ! $plan->counts_all() && ! $plan->calculates_found_rows() && count( $rows ) >= $plan->limit() ) {
				break;
			}
			if ( ! is_array( $row ) || true !== $schema->validate_projection( $row, $provider_projection ) ) {
				return $this->failure( 'invalid_provider_row', 'The native table provider returned a row outside its declared schema.' );
			}
			if ( $this->matches( $row, $residual, $schema ) ) {
				if ( $plan->calculates_found_rows() ) {
					++$found_rows;
				}
				if ( $plan->counts_all() ) {
					++$count;
				} elseif ( $matched_rows++ < $plan->limit_offset() ) {
					continue;
				} elseif ( count( $rows ) < $plan->limit() ) {
					$rows[] = $this->string_row( $row, $projection );
				}
			}
		}
		if ( $plan->calculates_found_rows() ) {
			$this->last_found_rows = $found_rows;
		}

		return $plan->counts_all()
			? $this->count_result( $count, true )
			: $this->result( $rows, $projection, $plan->table(), $schema );
	}

	public function last_found_rows(): ?int {
		return $this->last_found_rows;
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
		$predicates = array_fill_keys( array_keys( $sources ), array() );
		foreach ( $plan->predicates() as $predicate ) {
			$source = $predicate->source();
			if ( null === $source || ! isset( $sources[ $source ] )
				|| ! $sources[ $source ]['schema']->has_column( $predicate->column() )
				|| ( ! $sources[ $source ]['schema']->allows_lookup( $predicate->column(), $predicate->operator(), $predicate->values() )
					&& ! $sources[ $source ]['schema']->allows_filter( $predicate->column(), $predicate->operator(), $predicate->values() ) )
			) {
				return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested JOIN predicate.' );
			}
			$predicates[ $source ][] = $predicate;
			$needed[ $source ][] = $predicate->column();
		}
		foreach ( $plan->joins() as $join ) {
			if ( ! isset( $sources[ $join->left_source() ], $sources[ $join->right_source() ] )
				|| ! $sources[ $join->left_source() ]['schema']->has_column( $join->left_column() )
				|| ! $sources[ $join->right_source() ]['schema']->has_column( $join->right_column() )
			) {
				return $this->failure( 'unsupported_join_shape', 'mdi-native cannot apply the requested equality JOIN.' );
			}
			$needed[ $join->left_source() ][] = $join->left_column();
			$needed[ $join->right_source() ][] = $join->right_column();
		}
		if ( null !== $plan->order() ) {
			$order_source = $plan->order_source();
			if ( null === $order_source || ! isset( $sources[ $order_source ] ) || ! $sources[ $order_source ]['schema']->allows_order( $plan->order() ) ) {
				return $this->failure( 'unsupported_order', 'mdi-native cannot apply the requested JOIN ordering collation.' );
			}
			$needed[ $order_source ][] = $plan->order();
		}
		foreach ( $needed as &$columns ) {
			$columns = array_values( array_unique( $columns ) );
		}
		unset( $columns );

		$seed_source = null;
		$seed_predicate = null;
		foreach ( $sources as $source => $definition ) {
			$candidate = $this->pushdown( $predicates[ $source ], $definition['schema'] );
			if ( null === $candidate ) {
				continue;
			}
			if ( null === $seed_predicate
				|| ( '=' === $candidate->operator() ? 0 : 1 ) < ( '=' === $seed_predicate->operator() ? 0 : 1 )
				|| ( $candidate->operator() === $seed_predicate->operator() && count( $candidate->values() ) < count( $seed_predicate->values() ) )
			) {
				$seed_source = $source;
				$seed_predicate = $candidate;
			}
		}
		if ( null === $seed_source || null === $seed_predicate ) {
			return $this->failure( 'unsupported_lookup', 'mdi-native requires one indexable predicate to seed a JOIN query.' );
		}
		$seed = $sources[ $seed_source ];
		$provided = $seed['provider']->read(
			new WP_Markdown_Native_Table_Access( $needed[ $seed_source ], $seed_predicate, $seed['schema']->natural_order(), PHP_INT_MAX )
		);
		if ( $provided instanceof WP_Markdown_Query_Result ) {
			return $provided;
		}
		$residual = array_values( array_filter( $predicates[ $seed_source ], static fn( WP_Markdown_Native_Query_Predicate $predicate ): bool => $predicate !== $seed_predicate ) );
		$rows = array();
		foreach ( $provided as $row ) {
			if ( ! is_array( $row ) || true !== $seed['schema']->validate_projection( $row, $needed[ $seed_source ] ) ) {
				return $this->failure( 'invalid_provider_row', 'The native JOIN provider returned a row outside its declared schema.' );
			}
			if ( $this->matches( $row, $residual, $seed['schema'] ) ) {
				$rows[] = array( $seed_source => $row );
			}
		}

		$joined_sources = array( $seed_source => true );
		$remaining = $plan->joins();
		while ( array() !== $remaining ) {
			$join_index = null;
			foreach ( $remaining as $index => $candidate ) {
				$left_joined = isset( $joined_sources[ $candidate->left_source() ] );
				$right_joined = isset( $joined_sources[ $candidate->right_source() ] );
				if ( $left_joined xor $right_joined ) {
					$join_index = $index;
					break;
				}
			}
			if ( null === $join_index ) {
				return $this->failure( 'unsupported_join_shape', 'mdi-native JOIN sources must form one connected equality graph.' );
			}
			$join = $remaining[ $join_index ];
			unset( $remaining[ $join_index ] );
			if ( isset( $joined_sources[ $join->left_source() ] ) ) {
				$known_source = $join->left_source();
				$known_column = $join->left_column();
				$target_source = $join->right_source();
				$target_column = $join->right_column();
			} else {
				$known_source = $join->right_source();
				$known_column = $join->right_column();
				$target_source = $join->left_source();
				$target_column = $join->left_column();
			}
			$target = $sources[ $target_source ];
			$values = array();
			foreach ( $rows as $row ) {
				$value = $row[ $known_source ][ $known_column ];
				$key = $target['schema']->value_key( $target_column, $value );
				if ( null === $key ) {
					return $this->failure( 'unsupported_join_lookup', 'mdi-native cannot normalize the requested JOIN identity.' );
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
			$join_predicate = new WP_Markdown_Native_Query_Predicate( $target_column, $operator, $values );
			$provided = $target['provider']->read(
				new WP_Markdown_Native_Table_Access( $needed[ $target_source ], $join_predicate, $target['schema']->natural_order(), PHP_INT_MAX )
			);
			if ( $provided instanceof WP_Markdown_Query_Result ) {
				return $provided;
			}
			$target_rows = array();
			foreach ( $provided as $target_row ) {
				if ( ! is_array( $target_row ) || true !== $target['schema']->validate_projection( $target_row, $needed[ $target_source ] ) ) {
					return $this->failure( 'invalid_provider_row', 'The native JOIN provider returned a row outside its declared schema.' );
				}
				if ( ! $this->matches( $target_row, $predicates[ $target_source ], $target['schema'] ) ) {
					continue;
				}
				$key = $target['schema']->value_key( $target_column, $target_row[ $target_column ] );
				if ( null === $key ) {
					return $this->failure( 'invalid_provider_row', 'The native JOIN provider returned an invalid JOIN identity.' );
				}
				$target_rows[ $key ][] = $target_row;
			}
			$joined = array();
			foreach ( $rows as $row ) {
				$value = $row[ $known_source ][ $known_column ];
				$key = $target['schema']->value_key( $target_column, $value );
				foreach ( null === $key ? array() : ( $target_rows[ $key ] ?? array() ) as $target_row ) {
					$row[ $target_source ] = $target_row;
					$joined[] = $row;
				}
			}
			$rows = $joined;
			$joined_sources[ $target_source ] = true;
		}

		if ( null !== $plan->order() ) {
			$order_source = (string) $plan->order_source();
			$order = $plan->order();
			usort(
				$rows,
				fn( array $left, array $right ): int => ( $plan->order_descending() ? -1 : 1 ) * $sources[ $order_source ]['schema']->compare_rows( $order, $left[ $order_source ], $right[ $order_source ] )
			);
		}
		$selected_rows = array();
		$seen = array();
		foreach ( $rows as $row_index => $row ) {
			$selected_row = array();
			foreach ( $projection as $column_index => $column ) {
				$source = $projection_sources[ $column_index ];
				$value = $row[ $source ][ $column ];
				$selected_row[ $column ] = null === $value ? null : (string) $value;
			}
			$key = serialize( $selected_row );
			if ( ! $plan->is_distinct() || ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$selected_rows[] = $selected_row;
			}
		}
		$columns = array();
		foreach ( $projection as $index => $column ) {
			$source = $projection_sources[ $index ];
			$columns[] = array( 'name' => $column, 'table' => $sources[ $source ]['table'], 'type' => $sources[ $source ]['schema']->column( $column )->type() );
		}
		return WP_Markdown_Query_Result::selected( $selected_rows, $columns );
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
