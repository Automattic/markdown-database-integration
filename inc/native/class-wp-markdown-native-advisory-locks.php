<?php
/** Root-scoped, process-safe named locks for the native MySQL compatibility layer. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Advisory_Locks {

	private const DIRECTORY = '_locks';
	public const MAX_WAIT_SECONDS = 5.0;

	/** @var array<string,array{handle:resource,count:int,path:string}> */
	private array $locks = array();

	public function __construct( private readonly string $state_root ) {}

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
			if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
				$this->locks[ $name ] = array( 'handle' => $handle, 'count' => 1, 'path' => $path );
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
