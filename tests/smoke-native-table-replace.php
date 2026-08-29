<?php
/** MySQL-compatible REPLACE for generic persisted snapshot tables. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-replace-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the generic table REPLACE fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$created = $runtime->execute(
	new WP_Markdown_Query_Request(
		"CREATE TABLE wp_inventory (id bigint unsigned NOT NULL auto_increment, handle varchar(191) NOT NULL, path varchar(191) NOT NULL, state varchar(16) NOT NULL DEFAULT 'ready', payload longtext NULL, PRIMARY KEY (id), UNIQUE KEY handle (handle))",
		'wp_'
	)
);

// This is the identifier and literal quoting shape emitted by wpdb::replace().
$inserted = $runtime->execute(
	new WP_Markdown_Query_Request(
		"REPLACE INTO `wp_inventory` (`handle`, `path`, `payload`) VALUES ('alpha', '/first', 'one')",
		'wp_'
	)
);
$primary_replaced = $runtime->execute(
	new WP_Markdown_Query_Request(
		"REPLACE INTO `wp_inventory` (`id`, `handle`, `path`, `payload`) VALUES (1, 'primary', '/primary', 'two')",
		'wp_'
	)
);
$unique_replaced = $runtime->execute(
	new WP_Markdown_Query_Request(
		"REPLACE INTO `wp_inventory` (`handle`, `path`, `payload`) VALUES ('primary', '/unique', 'three')",
		'wp_'
	)
);
$without_into = $runtime->execute(
	new WP_Markdown_Query_Request(
		"REPLACE `wp_inventory` (`handle`, `path`, `payload`) VALUES ('beta', '/beta', 'four')",
		'wp_'
	)
);
$partial = $runtime->execute(
	new WP_Markdown_Query_Request( "REPLACE INTO wp_inventory (handle) VALUES ('partial')", 'wp_' )
);
$unsupported = $runtime->execute(
	new WP_Markdown_Query_Request( "REPLACE INTO wp_absent (id) VALUES (1)", 'wp_' )
);
$runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION', 'wp_' ) );
$rolled_back_replace = $runtime->execute(
	new WP_Markdown_Query_Request(
		"REPLACE INTO wp_inventory (handle, path, payload) VALUES ('primary', '/rolled-back', 'discarded')",
		'wp_'
	)
);
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK', 'wp_' ) );

// A new runtime proves the complete replacement row was atomically published.
$fresh_runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$fresh_read = $fresh_runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT id, handle, path, state, payload FROM wp_inventory WHERE handle = \'primary\'', 'wp_' )
);
$fresh_rows = $fresh_read->wpdb_state()['last_result'] ?? array();
$fresh = $fresh_rows[0] ?? null;
$snapshot = json_decode( (string) file_get_contents( $root . '/_tables/inventory.json' ), true );

$checks = array(
	'the fixture table is created' => 0 === $created->return_value() || true === $created->succeeded(),
	'REPLACE INTO inserts a nonconflicting complete defaulted row' => 1 === $inserted->return_value()
		&& 1 === $inserted->wpdb_state()['insert_id'],
	'REPLACE INTO deletes and inserts on a primary-key conflict' => 2 === $primary_replaced->return_value()
		&& 1 === $primary_replaced->wpdb_state()['insert_id'],
	'REPLACE INTO deletes and inserts on a declared unique-key conflict' => 2 === $unique_replaced->return_value()
		&& 2 === $unique_replaced->wpdb_state()['insert_id'],
	'REPLACE accepts MySQL optional INTO syntax' => 1 === $without_into->return_value()
		&& 3 === $without_into->wpdb_state()['insert_id'],
	'a rolled-back replacement restores the published snapshot' => 2 === $rolled_back_replace->return_value(),
	'the old row is gone and the fresh runtime reads the complete replacement' => 1 === count( $fresh_rows )
		&& '2' === (string) ( $fresh->id ?? '' )
		&& '/unique' === (string) ( $fresh->path ?? '' )
		&& 'ready' === (string) ( $fresh->state ?? '' )
		&& 'three' === (string) ( $fresh->payload ?? '' )
		&& is_array( $snapshot )
		&& 2 === count( $snapshot ),
	'invalid partial replacement rows remain rejected' => false === $partial->return_value()
		&& 'missing_required_column' === ( $partial->diagnostic()['reason'] ?? null ),
	'unsupported replacement tables remain rejected' => false === $unsupported->return_value()
		&& 'unsupported_mutation_table' === ( $unsupported->diagnostic()['reason'] ?? null ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/_tables/.index/*' ) ?: array() );
@rmdir( $root . '/_tables/.index' );
array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_schema/*' ) ?: array() );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_schema' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
