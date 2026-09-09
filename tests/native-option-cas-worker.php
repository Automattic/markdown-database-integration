<?php
/** One independently started contender for the native option CAS smoke test. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

if ( 3 !== $argc ) {
	fwrite( STDERR, "Expected the canonical root and replacement value.\n" );
	exit( 2 );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $argv[1] );
$result = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_options SET option_value = '" . $argv[2] . "' WHERE option_name = 'cas-race' AND option_value = 'pending'" ) );
fwrite( STDOUT, (string) $result->return_value() );
