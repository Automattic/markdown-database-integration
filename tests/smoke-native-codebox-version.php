<?php
/** Verify native lifecycle probes reject unsupported WP Codebox releases clearly. */

declare( strict_types=1 );

require_once __DIR__ . '/lib-native-lifecycle-fixture.php';

$binary = sys_get_temp_dir() . '/mdi-wp-codebox-version-' . bin2hex( random_bytes( 6 ) );
$failures = array();

try {
	file_put_contents( $binary, "#!/bin/sh\nprintf '0.24.5\\n'\n" );
	chmod( $binary, 0755 );
	try {
		mdi_native_lifecycle_require_wp_codebox( $binary );
	} catch ( RuntimeException $error ) {
		$failures[] = 'minimum supported version was rejected: ' . $error->getMessage();
	}

	file_put_contents( $binary, "#!/bin/sh\nprintf '0.24.4\\n'\n" );
	try {
		mdi_native_lifecycle_require_wp_codebox( $binary );
		$failures[] = 'unsupported version was accepted';
	} catch ( RuntimeException $error ) {
		if ( ! str_contains( $error->getMessage(), '0.24.5 or newer is required; 0.24.4 was found.' ) ) {
			$failures[] = 'unsupported version error was not actionable: ' . $error->getMessage();
		}
	}
} finally {
	@unlink( $binary );
}

foreach ( $failures as $failure ) {
	fwrite( STDERR, "FAIL: {$failure}\n" );
}
exit( array() === $failures ? 0 : 1 );
