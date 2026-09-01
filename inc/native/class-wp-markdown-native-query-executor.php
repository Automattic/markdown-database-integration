<?php
/** Generic bounded query executor over registered providers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Query_Runtime implements WP_Markdown_Query_Runtime {
	private ?int $last_found_rows = null;
	private WP_Markdown_Native_Schema_Introspection $schema_introspection;

	public function __construct(
		private WP_Markdown_Native_Table_Registry $registry,
		private WP_Markdown_Native_Query_Parser $parser = new WP_Markdown_Native_Query_Parser(),
		private ?WP_Markdown_Native_Option_Mutation_Runtime $option_mutations = null,
		private ?WP_Markdown_Native_Schema_Mutation_Runtime $schema_mutations = null,
		private ?WP_Markdown_Native_Table_Mutation_Runtime $table_mutations = null,
		private ?WP_Markdown_Native_Transaction_Journal $transactions = null,
		private ?WP_Markdown_Native_Post_Mutation_Runtime $post_mutations = null
	) {
		$this->schema_introspection = new WP_Markdown_Native_Schema_Introspection( $registry );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$transaction_control = WP_Markdown_SQL_Classifier::transaction_control( $request->sql() );
		if ( null !== $transaction_control ) {
			return $this->execute_transaction_control( $transaction_control );
		}
		if ( 1 === preg_match( '/^\s*(?:SHOW|DESCRIBE)\b/i', $request->sql() ) ) {
			return $this->schema_introspection->execute( $request );
		}
		// The canonical store is a directory, not a named server database.
		if ( 1 === preg_match( '/^\s*SELECT\s+DATABASE\s*\(\s*\)\s*;?\s*$/i', $request->sql() ) ) {
			return WP_Markdown_Query_Result::selected(
				array( array( 'DATABASE()' => defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) ),
				array( array( 'name' => 'DATABASE()', 'table' => '', 'type' => 253 ) )
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
		if ( null !== $dml_table && 0 !== strcasecmp( $request->table_prefix() . 'options', $dml_table ) ) {
			return $this->execute_table_dml( $request, $dml_table );
		}
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
		if ( $plan->is_unsatisfiable() ) {
			$table = $this->registry->table( $plan->table() );
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

		$table = $this->registry->table( $plan->table() );
		if ( null === $table ) {
			return $this->failure( 'unsupported_table', 'mdi-native cannot query the requested table.' );
		}

		$schema     = $table['schema'];
		$predicates = $plan->predicates();
		$projection = array( '*' ) === $plan->projection() ? $schema->column_names() : $plan->projection();
		$columns    = $projection;
		foreach ( $predicates as $predicate ) {
			foreach ( $predicate->columns() as $column ) {
				$columns[] = $column;
			}
		}
		foreach ( $plan->order_by() as $item ) {
			$columns[] = $item['column'];
		}
		foreach ( $columns as $column ) {
			if ( ! $schema->has_column( $column ) ) {
				return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested column.' );
			}
		}

		foreach ( $predicates as $predicate ) {
			if ( ! $this->supports_predicate( $schema, $predicate ) ) {
				return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested predicate.' );
			}
		}
		$pushdown = $this->pushdown( $predicates, $schema );
		if ( array() !== $predicates && null === $pushdown && ! $this->allows_residual_scan( $predicates, $schema ) ) {
			return $this->failure( 'unsupported_lookup', 'mdi-native requires one indexable predicate for a filtered query.' );
		}
		foreach ( $plan->order_by() as $item ) {
			if ( ! $schema->allows_order( $item['column'] ) ) {
				return $this->failure( 'unsupported_order', 'mdi-native cannot apply the requested ordering collation.' );
			}
		}

		if ( 0 === $plan->limit() && ! $plan->calculates_found_rows() ) {
			return $plan->counts_all()
				? $this->count_result( 0, false )
				: $this->result( array(), $projection, $plan->table(), $schema );
		}

		$residual = $table['provider'] instanceof WP_Markdown_Native_JSON_Snapshot_Provider
			? array()
			: array_values( array_filter( $predicates, static fn( WP_Markdown_Native_Query_Predicate $predicate ): bool => $predicate !== $pushdown ) );
		$provider_projection = $plan->counts_all() ? array() : $projection;
		foreach ( $residual as $predicate ) {
			foreach ( $predicate->columns() as $column ) {
				$provider_projection[] = $column;
			}
		}
		if ( array() === $provider_projection ) {
			$provider_projection[] = $schema->natural_order();
		}
		$provider_projection = array_values( array_unique( $provider_projection ) );
		$order_by = $plan->order_by();
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
				$plan->counts_all() || $plan->calculates_found_rows() || null !== $plan->group_count_alias() || array() !== $residual ? PHP_INT_MAX : $plan->limit_offset() + $plan->limit(),
				$order_by[0]['descending'],
				$order_by,
				$predicates
			)
		);
		if ( $provided instanceof WP_Markdown_Query_Result ) {
			return $provided;
		}

		$rows  = array();
		$count = 0;
		$found_rows = 0;
		$matched_rows = 0;
		$groups = array();
		foreach ( $provided as $row ) {
			if ( ! $plan->counts_all() && ! $plan->calculates_found_rows() && null === $plan->group_count_alias() && count( $rows ) >= $plan->limit() ) {
				break;
			}
			if ( ! is_array( $row ) || true !== $schema->validate_projection( $row, $provider_projection ) ) {
				return $this->failure( 'invalid_provider_row', 'The native table provider returned a row outside its declared schema.' );
			}
			if ( $this->matches( $row, $residual, $schema ) ) {
				if ( $plan->calculates_found_rows() ) {
					++$found_rows;
				}
				if ( null !== $plan->group_count_alias() ) {
					$value = $row[ $projection[0] ] ?? null;
					$key = null === $value ? '' : (string) $value;
					$groups[ $key ] = ( $groups[ $key ] ?? 0 ) + 1;
					continue;
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
		if ( null !== $plan->group_count_alias() ) {
			$alias = $plan->group_count_alias();
			$grouped_rows = array();
			foreach ( $groups as $value => $total ) {
				$grouped_rows[] = array( $projection[0] => (string) $value, $alias => (string) $total );
			}
			return WP_Markdown_Query_Result::selected(
				$grouped_rows,
				array(
					array( 'name' => $projection[0], 'table' => $plan->table(), 'type' => $schema->column( $projection[0] )->type() ),
					array( 'name' => $alias, 'table' => '', 'type' => 8 ),
				)
			);
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
		$reports_over_outer_join = array() !== $plan->aggregates()
			&& array_reduce( $plan->joins(), static fn( bool $outer, WP_Markdown_Native_Query_Join $join ): bool => $outer && $join->is_outer(), true );
		if ( null === $base_alias || null === $base || ( array() === $plan->predicates() && ! $reports_over_outer_join ) ) {
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
				|| ! $this->supports_predicate( $sources[ $source ]['schema'], $predicate )
			) {
				return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested JOIN predicate.' );
			}
			$predicates[ $source ][] = $predicate;
			$needed[ $source ][] = $predicate->column();
		}
		foreach ( $plan->aggregates() as $aggregate ) {
			if ( null === $aggregate['column'] ) {
				continue;
			}
			$aggregate_source = (string) $aggregate['source'];
			if ( ! isset( $sources[ $aggregate_source ] ) || ! $sources[ $aggregate_source ]['schema']->has_column( $aggregate['column'] ) ) {
				return $this->failure( 'unsupported_column', 'mdi-native cannot aggregate the requested qualified column.' );
			}
			$needed[ $aggregate_source ][] = $aggregate['column'];
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
			foreach ( $join->on_filters() as $filter ) {
				$filter_source = $filter->source();
				if ( null === $filter_source || ! isset( $sources[ $filter_source ] ) || ! $this->supports_predicate( $sources[ $filter_source ]['schema'], $filter ) ) {
					return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested JOIN ON predicate.' );
				}
				$needed[ $filter_source ][] = $filter->column();
			}
		}
		foreach ( $plan->order_by() as $item ) {
			$order_source = $item['source'] ?? $plan->order_source();
			$numeric = true === ( $item['numeric'] ?? false );
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

		$seed_source = null;
		$seed_predicate = null;
		foreach ( $sources as $source => $definition ) {
			$candidate = $this->pushdown( $predicates[ $source ], $definition['schema'] );
			if ( null === $candidate ) {
				continue;
			}
			if ( null === $seed_predicate || $this->compare_pushdowns( $candidate, $seed_predicate ) < 0 ) {
				$seed_source = $source;
				$seed_predicate = $candidate;
			}
		}
		if ( null === $seed_source ) {
			// A grouped aggregate over an outer JOIN reports on every base row,
			// so the base table is the seed and the scan is the whole point.
			if ( $reports_over_outer_join ) {
				$seed_source = $base_alias;
			}
		}
		if ( null === $seed_source ) {
			return $this->failure( 'unsupported_lookup', 'mdi-native requires one indexable predicate to seed a JOIN query.' );
		}
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
			$provided = $this->read_provider(
				$target['provider'],
				$target['schema'],
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
				if ( ! $this->matches( $target_row, array_merge( $predicates[ $target_source ], $join->on_filters() ), $target['schema'] ) ) {
					continue;
				}
				$key = $target['schema']->value_key( $target_column, $target_row[ $target_column ] );
				if ( null === $key ) {
					return $this->failure( 'invalid_provider_row', 'The native JOIN provider returned an invalid JOIN identity.' );
				}
				$target_rows[ $key ][] = $target_row;
			}
			$joined = array();
			$null_row = array_fill_keys( $needed[ $target_source ], null );
			foreach ( $rows as $row ) {
				$value = $row[ $known_source ][ $known_column ];
				$key = $target['schema']->value_key( $target_column, $value );
				$matched = null === $key ? array() : ( $target_rows[ $key ] ?? array() );
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

		if ( array() !== $plan->order_by() ) {
			foreach ( $plan->order_by() as $item ) {
				if ( true === ( $item['numeric'] ?? false ) ) {
					continue;
				}
				$order_source = (string) ( $item['source'] ?? $plan->order_source() );
				$extracted = array_map( static fn( array $row ): array => $row[ $order_source ], $rows );
				if ( null !== $sources[ $order_source ]['schema']->unsupported_order_reason( array( $item ), $extracted ) ) {
					return $this->failure( 'unsupported_order', 'mdi-native cannot apply the requested JOIN ordering collation.' );
				}
			}
			usort(
				$rows,
				function ( array $left, array $right ) use ( $plan, $sources ): int {
					foreach ( $plan->order_by() as $item ) {
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
			$selected_row = array();
			foreach ( $projection as $column_index => $column ) {
				$source = $projection_sources[ $column_index ];
				$value = $row[ $source ][ $column ];
				$selected_row[ $column ] = null === $value ? null : (string) $value;
			}
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
			foreach ( $rows as $index => $row ) {
				$key = serialize( $selected_rows[ $index ] ?? array() );
				if ( ! isset( $grouped_rows[ $key ] ) ) {
					$grouped_rows[ $key ] = $selected_rows[ $index ] ?? array();
					$totals[ $key ] = array_fill_keys( array_column( $aggregates, 'alias' ), null );
				}
				foreach ( $aggregates as $aggregate ) {
					$alias = $aggregate['alias'];
					$value = null === $aggregate['column'] ? null : ( $row[ (string) $aggregate['source'] ][ $aggregate['column'] ] ?? null );
					if ( 'COUNT' === $aggregate['function'] ) {
						// COUNT(*) counts rows; COUNT(col) skips NULL, which is
						// how an unmatched outer row contributes nothing.
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
		foreach ( $aggregates as $aggregate ) {
			$columns[] = array( 'name' => $aggregate['alias'], 'table' => '', 'type' => 8 );
		}
		return WP_Markdown_Query_Result::selected( $selected_rows, $columns );
	}

	/** @param array<int,WP_Markdown_Native_Query_Predicate> $predicates */
	private function allows_residual_scan( array $predicates, WP_Markdown_Native_Table_Schema $schema ): bool {
		$indexed = $this->indexed_columns( $schema );
		foreach ( $predicates as $predicate ) {
			if ( in_array( $predicate->operator(), array( 'OR', 'LOWER =' ), true ) ) {
				continue;
			}
			if ( $this->predicate_uses_like( $predicate ) ) {
				continue;
			}
			if ( in_array( $predicate->operator(), array( 'IS NULL', 'IS NOT NULL' ), true ) ) {
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
			if ( isset( $indexed[ $column ] )
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
		usort(
			$candidates,
			$this->compare_pushdowns( ... )
		);
		return $candidates[0] ?? null;
	}

	private function supports_predicate( WP_Markdown_Native_Table_Schema $schema, WP_Markdown_Native_Query_Predicate $predicate ): bool {
		if ( 'OR' === $predicate->operator() ) {
			foreach ( $predicate->any() as $alternative ) {
				if ( ! $this->supports_predicate( $schema, $alternative ) ) {
					return false;
				}
			}
			return array() !== $predicate->any();
		}
		if ( in_array( $predicate->operator(), array( 'IS NULL', 'IS NOT NULL' ), true ) ) {
			return $schema->has_column( $predicate->column() );
		}
		if ( 'LOWER =' === $predicate->operator() ) {
			return $schema->has_column( $predicate->column() )
				&& 1 === count( $predicate->values() )
				&& null !== WP_Markdown_Native_Runtime_Factory::normalize_ascii_ci( $predicate->values()[0] );
		}
		return $schema->allows_lookup( $predicate->column(), $predicate->operator(), $predicate->values() )
			|| $schema->allows_filter( $predicate->column(), $predicate->operator(), $predicate->values() );
	}

	private function predicate_uses_like( WP_Markdown_Native_Query_Predicate $predicate ): bool {
		if ( 'LIKE' === $predicate->operator() ) {
			return true;
		}
		foreach ( $predicate->any() as $alternative ) {
			if ( $this->predicate_uses_like( $alternative ) ) {
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
		if ( ! $provider instanceof WP_Markdown_Native_JSON_Snapshot_Provider ) {
			return $provider->read( $access );
		}

		$rows = $provider->rows();
		if ( $rows instanceof WP_Markdown_Query_Result ) {
			return $rows;
		}
		$predicates = $access->predicates();
		if ( array() === $predicates && null !== $access->predicate() ) {
			$predicates[] = $access->predicate();
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
		foreach ( $predicates as $predicate ) {
			if ( ! $this->matches_predicate( $row, $predicate, $schema ) ) {
				return false;
			}
		}
		return true;
	}

	private function matches_predicate( array $row, WP_Markdown_Native_Query_Predicate $predicate, WP_Markdown_Native_Table_Schema $schema ): bool {
		if ( 'OR' === $predicate->operator() ) {
			foreach ( $predicate->any() as $alternative ) {
				if ( $this->matches_predicate( $row, $alternative, $schema ) ) {
					return true;
				}
			}
			return false;
		}
		if ( 'IS NULL' === $predicate->operator() ) {
			return null === ( $row[ $predicate->column() ] ?? null );
		}
		if ( 'IS NOT NULL' === $predicate->operator() ) {
			return null !== ( $row[ $predicate->column() ] ?? null );
		}
		if ( 'LOWER =' === $predicate->operator() ) {
			$left  = WP_Markdown_Native_Runtime_Factory::normalize_ascii_ci( $row[ $predicate->column() ] ?? null );
			$right = WP_Markdown_Native_Runtime_Factory::normalize_ascii_ci( $predicate->values()[0] ?? null );
			return null !== $left && null !== $right && $left === $right;
		}
		$negated = in_array( $predicate->operator(), array( 'NOT IN', 'NOT LIKE' ), true );
		if ( $negated && null === ( $row[ $predicate->column() ] ?? null ) ) {
			return false;
		}
		foreach ( $predicate->values() as $value ) {
			$compare = match ( $predicate->operator() ) {
				'<>' => $schema->values_differ( $predicate->column(), $row[ $predicate->column() ], $value ),
				'LIKE', 'NOT LIKE' => is_string( $value ) && $schema->value_matches_like( $predicate->column(), $row[ $predicate->column() ], $value ),
				default => $schema->values_match( $predicate->column(), $row[ $predicate->column() ], $value ),
			};
			if ( $compare ) {
				return ! $negated;
			}
		}
		return $negated;
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
		if ( 'commit_chain' === $control['action'] || 'rollback_chain' === $control['action'] ) {
			$chained = $this->transactions->begin();
			if ( true !== $chained ) {
				return $this->failure( 'transaction_control_failed', $chained );
			}
		}

		return WP_Markdown_Query_Result::mutated( 0 );
	}

	private function dml_table( WP_Markdown_Query_Request $request ): ?string {
		if ( 1 === preg_match( '/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE(?:\s+INTO)?|UPDATE|DELETE\s+FROM)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $request->sql(), $match ) ) {
			return $match[1];
		}
		return null;
	}

	private function execute_table_dml( WP_Markdown_Query_Request $request, string $table ): WP_Markdown_Query_Result {
		if ( 0 === strcasecmp( $request->table_prefix() . 'posts', $table ) ) {
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
