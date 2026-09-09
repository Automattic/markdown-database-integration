<?php
/** Native runtimes materialize declared fresh state roots without accepting unsafe ones. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-runtime-root-' . bin2hex( random_bytes( 6 ) );
$state = $root . '/fresh/state';
$runtime = WP_Markdown_Native_Runtime_Factory::prefix_runtime( $state );
$runtime->execute( new WP_Markdown_Query_Request( 'SELECT option_value FROM wptests_options WHERE option_name = \'siteurl\'', 'wptests_' ) );
file_put_contents( $root . '/not-a-directory', 'state' );
try {
	WP_Markdown_Native_Runtime_Factory::runtime( $root . '/not-a-directory' );
	$rejected_file = false;
} catch ( InvalidArgumentException ) {
	$rejected_file = true;
}

$checks = array(
	'fresh declared state roots are materialized before journal recovery' => is_dir( $state ),
	'non-directory state roots remain rejected' => $rejected_file,
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

@unlink( $root . '/not-a-directory' );
@rmdir( $state );
@rmdir( dirname( $state ) );
@rmdir( $root );
exit( $failed ? 1 : 0 );
