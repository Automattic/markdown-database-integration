<?php
/**
 * One-time fold of a legacy row-partitioned table into a JSON snapshot.
 *
 * Earlier MDI write engines stored some plugin tables as one file per row under
 * `_tables/<table>/generation-*`. mdi-native writes only `_tables/<table>.json`
 * snapshots, so the first bootstrap that finds a partition folds it: every row
 * of the active generation, with any column added to the schema since filled
 * from its declared default, lands in the snapshot before the partition
 * directory is removed.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Partition_Fold {
	/**
	 * Fold the partitioned table into `_tables/<table>.json`.
	 *
	 * @param array{columns:array<string,array<string,mixed>>} $definition
	 * @return true|string True once the snapshot owns the rows, or why the partition stays untouched.
	 */
	public static function fold( string $state_root, string $table, WP_Markdown_Native_Table_Schema $schema, array $definition ): true|string {
		$tables   = rtrim( $state_root, '/\\' ) . '/_tables';
		$root     = $tables . '/' . $table;
		$snapshot = $tables . '/' . $table . '.json';
		$lock     = @fopen( $tables . '/.mdi-partition-fold-' . $table . '.lock', 'c' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			return 'The partition fold lock is unavailable.';
		}
		try {
			if ( ! is_file( $root . '/.mdi-partition.json' ) ) {
				// A concurrent bootstrap already folded it.
				return is_file( $snapshot ) ? true : 'The partition marker disappeared without a snapshot.';
			}
			if ( file_exists( $snapshot ) || is_link( $root ) || is_link( $root . '/.mdi-partition.json' ) ) {
				return 'Both a partition and a snapshot exist, or the partition is a symlink.';
			}
			$marker = json_decode( (string) file_get_contents( $root . '/.mdi-partition.json' ), true );
			$identity = is_array( $marker ) ? ( $marker['identity_column'] ?? null ) : null;
			$generation = is_array( $marker ) ? (string) ( $marker['generation'] ?? '' ) : '';
			if ( ! is_string( $identity ) || array( $identity ) !== $schema->identity_columns() || 1 !== preg_match( '/^generation-[a-f0-9]{24}$/D', $generation ) || ! is_dir( $root . '/' . $generation ) ) {
				return 'The partition marker does not match the table schema.';
			}

			$rows = array();
			foreach ( glob( $root . '/' . $generation . '/*.json' ) ?: array() as $path ) {
				$data = json_decode( (string) file_get_contents( $path ), true );
				$row  = is_array( $data ) ? ( $data['row'] ?? null ) : null;
				$stored = is_array( $data ) ? ( $data['_mdi_partition']['identity'] ?? null ) : null;
				if ( ! is_array( $row ) || ! is_string( $stored ) || hash( 'sha256', $stored ) . '.json' !== basename( $path ) ) {
					return 'Partition row ' . basename( $path ) . ' is malformed.';
				}
				$complete = array();
				foreach ( $schema->column_names() as $column ) {
					$complete[ $column ] = array_key_exists( $column, $row ) ? $row[ $column ] : ( $definition['columns'][ $column ]['default'] ?? null );
				}
				if ( array_diff_key( $row, $complete ) || true !== $schema->validate_row( $complete ) || (string) $schema->column( $identity )->normalize( $complete[ $identity ] ) !== $stored ) {
					return 'Partition row ' . basename( $path ) . ' does not fit the table schema.';
				}
				$rows[ $stored ] = $complete;
			}
			uksort( $rows, 'strnatcmp' );

			$temporary = $snapshot . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 8 ) );
			$encoded   = json_encode( array_values( $rows ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
			if ( strlen( $encoded ) !== @file_put_contents( $temporary, $encoded, LOCK_EX ) || ! @rename( $temporary, $snapshot ) ) {
				@unlink( $temporary );
				return 'The folded snapshot could not be written.';
			}
			// Drop the marker first so an interrupted cleanup never shadows the snapshot.
			unlink( $root . '/.mdi-partition.json' );
			self::remove( $root );
			return true;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private static function remove( string $directory ): void {
		$entries = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $entries as $entry ) {
			$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}
		rmdir( $directory );
	}
}
