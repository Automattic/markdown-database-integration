<?php
/** Generate the canonical installed-site state used by the native benchmark rig. */

declare( strict_types=1 );

require_once __DIR__ . '/lib-native-lifecycle-fixture.php';

$root = __DIR__ . '/fixtures/native-bench-state';
if ( is_dir( $root ) ) {
	fwrite( STDERR, "Native benchmark state already exists: {$root}\n" );
	exit( 1 );
}

mkdir( $root . '/_options', 0755, true );
mkdir( $root . '/_tables', 0755, true );
mdi_native_lifecycle_seed_options( $root );
mdi_native_lifecycle_seed_administrator( $root );

fwrite( STDOUT, "Generated native benchmark state: {$root}\n" );
