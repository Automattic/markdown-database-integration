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
					$rows[] = $row;
				}
			}
		}

		return $plan->counts_all()
			? $this->count_result( $count, true )
			: $this->result( $rows, $projection, $plan->table(), $schema );
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
		$selected = array_map(
			static function ( array $source ) use ( $projection ): array {
				$row = array();
				foreach ( $projection as $column ) {
					$row[ $column ] = null === $source[ $column ] ? null : (string) $source[ $column ];
				}
				return $row;
			},
			$rows
		);
		$columns = array_map(
			static fn( string $column ): array => array(
				'name'  => $column,
				'table' => $table,
				'type'  => $schema->column( $column )->type(),
			),
			$projection
		);
		return WP_Markdown_Query_Result::selected( $selected, $columns );
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
