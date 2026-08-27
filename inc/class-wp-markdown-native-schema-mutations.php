<?php
/** Atomic native CREATE TABLE persistence over the canonical schema catalog. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Schema_Mutation_Runtime {
	private string $state_root;

	public function __construct(
		string $state_root,
		private WP_Markdown_Native_Table_Registry $registry,
		private ?WP_Markdown_Native_Transaction_Journal $transactions = null
	) {
		$root = realpath( $state_root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new InvalidArgumentException( 'The canonical state root must be an existing directory.' );
		}
		$this->state_root = rtrim( $root, DIRECTORY_SEPARATOR );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$sql = trim( $request->sql() );
		if ( str_ends_with( $sql, ';' ) ) {
			$sql = rtrim( substr( $sql, 0, -1 ) );
		}
		if ( '' === $sql || str_contains( $sql, ';' ) ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native requires one bounded CREATE TABLE statement.' );
		}
		if ( 1 === preg_match( '/^\s*ALTER\s+TABLE\b/i', $sql ) ) {
			return $this->execute_alter( $request, $sql );
		}

		try {
			$definitions = WP_Markdown_Native_Schema_Catalog::compile( $sql, array( $request->table_prefix() ) );
		} catch ( InvalidArgumentException ) {
			return $this->failure( 'unsupported_schema', 'mdi-native cannot compile the requested table definition.' );
		}
		if ( 1 !== count( $definitions ) ) {
			return $this->failure( 'unsupported_schema', 'mdi-native requires one prefixed table definition.' );
		}

		$suffix = (string) array_key_first( $definitions );
		$table = $request->table_prefix() . $suffix;
		$definition = $definitions[ $suffix ];
		if ( null !== $this->registry->definition( $table ) ) {
			return $this->failure( 'table_exists', 'mdi-native cannot create a table that already exists.' );
		}

		$directory = $this->schema_directory();
		if ( $directory instanceof WP_Markdown_Query_Result ) {
			return $directory;
		}
		$lock = @fopen( $directory . '/.mdi-native.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return $this->failure( 'mutation_lock_failed', 'The canonical schema mutation lock could not be acquired.' );
		}

		try {
			$path = $directory . '/' . $suffix . '.sql';
			if ( file_exists( $path ) || is_link( $path ) || null !== $this->registry->definition( $table ) ) {
				return $this->failure( 'table_exists', 'mdi-native cannot create a table that already exists.' );
			}
			$written = $this->write( $path, $sql . ";\n" );
			if ( $written instanceof WP_Markdown_Query_Result ) {
				return $written;
			}
			$schema = WP_Markdown_Native_Schema_Catalog::indexed_snapshot_schema( $definition );
			if ( null === $schema ) {
				$this->registry->register_definition( $table, $definition );
			} else {
				$this->registry->register(
					$table,
					$schema,
					new WP_Markdown_Native_JSON_Snapshot_Provider( $this->state_root, $schema, $suffix . '.json' )
				);
			}
			return WP_Markdown_Query_Result::schema_changed();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * Apply one bounded ALTER TABLE column action.
	 *
	 * The persisted CREATE TABLE statement stays the single source of truth, so
	 * an alteration rewrites that statement and recompiles it rather than
	 * maintaining a second schema representation.
	 */
	private function execute_alter( WP_Markdown_Query_Request $request, string $sql ): WP_Markdown_Query_Result {
		if ( 1 !== preg_match( '/^\s*ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.*)$/is', $sql, $statement ) ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native requires one bounded ALTER TABLE statement.' );
		}
		$table = $statement[1];
		$action = trim( $statement[2] );
		$prefix = $request->table_prefix();
		if ( ! str_starts_with( $table, $prefix ) ) {
			return $this->failure( 'unsupported_schema', 'mdi-native requires a table in the active prefix.' );
		}
		$suffix = substr( $table, strlen( $prefix ) );
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $suffix ) || null === $this->registry->definition( $table ) ) {
			return $this->failure( 'unknown_table', 'mdi-native cannot alter a table it does not persist.' );
		}

		if ( 1 === preg_match( '/^(MODIFY|ADD|DROP)\s+(?:COLUMN\s+)?`?([A-Za-z_][A-Za-z0-9_]*)`?\s*(.*)$/is', $action, $parts ) ) {
			$operation = strtoupper( $parts[1] );
			$column = $parts[2];
			$column_definition = trim( $parts[3] );
		} else {
			return $this->failure( 'unsupported_schema', 'mdi-native supports bounded ADD, MODIFY, and DROP column alterations.' );
		}
		if ( 'DROP' !== $operation && '' === $column_definition ) {
			return $this->failure( 'unsupported_schema', 'mdi-native requires a column definition for ADD and MODIFY.' );
		}

		$directory = $this->schema_directory();
		if ( $directory instanceof WP_Markdown_Query_Result ) {
			return $directory;
		}
		$lock = @fopen( $directory . '/.mdi-native.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return $this->failure( 'mutation_lock_failed', 'The canonical schema mutation lock could not be acquired.' );
		}

		try {
			$path = $directory . '/' . $suffix . '.sql';
			if ( ! is_file( $path ) || is_link( $path ) ) {
				return $this->failure( 'unknown_table', 'The persisted table definition is unavailable.' );
			}
			$persisted = (string) file_get_contents( $path );
			$rewritten = $this->rewrite_definition( $persisted, $operation, $column, $column_definition );
			if ( $rewritten instanceof WP_Markdown_Query_Result ) {
				return $rewritten;
			}

			try {
				$definitions = WP_Markdown_Native_Schema_Catalog::compile( $rewritten, array( $prefix ) );
			} catch ( InvalidArgumentException ) {
				return $this->failure( 'unsupported_schema', 'The altered table definition could not be compiled.' );
			}
			if ( 1 !== count( $definitions ) || ! isset( $definitions[ $suffix ] ) ) {
				return $this->failure( 'unsupported_schema', 'The altered table definition did not resolve to one prefixed table.' );
			}
			$definition = $definitions[ $suffix ];
			$schema = WP_Markdown_Native_Schema_Catalog::indexed_snapshot_schema( $definition );

			$reconciled = $this->reconcile_rows( $suffix, $operation, $column, $schema );
			if ( $reconciled instanceof WP_Markdown_Query_Result ) {
				return $reconciled;
			}

			$written = $this->write( $path, $rewritten );
			if ( $written instanceof WP_Markdown_Query_Result ) {
				return $written;
			}
			$this->registry->reregister(
				$table,
				$schema,
				null === $schema ? null : new WP_Markdown_Native_JSON_Snapshot_Provider( $this->state_root, $schema, $suffix . '.json' ),
				$definition
			);
			return WP_Markdown_Query_Result::schema_changed();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** Rewrite the persisted CREATE TABLE body for one column alteration. */
	private function rewrite_definition( string $persisted, string $operation, string $column, string $column_definition ): string|WP_Markdown_Query_Result {
		if ( 1 !== preg_match( '/CREATE\s+TABLE\s+`?[A-Za-z0-9_]+`?\s*\(/is', $persisted, $header, PREG_OFFSET_CAPTURE ) ) {
			return $this->failure( 'unsupported_schema', 'The persisted table definition could not be parsed.' );
		}
		$open = $header[0][1] + strlen( $header[0][0] );
		$close = $this->matching_paren( $persisted, $open - 1 );
		if ( null === $close ) {
			return $this->failure( 'unsupported_schema', 'The persisted table definition is unbalanced.' );
		}

		$entries = $this->split_entries( substr( $persisted, $open, $close - $open ) );
		$replacement = sprintf( '`%s` %s', $column, $column_definition );
		$index = null;
		$last_column = -1;
		foreach ( $entries as $position => $entry ) {
			$name = $this->entry_column( $entry );
			if ( null === $name ) {
				continue;
			}
			$last_column = $position;
			if ( 0 === strcasecmp( $name, $column ) ) {
				$index = $position;
			}
		}

		if ( 'ADD' === $operation ) {
			if ( null !== $index ) {
				return $this->failure( 'column_exists', 'mdi-native cannot add a column that already exists.' );
			}
			array_splice( $entries, $last_column + 1, 0, array( $replacement ) );
		} elseif ( null === $index ) {
			return $this->failure( 'unknown_column', 'mdi-native cannot alter a column the table does not define.' );
		} elseif ( 'MODIFY' === $operation ) {
			$entries[ $index ] = $replacement;
		} else {
			foreach ( $entries as $position => $entry ) {
				if ( $position !== $index && null === $this->entry_column( $entry ) && $this->references_column( $entry, $column ) ) {
					return $this->failure( 'unsupported_schema', 'mdi-native cannot drop a column an index still references.' );
				}
			}
			array_splice( $entries, $index, 1 );
		}

		return substr( $persisted, 0, $open ) . "\n\t" . implode( ",\n\t", $entries ) . "\n" . substr( $persisted, $close );
	}

	/** Reconcile persisted snapshot rows with an added or dropped column. */
	private function reconcile_rows( string $suffix, string $operation, string $column, ?WP_Markdown_Native_Table_Schema $schema ): true|WP_Markdown_Query_Result {
		if ( 'MODIFY' === $operation || null === $schema ) {
			return true;
		}
		$path = $this->state_root . '/_tables/' . $suffix . '.json';
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return true;
		}
		$rows = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $rows ) ) {
			return $this->failure( 'unsupported_schema', 'The persisted table snapshot could not be read.' );
		}
		foreach ( $rows as $position => $row ) {
			if ( ! is_array( $row ) ) {
				return $this->failure( 'unsupported_schema', 'The persisted table snapshot contains an unsupported row.' );
			}
			if ( 'ADD' === $operation ) {
				$rows[ $position ][ $column ] = null;
				continue;
			}
			unset( $rows[ $position ][ $column ] );
		}
		try {
			$encoded = json_encode( array_values( $rows ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		} catch ( Throwable ) {
			return $this->failure( 'unsupported_schema', 'The reconciled table snapshot could not be encoded.' );
		}
		$written = $this->write( $path, $encoded );
		return $written instanceof WP_Markdown_Query_Result ? $written : true;
	}

	/** @return array<int,string> */
	private function split_entries( string $body ): array {
		$entries = array();
		$current = '';
		$depth = 0;
		$quote = null;
		$length = strlen( $body );
		for ( $position = 0; $position < $length; $position++ ) {
			$character = $body[ $position ];
			if ( null !== $quote ) {
				$current .= $character;
				if ( $character === $quote ) {
					$quote = null;
				}
				continue;
			}
			if ( '`' === $character || "'" === $character || '"' === $character ) {
				$quote = $character;
				$current .= $character;
				continue;
			}
			if ( '(' === $character ) {
				++$depth;
			} elseif ( ')' === $character ) {
				--$depth;
			} elseif ( ',' === $character && 0 === $depth ) {
				$entries[] = trim( $current );
				$current = '';
				continue;
			}
			$current .= $character;
		}
		if ( '' !== trim( $current ) ) {
			$entries[] = trim( $current );
		}
		return array_values( array_filter( $entries, static fn( string $entry ): bool => '' !== $entry ) );
	}

	/** Resolve the column an entry defines, or null when it defines an index. */
	private function entry_column( string $entry ): ?string {
		if ( 1 === preg_match( '/^(?:PRIMARY|UNIQUE|FULLTEXT|SPATIAL|FOREIGN|CONSTRAINT|CHECK|KEY|INDEX)\b/i', $entry ) ) {
			return null;
		}
		return 1 === preg_match( '/^`?([A-Za-z_][A-Za-z0-9_]*)`?\s/', $entry, $match ) ? $match[1] : null;
	}

	private function references_column( string $entry, string $column ): bool {
		return 1 === preg_match( '/[`(,\s]' . preg_quote( $column, '/' ) . '[`),\s]/i', $entry );
	}

	private function matching_paren( string $sql, int $open ): ?int {
		$depth = 0;
		$quote = null;
		$length = strlen( $sql );
		for ( $position = $open; $position < $length; $position++ ) {
			$character = $sql[ $position ];
			if ( null !== $quote ) {
				if ( $character === $quote ) {
					$quote = null;
				}
				continue;
			}
			if ( '`' === $character || "'" === $character || '"' === $character ) {
				$quote = $character;
				continue;
			}
			if ( '(' === $character ) {
				++$depth;
			} elseif ( ')' === $character ) {
				--$depth;
				if ( 0 === $depth ) {
					return $position;
				}
			}
		}
		return null;
	}

	private function schema_directory(): string|WP_Markdown_Query_Result {
		$path = $this->state_root . '/_schema';
		if ( ! file_exists( $path ) && ! @mkdir( $path, 0755 ) && ! is_dir( $path ) ) {
			return $this->failure( 'schema_directory_failed', 'The canonical schema directory could not be created.' );
		}
		$root = realpath( $path );
		if ( false === $root || ! is_dir( $root ) || is_link( $path ) || dirname( $root ) !== $this->state_root ) {
			return $this->failure( 'unsafe_schema_directory', 'The canonical schema directory is unavailable or unsafe.' );
		}
		return $root;
	}

	private function write( string $path, string $contents ): true|WP_Markdown_Query_Result {
		if ( null !== $this->transactions ) {
			$recorded = $this->transactions->record( $path );
			if ( true !== $recorded ) {
				return $this->failure( 'transaction_journal_failed', $recorded );
			}
		}
		try {
			$temp = $path . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 8 ) );
		} catch ( Throwable ) {
			return $this->failure( 'schema_temp_failed', 'The canonical schema temporary path could not be created.' );
		}
		$handle = @fopen( $temp, 'x+b' );
		if ( false === $handle ) {
			return $this->failure( 'schema_temp_failed', 'The canonical schema temporary file could not be created.' );
		}
		$error = null;
		try {
			$length = strlen( $contents );
			$offset = 0;
			while ( $offset < $length ) {
				$written = fwrite( $handle, substr( $contents, $offset ) );
				if ( false === $written || 0 === $written ) {
					$error = $this->failure( 'schema_write_failed', 'The canonical schema could not be written.' );
					break;
				}
				$offset += $written;
			}
			if ( null === $error && ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) ) {
				$error = $this->failure( 'schema_flush_failed', 'The canonical schema could not be flushed.' );
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
			return $this->failure( 'schema_publish_failed', 'The canonical schema could not be atomically published.' );
		}
		return true;
	}

	private function failure( string $reason, string $message ): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure(
			array(
				'code'    => 'markdown_db_native_schema_mutation_failed',
				'reason'  => $reason,
				'message' => $message,
			)
		);
	}
}
