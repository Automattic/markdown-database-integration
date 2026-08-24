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

		$table = $this->registry->table( $plan['table'] );
		if ( null === $table ) {
			return $this->failure( 'unsupported_table', 'mdi-native cannot query the requested table.' );
		}

		$schema     = $table['schema'];
		$projection = array( '*' ) === $plan['projection'] ? array_keys( $schema->columns() ) : $plan['projection'];
		$columns    = $projection;
		if ( null !== $plan['predicate'] ) {
			$columns[] = $plan['predicate']['column'];
		}
		if ( null !== $plan['order'] ) {
			$columns[] = $plan['order'];
		}
		foreach ( $columns as $column ) {
			if ( ! $schema->has_column( $column ) ) {
				return $this->failure( 'unsupported_column', 'mdi-native cannot query the requested column.' );
			}
		}

		if ( null !== $plan['predicate'] && ! $schema->allows_lookup(
			$plan['predicate']['column'],
			$plan['predicate']['operator'],
			$plan['predicate']['values']
		) ) {
			return $this->failure( 'unsupported_lookup', 'mdi-native cannot apply the requested predicate.' );
		}
		if ( null !== $plan['order'] && ! $schema->allows_order( $plan['order'] ) ) {
			return $this->failure( 'unsupported_order', 'mdi-native cannot apply the requested ordering collation.' );
		}

		if ( 0 === $plan['limit'] ) {
			return $this->result( array(), $projection, $plan['table'], $schema );
		}

		$rows = null === $plan['predicate']
			? $table['provider']->scan()
			: $table['provider']->lookup( $plan['predicate']['column'], $plan['predicate']['values'] );
		if ( $rows instanceof WP_Markdown_Query_Result ) {
			return $rows;
		}
		foreach ( $rows as $row ) {
			if ( true !== $schema->validate_row( $row ) ) {
				return $this->failure( 'invalid_provider_row', 'The native table provider returned a row outside its declared schema.' );
			}
		}

		$order = $plan['order'] ?? $schema->natural_order();
		usort(
			$rows,
			static fn( array $left, array $right ): int => $schema->compare_values(
				$order,
				$left[ $order ],
				$right[ $order ]
			)
		);

		return $this->result( array_slice( $rows, 0, $plan['limit'] ), $projection, $plan['table'], $schema );
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
					$row[ $column ] = (string) $source[ $column ];
				}
				return $row;
			},
			$rows
		);
		$types = $schema->columns();
		$columns = array_map(
			static fn( string $column ): array => array(
				'name'  => $column,
				'table' => $table,
				'type'  => $types[ $column ]['type'],
			),
			$projection
		);
		return WP_Markdown_Query_Result::selected( $selected, $columns );
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
