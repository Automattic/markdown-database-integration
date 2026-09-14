<?php
/** One concurrent contender for the generic derived-selection claim test. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

if ( 3 !== $argc ) {
	fwrite( STDERR, "Expected canonical root and claim id.\n" );
	exit( 2 );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $argv[1] );
$claim_id = (int) $argv[2];
$query = "UPDATE wp_action_claims t1 JOIN ( SELECT action_id FROM wp_action_claims WHERE claim_id = 0 AND hook = 'race' ORDER BY priority ASC, attempts ASC, scheduled_date_gmt ASC, action_id ASC LIMIT 2 FOR UPDATE ) t2 ON t1.action_id = t2.action_id SET claim_id={$claim_id}, last_attempt_gmt='2026-09-13 00:00:00', last_attempt_local='2026-09-13 00:00:00'";
$result = $runtime->execute( new WP_Markdown_Query_Request( $query, 'wp_' ) );
fwrite( STDOUT, (string) $result->return_value() );
