<?php
/** File-backed providers; query execution remains table-neutral. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WP_Markdown_Native_File_Provider implements WP_Markdown_Native_Table_Provider {

	protected string $state_root;

	public function __construct(
		string $state_root,
		protected WP_Markdown_Native_Table_Schema $schema
	) {
		$root = realpath( $state_root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new InvalidArgumentException( 'The canonical state root must be an existing directory.' );
		}
		$this->state_root = rtrim( $root, DIRECTORY_SEPARATOR );
	}

	protected function failure( string $code, string $reason, string $message ): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure(
			array(
				'code'    => $code,
				'reason'  => $reason,
				'message' => $message,
			)
		);
	}

	protected function contains( string $root, string $path ): bool {
		return str_starts_with( $path, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
	}

	/** @return mixed|WP_Markdown_Query_Result */
	protected function read_json( string $path, string $root, string $kind ) {
		$real = realpath( $path );
		if ( is_link( $path ) || false === $real || ! is_file( $real ) || ! $this->contains( $root, $real ) ) {
			return $this->failure(
				'markdown_db_native_unsafe_path',
				'unsafe_' . $kind,
				'The canonical state file is not contained by its state directory.'
			);
		}

		$handle = @fopen( $real, 'rb' );
		if ( false === $handle ) {
			return $this->failure(
				'markdown_db_native_malformed_table',
				'unreadable_' . $kind,
				'The canonical state file cannot be read.'
			);
		}

		try {
			$opened  = fstat( $handle );
			$current = @lstat( $real );
			if ( false === $opened
				|| false === $current
				|| $opened['dev'] !== $current['dev']
				|| $opened['ino'] !== $current['ino']
				|| 1 !== ( $opened['nlink'] ?? 1 )
				|| is_link( $real )
				|| $root !== realpath( dirname( $path ) )
			) {
				return $this->failure(
					'markdown_db_native_unsafe_path',
					'changed_' . $kind,
					'The canonical state file changed while it was being opened.'
				);
			}

			$contents = stream_get_contents( $handle );
			if ( false === $contents ) {
				return $this->failure(
					'markdown_db_native_malformed_table',
					'unreadable_' . $kind,
					'The canonical state file cannot be read.'
				);
			}
			return json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			return $this->failure(
				'markdown_db_native_malformed_table',
				'invalid_json',
				'The canonical state file contains invalid JSON.'
			);
		} finally {
			fclose( $handle );
		}
	}

	/** @return array<int,array<string,mixed>>|WP_Markdown_Query_Result */
	protected function validate_rows( mixed $rows, string $identity ): array|WP_Markdown_Query_Result {
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
			return $this->failure(
				'markdown_db_native_malformed_table',
				'invalid_table_rows',
				'The canonical table must contain a JSON array of rows.'
			);
		}

		$identities = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || true !== $this->schema->validate_row( $row ) ) {
				return $this->failure(
					'markdown_db_native_malformed_table',
					'invalid_table_row',
					'The canonical table contains a row outside its declared schema.'
				);
			}
			$key = serialize( $row[ $identity ] );
			if ( isset( $identities[ $key ] ) ) {
				return $this->failure(
					'markdown_db_native_malformed_table',
					'duplicate_natural_identity',
					'The canonical table contains duplicate natural identities.'
				);
			}
			$identities[ $key ] = true;
		}
		return $rows;
	}
}

final class WP_Markdown_Native_JSON_Snapshot_Provider extends WP_Markdown_Native_File_Provider {

	public function __construct(
		string $state_root,
		WP_Markdown_Native_Table_Schema $schema,
		private string $filename
	) {
		parent::__construct( $state_root, $schema );
	}

	public function scan(): array|WP_Markdown_Query_Result {
		$directory = $this->state_root . DIRECTORY_SEPARATOR . '_tables';
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
			return array();
		}
		$root = realpath( $directory );
		if ( is_link( $directory ) || false === $root || ! is_dir( $root ) || ! $this->contains( $this->state_root, $root ) ) {
			return $this->failure(
				'markdown_db_native_unsafe_path',
				'unsafe_tables_directory',
				'The canonical tables directory is not contained by the state root.'
			);
		}

		$path = $root . DIRECTORY_SEPARATOR . $this->filename;
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return array();
		}
		$data = $this->read_json( $path, $root, 'table_file' );
		return $data instanceof WP_Markdown_Query_Result
			? $data
			: $this->validate_rows( $data, $this->schema->natural_order() );
	}

	public function lookup( string $column, array $values ): array|WP_Markdown_Query_Result {
		if ( ! $this->schema->is_lookup( $column ) ) {
			return $this->failure(
				'markdown_db_native_unsupported_query',
				'unsupported_lookup',
				'The requested predicate column is not indexed by this native table.'
			);
		}
		$rows = $this->scan();
		if ( $rows instanceof WP_Markdown_Query_Result ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $column, $values ): bool {
					foreach ( $values as $value ) {
						if ( $this->schema->values_match( $column, $row[ $column ], $value ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}
}

final class WP_Markdown_Native_Option_Provider extends WP_Markdown_Native_File_Provider {

	public function scan(): array|WP_Markdown_Query_Result {
		$root = $this->options_root();
		if ( $root instanceof WP_Markdown_Query_Result ) {
			return $root;
		}
		if ( null === $root ) {
			return array();
		}

		try {
			$paths = array();
			foreach ( new FilesystemIterator( $root, FilesystemIterator::SKIP_DOTS ) as $entry ) {
				if ( str_ends_with( $entry->getFilename(), '.json' ) ) {
					$paths[] = $root . DIRECTORY_SEPARATOR . $entry->getFilename();
				}
			}
		} catch ( UnexpectedValueException $error ) {
			return $this->failure(
				'markdown_db_native_unsafe_path',
				'unreadable_options_directory',
				'The canonical options directory cannot be enumerated.'
			);
		}
		if ( $root !== realpath( $this->state_root . DIRECTORY_SEPARATOR . '_options' )
			|| is_link( $this->state_root . DIRECTORY_SEPARATOR . '_options' )
		) {
			return $this->failure(
				'markdown_db_native_unsafe_path',
				'changed_options_directory',
				'The canonical options directory changed while it was being enumerated.'
			);
		}

		sort( $paths, SORT_STRING );
		$rows  = array();
		$ids   = array();
		$names = array();
		foreach ( $paths as $path ) {
			$row = $this->read_option( $path, $root );
			if ( $row instanceof WP_Markdown_Query_Result ) {
				return $row;
			}
			if ( basename( $path ) !== WP_Markdown_Canonical_Option_Path::filename( $row['option_name'] )
				|| isset( $ids[ $row['option_id'] ] )
				|| isset( $names[ $row['option_name'] ] )
			) {
				return $this->failure(
					'markdown_db_native_malformed_option',
					'invalid_option_identity',
					'Canonical option files must have unique identities and canonical filenames.'
				);
			}
			$ids[ $row['option_id'] ]     = true;
			$names[ $row['option_name'] ] = true;
			$rows[]                       = $row;
		}
		return $rows;
	}

	public function lookup( string $column, array $values ): array|WP_Markdown_Query_Result {
		if ( ! $this->schema->is_lookup( $column ) ) {
			return $this->failure(
				'markdown_db_native_unsupported_query',
				'unsupported_lookup',
				'The requested predicate column is not indexed by this native table.'
			);
		}
		if ( 'option_name' !== $column ) {
			$rows = $this->scan();
			if ( $rows instanceof WP_Markdown_Query_Result ) {
				return $rows;
			}
			return array_values(
				array_filter(
					$rows,
					fn( array $row ): bool => $this->matches_any( $column, $row[ $column ], $values )
				)
			);
		}

		$root = $this->options_root();
		if ( $root instanceof WP_Markdown_Query_Result ) {
			return $root;
		}
		if ( null === $root ) {
			return array();
		}

		$rows = array();
		$ids  = array();
		foreach ( $values as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$path = $this->option_path( $root, $name );
			if ( $path instanceof WP_Markdown_Query_Result ) {
				return $path;
			}
			if ( null === $path ) {
				continue;
			}
			$row = $this->read_option( $path, $root, $name );
			if ( $row instanceof WP_Markdown_Query_Result ) {
				return $row;
			}
			if ( isset( $ids[ $row['option_id'] ] ) ) {
				return $this->failure(
					'markdown_db_native_malformed_option',
					'invalid_option_identity',
					'Canonical option files must have unique identities.'
				);
			}
			$ids[ $row['option_id'] ] = true;
			$rows[]                   = $row;
		}
		return $rows;
	}

	private function option_path( string $root, string $name ): string|WP_Markdown_Query_Result|null {
		$filename = WP_Markdown_Canonical_Option_Path::filename( $name );
		$path     = $root . DIRECTORY_SEPARATOR . $filename;
		if ( file_exists( $path ) || is_link( $path ) ) {
			return $path;
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]+$/D', $name ) ) {
			return $this->failure(
				'markdown_db_native_unsupported_query',
				'unsupported_option_collation',
				'The native option provider cannot resolve this missing identity under the configured collation.'
			);
		}

		$matches = array();
		try {
			foreach ( new FilesystemIterator( $root, FilesystemIterator::SKIP_DOTS ) as $entry ) {
				if ( 0 === strcasecmp( $entry->getFilename(), $filename ) ) {
					$matches[] = $root . DIRECTORY_SEPARATOR . $entry->getFilename();
				}
			}
		} catch ( UnexpectedValueException $error ) {
			return $this->failure(
				'markdown_db_native_unsafe_path',
				'unreadable_options_directory',
				'The canonical options directory cannot be enumerated.'
			);
		}
		if ( $root !== realpath( $this->state_root . DIRECTORY_SEPARATOR . '_options' ) || is_link( $root ) ) {
			return $this->failure(
				'markdown_db_native_unsafe_path',
				'changed_options_directory',
				'The canonical options directory changed while resolving an identity.'
			);
		}
		if ( count( $matches ) > 1 ) {
			return $this->failure(
				'markdown_db_native_malformed_option',
				'duplicate_collated_identity',
				'Canonical option files contain duplicate collated identities.'
			);
		}
		return $matches[0] ?? null;
	}

	private function options_root(): string|WP_Markdown_Query_Result|null {
		$path = $this->state_root . DIRECTORY_SEPARATOR . '_options';
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return null;
		}
		$root = realpath( $path );
		if ( is_link( $path ) || false === $root || ! is_dir( $root ) || ! $this->contains( $this->state_root, $root ) ) {
			return $this->failure(
				'markdown_db_native_unsafe_path',
				'unsafe_options_directory',
				'The canonical options directory is not contained by the state root.'
			);
		}
		return $root;
	}

	/** @return array<string,mixed>|WP_Markdown_Query_Result */
	private function read_option(
		string $path,
		string $root,
		?string $expected = null
	): array|WP_Markdown_Query_Result {
		$row = $this->read_json( $path, $root, 'option_file' );
		if ( $row instanceof WP_Markdown_Query_Result ) {
			$diagnostic = $row->diagnostic();
			return 'markdown_db_native_unsafe_path' === ( $diagnostic['code'] ?? '' )
				? $row
				: $this->failure(
					'markdown_db_native_malformed_option',
					$diagnostic['reason'] ?? 'unreadable_option_file',
					'The canonical option file cannot be read.'
				);
		}
		if ( ! is_array( $row )
			|| true !== $this->schema->validate_row( $row )
			|| ( null !== $expected && ! $this->schema->values_match( 'option_name', $expected, $row['option_name'] ) )
		) {
			return $this->failure(
				'markdown_db_native_malformed_option',
				'invalid_option_row',
				'The canonical option file does not contain the requested option row.'
			);
		}
		return $row;
	}

	private function matches_any( string $column, mixed $row_value, array $values ): bool {
		foreach ( $values as $value ) {
			if ( $this->schema->values_match( $column, $row_value, $value ) ) {
				return true;
			}
		}
		return false;
	}
}
