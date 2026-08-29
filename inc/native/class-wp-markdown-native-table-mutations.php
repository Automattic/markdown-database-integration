<?php
/** Atomic INSERT mutations for persisted generic table snapshots. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-native-table-statements.php';
require_once __DIR__ . '/class-wp-markdown-native-table-insert-parser.php';

final class WP_Markdown_Native_Table_Mutation_Runtime {
	private string $state_root;
	private WP_Markdown_Native_Table_Index $index;

	public function __construct(
		string $state_root,
		private WP_Markdown_Native_Table_Registry $registry,
		private WP_Markdown_Native_Table_Insert_Parser $parser = new WP_Markdown_Native_Table_Insert_Parser(),
		private ?WP_Markdown_Native_Transaction_Journal $transactions = null
	) {
		$root = realpath( $state_root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new InvalidArgumentException( 'The canonical state root must be an existing directory.' );
		}
		$this->state_root = rtrim( $root, DIRECTORY_SEPARATOR );
		$this->index = new WP_Markdown_Native_Table_Index( $this->state_root . DIRECTORY_SEPARATOR . '_tables' );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		if ( 1 === preg_match( '/^\s*(?:UPDATE|DELETE)\b/i', $request->sql() ) ) {
			return $this->execute_write( $request );
		}
		$inserts = $this->parser->parse_rows( $request );
		if ( $inserts instanceof WP_Markdown_Query_Result ) {
			return $inserts;
		}
		if ( 1 !== count( $inserts ) ) {
			return $this->execute_rows( $request, $inserts );
		}
		return $this->execute_insert( $request, $inserts[0] );
	}

	/**
	 * Apply every row of a multi-row INSERT as one statement.
	 *
	 * MySQL reports the rows it affected and the identifier of the first row
	 * it inserted, and a statement that fails part way through leaves nothing
	 * behind, so the rows are applied inside a transaction.
	 *
	 * @param array<int,WP_Markdown_Native_Table_Insert> $inserts
	 */
	private function execute_rows( WP_Markdown_Query_Request $request, array $inserts ): WP_Markdown_Query_Result {
		$owns_transaction = null !== $this->transactions && ! $this->transactions->is_active();
		if ( $owns_transaction && true !== $this->transactions->begin() ) {
			return $this->failure( 'mutation_transaction_failed', 'The canonical multi-row INSERT could not be isolated.' );
		}
		$affected = 0;
		$insert_id = 0;
		foreach ( $inserts as $insert ) {
			$result = $this->execute_insert( $request, $insert );
			if ( false === $result->return_value() ) {
				if ( $owns_transaction ) {
					$this->transactions->rollback();
				}
				return $result;
			}
			$affected += (int) $result->return_value();
			$row_id = (int) ( $result->wpdb_state()['insert_id'] ?? 0 );
			if ( 0 === $insert_id && 0 !== $row_id ) {
				$insert_id = $row_id;
			}
		}
		if ( $owns_transaction && true !== $this->transactions->commit() ) {
			return $this->failure( 'mutation_transaction_failed', 'The canonical multi-row INSERT could not be committed.' );
		}
		return WP_Markdown_Query_Result::mutated( $affected, $insert_id );
	}

	private function execute_insert( WP_Markdown_Query_Request $request, WP_Markdown_Native_Table_Insert $insert ): WP_Markdown_Query_Result {
		$prefix = $request->table_prefix();
		if ( ! str_starts_with( $insert->table(), $prefix ) ) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native requires a table in the active prefix.' );
		}
		$suffix = substr( $insert->table(), strlen( $prefix ) );
		$table = $this->registry->table( $insert->table() );
		$definition = $this->registry->definition( $insert->table() );
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $suffix )
			|| null === $table
			|| ! $table['provider'] instanceof WP_Markdown_Native_JSON_Snapshot_Provider
			|| ! is_array( $definition )
			|| ! $this->is_authoritative_definition( $suffix, $definition, $prefix )
		) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native can insert only into a persisted generic snapshot table.' );
		}

		$directory = $this->tables_directory();
		if ( $directory instanceof WP_Markdown_Query_Result ) {
			return $directory;
		}
		$lock = $this->table_lock( $directory, $suffix );
		if ( $lock instanceof WP_Markdown_Query_Result ) {
			return $lock;
		}

		try {
			$schema = $table['schema'];
			if ( ! $this->supports_unique_indexes( $definition ) ) {
				return $this->failure( 'unsupported_unique_collation', 'mdi-native cannot enforce a persisted string or prefix unique key without its exact collation.' );
			}
			if ( null !== $insert->unless_exists() ) {
				$existing = $table['provider']->read( new WP_Markdown_Native_Table_Access( $schema->column_names(), null, $schema->natural_order(), PHP_INT_MAX ) );
				if ( $existing instanceof WP_Markdown_Query_Result ) {
					return $existing;
				}
				$existing = is_array( $existing ) ? $existing : iterator_to_array( $existing, false );
				foreach ( $existing as $row ) {
					if ( is_array( $row ) && $this->restricts( $row, $insert->unless_exists(), $schema ) ) {
						return WP_Markdown_Query_Result::mutated( 0 );
					}
				}
			}
			$path = $directory . '/' . $suffix . '.json';
			$index = null !== $insert->upsert_columns() || WP_Markdown_Native_Table_Index::supplies_identity( $insert->values(), $definition )
				? null
				: $this->index->load( $suffix, $path );
			if ( null !== $index ) {
				// The index answers identity and uniqueness, so the snapshot is
				// appended to rather than read, decoded, and republished.
				$row = $this->complete_row( $insert->values(), $definition, array(), $index['max'] );
				if ( $row instanceof WP_Markdown_Query_Result ) {
					return $row;
				}
				if ( true !== $schema->validate_row( $row ) ) {
					return $this->failure( 'invalid_insert_row', 'The INSERT row is outside the persisted table schema.' );
				}
				if ( ! $this->unique_values_enforceable( $row, $definition ) ) {
					return $this->failure( 'unsupported_unique_collation', 'mdi-native cannot enforce a unique key that is not exact ASCII or integer identity.' );
				}
				if ( WP_Markdown_Native_Table_Index::duplicates( $index, $row, $definition, $schema ) ) {
					return $insert->ignores_duplicate()
						? WP_Markdown_Query_Result::mutated( 0 )
						: $this->failure( 'duplicate_key', 'The INSERT row duplicates a persisted unique key.' );
				}
				$appended = $this->append_row( $path, $row, 0 === $index['row_count'] );
				if ( $appended instanceof WP_Markdown_Query_Result ) {
					return $appended;
				}
				if ( ! $this->index->save( $suffix, $path, WP_Markdown_Native_Table_Index::with_row( $index, $row, $definition, $schema ), $this->transactions ) ) {
					// A snapshot without a current index stays correct and simply
					// costs a rebuild on the next insert.
					$this->index->forget( $suffix, $this->transactions );
				}
				return $this->insert_result( $row, $definition );
			}

			$rows = $table['provider']->read( new WP_Markdown_Native_Table_Access( $schema->column_names(), null, $schema->natural_order(), PHP_INT_MAX ) );
			if ( $rows instanceof WP_Markdown_Query_Result ) {
				return $rows;
			}
			$rows = is_array( $rows ) ? $rows : iterator_to_array( $rows, false );
			$row = $this->complete_row( $insert->values(), $definition, $rows );
			if ( $row instanceof WP_Markdown_Query_Result ) {
				return $row;
			}
			if ( true !== $schema->validate_row( $row ) ) {
				return $this->failure( 'invalid_insert_row', 'The INSERT row is outside the persisted table schema.' );
			}
			if ( ! $this->unique_values_enforceable( $row, $definition ) ) {
				return $this->failure( 'unsupported_unique_collation', 'mdi-native cannot enforce a unique key that is not exact ASCII or integer identity.' );
			}
			$duplicate = $this->duplicate_row_offset( $row, $rows, $definition, $schema );
			if ( null !== $duplicate ) {
				if ( $insert->ignores_duplicate() ) {
					return WP_Markdown_Query_Result::mutated( 0 );
				}
				$upsert_columns = $insert->upsert_columns();
				if ( null === $upsert_columns ) {
					return $this->failure( 'duplicate_key', 'The INSERT row duplicates a persisted unique key.' );
				}
				$updated = $rows[ $duplicate ];
				foreach ( $upsert_columns as $column ) {
					$updated[ $column ] = $row[ $column ];
				}
				if ( true !== $schema->validate_row( $updated ) ) {
					return $this->failure( 'invalid_insert_row', 'The INSERT row is outside the persisted table schema.' );
				}
				$others = $rows;
				unset( $others[ $duplicate ] );
				if ( $this->duplicate_row_offset( $updated, array_values( $others ), $definition, $schema ) !== null ) {
					return $this->failure( 'duplicate_key', 'The INSERT row duplicates a persisted unique key.' );
				}
				$rows[ $duplicate ] = $updated;
				$written = $this->write( $path, array_values( $rows ) );
				if ( $written instanceof WP_Markdown_Query_Result ) {
					return $written;
				}
				$this->index->save( $suffix, $path, WP_Markdown_Native_Table_Index::build( array_values( $rows ), $definition, $schema ), $this->transactions );
				$insert_id = 0;
				foreach ( $definition['columns'] as $name => $column ) {
					if ( true === ( $column['auto_increment'] ?? false ) ) {
						$insert_id = (int) $updated[ $name ];
						break;
					}
				}
				return WP_Markdown_Query_Result::mutated( 2, $insert_id );
			}
			$rows[] = $row;
			$written = $this->write( $path, $rows );
			if ( $written instanceof WP_Markdown_Query_Result ) {
				return $written;
			}
			$this->index->save( $suffix, $path, WP_Markdown_Native_Table_Index::build( $rows, $definition, $schema ), $this->transactions );
			$insert_id = 0;
			foreach ( $definition['columns'] as $name => $column ) {
				if ( true === ( $column['auto_increment'] ?? false ) ) {
					$insert_id = (int) $row[ $name ];
					break;
				}
			}
			return WP_Markdown_Query_Result::mutated( 1, $insert_id );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * Resolve the auto-increment identifier assigned to a completed row.
	 *
	 * @param array<string,mixed> $row        Completed row.
	 * @param array<string,mixed> $definition Compiled definition.
	 */
	private function insert_result( array $row, array $definition ): WP_Markdown_Query_Result {
		foreach ( $definition['columns'] as $name => $column ) {
			if ( true === ( $column['auto_increment'] ?? false ) ) {
				return WP_Markdown_Query_Result::mutated( 1, (int) $row[ $name ] );
			}
		}
		return WP_Markdown_Query_Result::mutated( 1, 0 );
	}

	/**
	 * Append one encoded row inside the snapshot's JSON array.
	 *
	 * The existing rows are never decoded or re-encoded, so the cost of an
	 * insert does not grow with the size of the snapshot.
	 *
	 * @param array<string,mixed> $row Row to append.
	 */
	private function append_row( string $path, array $row, bool $empty ): true|WP_Markdown_Query_Result {
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return $this->failure( 'unsafe_table_file', 'The canonical table file is unavailable or unsafe.' );
		}
		if ( null !== $this->transactions ) {
			$recorded = $this->transactions->record( $path );
			if ( true !== $recorded ) {
				return $this->failure( 'transaction_journal_failed', $recorded );
			}
		}
		try {
			$encoded = json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		} catch ( Throwable ) {
			return $this->failure( 'table_encoding_failed', 'The canonical table row could not be encoded.' );
		}

		$handle = @fopen( $path, 'c+b' );
		if ( false === $handle ) {
			return $this->failure( 'table_append_failed', 'The canonical table file could not be opened for append.' );
		}
		try {
			$size = fstat( $handle )['size'] ?? 0;
			$window = (int) min( $size, 4096 );
			if ( 0 === $window || -1 === fseek( $handle, $size - $window ) ) {
				return $this->failure( 'table_append_failed', 'The canonical table file could not be positioned.' );
			}
			$tail = (string) fread( $handle, $window );
			$close = strrpos( $tail, ']' );
			if ( false === $close ) {
				return $this->failure( 'table_append_failed', 'The canonical table file has no array terminator.' );
			}
			$offset = $size - $window + $close;
			if ( -1 === fseek( $handle, $offset ) ) {
				return $this->failure( 'table_append_failed', 'The canonical table file could not be positioned.' );
			}
			$payload = ( $empty ? '' : ',' ) . $encoded . ']';
			if ( strlen( $payload ) !== fwrite( $handle, $payload ) ) {
				return $this->failure( 'table_append_failed', 'The canonical table row could not be appended.' );
			}
			if ( ! ftruncate( $handle, $offset + strlen( $payload ) ) ) {
				return $this->failure( 'table_append_failed', 'The canonical table file could not be truncated.' );
			}
			if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) {
				return $this->failure( 'table_append_failed', 'The canonical table row could not be flushed.' );
			}
		} finally {
			fclose( $handle );
		}
		return true;
	}

	/**
	 * @param array<string,int|string|null>   $provided   Supplied columns.
	 * @param array<string,mixed>             $definition Compiled definition.
	 * @param array<int,array<string,mixed>>  $rows       Snapshot rows, empty when maxima are supplied.
	 * @param array<string,int>|null          $maxima     Known auto-increment maxima.
	 */
	private function complete_row( array $provided, array $definition, array $rows, ?array $maxima = null ): array|WP_Markdown_Query_Result {
		if ( array_diff_key( $provided, $definition['columns'] ) ) {
			return $this->failure( 'unsupported_column', 'The INSERT references an undeclared column.' );
		}
		$row = array();
		foreach ( $definition['columns'] as $name => $column ) {
			$generate_identity = true === ( $column['auto_increment'] ?? false )
				&& ( ! array_key_exists( $name, $provided ) || null === $provided[ $name ] || '0' === (string) $provided[ $name ] );
			if ( array_key_exists( $name, $provided ) && ! $generate_identity ) {
				$row[ $name ] = $provided[ $name ];
				continue;
			}
			if ( $generate_identity ) {
				$maximum = $maxima[ $name ] ?? null;
				if ( null === $maximum ) {
					$maximum = 0;
					foreach ( $rows as $existing ) {
						$maximum = max( $maximum, (int) $existing[ $name ] );
					}
				}
				if ( PHP_INT_MAX === $maximum ) {
					return $this->failure( 'auto_increment_exhausted', 'The persisted auto-increment range is exhausted.' );
				}
				$row[ $name ] = (string) ( $maximum + 1 );
				continue;
			}
			$default = $column['default'] ?? null;
			if ( null !== $default ) {
				// MySQL evaluates dynamic defaults at write time. The canonical
				// store has no session time zone, so UTC is the honest clock.
				$dynamic = strtoupper( (string) $default );
				if ( 1 === preg_match( '/^CURRENT_TIMESTAMP(?:\(\))?$/i', (string) $default ) ) {
					$row[ $name ] = gmdate( 'Y-m-d H:i:s' );
					continue;
				}
				if ( 1 === preg_match( '/^CURRENT_DATE(?:\(\))?$/i', (string) $default ) ) {
					$row[ $name ] = gmdate( 'Y-m-d' );
					continue;
				}
				if ( 1 === preg_match( '/^CURRENT_TIME(?:\(\))?$/i', (string) $default ) ) {
					$row[ $name ] = gmdate( 'H:i:s' );
					continue;
				}
				$row[ $name ] = (string) $default;
				continue;
			}
			if ( true === ( $column['nullable'] ?? false ) ) {
				$row[ $name ] = null;
				continue;
			}
			return $this->failure( 'missing_required_column', 'The INSERT omits a required column without a deterministic default.' );
		}
		return $row;
	}

	/** @param array<string,mixed> $row @param array<int,array<string,mixed>> $rows @param array<string,mixed> $definition */
	private function duplicates_unique_index( array $row, array $rows, array $definition, WP_Markdown_Native_Table_Schema $schema ): bool {
		return null !== $this->duplicate_row_offset( $row, $rows, $definition, $schema );
	}

	/** @param array<string,mixed> $row @param array<int,array<string,mixed>> $rows @param array<string,mixed> $definition */
	private function duplicate_row_offset( array $row, array $rows, array $definition, WP_Markdown_Native_Table_Schema $schema ): ?int {
		foreach ( $definition['indexes'] as $index ) {
			if ( true !== ( $index['unique'] ?? false ) ) {
				continue;
			}
			$columns = $index['columns'];
			$names   = array_column( $columns, 'name' );
			if ( array() === $names || array_filter( $names, static fn( string $column ): bool => null === $row[ $column ] ) ) {
				continue;
			}
			foreach ( $rows as $offset => $existing ) {
				$matches = true;
				foreach ( $columns as $column ) {
					$name   = (string) ( $column['name'] ?? '' );
					$length = $column['length'] ?? null;
					$matches = $matches && $schema->values_match(
						$name,
						$this->unique_index_value( $existing[ $name ] ?? null, $length ),
						$this->unique_index_value( $row[ $name ] ?? null, $length )
					);
				}
				if ( $matches ) {
					return (int) $offset;
				}
			}
		}
		return null;
	}

	/** @param array<string,mixed> $definition */
	/** Apply one generic UPDATE or DELETE to a persisted snapshot table. */
	private function execute_write( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$write = $this->parser->parse_write( $request );
		if ( $write instanceof WP_Markdown_Query_Result ) {
			return $write;
		}

		$prefix = $request->table_prefix();
		if ( ! str_starts_with( $write->table(), $prefix ) ) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native requires a table in the active prefix.' );
		}
		$suffix = substr( $write->table(), strlen( $prefix ) );
		$table = $this->registry->table( $write->table() );
		$definition = $this->registry->definition( $write->table() );
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $suffix )
			|| null === $table
			|| ! $table['provider'] instanceof WP_Markdown_Native_JSON_Snapshot_Provider
			|| ! is_array( $definition )
			|| ! $this->is_authoritative_definition( $suffix, $definition, $prefix )
		) {
			return $this->failure( 'unsupported_mutation_table', 'mdi-native can mutate only a persisted generic snapshot table.' );
		}

		$schema = $table['schema'];
		if ( ! $this->supports_unique_indexes( $definition ) ) {
			return $this->failure( 'unsupported_unique_collation', 'mdi-native cannot enforce a persisted string or prefix unique key without its exact collation.' );
		}
		foreach ( $write->predicates() as $predicate ) {
			foreach ( $this->predicate_columns( $predicate ) as $column ) {
				if ( ! $schema->has_column( $column ) ) {
					return $this->failure( 'unsupported_mutation_column', 'The WHERE restriction names a column outside the persisted table schema.' );
				}
			}
		}
		foreach ( array_keys( $write->values() ) as $column ) {
			if ( ! $schema->has_column( (string) $column ) ) {
				return $this->failure( 'unsupported_mutation_column', 'The assignment names a column outside the persisted table schema.' );
			}
		}

		$directory = $this->tables_directory();
		if ( $directory instanceof WP_Markdown_Query_Result ) {
			return $directory;
		}
		$lock = $this->table_lock( $directory, $suffix );
		if ( $lock instanceof WP_Markdown_Query_Result ) {
			return $lock;
		}

		try {
			$path = $directory . '/' . $suffix . '.json';
			$index = $this->index->load( $suffix, $path );
			if ( null !== $index && $this->index_excludes( $index, $write->predicates() ) ) {
				return WP_Markdown_Query_Result::mutated( 0 );
			}
			$rows = $table['provider']->read( new WP_Markdown_Native_Table_Access( $schema->column_names(), null, $schema->natural_order(), PHP_INT_MAX ) );
			if ( $rows instanceof WP_Markdown_Query_Result ) {
				return $rows;
			}
			$rows = is_array( $rows ) ? $rows : iterator_to_array( $rows, false );

			$retained = array();
			$affected = 0;
			foreach ( $rows as $row ) {
				if ( ! $this->restricts( $row, $write->predicates(), $schema ) ) {
					$retained[] = $row;
					continue;
				}
				++$affected;
				if ( ! $write->is_update() ) {
					continue;
				}
				$updated = array_merge( $row, $write->values() );
				if ( true !== $schema->validate_row( $updated ) ) {
					return $this->failure( 'invalid_update_row', 'The UPDATE row is outside the persisted table schema.' );
				}
				$retained[] = $updated;
			}

			if ( 0 === $affected ) {
				$this->index->save( $suffix, $path, WP_Markdown_Native_Table_Index::build( $rows, $definition, $schema ), $this->transactions );
				return WP_Markdown_Query_Result::mutated( 0 );
			}
			$violation = $this->unique_set_violation( $retained, $definition, $schema );
			if ( $violation instanceof WP_Markdown_Query_Result ) {
				return $violation;
			}
			$written = $this->write( $path, $retained );
			if ( $written instanceof WP_Markdown_Query_Result ) {
				return $written;
			}
			// The republished snapshot invalidates the previous index, so it is
			// refreshed from the rows already in memory.
			$this->index->save( $suffix, $path, WP_Markdown_Native_Table_Index::build( $retained, $definition, $schema ), $this->transactions );
			return WP_Markdown_Query_Result::mutated( $affected );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** @param array{summary:array<string,array{null:int,empty:int}>} $index @param array<int,mixed> $predicates */
	private function index_excludes( array $index, array $predicates ): bool {
		foreach ( $predicates as $predicate ) {
			if ( $this->index_predicate_excludes( $index, $predicate ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array{summary:array<string,array{null:int,empty:int}>} $index */
	private function index_predicate_excludes( array $index, mixed $predicate ): bool {
		if ( $predicate instanceof WP_Markdown_Native_Table_Predicate_Group ) {
			foreach ( $predicate->any() as $alternative ) {
				if ( ! $this->index_predicate_excludes( $index, $alternative ) ) {
					return false;
				}
			}
			return true;
		}
		if ( ! $predicate instanceof WP_Markdown_Native_Table_Predicate || '=' !== $predicate->operator() ) {
			return false;
		}
		$counts = $index['summary'][ $predicate->column() ] ?? null;
		if ( ! is_array( $counts ) ) {
			return false;
		}
		$can_match = $predicate->matches_null() && 0 < $counts['null'];
		foreach ( $predicate->values() as $value ) {
			if ( '' !== $value ) {
				return false;
			}
			$can_match = $can_match || 0 < $counts['empty'];
		}
		return ! $can_match;
	}

	/**
	 * Decide whether one row satisfies every conjunctive restriction.
	 *
	 * @param array<string,mixed>                          $row        Canonical row.
	 * @param array<int,WP_Markdown_Native_Table_Predicate> $predicates Restrictions.
	 */
	/**
	 * @param array<int,WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group> $predicates
	 */
	private function restricts( array $row, array $predicates, WP_Markdown_Native_Table_Schema $schema ): bool {
		foreach ( $predicates as $predicate ) {
			if ( ! $this->restricts_predicate( $row, $predicate, $schema ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group $predicate */
	private function restricts_predicate( array $row, $predicate, WP_Markdown_Native_Table_Schema $schema ): bool {
		if ( $predicate instanceof WP_Markdown_Native_Table_Predicate_Group ) {
			foreach ( $predicate->any() as $alternative ) {
				if ( $this->restricts_predicate( $row, $alternative, $schema ) ) {
					return true;
				}
			}
			return array() === $predicate->any();
		}
		$value = $row[ $predicate->column() ] ?? null;
		if ( $predicate->matches_null() && null === $value ) {
			return true;
		}
		$operator = $predicate->operator();
		if ( in_array( $operator, array( '<', '<=', '>', '>=' ), true ) ) {
			// A comparison with NULL is unknown, which never restricts.
			if ( null === $value ) {
				return false;
			}
			$comparison = $schema->compare_values( $predicate->column(), $value, $predicate->values()[0] ?? null );
			return match ( $operator ) {
				'<' => $comparison < 0,
				'<=' => $comparison <= 0,
				'>' => $comparison > 0,
				default => $comparison >= 0,
			};
		}
		foreach ( $predicate->values() as $candidate ) {
			if ( $schema->values_match( $predicate->column(), $value, $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param WP_Markdown_Native_Table_Predicate|WP_Markdown_Native_Table_Predicate_Group $predicate @return array<int,string> */
	private function predicate_columns( $predicate ): array {
		if ( $predicate instanceof WP_Markdown_Native_Table_Predicate_Group ) {
			$columns = array();
			foreach ( $predicate->any() as $alternative ) {
				foreach ( $this->predicate_columns( $alternative ) as $column ) {
					$columns[] = $column;
				}
			}
			return array_values( array_unique( $columns ) );
		}
		return array( $predicate->column() );
	}

	private function supports_unique_indexes( array $definition ): bool {
		foreach ( $definition['indexes'] as $index ) {
			if ( true !== ( $index['unique'] ?? false ) ) {
				continue;
			}
			foreach ( $index['columns'] as $column ) {
				$name   = $column['name'] ?? '';
				$type   = (string) ( $definition['columns'][ $name ]['type'] ?? '' );
				$length = $column['length'] ?? null;
				if ( ! $this->unique_column_type_supported( $type ) ) {
					return false;
				}
				if ( null !== $length && ( ! is_int( $length ) || $length < 1 || WP_Markdown_Native_Schema_Catalog::is_integer( $type ) ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private function unique_index_value( mixed $value, mixed $length ): mixed {
		if ( null === $value || ! is_int( $length ) ) {
			return $value;
		}
		return is_string( $value ) ? substr( $value, 0, $length ) : $value;
	}

	private function unique_column_type_supported( string $type ): bool {
		return WP_Markdown_Native_Schema_Catalog::is_integer( $type ) || in_array( $type, array( 'char', 'varchar' ), true );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $definition */
	private function unique_values_enforceable( array $row, array $definition ): bool {
		foreach ( $definition['indexes'] as $index ) {
			if ( true !== ( $index['unique'] ?? false ) ) {
				continue;
			}
			foreach ( $index['columns'] as $column ) {
				$name  = (string) ( $column['name'] ?? '' );
				$value = $row[ $name ] ?? null;
				$type  = (string) ( $definition['columns'][ $name ]['type'] ?? '' );
				if ( null === $value || WP_Markdown_Native_Schema_Catalog::is_integer( $type ) ) {
					continue;
				}
				if ( ! is_string( $value ) || 1 === preg_match( '/[^\x00-\x7F]/', $value ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed>            $definition
	 */
	private function unique_set_violation( array $rows, array $definition, WP_Markdown_Native_Table_Schema $schema ): ?WP_Markdown_Query_Result {
		$seen = array();
		foreach ( $rows as $row ) {
			if ( ! $this->unique_values_enforceable( $row, $definition ) ) {
				return $this->failure( 'unsupported_unique_collation', 'mdi-native cannot enforce a unique key that is not exact ASCII or integer identity.' );
			}
			if ( $this->duplicates_unique_index( $row, $seen, $definition, $schema ) ) {
				return $this->failure( 'duplicate_key', 'The UPDATE row duplicates a persisted unique key.' );
			}
			$seen[] = $row;
		}
		return null;
	}

	/** @param array<string,mixed> $definition */
	/**
	 * Report whether a definition is authoritative enough to mutate.
	 *
	 * A plugin table earns trust from its persisted schema file. A core table
	 * carries no such file because its definition is generated from WordPress
	 * core itself and verified against a recorded hash, which is the stronger
	 * provenance of the two.
	 *
	 * @param array<string,mixed> $definition
	 */
	private function is_authoritative_definition( string $suffix, array $definition, string $prefix ): bool {
		return $this->is_persisted_definition( $suffix, $definition, $prefix )
			|| $this->is_generated_core_definition( $suffix, $definition );
	}

	/** @param array<string,mixed> $definition */
	private function is_generated_core_definition( string $suffix, array $definition ): bool {
		return WP_Markdown_Native_Schema_Catalog::is_generated_core_definition( $suffix, $definition );
	}

	private function is_persisted_definition( string $suffix, array $definition, string $prefix ): bool {
		$directory = realpath( $this->state_root . '/_schema' );
		$path = false === $directory ? '' : $directory . '/' . $suffix . '.sql';
		if ( false === $directory || is_link( $this->state_root . '/_schema' ) || ! is_file( $path ) || is_link( $path ) ) {
			return false;
		}
		try {
			$compiled = WP_Markdown_Native_Schema_Catalog::compile( (string) file_get_contents( $path ), array( $prefix ) );
			return array( $suffix => $definition ) === $compiled;
		} catch ( Throwable ) {
			return false;
		}
	}

	private function tables_directory(): string|WP_Markdown_Query_Result {
		$path = $this->state_root . '/_tables';
		if ( ! file_exists( $path ) && ! @mkdir( $path, 0755 ) && ! is_dir( $path ) ) {
			return $this->failure( 'tables_directory_failed', 'The canonical tables directory could not be created.' );
		}
		$root = realpath( $path );
		if ( false === $root || ! is_dir( $root ) || is_link( $path ) || dirname( $root ) !== $this->state_root ) {
			return $this->failure( 'unsafe_tables_directory', 'The canonical tables directory is unavailable or unsafe.' );
		}
		return $root;
	}

	/** Coordinate only writers that publish the same canonical table. */
	private function table_lock( string $directory, string $suffix ) {
		$path = $directory . '/.mdi-native-' . hash( 'sha256', $suffix ) . '.lock';
		$lock = @fopen( $path, 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return $this->failure( 'mutation_lock_failed', 'The canonical table mutation lock could not be acquired.' );
		}
		return $lock;
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function write( string $path, array $rows ): true|WP_Markdown_Query_Result {
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			return $this->failure( 'unsafe_table_file', 'The canonical table file is unavailable or unsafe.' );
		}
		if ( null !== $this->transactions ) {
			$recorded = $this->transactions->record( $path );
			if ( true !== $recorded ) {
				return $this->failure( 'transaction_journal_failed', $recorded );
			}
		}
		try {
			$json = json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
			$temp = $path . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 8 ) );
		} catch ( Throwable ) {
			return $this->failure( 'table_encoding_failed', 'The canonical table rows could not be encoded.' );
		}
		$handle = @fopen( $temp, 'x+b' );
		if ( false === $handle ) {
			return $this->failure( 'table_temp_failed', 'The canonical table temporary file could not be created.' );
		}
		$error = null;
		try {
			$length = strlen( $json );
			$offset = 0;
			while ( $offset < $length ) {
				$written = fwrite( $handle, substr( $json, $offset ) );
				if ( false === $written || 0 === $written ) {
					$error = $this->failure( 'table_write_failed', 'The canonical table rows could not be written.' );
					break;
				}
				$offset += $written;
			}
			if ( null === $error && ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) ) {
				$error = $this->failure( 'table_flush_failed', 'The canonical table rows could not be flushed.' );
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
			return $this->failure( 'table_publish_failed', 'The canonical table rows could not be atomically published.' );
		}
		return true;
	}

	private function failure( string $reason, string $message ): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure(
			array(
				'code'    => 'markdown_db_native_table_mutation_failed',
				'reason'  => $reason,
				'message' => $message,
			)
		);
	}
}
