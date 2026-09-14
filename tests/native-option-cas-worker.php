<?php
/** One independently started contender for the native option CAS smoke test. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

if ( $argc < 3 || $argc > 4 ) {
	fwrite( STDERR, "Expected the canonical root, value, and optional mutation mode.\n" );
	exit( 2 );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $argv[1] );
$query = 'insert-ignore' === ( $argv[3] ?? '' )
	? "INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES ('insert-ignore-race', '" . $argv[2] . "', 'no')"
	: "UPDATE wp_options SET option_value = '" . $argv[2] . "' WHERE option_name = 'cas-race' AND option_value = 'pending'";
$result = $runtime->execute( new WP_Markdown_Query_Request( $query ) );
fwrite( STDOUT, (string) $result->return_value() );
