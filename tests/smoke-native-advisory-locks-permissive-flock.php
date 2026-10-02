<?php
/**
 * Advisory locks must exclude same-process owners even when flock() grants
 * every handle, as php-wasm (WordPress Playground) does within one process.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-advisory-locks.php';

/** Passthrough file wrapper whose stream_lock() always succeeds, like php-wasm. */
final class MDI_Permissive_Flock_Stream {
	/** @var resource|null */
	public $context;
	/** @var resource */
	private $handle;

	private static function real( string $path ): string {
		return substr( $path, strlen( 'mdi-permissive://' ) );
	}
	public function stream_open( string $path, string $mode ): bool {
		$handle = fopen( self::real( $path ), $mode );
		if ( false === $handle ) {
			return false;
		}
		$this->handle = $handle;
		return true;
	}
	public function stream_lock( int $operation ): bool {
		unset( $operation );
		return true;
	}
	public function stream_close(): void { fclose( $this->handle ); }
	public function stream_read( int $count ): string|false { return fread( $this->handle, $count ); }
	public function stream_write( string $data ): int|false { return fwrite( $this->handle, $data ); }
	public function stream_eof(): bool { return feof( $this->handle ); }
	public function stream_stat(): array|false { return fstat( $this->handle ); }
	public function url_stat( string $path, int $flags ): array|false {
		$real = self::real( $path );
		return file_exists( $real ) ? stat( $real ) : false;
	}
	public function mkdir( string $path, int $mode, int $options ): bool {
		return mkdir( self::real( $path ), $mode, (bool) ( $options & STREAM_MKDIR_RECURSIVE ) );
	}
}
stream_wrapper_register( 'mdi-permissive', MDI_Permissive_Flock_Stream::class );

$root = 'mdi-permissive://' . sys_get_temp_dir() . '/mdi-permissive-flock-' . bin2hex( random_bytes( 6 ) );
$first  = new WP_Markdown_Native_Advisory_Locks( $root );
$second = new WP_Markdown_Native_Advisory_Locks( $root );

$assertions = array(
	'the first owner acquires'                    => true === $first->acquire( 'venue-lock', 0 ),
	'a same-process second owner is excluded'     => false === $second->acquire( 'venue-lock', 0 ),
	'a same-process non-owner release reports 0'  => 0 === $second->release( 'venue-lock' ),
	'the owner remains reentrant'                 => true === $first->acquire( 'venue-lock', 0 ) && 1 === $first->release( 'venue-lock' ),
	'still held after one reentrant release'      => false === $second->acquire( 'venue-lock', 0 ),
	'final release transfers ownership'           => 1 === $first->release( 'venue-lock' ) && true === $second->acquire( 'venue-lock', 0 ),
	'close releases in-process ownership'         => ( static function () use ( $first, $second ): bool { $second->close(); return true === $first->acquire( 'venue-lock', 0 ); } )(),
	'unrelated names do not contend'              => true === $second->acquire( 'other-lock', 0 ),
);
$passed = ! in_array( false, $assertions, true );
fwrite( $passed ? STDOUT : STDERR, json_encode( array( 'assertions' => $assertions, 'passed' => $passed ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n" );
exit( $passed ? 0 : 1 );
