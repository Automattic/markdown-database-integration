<?php
/** File-backed providers; query execution remains table-neutral. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WP_Markdown_Native_File_Provider implements WP_Markdown_Native_Table_Provider {

	protected string $state_root;
	/** @var array<string,array<string,array<int,int>>> */
	private array $indexes = array();

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
	protected function read_json( string $path, string $root, string $kind, ?string &$digest = null ) {
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
			$digest = hash( 'sha256', $contents );
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
	protected function validate_rows( mixed $rows ): array|WP_Markdown_Query_Result {
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
			$key = $this->schema->identity_key( $row );
			if ( null === $key || isset( $identities[ $key ] ) ) {
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

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	protected function indexed_rows( array $rows, WP_Markdown_Native_Query_Predicate $predicate ): array {
		$column = $predicate->column();
		if ( ! isset( $this->indexes[ $column ] ) ) {
			$this->indexes[ $column ] = array();
			foreach ( $rows as $offset => $row ) {
				$key = $this->schema->value_key( $column, $row[ $column ] );
				if ( null !== $key ) {
					$this->indexes[ $column ][ $key ][] = $offset;
				}
			}
		}

		$selected = array();
		$seen     = array();
		foreach ( $predicate->values() as $value ) {
			$key = $this->schema->value_key( $column, $value );
			foreach ( null === $key ? array() : ( $this->indexes[ $column ][ $key ] ?? array() ) as $offset ) {
				$row = $rows[ $offset ];
				$identity = $this->schema->identity_key( $row );
				if ( null !== $identity && ! isset( $seen[ $identity ] ) ) {
					$seen[ $identity ] = true;
					$selected[]        = $row;
				}
			}
		}
		return $selected;
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	protected function bounded_rows( array $rows, WP_Markdown_Native_Table_Access $access ): array {
		usort(
			$rows,
			fn( array $left, array $right ): int => $this->schema->compare_rows( $access->order(), $left, $right )
		);

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

	protected function reset_indexes(): void {
		$this->indexes = array();
	}

	/** Cache identity only; path safety is still enforced while reading. */
	protected function path_signature( string $path, ?string $content_digest = null ): string {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( false === $stat ) {
			return 'missing';
		}
		$identity = implode( ':', array( $stat['dev'], $stat['ino'], $stat['mode'], $stat['size'], $stat['mtime'], $stat['ctime'], $stat['nlink'] ?? 0 ) );
		if ( is_link( $path ) ) {
			return 'link:' . $identity . ':' . (string) @readlink( $path );
		}
		if ( is_file( $path ) ) {
			if ( 1 !== ( $stat['nlink'] ?? 1 ) ) {
				return 'linked-file:' . $identity;
			}
			return 'file:' . $identity . ':' . ( $content_digest ?? (string) @hash_file( 'sha256', $path ) );
		}
		return 'node:' . $identity;
	}
}

final class WP_Markdown_Native_Post_Provider extends WP_Markdown_Native_File_Provider {
	private WP_Markdown_Storage $storage;

	public function __construct(
		string $content_root,
		WP_Markdown_Native_Table_Schema $schema,
		?WP_Markdown_Storage $storage = null
	) {
		parent::__construct( $content_root, $schema );
		$this->storage = $storage ?? new WP_Markdown_Storage( $content_root );
	}

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		try {
			$posts = array();
			$ids   = array();
			foreach ( $this->storage->get_markdown_file_manifest_iterator( true ) as $file ) {
				$identity = $this->file_identity( $file['absolute'] );
				$post = $this->storage->read_file( $file['absolute'], true, $file['parent_id'] );
				if ( null === $identity || null === $post || (int) ( $post->ID ?? 0 ) < 1 ) {
					return $this->malformed( 'invalid_post', 'A canonical Markdown post is malformed or has no durable identity.' );
				}
				if ( ! $this->unchanged( $file['absolute'], $identity ) ) {
					return $this->malformed( 'changed_post', 'A canonical Markdown post changed while it was being read.' );
				}
				$id = (int) $post->ID;
				if ( isset( $ids[ $id ] ) ) {
					return $this->malformed( 'duplicate_post_id', 'Canonical Markdown posts contain a duplicate durable identity.' );
				}
				$ids[ $id ] = true;
				$row = $this->row( $post );
				if ( true !== $this->schema->validate_row( $row ) ) {
					return $this->malformed( 'invalid_post_row', 'A canonical Markdown post is outside the wp_posts schema.' );
				}
				$predicate = $access->predicate();
				if ( null !== $predicate && ! $this->matches( $row, $predicate ) ) {
					continue;
				}
				$posts[] = array( 'post' => $post, 'row' => $row, 'file' => $file, 'identity' => $identity );
			}

			usort(
				$posts,
				fn( array $left, array $right ): int => $this->schema->compare_rows( $access->order(), $left['row'], $right['row'] )
			);
			$selected = array();
			foreach ( $posts as $candidate ) {
				if ( count( $selected ) >= $access->limit() ) {
					break;
				}
				$row = $candidate['row'];
				if ( in_array( 'post_content', $access->projection(), true ) ) {
					$post = $this->storage->read_file( $candidate['file']['absolute'], false, $candidate['file']['parent_id'] );
					if ( ! $this->unchanged( $candidate['file']['absolute'], $candidate['identity'] ) || null === $post ) {
						return $this->malformed( 'changed_post', 'A canonical Markdown post changed while it was being read.' );
					}
					$row = $this->row( $post );
				}
				$projected = array();
				foreach ( $access->projection() as $column ) {
					$projected[ $column ] = $row[ $column ];
				}
				$selected[] = $projected;
			}
			return $selected;
		} catch ( Throwable $error ) {
			return $this->malformed( 'unsafe_post_storage', 'Canonical Markdown posts cannot be read safely.' );
		}
	}

	/** @param array<string,mixed> $row */
	private function matches( array $row, WP_Markdown_Native_Query_Predicate $predicate ): bool {
		foreach ( $predicate->values() as $value ) {
			if ( $this->schema->values_match( $predicate->column(), $row[ $predicate->column() ], $value ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string,mixed> */
	private function row( object $post ): array {
		$row = array();
		foreach ( $this->schema->column_names() as $column ) {
			$row[ $column ] = $post->{$column};
		}
		return $row;
	}

	private function malformed( string $reason, string $message ): WP_Markdown_Query_Result {
		return $this->failure( 'markdown_db_native_malformed_post', $reason, $message );
	}

	/** @return array{dev:int,ino:int,mode:int,size:int,mtime:int,ctime:int,nlink:int}|null */
	private function file_identity( string $path ): ?array {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( ! is_array( $stat ) || is_link( $path ) || 1 !== ( $stat['nlink'] ?? 1 ) ) {
			return null;
		}
		return array(
			'dev'   => (int) $stat['dev'],
			'ino'   => (int) $stat['ino'],
			'mode'  => (int) $stat['mode'],
			'size'  => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
			'ctime' => (int) $stat['ctime'],
			'nlink' => (int) $stat['nlink'],
		);
	}

	/** @param array{dev:int,ino:int,mode:int,size:int,mtime:int,ctime:int,nlink:int} $identity */
	private function unchanged( string $path, array $identity ): bool {
		return $identity === $this->file_identity( $path );
	}
}

final class WP_Markdown_Native_JSON_Snapshot_Provider extends WP_Markdown_Native_File_Provider {
	private bool $loaded = false;
	private string $signature = '';
	/** @var array<int,array<string,mixed>>|WP_Markdown_Query_Result|null */
	private array|WP_Markdown_Query_Result|null $snapshot = null;

	public function __construct(
		string $state_root,
		WP_Markdown_Native_Table_Schema $schema,
		private string $filename
	) {
		parent::__construct( $state_root, $schema );
	}

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$rows = $this->snapshot();
		if ( $rows instanceof WP_Markdown_Query_Result ) {
			return $rows;
		}
		if ( null !== $access->predicate() ) {
			$rows = $this->indexed_rows( $rows, $access->predicate() );
		}
		return $this->bounded_rows( $rows, $access );
	}

	/** @return array<int,array<string,mixed>>|WP_Markdown_Query_Result */
	private function snapshot(): array|WP_Markdown_Query_Result {
		$directory = $this->state_root . DIRECTORY_SEPARATOR . '_tables';
		$path      = $directory . DIRECTORY_SEPARATOR . $this->filename;
		if ( $this->loaded ) {
			$signature = $this->snapshot_signature( $directory, $path );
			if ( $signature === $this->signature ) {
				return $this->snapshot;
			}
		}
		$this->loaded = true;
		$this->reset_indexes();
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
			$this->signature = $this->snapshot_signature( $directory, $path );
			return $this->snapshot = array();
		}
		$root = realpath( $directory );
		if ( is_link( $directory ) || false === $root || ! is_dir( $root ) || ! $this->contains( $this->state_root, $root ) ) {
			$this->signature = $this->snapshot_signature( $directory, $path );
			return $this->snapshot = $this->failure(
				'markdown_db_native_unsafe_path',
				'unsafe_tables_directory',
				'The canonical tables directory is not contained by the state root.'
			);
		}

		$path = $root . DIRECTORY_SEPARATOR . $this->filename;
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			$this->signature = $this->snapshot_signature( $directory, $path );
			return $this->snapshot = array();
		}
		$digest = null;
		$data = $this->read_json( $path, $root, 'table_file', $digest );
		$this->signature = $this->snapshot_signature( $directory, $path, $digest );
		return $this->snapshot = $data instanceof WP_Markdown_Query_Result
			? $data
			: $this->validate_rows( $data );
	}

	private function snapshot_signature( string $directory, string $path, ?string $digest = null ): string {
		$directory_signature = $this->path_signature( $directory );
		if ( is_link( $directory ) || ! is_dir( $directory ) ) {
			return $directory_signature . '|unavailable';
		}
		return $directory_signature . '|' . $this->path_signature( $path, $digest );
	}
}

final class WP_Markdown_Native_JSON_Partition_Provider extends WP_Markdown_Native_File_Provider {
	private string $lock_root;

	public function __construct(
		string $state_root,
		WP_Markdown_Native_Table_Schema $schema,
		private string $table,
		private string $identity_column
	) {
		$this->lock_root = rtrim( $state_root, '/\\' );
		parent::__construct( $state_root, $schema );
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $table )
			|| ! $schema->has_column( $identity_column )
			|| ! $schema->is_lookup( $identity_column )
		) {
			throw new InvalidArgumentException( 'Partition providers require a table and indexed identity column.' );
		}
	}

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$predicate = $access->predicate();
		if ( null === $predicate || $this->identity_column !== $predicate->column() ) {
			return $this->failure(
				'markdown_db_native_unsupported_query',
				'unsupported_partition_access',
				'The native partition provider requires an exact identity predicate.'
			);
		}

		$lock = $this->partition_lock();
		if ( $lock instanceof WP_Markdown_Query_Result ) {
			return $lock;
		}
		try {
			$generation = $this->active_generation();
			if ( $generation instanceof WP_Markdown_Query_Result ) {
				return $generation;
			}
			if ( null === $generation ) {
				return array();
			}

			$identities = array();
			foreach ( $predicate->values() as $value ) {
				$normalized = $this->schema->column( $this->identity_column )->normalize( $value );
				if ( ! is_int( $normalized ) && ! is_string( $normalized ) ) {
					return $this->malformed( 'invalid_partition_identity', 'The requested partition identity cannot be normalized.' );
				}
				$identity = (string) $normalized;
				$identities[ $identity ] = $normalized;
			}
			if ( $access->order() === $this->identity_column ) {
				usort(
					$identities,
					fn( mixed $left, mixed $right ): int => $this->schema->compare_values( $this->identity_column, $left, $right )
				);
			}

			$rows = array();
			foreach ( $identities as $normalized ) {
				if ( $access->order() === $this->identity_column && count( $rows ) >= $access->limit() ) {
					break;
				}
				$identity = (string) $normalized;
				$row = $this->partition_row( $generation, $identity );
				if ( $row instanceof WP_Markdown_Query_Result ) {
					return $row;
				}
				if ( null !== $row ) {
					$rows[] = $row;
				}
			}
			return $this->bounded_rows( $rows, $access );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/** @return resource|WP_Markdown_Query_Result */
	private function partition_lock() {
		$directory = rtrim( sys_get_temp_dir(), '/\\' ) . '/markdown-database-integration-locks';
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			return $this->malformed( 'unavailable_partition_lock', 'The native partition lock directory is unavailable.' );
		}
		$path = $directory . '/partition-' . hash( 'sha256', $this->lock_root . "\0" . $this->table ) . '.lock';
		$lock = @fopen( $path, 'c+' );
		if ( false === $lock || ! flock( $lock, LOCK_SH ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return $this->malformed( 'unavailable_partition_lock', 'The native partition lock cannot be acquired.' );
		}
		return $lock;
	}

	private function active_generation(): string|WP_Markdown_Query_Result|null {
		$directory = $this->state_root . DIRECTORY_SEPARATOR . '_tables' . DIRECTORY_SEPARATOR . $this->table;
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
			return null;
		}
		$root = realpath( $directory );
		if ( is_link( $directory ) || false === $root || ! is_dir( $root ) || ! $this->contains( $this->state_root, $root ) ) {
			return $this->malformed( 'unsafe_partition_directory', 'The canonical partition directory is unsafe.' );
		}

		$marker = $this->read_json( $root . DIRECTORY_SEPARATOR . '.mdi-partition.json', $root, 'partition_marker' );
		if ( $marker instanceof WP_Markdown_Query_Result ) {
			return $this->read_failure( $marker, 'invalid_partition_marker', 'The canonical partition marker cannot be read.' );
		}
		if ( ! is_array( $marker )
			|| 1 !== ( $marker['version'] ?? null )
			|| $this->table !== ( $marker['table'] ?? null )
			|| $this->identity_column !== ( $marker['identity_column'] ?? null )
			|| 1 !== preg_match( '/^generation-[a-f0-9]{24}$/D', (string) ( $marker['generation'] ?? '' ) )
		) {
			return $this->malformed( 'invalid_partition_marker', 'The canonical partition marker does not match its declared table.' );
		}

		$path = $root . DIRECTORY_SEPARATOR . $marker['generation'];
		$generation = realpath( $path );
		if ( is_link( $path ) || false === $generation || ! is_dir( $generation ) || ! $this->contains( $root, $generation ) ) {
			return $this->malformed( 'invalid_partition_generation', 'The active canonical partition generation is unavailable.' );
		}
		return $generation;
	}

	/** @return array<string,mixed>|WP_Markdown_Query_Result|null */
	private function partition_row( string $generation, string $identity ): array|WP_Markdown_Query_Result|null {
		$path = $generation . DIRECTORY_SEPARATOR . hash( 'sha256', $identity ) . '.json';
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return null;
		}
		$data = $this->read_json( $path, $generation, 'partition_row' );
		if ( $data instanceof WP_Markdown_Query_Result ) {
			return $this->read_failure( $data, 'invalid_partition_row', 'The requested canonical partition row cannot be read.' );
		}
		$metadata = is_array( $data ) ? ( $data['_mdi_partition'] ?? null ) : null;
		$row      = is_array( $data ) ? ( $data['row'] ?? null ) : null;
		if ( ! is_array( $metadata )
			|| 1 !== ( $metadata['version'] ?? null )
			|| $this->identity_column !== ( $metadata['identity_column'] ?? null )
			|| $identity !== ( $metadata['identity'] ?? null )
			|| ! is_array( $row )
			|| true !== $this->schema->validate_row( $row )
			|| $identity !== (string) $this->schema->column( $this->identity_column )->normalize( $row[ $this->identity_column ] )
		) {
			return $this->malformed( 'invalid_partition_row', 'The requested canonical partition row does not match its identity or schema.' );
		}
		return $row;
	}

	private function malformed( string $reason, string $message ): WP_Markdown_Query_Result {
		return $this->failure( 'markdown_db_native_malformed_partition', $reason, $message );
	}

	private function read_failure( WP_Markdown_Query_Result $failure, string $reason, string $message ): WP_Markdown_Query_Result {
		return 'markdown_db_native_unsafe_path' === ( $failure->diagnostic()['code'] ?? '' )
			? $failure
			: $this->malformed( $reason, $message );
	}
}

final class WP_Markdown_Native_Option_Provider extends WP_Markdown_Native_File_Provider {
	private bool $loaded = false;
	private string $signature = '';
	/** @var array<int,array<string,mixed>>|WP_Markdown_Query_Result|null */
	private array|WP_Markdown_Query_Result|null $snapshot = null;
	/** @var array<string,array{signature:string,value:array<string,mixed>|WP_Markdown_Query_Result|null}> */
	private array $option_cache = array();

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$predicate = $access->predicate();
		if ( null !== $predicate && 'option_name' === $predicate->column() ) {
			$rows = $this->named_rows( $predicate->values() );
		} else {
			$rows = $this->snapshot();
			if ( is_array( $rows ) && null !== $predicate ) {
				$rows = $this->indexed_rows( $rows, $predicate );
			}
		}
		return $rows instanceof WP_Markdown_Query_Result ? $rows : $this->bounded_rows( $rows, $access );
	}

	/** @return array<int,array<string,mixed>>|WP_Markdown_Query_Result */
	private function snapshot(): array|WP_Markdown_Query_Result {
		if ( $this->loaded ) {
			$signature = $this->options_signature();
			if ( $signature === $this->signature ) {
				return $this->snapshot;
			}
		}
		$this->loaded = true;
		$this->reset_indexes();
		$root = $this->options_root();
		if ( $root instanceof WP_Markdown_Query_Result ) {
			$this->signature = $this->options_signature();
			return $this->snapshot = $root;
		}
		if ( null === $root ) {
			$this->signature = $this->options_signature();
			return $this->snapshot = array();
		}

		try {
			$paths = array();
			foreach ( new FilesystemIterator( $root, FilesystemIterator::SKIP_DOTS ) as $entry ) {
				if ( str_ends_with( $entry->getFilename(), '.json' ) ) {
					$paths[] = $root . DIRECTORY_SEPARATOR . $entry->getFilename();
				}
			}
		} catch ( UnexpectedValueException $error ) {
			$this->signature = $this->options_signature();
			return $this->snapshot = $this->failure(
				'markdown_db_native_unsafe_path',
				'unreadable_options_directory',
				'The canonical options directory cannot be enumerated.'
			);
		}
		if ( $root !== realpath( $this->state_root . DIRECTORY_SEPARATOR . '_options' )
			|| is_link( $this->state_root . DIRECTORY_SEPARATOR . '_options' )
		) {
			$this->signature = $this->options_signature();
			return $this->snapshot = $this->failure(
				'markdown_db_native_unsafe_path',
				'changed_options_directory',
				'The canonical options directory changed while it was being enumerated.'
			);
		}

		sort( $paths, SORT_STRING );
		$rows  = array();
		$ids   = array();
		$names = array();
		$signatures = array();
		foreach ( $paths as $path ) {
			$path_signature = null;
			$row = $this->read_option( $path, $root, null, $path_signature );
			if ( $row instanceof WP_Markdown_Query_Result ) {
				$this->signature = $this->options_signature();
				return $this->snapshot = $row;
			}
			if ( basename( $path ) !== WP_Markdown_Canonical_Option_Path::filename( $row['option_name'] )
				|| isset( $ids[ $row['option_id'] ] )
				|| isset( $names[ $row['option_name'] ] )
			) {
				$this->signature = $this->options_signature();
				return $this->snapshot = $this->failure(
					'markdown_db_native_malformed_option',
					'invalid_option_identity',
					'Canonical option files must have unique identities and canonical filenames.'
				);
			}
			$ids[ $row['option_id'] ]     = true;
			$names[ $row['option_name'] ] = true;
			$rows[]                       = $row;
			$signatures[ basename( $path ) ] = (string) $path_signature;
			$key = (string) $this->schema->value_key( 'option_name', $row['option_name'] );
			$this->option_cache[ $key ] = array(
				'signature' => (string) $path_signature,
				'value'     => $row,
			);
		}
		$this->signature = $this->options_signature( $signatures );
		return $this->snapshot = $rows;
	}

	/** @param array<int,int|string> $values @return array<int,array<string,mixed>>|WP_Markdown_Query_Result */
	private function named_rows( array $values ): array|WP_Markdown_Query_Result {
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
			$key = (string) $this->schema->value_key( 'option_name', $name );
			$path = $this->option_path( $root, $name );
			if ( $path instanceof WP_Markdown_Query_Result ) {
				return $path;
			}
			$signature = null === $path ? 'missing' : ( isset( $this->option_cache[ $key ] ) ? $this->path_signature( $path ) : null );
			if ( ! isset( $this->option_cache[ $key ] ) || $signature !== $this->option_cache[ $key ]['signature'] ) {
				$path_signature = null;
				$value = null === $path ? null : $this->read_option( $path, $root, $name, $path_signature );
				$this->option_cache[ $key ] = array(
					'signature' => null === $path ? 'missing' : (string) $path_signature,
					'value'     => $value,
				);
			}
			$row = $this->option_cache[ $key ]['value'];
			if ( $row instanceof WP_Markdown_Query_Result ) {
				return $row;
			}
			if ( null === $row ) {
				continue;
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

	/** @param array<string,string>|null $file_signatures */
	private function options_signature( ?array $file_signatures = null ): string {
		$directory = $this->state_root . DIRECTORY_SEPARATOR . '_options';
		$parts = array( $this->path_signature( $directory ) );
		if ( null !== $file_signatures ) {
			$parts += $file_signatures;
		} elseif ( is_dir( $directory ) && ! is_link( $directory ) ) {
			try {
				foreach ( new FilesystemIterator( $directory, FilesystemIterator::SKIP_DOTS ) as $entry ) {
					if ( str_ends_with( $entry->getFilename(), '.json' ) ) {
						$parts[ $entry->getFilename() ] = $this->path_signature( $directory . DIRECTORY_SEPARATOR . $entry->getFilename() );
					}
				}
			} catch ( UnexpectedValueException $error ) {
				$parts[] = 'unreadable';
			}
		}
		ksort( $parts, SORT_STRING );
		return hash( 'sha256', serialize( $parts ) );
	}

	private function option_path( string $root, string $name ): string|WP_Markdown_Query_Result|null {
		$filename = WP_Markdown_Canonical_Option_Path::filename( $name );
		$path     = $root . DIRECTORY_SEPARATOR . $filename;
		if ( file_exists( $path ) || is_link( $path ) ) {
			return $path;
		}
		$hashed_prefix = null;
		if ( $filename !== $name . '.json'
			&& 1 === preg_match( '/^(.*-)[0-9a-f]{8}\.json$/D', $filename, $expected )
		) {
			$hashed_prefix = $expected[1];
		}

		$matches = array();
		try {
			foreach ( new FilesystemIterator( $root, FilesystemIterator::SKIP_DOTS ) as $entry ) {
				$candidate = $entry->getFilename();
				if ( 0 === strcasecmp( $candidate, $filename ) ) {
					$matches[] = $root . DIRECTORY_SEPARATOR . $candidate;
					continue;
				}
				if ( null === $hashed_prefix
					|| 1 !== preg_match( '/^(.*-)[0-9a-f]{8}\.json$/D', $candidate, $actual )
					|| 0 !== strcasecmp( $hashed_prefix, $actual[1] )
				) {
					continue;
				}
				$candidate_path = $root . DIRECTORY_SEPARATOR . $candidate;
				$signature = null;
				$row = $this->read_option( $candidate_path, $root, null, $signature );
				if ( $row instanceof WP_Markdown_Query_Result ) {
					return $row;
				}
				if ( $this->schema->values_match( 'option_name', $name, $row['option_name'] ) ) {
					$matches[] = $candidate_path;
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
		?string $expected = null,
		?string &$signature = null
	): array|WP_Markdown_Query_Result {
		$digest = null;
		$row = $this->read_json( $path, $root, 'option_file', $digest );
		$signature = $this->path_signature( $path, $digest );
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
}
