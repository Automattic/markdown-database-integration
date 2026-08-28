<?php
/** Canonical Markdown INSERT, UPDATE, and DELETE for wp_posts. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Post_Mutation_Runtime {

	public function __construct(
		private WP_Markdown_Native_Table_Registry $registry,
		private WP_Markdown_Native_Table_Insert_Parser $parser,
		private WP_Markdown_Storage $storage
	) {}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		if ( 1 === preg_match( '/^\s*INSERT\b/i', $request->sql() ) ) {
			return $this->insert( $request );
		}
		return $this->write( $request );
	}

	private function insert( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$insert = $this->parser->parse( $request );
		if ( $insert instanceof WP_Markdown_Query_Result ) {
			return $insert;
		}
		$bound = $this->posts_table( $insert->table(), $request->table_prefix() );
		if ( $bound instanceof WP_Markdown_Query_Result ) {
			return $bound;
		}
		$schema = $bound['schema'];
		$definition = $schema->definition();
		$row = $this->complete_row( $insert->values(), $definition, $this->existing_rows( $bound['provider'], $schema ) );
		if ( $row instanceof WP_Markdown_Query_Result ) {
			return $row;
		}
		$valid = $schema->validate_row( $row );
		if ( true !== $valid ) {
			return $this->failure( 'invalid_insert_row', is_string( $valid ) ? $valid : 'The INSERT row is outside the wp_posts schema.' );
		}
		if ( false === $this->storage->write_post( (object) $row, true ) ) {
			return $this->failure( 'post_write_failed', 'The canonical Markdown post could not be written.' );
		}
		return WP_Markdown_Query_Result::mutated( 1, (int) $row['ID'] );
	}

	private function write( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$write = $this->parser->parse_write( $request );
		if ( $write instanceof WP_Markdown_Query_Result ) {
			return $write;
		}
		$bound = $this->posts_table( $write->table(), $request->table_prefix() );
		if ( $bound instanceof WP_Markdown_Query_Result ) {
			return $bound;
		}
		$schema = $bound['schema'];
		foreach ( $write->predicates() as $predicate ) {
			if ( ! $schema->has_column( $predicate->column() ) ) {
				return $this->failure( 'unsupported_mutation_column', 'The WHERE restriction names a column outside the wp_posts schema.' );
			}
		}
		foreach ( array_keys( $write->values() ) as $column ) {
			if ( ! $schema->has_column( (string) $column ) ) {
				return $this->failure( 'unsupported_mutation_column', 'The assignment names a column outside the wp_posts schema.' );
			}
		}
		$affected = 0;
		foreach ( $this->existing_rows( $bound['provider'], $schema ) as $row ) {
			if ( ! $this->restricts( $row, $write->predicates(), $schema ) ) {
				continue;
			}
			++$affected;
			if ( $write->is_update() ) {
				$updated = $row;
				foreach ( $write->values() as $column => $value ) {
					$updated[ $column ] = $this->typed_value( $value, $schema->definition()['columns'][ $column ] ?? array() );
				}
				if ( true !== $schema->validate_row( $updated ) ) {
					return $this->failure( 'invalid_update_row', 'The UPDATE row is outside the wp_posts schema.' );
				}
				if ( false === $this->storage->write_post( (object) $updated, true ) ) {
					return $this->failure( 'post_write_failed', 'The canonical Markdown post could not be written.' );
				}
				continue;
			}
			if ( ! $this->storage->delete_post( (int) $row['ID'] ) ) {
				return $this->failure( 'post_delete_failed', 'The canonical Markdown post could not be deleted.' );
			}
		}
		return WP_Markdown_Query_Result::mutated( $affected );
	}

	/** @return array{schema:WP_Markdown_Native_Table_Schema,provider:WP_Markdown_Native_Post_Provider}|WP_Markdown_Query_Result */
	private function posts_table( string $table, string $prefix ): array|WP_Markdown_Query_Result {
		if ( 0 !== strcasecmp( $prefix . 'posts', $table ) ) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native post mutations require the active posts table.' );
		}
		$registered = $this->registry->table( $table );
		if ( null === $registered || ! $registered['provider'] instanceof WP_Markdown_Native_Post_Provider ) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native post mutations require the canonical Markdown post provider.' );
		}
		return $registered;
	}

	/** @return array<int,array<string,mixed>> */
	private function existing_rows( WP_Markdown_Native_Post_Provider $provider, WP_Markdown_Native_Table_Schema $schema ): array {
		$access = new WP_Markdown_Native_Table_Access( $schema->column_names(), null, $schema->natural_order(), PHP_INT_MAX );
		$rows = $provider->read( $access );
		if ( $rows instanceof WP_Markdown_Query_Result ) {
			return array();
		}
		return is_array( $rows ) ? $rows : iterator_to_array( $rows, false );
	}

	/**
	 * @param array<string,int|string|null>  $provided
	 * @param array<string,mixed>            $definition
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function complete_row( array $provided, array $definition, array $rows ): array|WP_Markdown_Query_Result {
		if ( array_diff_key( $provided, $definition['columns'] ) ) {
			return $this->failure( 'unsupported_column', 'The INSERT references an undeclared column.' );
		}
		$row = array();
		foreach ( $definition['columns'] as $name => $column ) {
			$generate_identity = true === ( $column['auto_increment'] ?? false )
				&& ( ! array_key_exists( $name, $provided ) || null === $provided[ $name ] || '0' === (string) $provided[ $name ] );
			if ( array_key_exists( $name, $provided ) && ! $generate_identity ) {
				$row[ $name ] = $this->typed_value( $provided[ $name ], $column );
				continue;
			}
			if ( $generate_identity ) {
				$maximum = 0;
				foreach ( $rows as $existing ) {
					$maximum = max( $maximum, (int) $existing[ $name ] );
				}
				$row[ $name ] = $maximum + 1;
				continue;
			}
			$default = $column['default'] ?? null;
			if ( null !== $default ) {
				$value = 1 === preg_match( '/^(?:CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME)(?:\(\))?$/i', (string) $default )
					? gmdate( 'Y-m-d H:i:s' )
					: $default;
				$row[ $name ] = $this->typed_value( $value, $column );
				continue;
			}
			if ( true === ( $column['nullable'] ?? false ) ) {
				$row[ $name ] = null;
				continue;
			}
			$row[ $name ] = WP_Markdown_Native_Schema_Catalog::is_integer( (string) ( $column['type'] ?? '' ) ) ? 0 : '';
		}
		return $row;
	}

	/** @param array<string,mixed> $column */
	private function typed_value( mixed $value, array $column ): mixed {
		if ( null === $value ) {
			return null;
		}
		return WP_Markdown_Native_Schema_Catalog::is_integer( (string) ( $column['type'] ?? '' ) ) ? (int) $value : $value;
	}

	/**
	 * @param array<string,mixed>                          $row
	 * @param array<int,WP_Markdown_Native_Table_Predicate> $predicates
	 */
	private function restricts( array $row, array $predicates, WP_Markdown_Native_Table_Schema $schema ): bool {
		foreach ( $predicates as $predicate ) {
			$value = $row[ $predicate->column() ] ?? null;
			$matched = $predicate->matches_null() && null === $value;
			if ( ! $matched ) {
				foreach ( $predicate->values() as $candidate ) {
					if ( $schema->values_match( $predicate->column(), $value, $candidate ) ) {
						$matched = true;
						break;
					}
				}
			}
			if ( ! $matched ) {
				return false;
			}
		}
		return true;
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
