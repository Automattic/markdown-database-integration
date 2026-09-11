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
	private const JOURNAL_PREFIX    = 'native-transaction-';
	private const JOURNAL_SUFFIX    = '.json';
	private const CLAIM_SUFFIX      = '.lock';
	private const WRITE_LOCK_SUFFIX = '-write.lock';
	private const WRITE_LOCK_WAIT_US = 5000000;

	private string $state_root;
	/** @var list<string> Trusted canonical roots supplied by the runtime factory. */
	private array $admitted_roots;
	private string $owner;
	/** @var resource|null */
	private $claim = null;
	/** @var resource|null */
	private $write_lock = null;
	private bool $active = false;
	private bool $autocommit = true;
	private bool $in_transaction = false;
	private bool $recovery_required = false;
	private bool $waited_for_write_lock = false;

	/** @var list<array{path:string,existed:bool,contents:?string}> */
	private array $entries = array();

	/** @var array<string,int> */
	private array $savepoints = array();
	/** @var array<string,callable> */
	private array $restore_observers = array();

	/** @param list<string> $admitted_roots */
	public function __construct( string $state_root, array $admitted_roots = array() ) {
		$root = realpath( $state_root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new InvalidArgumentException( 'The canonical state root must be an existing directory.' );
		}
		$this->state_root = rtrim( $root, DIRECTORY_SEPARATOR );
		$this->admitted_roots = array( $this->state_root );
		$this->admit_roots( $admitted_roots );
		$this->owner = bin2hex( random_bytes( 8 ) );
	}

	/** Add canonical roots validated by the runtime factory, never journal data. */
	public function admit_roots( array $admitted_roots ): void {
		foreach ( $admitted_roots as $admitted_root ) {
			$resolved = realpath( $admitted_root );
			if ( false === $resolved || ! is_dir( $resolved ) || is_link( $admitted_root ) ) {
				throw new InvalidArgumentException( 'A canonical transaction root must be an existing directory.' );
			}
			$this->admitted_roots[] = rtrim( $resolved, DIRECTORY_SEPARATOR );
		}
		$this->admitted_roots = array_values( array_unique( $this->admitted_roots ) );
	}

	public function is_active(): bool {
		return $this->active;
	}

	/** Whether MySQL considers a logical transaction to be in progress. */
	public function is_in_transaction(): bool {
		return $this->in_transaction;
	}

	/** Whether statements commit individually when no explicit transaction is open. */
	public function is_autocommit(): bool {
		return $this->autocommit;
	}

	/**
	 * Restore journals left behind by a process that terminated mid-transaction.
	 *
	 * A journal belongs to the writer that opened it, which holds it locked
	 * for as long as it lives. Another writer may only restore a journal it
	 * can take that lock on, because an unheld lock is what proves the writer
	 * that wrote it is gone. This is what keeps one process from rolling back
	 * a transaction another process is still running.
	 */
	public function recover(): bool {
		$locked = $this->acquire_write_lock();
		if ( true !== $locked ) {
			return false;
		}
		try {
			$directory = $this->journal_directory();
			$had_journal = false !== $directory && array() !== ( glob( $directory . DIRECTORY_SEPARATOR . self::JOURNAL_PREFIX . '*' . self::JOURNAL_SUFFIX ) ?: array() );
			$recovered = $this->recover_locked();
			return true === $recovered && $had_journal;
		} finally {
			$this->finish_write();
		}
	}

	/**
	 * Serialize canonical writes before a mutation reads its pre-image or
	 * allocates an identifier. Lock order is root transaction lock, then the
	 * narrower statement/table lock; recovery uses this same root lock.
	 */
	public function begin_write(): true|string {
		if ( null !== $this->write_lock ) {
			if ( $this->recovery_required ) {
				$recovered = $this->recover_locked();
				if ( true !== $recovered ) {
					return $recovered;
				}
			}
			return true;
		}
		$locked = $this->acquire_write_lock();
		if ( true !== $locked ) {
			return $locked;
		}
		$recovered = $this->recover_locked();
		if ( true === $recovered ) {
			return true;
		}
		$this->finish_write();
		return $recovered;
	}

	/** Whether the most recent root-lock acquisition had to wait for another process. */
	public function waited_for_write_lock(): bool {
		return $this->waited_for_write_lock;
	}

	/** Acquire the stable root lock without attempting recovery recursively. */
	private function acquire_write_lock(): true|string {
		$directory = $this->journal_directory();
		if ( false === $directory ) {
			return 'The canonical transaction lock directory is unsafe or could not be created.';
		}
		$lock_path = $directory . DIRECTORY_SEPARATOR . self::JOURNAL_PREFIX . self::WRITE_LOCK_SUFFIX;
		if ( is_link( $lock_path ) ) {
			return 'The canonical transaction write lock path is unsafe.';
		}
		$handle = $this->open_safe_lock( $lock_path );
		if ( false === $handle ) {
			return 'The canonical transaction write lock could not be opened.';
		}
		$deadline = hrtime( true ) + ( self::WRITE_LOCK_WAIT_US * 1000 );
		$this->waited_for_write_lock = false;
		do {
			if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
				$this->write_lock = $handle;
				return true;
			}
			$this->waited_for_write_lock = true;
			usleep( 10000 );
		} while ( hrtime( true ) < $deadline );
		fclose( $handle );
		return 'The canonical transaction write lock timed out.';
	}

	/**
	 * Recover every abandoned journal while the root lock excludes a new writer.
	 * A malformed or un-restorable journal remains claimed for a later safe retry.
	 */
	private function recover_locked(): true|string {
		// Every failed scan must be retried before an active owner can write again.
		$this->recovery_required = true;
		$directory = $this->journal_directory();
		if ( false === $directory ) {
			return 'The canonical transaction journal directory is unsafe.';
		}
		foreach ( glob( $directory . DIRECTORY_SEPARATOR . self::JOURNAL_PREFIX . '*' . self::JOURNAL_SUFFIX ) ?: array() as $path ) {
			if ( is_link( $path ) ) {
				return 'A canonical transaction journal path is unsafe.';
			}
			if ( ! is_file( $path ) || $path === $this->journal_path() ) {
				continue;
			}
			$owner = substr( basename( $path ), strlen( self::JOURNAL_PREFIX ), -strlen( self::JOURNAL_SUFFIX ) );
			$claim = $this->claim_path( $owner );
			if ( is_link( $claim ) ) {
				return 'A canonical transaction claim path is unsafe.';
			}
			$handle = $this->open_safe_lock( $claim );
			if ( false === $handle ) {
				return 'A canonical transaction claim could not be opened.';
			}
			if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
				fclose( $handle );
				return 'A canonical transaction journal is still owned by another writer.';
			}
			$entries = json_decode( (string) file_get_contents( $path ), true );
			$normalized = is_array( $entries ) ? $this->normalize_entries( $entries ) : array();
			$restored = is_array( $entries ) && count( $normalized ) === count( $entries ) ? $this->restore( $normalized, 0 ) : 'A canonical transaction journal could not be decoded.';
			if ( true !== $restored ) {
				flock( $handle, LOCK_UN );
				fclose( $handle );
				$this->recovery_required = true;
				return $restored;
			}
			if ( ! @unlink( $path ) || ! @unlink( $claim ) ) {
				flock( $handle, LOCK_UN );
				fclose( $handle );
				$this->recovery_required = true;
				return 'A recovered canonical transaction journal could not be cleared.';
			}
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
		$this->recovery_required = false;
		return true;
	}

	/** Release an autocommit statement's root lock after it has published. */
	public function finish_write(): void {
		if ( $this->active || null === $this->write_lock ) {
			return;
		}
		flock( $this->write_lock, LOCK_UN );
		fclose( $this->write_lock );
		$this->write_lock = null;
	}

	/**
	 * Hold this writer's journal for as long as its transaction runs.
	 *
	 * The claim is a file of its own because the journal is published by
	 * renaming a replacement over it, and a lock does not survive the inode
	 * it was taken on being replaced.
	 */
	private function claim(): true|string {
		if ( null !== $this->claim ) {
			return true;
		}
		$path = $this->claim_path( $this->owner );
		if ( is_link( $path ) ) {
			return 'The canonical transaction claim path is unsafe.';
		}
		$handle = $this->open_safe_lock( $path );
		if ( false !== $handle && flock( $handle, LOCK_EX | LOCK_NB ) ) {
			$this->claim = $handle;
			return true;
		}
		if ( false !== $handle ) {
			fclose( $handle );
		}
		return 'The canonical transaction journal could not be claimed.';
	}

	private function release(): void {
		if ( null === $this->claim ) {
			return;
		}
		flock( $this->claim, LOCK_UN );
		fclose( $this->claim );
		$this->claim = null;
		@unlink( $this->claim_path( $this->owner ) );
	}

	private function claim_path( string $owner ): string {
		return $this->state_root . DIRECTORY_SEPARATOR . self::JOURNAL_DIRECTORY
			. DIRECTORY_SEPARATOR . self::JOURNAL_PREFIX . $owner . self::CLAIM_SUFFIX;
	}

	/** @return resource|false */
	private function open_safe_lock( string $path ) {
		$existing = @lstat( $path );
		if ( false !== $existing && ( 0100000 !== ( $existing['mode'] & 0170000 ) || 1 !== ( $existing['nlink'] ?? 1 ) ) ) {
			return false;
		}
		$handle = @fopen( $path, 'c+b' );
		if ( false === $handle ) {
			return false;
		}
		$opened = fstat( $handle );
		$current = @lstat( $path );
		if ( false === $opened
			|| false === $current
			|| $opened['dev'] !== $current['dev']
			|| $opened['ino'] !== $current['ino']
			|| 0100000 !== ( $opened['mode'] & 0170000 )
			|| 1 !== ( $opened['nlink'] ?? 1 )
			|| is_link( $path )
		) {
			fclose( $handle );
			return false;
		}
		return $handle;
	}

	public function begin(): true|string {
		if ( $this->active ) {
			// MySQL commits an open transaction when a new one starts.
			$commit = $this->commit();
			if ( true !== $commit ) {
				return $commit;
			}
		}
		$locked = $this->begin_write();
		if ( true !== $locked ) {
			return $locked;
		}
		$this->active     = true;
		$this->in_transaction = true;
		$this->entries    = array();
		$this->savepoints = array();
		$this->restore_observers = array();
		$persisted = $this->persist();
		if ( true === $persisted ) {
			// A foreign abandoned journal can appear after this transaction begins.
			// Its next canonical admission must scan before reading or mutating.
			$this->recovery_required = true;
			return true;
		}
		$this->active = false;
		$this->in_transaction = false;
		$this->release();
		$this->finish_write();
		return $persisted;
	}

	/** Capture the current state of a canonical path before it is mutated. */
	public function record( string $path, ?callable $restore_observer = null ): true|string {
		if ( is_link( $path ) || ! $this->admitted_path( $path ) ) {
			return 'The canonical pre-image path is outside the transaction state root.';
		}
		// With autocommit disabled, the first transactional access starts the
		// implicit transaction. Non-table statements never call record().
		if ( ! $this->active && ! $this->autocommit ) {
			$begun = $this->begin();
			if ( true !== $begun ) {
				return $begun;
			}
		}
		if ( ! $this->active ) {
			return true;
		}
		if ( null !== $restore_observer ) {
			$this->restore_observers[ $path ] = $restore_observer;
		}
		$segment_start = $this->savepoints ? max( $this->savepoints ) : 0;
		for ( $index = count( $this->entries ) - 1; $index >= $segment_start; $index-- ) {
			if ( $path === $this->entries[ $index ]['path'] ) {
				// The segment-start pre-image already restores every later write.
				return true;
			}
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

	/** Record a session-local pre-image without serializing it into the durable journal. */
	public function record_ephemeral( string $path, ?callable $restore_observer = null ): true|string {
		if ( ! $this->active ) {
			return true;
		}
		if ( null !== $restore_observer ) {
			$this->restore_observers[ $path ] = $restore_observer;
		}
		foreach ( $this->entries as $entry ) {
			if ( $path === $entry['path'] ) {
				return true;
			}
		}
		$contents = is_file( $path ) ? @file_get_contents( $path ) : false;
		if ( is_file( $path ) && false === $contents ) {
			return 'The temporary table pre-image could not be journaled.';
		}
		$this->entries[] = array( 'path' => $path, 'existed' => false !== $contents, 'contents' => false === $contents ? null : base64_encode( $contents ) );
		return true;
	}

	/** A dropped temporary table ends its generation, so old row pre-images are invalid. */
	public function discard_ephemeral( string $path ): void {
		$this->entries = array_values( array_filter( $this->entries, static fn( array $entry ): bool => $path !== $entry['path'] ) );
		foreach ( array_keys( $this->restore_observers ) as $observed_path ) {
			if ( $observed_path === $path ) {
				unset( $this->restore_observers[ $observed_path ] );
			}
		}
	}

	/** Start an autocommit-off transaction when a transactional table is read. */
	public function access(): true|string {
		return ! $this->active && ! $this->autocommit ? $this->begin() : true;
	}

	public function commit(): true|string {
		if ( ! $this->active ) {
			return true;
		}
		$path = $this->journal_path();
		if ( is_file( $path ) && ! @unlink( $path ) ) {
			return 'The canonical transaction journal could not be cleared.';
		}
		$this->active     = false;
		$this->in_transaction = false;
		$this->entries    = array();
		$this->savepoints = array();
		$this->restore_observers = array();
		$this->release();
		$this->finish_write();
		return true;
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
			// With autocommit on, MySQL accepts SAVEPOINT without opening a transaction.
			if ( $this->autocommit ) {
				return true;
			}
			$this->savepoints[ $name ] = 0;
			return true;
		}
		$this->savepoints[ $name ] = count( $this->entries );
		return true;
	}

	public function rollback_to( string $name ): true|string {
		if ( ! isset( $this->savepoints[ $name ] ) || ( ! $this->active && $this->autocommit ) ) {
			return sprintf( 'SAVEPOINT %s does not exist.', $name );
		}
		if ( ! $this->active ) {
			return true;
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
		if ( ! isset( $this->savepoints[ $name ] ) || ( ! $this->active && $this->autocommit ) ) {
			return sprintf( 'SAVEPOINT %s does not exist.', $name );
		}
		if ( ! $this->active ) {
			unset( $this->savepoints[ $name ] );
			return true;
		}
		$marker = $this->savepoints[ $name ];
		foreach ( $this->savepoints as $savepoint => $offset ) {
			if ( $offset >= $marker ) {
				unset( $this->savepoints[ $savepoint ] );
			}
		}
		return true;
	}

	/** Disabling autocommit starts the lock-protected implicit transaction. */
	public function set_autocommit( bool $enabled ): true|string {
		if ( $enabled ) {
			$this->autocommit = true;
			return $this->commit();
		}
		$this->autocommit = false;
		if ( $this->active ) {
			return true;
		}
		$begun = $this->begin();
		if ( true !== $begun ) {
			$this->autocommit = true;
		}
		return $begun;
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
				$this->notify_restored( $entry['path'] );
				continue;
			}
			$contents = base64_decode( (string) $entry['contents'], true );
			if ( false === $contents ) {
				return 'A journaled canonical pre-image could not be decoded.';
			}
			if ( true !== $this->publish( $entry['path'], $contents ) ) {
				return 'A journaled canonical pre-image could not be restored.';
			}
			$this->notify_restored( $entry['path'] );
		}
		return true;
	}

	private function notify_restored( string $path ): void {
		if ( isset( $this->restore_observers[ $path ] ) ) {
			( $this->restore_observers[ $path ] )( $path );
		}
	}

	private function persist(): true|string {
		$path = $this->journal_path();
		if ( ! $this->active ) {
			return true;
		}
		$directory = $this->journal_directory();
		if ( false === $directory || is_link( $path ) ) {
			return 'The canonical transaction journal path is unsafe or could not be created.';
		}
		$claimed = $this->claim();
		if ( true !== $claimed ) {
			return $claimed;
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
			if ( ! is_array( $entry ) || ! is_string( $entry['path'] ?? null ) || ! $this->admitted_path( $entry['path'] ) ) {
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
		return $this->state_root . DIRECTORY_SEPARATOR . self::JOURNAL_DIRECTORY
			. DIRECTORY_SEPARATOR . self::JOURNAL_PREFIX . $this->owner . self::JOURNAL_SUFFIX;
	}

	/** @return string|false */
	private function journal_directory(): string|false {
		$directory = $this->state_root . DIRECTORY_SEPARATOR . self::JOURNAL_DIRECTORY;
		if ( is_link( $directory ) || ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) ) || ! is_dir( $directory ) || is_link( $directory ) ) {
			return false;
		}
		return $directory;
	}

	/** Only restore files beneath runtime-configured canonical roots. */
	private function admitted_path( string $path ): bool {
		$directory = realpath( dirname( $path ) );
		if ( false === $directory || is_link( $path ) ) {
			return false;
		}
		foreach ( $this->admitted_roots as $root ) {
			if ( $directory === $root || str_starts_with( $directory, $root . DIRECTORY_SEPARATOR ) ) {
				return true;
			}
		}
		return false;
	}
}
