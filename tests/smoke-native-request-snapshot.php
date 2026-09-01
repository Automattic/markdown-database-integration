<?php
/** Request-scoped generic snapshot consistency proof. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-request-snapshot-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the request snapshot fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_inventory (id bigint unsigned NOT NULL auto_increment, handle varchar(191) NOT NULL, label varchar(191) NOT NULL, PRIMARY KEY (id), UNIQUE KEY handle (handle))',
		'wp_'
	)
);
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_inventory (handle, label) VALUES ('alpha', 'one')", 'wp_' ) );
$first = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id, handle, label FROM wp_inventory ORDER BY id', 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_inventory (handle, label) VALUES ('beta', 'two')", 'wp_' ) );
$after_insert = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id, handle, label FROM wp_inventory WHERE handle = 'beta'", 'wp_' ) );

$runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION', 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_inventory SET label = 'temporary' WHERE handle = 'alpha'", 'wp_' ) );
$inside_transaction = $runtime->execute( new WP_Markdown_Query_Request( "SELECT label FROM wp_inventory WHERE handle = 'alpha'", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK', 'wp_' ) );
$after_rollback = $runtime->execute( new WP_Markdown_Query_Request( "SELECT label FROM wp_inventory WHERE handle = 'alpha'", 'wp_' ) );

$path = $root . '/_tables/inventory.json';
$external = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
$external[0]['label'] = 'external';
file_put_contents( $path, json_encode( $external, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
$stable = $runtime->execute( new WP_Markdown_Query_Request( "SELECT label FROM wp_inventory WHERE handle = 'alpha'", 'wp_' ) );
$fresh = WP_Markdown_Native_Runtime_Factory::runtime( $root )->execute(
	new WP_Markdown_Query_Request( "SELECT label FROM wp_inventory WHERE handle = 'alpha'", 'wp_' )
);

$first_rows = $first->wpdb_state()['last_result'] ?? array();
$insert_rows = $after_insert->wpdb_state()['last_result'] ?? array();
$transaction_rows = $inside_transaction->wpdb_state()['last_result'] ?? array();
$rollback_rows = $after_rollback->wpdb_state()['last_result'] ?? array();
$stable_rows = $stable->wpdb_state()['last_result'] ?? array();
$fresh_rows = $fresh->wpdb_state()['last_result'] ?? array();

$checks = array(
	'the first read loads the canonical snapshot' => 1 === count( $first_rows )
		&& 'one' === (string) ( $first_rows[0]->label ?? '' ),
	'a successful insert updates the request snapshot' => 1 === count( $insert_rows )
		&& 'two' === (string) ( $insert_rows[0]->label ?? '' ),
	'a transaction reads its own generic-table write' => 'temporary' === (string) ( $transaction_rows[0]->label ?? '' ),
	'rollback invalidates and reloads the restored snapshot' => 'one' === (string) ( $rollback_rows[0]->label ?? '' ),
	'external changes do not alter a loaded request snapshot' => 'one' === (string) ( $stable_rows[0]->label ?? '' ),
	'a new request observes the externally published snapshot' => 'external' === (string) ( $fresh_rows[0]->label ?? '' ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

exit( $failed ? 1 : 0 );
