<?php
/** Typed, bounded evidence for the disposable primary SQLite index. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Primary_Index_Evidence {
	private const SQLITE_HEADER = "SQLite format 3\0";
	private const MAX_ERROR_BYTES = 512;

	/** @param array<string,mixed> $details */
	private function __construct( private string $status, private array $details ) {}

	public static function probe( string $path, int $deadline_ms ): self {
		$started = hrtime( true );
		$stat    = @lstat( $path );
		if ( false === $stat || ! is_file( $path ) ) {
			return new self( 'missing', self::details( $path, $deadline_ms, $started ) );
		}

		$handle = @fopen( $path, 'rb' );
		$header = false === $handle ? false : fread( $handle, strlen( self::SQLITE_HEADER ) );
		if ( false !== $handle ) {
			fclose( $handle );
		}
		$details = self::details( $path, $deadline_ms, $started, $stat );
		if ( $details['elapsed_ms'] > $deadline_ms ) {
			return new self( 'deadline_exceeded', $details );
		}
		if ( self::SQLITE_HEADER !== $header ) {
			$details['reason'] = false === $header ? 'index_unreadable' : 'invalid_sqlite_header';
			return new self( 'unavailable', $details );
		}

		return new self( 'ready', $details );
	}

	public static function attached( self $probe, bool $previous = false, ?self $recovered_from = null ): self {
		$details = $probe->details;
		$details['served_generation'] = $previous ? 'previous' : 'active';
		if ( $previous && null !== $recovered_from ) {
			$details['recovered_from'] = $recovered_from->diagnostic();
		}
		return new self( $previous ? 'recovered_previous' : 'ready', $details );
	}

	public static function failed( self $probe, Throwable $error, string $phase = 'attach' ): self {
		$details                  = $probe->details;
		$details['phase']         = $phase;
		$details['exception']     = get_class( $error );
		$details['error_message'] = substr( preg_replace( '/\s+/', ' ', trim( $error->getMessage() ) ) ?? '', 0, self::MAX_ERROR_BYTES );
		return new self( 'unavailable', $details );
	}

	public static function cold_complete( string $path, int $deadline_ms ): self {
		$probe = self::probe( $path, $deadline_ms );
		$details = $probe->details;
		$details['served_generation'] = 'new';
		return new self( 'cold_reconstructed', $details );
	}

	public function status(): string { return $this->status; }
	public function is_ready(): bool { return 'ready' === $this->status; }

	/** @return array<string,mixed> */
	public function diagnostic(): array {
		return array_merge( array( 'code' => 'markdown_db_primary_index_' . $this->status ), $this->details );
	}

	public function operator_message(): string {
		$diagnostic = $this->diagnostic();
		$message = 'Markdown DB primary index is unavailable [' . $diagnostic['code'] . '].';
		if ( ! empty( $diagnostic['reason'] ) ) {
			$message .= ' Reason: ' . $diagnostic['reason'] . '.';
		}
		if ( ! empty( $diagnostic['error_message'] ) ) {
			$message .= ' Cause: ' . $diagnostic['error_message'] . '.';
		}
		return $message . ' Canonical files remain authoritative; run the explicit primary-index maintenance owner to rebuild the disposable index.';
	}

	/** @param array<string,mixed>|false $stat @return array<string,mixed> */
	private static function details( string $path, int $deadline_ms, int $started, array|false $stat = false ): array {
		$details = array(
			'path_hash'   => hash( 'sha256', $path ),
			'deadline_ms' => $deadline_ms,
			'elapsed_ms'  => (int) ceil( ( hrtime( true ) - $started ) / 1000000 ),
		);
		if ( false !== $stat ) {
			$details['size_bytes'] = (int) $stat['size'];
			$details['modified_at'] = (int) $stat['mtime'];
		}
		return $details;
	}
}
