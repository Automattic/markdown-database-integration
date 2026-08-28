<?php
/** Derived per-table index that keeps generic snapshot inserts constant time. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A rebuildable index over one persisted snapshot table.
 *
 * The index holds nothing that cannot be recomputed from the snapshot it
 * describes. A missing, stale, or unreadable index is not an error: callers
 * fall back to reading the snapshot, which is the behaviour that existed
 * before the index, and then republish it.
 */
final class WP_Markdown_Native_Table_Index {

	public const SCHEMA = 'mdi-native-table-index/v1';
	private const DIRECTORY = '.index';

	public function __construct( private string $tables_directory ) {}

	public function path( string $suffix ): string {
		return $this->tables_directory . DIRECTORY_SEPARATOR . self::DIRECTORY . DIRECTORY_SEPARATOR . $suffix . '.json';
	}

	/**
	 * Load an index that still describes the current snapshot.
	 *
	 * @return array{max:array<string,int>,unique:array<string,array<int,string>>,row_count:int}|null
	 */
	public function load( string $suffix, string $snapshot_path ): ?array {
		$path = $this->path( $suffix );
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return null;
		}
		$decoded = json_decode( (string) @file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) || self::SCHEMA !== ( $decoded['schema'] ?? null ) ) {
			return null;
		}
		if ( ! $this->describes( $decoded, $snapshot_path ) ) {
			return null;
		}
		$index = array(
			'max'       => array(),
			'unique'    => array(),
			'row_count' => (int) ( $decoded['row_count'] ?? 0 ),
		);
		foreach ( (array) ( $decoded['max'] ?? array() ) as $column => $value ) {
			$index['max'][ (string) $column ] = (int) $value;
		}
		foreach ( (array) ( $decoded['unique'] ?? array() ) as $name => $keys ) {
			$index['unique'][ (string) $name ] = array_map( 'strval', is_array( $keys ) ? $keys : array() );
		}
		return $index;
	}

	/**
	 * Compute an index from the snapshot rows.
	 *
	 * @param  array<int,array<string,mixed>> $rows       Snapshot rows.
	 * @param  array<string,mixed>            $definition Compiled table definition.
	 * @return array{max:array<string,int>,unique:array<string,array<int,string>>,row_count:int}
	 */
	public static function build( array $rows, array $definition, WP_Markdown_Native_Table_Schema $schema ): array {
		$index = array( 'max' => array(), 'unique' => array(), 'row_count' => count( $rows ) );
		foreach ( $definition['columns'] as $name => $column ) {
			if ( true === ( $column['auto_increment'] ?? false ) ) {
				$index['max'][ $name ] = 0;
			}
		}
		foreach ( self::unique_indexes( $definition ) as $name => $columns ) {
			$index['unique'][ $name ] = array();
		}
		foreach ( $rows as $row ) {
			$index = self::with_row( $index, $row, $definition, $schema );
		}
		return $index;
	}

	/**
	 * Fold one row into an index.
	 *
	 * @param  array{max:array<string,int>,unique:array<string,array<int,string>>,row_count:int} $index Current index.
	 * @param  array<string,mixed>                                                               $row   Row to record.
	 * @param  array<string,mixed>                                                               $definition Compiled definition.
	 * @return array{max:array<string,int>,unique:array<string,array<int,string>>,row_count:int}
	 */
	public static function with_row( array $index, array $row, array $definition, WP_Markdown_Native_Table_Schema $schema ): array {
		foreach ( array_keys( $index['max'] ) as $column ) {
			$index['max'][ $column ] = max( $index['max'][ $column ], (int) ( $row[ $column ] ?? 0 ) );
		}
		foreach ( self::unique_indexes( $definition ) as $name => $columns ) {
			$key = self::unique_key( $row, $columns, $schema );
			if ( null !== $key ) {
				$index['unique'][ $name ][] = $key;
			}
		}
		++$index['row_count'];
		return $index;
	}

	/**
	 * Decide whether a row would duplicate a recorded unique key.
	 *
	 * @param array{unique:array<string,array<int,string>>} $index      Current index.
	 * @param array<string,mixed>                          $row        Candidate row.
	 * @param array<string,mixed>                          $definition Compiled definition.
	 */
	public static function duplicates( array $index, array $row, array $definition, WP_Markdown_Native_Table_Schema $schema ): bool {
		foreach ( self::unique_indexes( $definition ) as $name => $columns ) {
			$key = self::unique_key( $row, $columns, $schema );
			if ( null !== $key && in_array( $key, $index['unique'][ $name ] ?? array(), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Persist an index describing the current snapshot.
	 *
	 * @param array{max:array<string,int>,unique:array<string,array<int,string>>,row_count:int} $index Index to persist.
	 */
	public function save( string $suffix, string $snapshot_path, array $index, ?WP_Markdown_Native_Transaction_Journal $transactions ): bool {
		$path = $this->path( $suffix );
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			return false;
		}
		$stat = @stat( $snapshot_path );
		if ( false === $stat ) {
			return false;
		}
		if ( null !== $transactions && true !== $transactions->record( $path ) ) {
			return false;
		}
		try {
			$encoded = json_encode(
				array(
					'schema'      => self::SCHEMA,
					'fingerprint' => array( 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'] ),
					'max'         => $index['max'],
					'unique'      => $index['unique'],
					'row_count'   => $index['row_count'],
				),
				JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
			);
		} catch ( Throwable ) {
			return false;
		}
		$temp = $path . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 8 ) );
		if ( false === @file_put_contents( $temp, $encoded ) || ! @rename( $temp, $path ) ) {
			@unlink( $temp );
			return false;
		}
		return true;
	}

	/** Discard an index that no longer describes its snapshot. */
	public function forget( string $suffix, ?WP_Markdown_Native_Transaction_Journal $transactions ): void {
		$path = $this->path( $suffix );
		if ( ! is_file( $path ) ) {
			return;
		}
		if ( null !== $transactions ) {
			$transactions->record( $path );
		}
		@unlink( $path );
	}

	/** @param array<string,mixed> $decoded */
	private function describes( array $decoded, string $snapshot_path ): bool {
		$stat = @stat( $snapshot_path );
		if ( false === $stat ) {
			return false;
		}
		$fingerprint = $decoded['fingerprint'] ?? array();
		return is_array( $fingerprint )
			&& (int) ( $fingerprint['size'] ?? -1 ) === (int) $stat['size']
			&& (int) ( $fingerprint['mtime'] ?? -1 ) === (int) $stat['mtime'];
	}

	/**
	 * Unique indexes whose members must be tracked by value.
	 *
	 * An index over only the auto-increment column is skipped: a generated
	 * identifier is derived from the recorded maximum and therefore cannot
	 * collide, so recording every identifier would grow the index with the
	 * table for no additional guarantee.
	 *
	 * @param  array<string,mixed> $definition Compiled definition.
	 * @return array<string,array<int,string>>
	 */
	private static function unique_indexes( array $definition ): array {
		$generated = array();
		foreach ( $definition['columns'] ?? array() as $name => $column ) {
			if ( true === ( $column['auto_increment'] ?? false ) ) {
				$generated[] = (string) $name;
			}
		}
		$indexes = array();
		foreach ( $definition['indexes'] ?? array() as $position => $index ) {
			if ( true !== ( $index['unique'] ?? false ) ) {
				continue;
			}
			$columns = $index['columns'] ?? array();
			$names   = array_column( $columns, 'name' );
			if ( array() === $names || array() === array_diff( $names, $generated ) ) {
				continue;
			}
			$indexes[ (string) ( $index['name'] ?? $position ) ] = $columns;
		}
		return $indexes;
	}

	/**
	 * Report whether an insert supplies its own auto-increment identifier.
	 *
	 * A supplied identifier bypasses the generated-value guarantee, so callers
	 * must verify it against the snapshot rather than the index.
	 *
	 * @param array<string,int|string|null> $provided   Supplied columns.
	 * @param array<string,mixed>           $definition Compiled definition.
	 */
	public static function supplies_identity( array $provided, array $definition ): bool {
		foreach ( $definition['columns'] ?? array() as $name => $column ) {
			if ( true !== ( $column['auto_increment'] ?? false ) ) {
				continue;
			}
			$value = $provided[ $name ] ?? null;
			if ( null !== $value && '0' !== (string) $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $row     Row to key.
	 * @param array<int,array{name?:string,length?:int|null}> $columns Index columns.
	 */
	private static function unique_key( array $row, array $columns, WP_Markdown_Native_Table_Schema $schema ): ?string {
		$parts = array();
		foreach ( $columns as $column ) {
			$name = (string) ( $column['name'] ?? '' );
			if ( ! array_key_exists( $name, $row ) || null === $row[ $name ] ) {
				return null;
			}
			$value  = $row[ $name ];
			$length = $column['length'] ?? null;
			if ( is_int( $length ) && is_string( $value ) ) {
				$value = substr( $value, 0, $length );
			}
			$key = $schema->value_key( $name, $value );
			if ( null === $key ) {
				return null;
			}
			$parts[] = $key;
		}
		return implode( "\x1f", $parts );
	}
}
