<?php
/** Hold a lock-file descriptor across an inter-process advisory-lock handoff. */

declare( strict_types=1 );

if ( 3 !== $argc ) {
	fwrite( STDERR, "Usage: php native-advisory-lock-worker.php <root> <name>\n" );
	exit( 2 );
}

$path = $argv[1] . '/_locks/' . hash( 'sha256', $argv[2] ) . '.lock';
$handle = fopen( $path, 'c+b' );
if ( false === $handle ) {
	exit( 2 );
}
fwrite( STDOUT, "descriptor-open\n" );
fflush( STDOUT );
for ( $attempt = 0; $attempt < 500; ++$attempt ) {
	if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
		fwrite( STDOUT, "acquired\n" );
		fflush( STDOUT );
		stream_get_contents( STDIN );
		flock( $handle, LOCK_UN );
		fclose( $handle );
		exit( 0 );
	}
	usleep( 10000 );
}
fclose( $handle );
exit( 1 );
