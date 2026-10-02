<?php
/** Root-scoped, process-safe named locks for the native MySQL compatibility layer. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Advisory_Locks {

	private const DIRECTORY = '_locks';
	/** The largest ordinary consumer lock wait accepted by the native runtime. */
	public const MAX_WAIT_SECONDS = 10.0;

	/** @var array<string,array{handle:resource,count:int,path:string}> */
	private array $locks = array();

	/**
	 * Lock files owned by some instance in this PHP process, keyed by path.
	 *
	 * flock() alone is not a same-process exclusion guarantee: php-wasm
	 * (WordPress Playground) grants every handle in one process, so two logical
	 * connections in one request both "acquired" the same lock. Ownership is
	 * therefore recorded in-process first; flock() still excludes other processes.
	 *
	 * @var array<string,int>
	 */
	private static array $process_owners = array();
	private static int $next_owner = 0;
	private readonly int $owner;

	public function __construct( private readonly string $state_root ) {
		$this->owner = ++self::$next_owner;
	}

	private function held_by_other_in_process( string $path ): bool {
		return isset( self::$process_owners[ $path ] ) && self::$process_owners[ $path ] !== $this->owner;
	}

	/** Acquire a named lock, waiting no longer than the requested bounded timeout. */
	public function acquire( string $name, float $timeout ): bool {
		if ( isset( $this->locks[ $name ] ) ) {
			++$this->locks[ $name ]['count'];
			return true;
		}
		$directory = $this->state_root . DIRECTORY_SEPARATOR . self::DIRECTORY;
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			return false;
		}
		$path = $directory . DIRECTORY_SEPARATOR . hash( 'sha256', $name ) . '.lock';
		$handle = @fopen( $path, 'c+b' );
		if ( false === $handle ) {
			return false;
		}
		$wait = $timeout * 1000000;
		$deadline = hrtime( true ) + (int) ( $wait * 1000 );
		do {
			if ( ! $this->held_by_other_in_process( $path ) && flock( $handle, LOCK_EX | LOCK_NB ) ) {
				$this->locks[ $name ] = array( 'handle' => $handle, 'count' => 1, 'path' => $path );
				self::$process_owners[ $path ] = $this->owner;
				return true;
			}
			if ( 0.0 === $wait || hrtime( true ) >= $deadline ) {
				break;
			}
			usleep( 10000 );
		} while ( true );
		fclose( $handle );
		return false;
	}

	/** @return int|null One when released, zero when held by another connection, null when absent. */
	public function release( string $name ): ?int {
		if ( ! isset( $this->locks[ $name ] ) ) {
			if ( $this->held_by_other_in_process( $this->path( $name ) ) ) {
				return 0;
			}
			$handle = @fopen( $this->path( $name ), 'c+b' );
			if ( false === $handle ) {
				return null;
			}
			$available = flock( $handle, LOCK_EX | LOCK_NB );
			if ( $available ) {
				flock( $handle, LOCK_UN );
			}
			fclose( $handle );
			return $available ? null : 0;
		}
		--$this->locks[ $name ]['count'];
		if ( 0 < $this->locks[ $name ]['count'] ) {
			return 1;
		}
		$lock = $this->locks[ $name ];
		unset( $this->locks[ $name ] );
		if ( ( self::$process_owners[ $lock['path'] ] ?? null ) === $this->owner ) {
			unset( self::$process_owners[ $lock['path'] ] );
		}
		flock( $lock['handle'], LOCK_UN );
		fclose( $lock['handle'] );
		return 1;
	}

	/** Release every lock when the logical native connection closes. */
	public function close(): void {
		foreach ( array_keys( $this->locks ) as $name ) {
			$this->locks[ $name ]['count'] = 1;
			$this->release( $name );
		}
	}

	public function __destruct() {
		$this->close();
	}

	private function path( string $name ): string {
		return $this->state_root . DIRECTORY_SEPARATOR . self::DIRECTORY . DIRECTORY_SEPARATOR . hash( 'sha256', $name ) . '.lock';
	}
}
