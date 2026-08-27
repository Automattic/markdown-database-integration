<?php
/** Canonical rollback journal providing atomic native transaction boundaries. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records the pre-image of every canonical file a transaction touches so a
 * rollback, a savepoint rewind, or a crash can restore the prior state.
 *
 * The journal is table-neutral. It knows only canonical paths and bytes.
 */
final class WP_Markdown_Native_Transaction_Journal {

	private const JOURNAL_DIRECTORY = '_journal';
	private const JOURNAL_FILE      = 'native-transaction.json';

	private string $state_root;
	private bool $active = false;
	private bool $autocommit = true;

	/** @var list<array{path:string,existed:bool,contents:?string}> */
	private array $entries = array();

	/** @var array<string,int> */
	private array $savepoints = array();

	public function __construct( string $state_root ) {
		$root = realpath( $state_root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new InvalidArgumentException( 'The canonical state root must be an existing directory.' );
		}
		$this->state_root = rtrim( $root, DIRECTORY_SEPARATOR );
	}

	public function is_active(): bool {
		return $this->active;
	}

	/** Restore any journal left behind by a process that terminated mid-transaction. */
	public function recover(): bool {
		$path = $this->journal_path();
		if ( ! is_file( $path ) ) {
			return false;
		}
		$entries = json_decode( (string) file_get_contents( $path ), true );
		if ( is_array( $entries ) ) {
			$this->restore( $this->normalize_entries( $entries ), 0 );
		}
		@unlink( $path );
		return true;
	}

	public function begin(): true|string {
		if ( $this->active ) {
			// MySQL commits an open transaction when a new one starts.
			$commit = $this->commit();
			if ( true !== $commit ) {
				return $commit;
			}
		}
		$this->active     = true;
		$this->entries    = array();
		$this->savepoints = array();
		return $this->persist();
	}

	/** Capture the current state of a canonical path before it is mutated. */
	public function record( string $path ): true|string {
		if ( ! $this->active ) {
			return true;
		}
		$existed  = is_file( $path );
		$contents = null;
		if ( $existed ) {
			$read = @file_get_contents( $path );
			if ( false === $read ) {
				return 'The canonical pre-image could not be journaled.';
			}
			$contents = base64_encode( $read );
		}
		$this->entries[] = array(
			'path'     => $path,
			'existed'  => $existed,
			'contents' => $contents,
		);
		return $this->persist();
	}

	public function commit(): true|string {
		if ( ! $this->active ) {
			return true;
		}
		$this->active     = false;
		$this->entries    = array();
		$this->savepoints = array();
		$path = $this->journal_path();
		if ( is_file( $path ) && ! @unlink( $path ) ) {
			return 'The canonical transaction journal could not be cleared.';
		}
		return $this->reopen_for_autocommit();
	}

	public function rollback(): true|string {
		if ( ! $this->active ) {
			return true;
		}
		$restored = $this->restore( $this->entries, 0 );
		if ( true !== $restored ) {
			return $restored;
		}
		return $this->commit();
	}

	public function savepoint( string $name ): true|string {
		if ( ! $this->active ) {
			$begun = $this->begin();
			if ( true !== $begun ) {
				return $begun;
			}
		}
		$this->savepoints[ $name ] = count( $this->entries );
		return true;
	}

	public function rollback_to( string $name ): true|string {
		if ( ! $this->active || ! isset( $this->savepoints[ $name ] ) ) {
			return sprintf( 'SAVEPOINT %s does not exist.', $name );
		}
		$marker   = $this->savepoints[ $name ];
		$restored = $this->restore( $this->entries, $marker );
		if ( true !== $restored ) {
			return $restored;
		}
		$this->entries = array_slice( $this->entries, 0, $marker );
		foreach ( $this->savepoints as $savepoint => $offset ) {
			if ( $offset > $marker ) {
				unset( $this->savepoints[ $savepoint ] );
			}
		}
		return $this->persist();
	}

	public function release_savepoint( string $name ): true|string {
		if ( ! $this->active || ! isset( $this->savepoints[ $name ] ) ) {
			return sprintf( 'SAVEPOINT %s does not exist.', $name );
		}
		$marker = $this->savepoints[ $name ];
		foreach ( $this->savepoints as $savepoint => $offset ) {
			if ( $offset >= $marker ) {
				unset( $this->savepoints[ $savepoint ] );
			}
		}
		return true;
	}

	/** Disabling autocommit opens an implicit transaction, as MySQL does. */
	public function set_autocommit( bool $enabled ): true|string {
		$this->autocommit = $enabled;
		if ( $enabled ) {
			return $this->commit();
		}
		return $this->active ? true : $this->begin();
	}

	/**
	 * Restore journaled pre-images newer than an offset, newest first.
	 *
	 * @param list<array{path:string,existed:bool,contents:?string}> $entries Journal entries.
	 */
	private function restore( array $entries, int $offset ): true|string {
		for ( $index = count( $entries ) - 1; $index >= $offset; $index-- ) {
			$entry = $entries[ $index ];
			if ( ! $entry['existed'] ) {
				if ( is_file( $entry['path'] ) && ! @unlink( $entry['path'] ) ) {
					return 'A canonical row created in the transaction could not be discarded.';
				}
				continue;
			}
			$contents = base64_decode( (string) $entry['contents'], true );
			if ( false === $contents ) {
				return 'A journaled canonical pre-image could not be decoded.';
			}
			if ( true !== $this->publish( $entry['path'], $contents ) ) {
				return 'A journaled canonical pre-image could not be restored.';
			}
		}
		return true;
	}

	/** Reopen an implicit transaction while autocommit remains disabled. */
	private function reopen_for_autocommit(): true|string {
		if ( $this->autocommit ) {
			return true;
		}
		$this->active     = true;
		$this->entries    = array();
		$this->savepoints = array();
		return $this->persist();
	}

	private function persist(): true|string {
		$path = $this->journal_path();
		if ( ! $this->active ) {
			return true;
		}
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
			return 'The canonical transaction journal directory could not be created.';
		}
		try {
			$json = json_encode( $this->entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		} catch ( Throwable ) {
			return 'The canonical transaction journal could not be encoded.';
		}
		return $this->publish( $path, $json );
	}

	/** Write bytes through a temporary file so readers never observe a partial row. */
	private function publish( string $path, string $contents ): true|string {
		$temp = $path . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 8 ) );
		$handle = @fopen( $temp, 'x+b' );
		if ( false === $handle ) {
			return 'A canonical temporary file could not be created.';
		}
		$failure = null;
		try {
			$length = strlen( $contents );
			$offset = 0;
			while ( $offset < $length ) {
				$written = fwrite( $handle, substr( $contents, $offset ) );
				if ( false === $written || 0 === $written ) {
					$failure = 'A canonical file could not be written.';
					break;
				}
				$offset += $written;
			}
			if ( null === $failure && ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) ) {
				$failure = 'A canonical file could not be flushed.';
			}
		} finally {
			fclose( $handle );
		}
		if ( null !== $failure ) {
			@unlink( $temp );
			return $failure;
		}
		if ( ! @rename( $temp, $path ) ) {
			@unlink( $temp );
			return 'A canonical file could not be atomically published.';
		}
		return true;
	}

	/**
	 * @param  array<int|string,mixed> $entries Decoded journal entries.
	 * @return list<array{path:string,existed:bool,contents:?string}>
	 */
	private function normalize_entries( array $entries ): array {
		$normalized = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['path'] ?? null ) ) {
				continue;
			}
			$normalized[] = array(
				'path'     => $entry['path'],
				'existed'  => (bool) ( $entry['existed'] ?? false ),
				'contents' => is_string( $entry['contents'] ?? null ) ? $entry['contents'] : null,
			);
		}
		return $normalized;
	}

	private function journal_path(): string {
		return $this->state_root . DIRECTORY_SEPARATOR . self::JOURNAL_DIRECTORY . DIRECTORY_SEPARATOR . self::JOURNAL_FILE;
	}
}
