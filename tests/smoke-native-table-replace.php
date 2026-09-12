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
$partial_warning_count = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT @@SESSION.warning_count AS warning_count', 'wp_' )
);
$partial_warnings = $runtime->execute( new WP_Markdown_Query_Request( 'SHOW WARNINGS', 'wp_' ) );
$rows_after_partial = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT id, handle, path, state, payload FROM wp_inventory ORDER BY id', 'wp_' )
);
$runtime->execute( new WP_Markdown_Query_Request( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES'", 'wp_' ) );
$strict_partial = $runtime->execute(
	new WP_Markdown_Query_Request( "REPLACE INTO wp_inventory (handle) VALUES ('strict-partial')", 'wp_' )
);
$rows_after_strict_partial = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT id, handle, path, state, payload FROM wp_inventory ORDER BY id', 'wp_' )
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

// A separate PHP process reads the published canonical root without inherited runtime state.
$fresh_process = proc_open(
	array(
		PHP_BINARY,
		'-r',
		'if ( ! isset( $argv[1], $argv[2], $argv[3] ) ) { exit( 2 ); } define( "ABSPATH", dirname( $argv[1] ) . "/" ); require_once $argv[1]; $rows = WP_Markdown_Native_Runtime_Factory::runtime( $argv[2] )->execute( new WP_Markdown_Query_Request( "SELECT id, handle, path, state, payload FROM wp_inventory ORDER BY id", "wp_" ) )->wpdb_state()["last_result"] ?? array(); echo json_encode( array( "pid" => getmypid(), "parent_pid" => (int) $argv[3], "rows" => array_map( static fn( object $row ): array => get_object_vars( $row ), $rows ) ), JSON_THROW_ON_ERROR );',
		__DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php',
		$root,
		(string) getmypid(),
	),
	array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$fresh_pipes
);
$fresh_output = is_resource( $fresh_process ) ? stream_get_contents( $fresh_pipes[1] ) : '';
$fresh_errors = is_resource( $fresh_process ) ? stream_get_contents( $fresh_pipes[2] ) : '';
if ( is_resource( $fresh_process ) ) {
	fclose( $fresh_pipes[0] );
	fclose( $fresh_pipes[1] );
	fclose( $fresh_pipes[2] );
}
$fresh_status = is_resource( $fresh_process ) ? proc_close( $fresh_process ) : 1;
if ( 0 !== $fresh_status && '' !== $fresh_errors ) {
	fwrite( STDERR, $fresh_errors );
}
$fresh = 0 === $fresh_status ? json_decode( $fresh_output, true ) : null;
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
	'non-strict REPLACE defaults omitted required columns with MariaDB warning 1364' => 1 === $partial->return_value()
		&& 4 === $partial->wpdb_state()['insert_id']
		&& '1' === (string) ( $partial_warning_count->wpdb_state()['last_result'][0]->warning_count ?? '' )
		&& 'Warning' === (string) ( $partial_warnings->wpdb_state()['last_result'][0]->Level ?? '' )
		&& '1364' === (string) ( $partial_warnings->wpdb_state()['last_result'][0]->Code ?? '' )
		&& "Field 'path' doesn't have a default value" === (string) ( $partial_warnings->wpdb_state()['last_result'][0]->Message ?? '' )
		&& array( '2', '3', '4' ) === array_map( static fn( object $row ): string => $row->id, $rows_after_partial->wpdb_state()['last_result'] )
		&& '' === (string) ( $rows_after_partial->wpdb_state()['last_result'][2]->path ?? null )
		&& 'ready' === (string) ( $rows_after_partial->wpdb_state()['last_result'][2]->state ?? '' )
		&& null === $rows_after_partial->wpdb_state()['last_result'][2]->payload,
	'strict-mode REPLACE rejects omitted required columns with 1364 without mutation' => false === $strict_partial->return_value()
		&& 1364 === ( $strict_partial->diagnostic()['code'] ?? null )
		&& 'missing_required_column' === ( $strict_partial->diagnostic()['reason'] ?? null )
		&& $rows_after_partial->corpus_result()['rows'] === $rows_after_strict_partial->corpus_result()['rows'],
	'the old row is gone and a separate PHP process reads the complete published replacement' => 0 === $fresh_status
		&& is_array( $fresh )
		&& is_int( $fresh['pid'] ?? null ) && $fresh['pid'] > 0
		&& getmypid() !== ( $fresh['pid'] ?? null )
		&& getmypid() === ( $fresh['parent_pid'] ?? null )
		&& array(
			array( 'id' => '2', 'handle' => 'primary', 'path' => '/unique', 'state' => 'ready', 'payload' => 'three' ),
			array( 'id' => '3', 'handle' => 'beta', 'path' => '/beta', 'state' => 'ready', 'payload' => 'four' ),
			array( 'id' => '4', 'handle' => 'partial', 'path' => '', 'state' => 'ready', 'payload' => null ),
		) === ( $fresh['rows'] ?? null )
		&& is_array( $snapshot )
		&& 3 === count( $snapshot ),
	'unsupported replacement tables remain rejected' => false === $unsupported->return_value()
		&& 'unsupported_mutation_table' === ( $unsupported->diagnostic()['reason'] ?? null ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

if ( is_dir( $root ) ) {
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}
exit( $failed ? 1 : 0 );
