<?php
/** Default ORDER BY direction and a single trailing statement terminator. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-order-direction-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_records (record_id bigint unsigned NOT NULL auto_increment, owner_id bigint unsigned NOT NULL, label varchar(64) NOT NULL, PRIMARY KEY (record_id), KEY owner_id (owner_id))',
		'wp_'
	)
);
foreach ( array( 'gamma', 'alpha', 'beta' ) as $label ) {
	$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_records (owner_id, label) VALUES (12, '{$label}')", 'wp_' ) );
}

$implicit = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT record_id, label FROM wp_plugin_records WHERE owner_id = 12 ORDER BY record_id;', 'wp_' )
);
$explicit = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT record_id FROM wp_plugin_records WHERE owner_id = 12 ORDER BY record_id DESC', 'wp_' )
);
$terminated = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT record_id FROM wp_plugin_records WHERE owner_id = 12 ;  ', 'wp_' )
);
$injected = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT record_id FROM wp_plugin_records WHERE owner_id = 12; DROP TABLE wp_plugin_records', 'wp_' )
);

$checks = array(
	'ORDER BY without a direction ascends' => array( '1', '2', '3' ) === array_map(
		static fn( object $row ): string => (string) $row->record_id,
		$implicit->wpdb_state()['last_result']
	),
	'ORDER BY DESC still descends' => array( '3', '2', '1' ) === array_map(
		static fn( object $row ): string => (string) $row->record_id,
		$explicit->wpdb_state()['last_result']
	),
	'a trailing terminator is not a second statement' => 3 === $terminated->return_value(),
	'a second statement still fails closed' => false === $injected->return_value(),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_schema/*' ) ?: array() );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_schema' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
