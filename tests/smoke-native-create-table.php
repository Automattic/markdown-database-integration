<?php
/** Native CREATE TABLE persistence and immediate introspection. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_native_create_remove_tree( string $root ): void {
	if ( ! is_dir( $root ) ) {
		return;
	}
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}

$root = sys_get_temp_dir() . '/mdi-native-create-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$ddl = "CREATE TABLE wp_plugin_events (\n"
	. " event_key varchar(64) NOT NULL,\n"
	. " owner_id bigint(20) unsigned NOT NULL DEFAULT '0',\n"
	. " payload longtext DEFAULT NULL,\n"
	. " PRIMARY KEY (event_key),\n"
	. " KEY owner_id (owner_id)\n"
	. ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4';

$created = $runtime->execute( new WP_Markdown_Query_Request( $ddl . ';' ) );
$shown = $runtime->execute( new WP_Markdown_Query_Request( "SHOW TABLES LIKE 'wp_plugin_events'" ) );
$described = $runtime->execute( new WP_Markdown_Query_Request( 'DESCRIBE wp_plugin_events' ) );
$indexed = $runtime->execute( new WP_Markdown_Query_Request( 'SHOW INDEX FROM wp_plugin_events' ) );
$executable = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT event_key FROM wp_plugin_events' ) );
$runtime->execute(
	new WP_Markdown_Query_Request( 'CREATE TABLE wp_plugin_keyless (label varchar(64) NOT NULL, payload longtext DEFAULT NULL)' )
);
$unexecutable = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT label FROM wp_plugin_keyless' ) );
$duplicate = $runtime->execute( new WP_Markdown_Query_Request( $ddl ) );
$injected = $runtime->execute( new WP_Markdown_Query_Request( $ddl . '; DROP TABLE wp_options' ) );
$reloaded = WP_Markdown_Native_Runtime_Factory::runtime( $root )->execute( new WP_Markdown_Query_Request( 'DESCRIBE wp_plugin_events' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION' ) );
$transactional_ddl = $runtime->execute( new WP_Markdown_Query_Request( "CREATE TABLE wp_ddl_commit (\n"
	. " id bigint unsigned NOT NULL,\n"
	. " start_datetime datetime NOT NULL,\n"
	. " end_datetime datetime DEFAULT NULL,\n"
	. " post_status varchar(20) NOT NULL DEFAULT 'publish',\n"
	. " PRIMARY KEY (id),\n"
	. " KEY start_datetime (start_datetime),\n"
	. " KEY end_datetime (end_datetime),\n"
	. " KEY status_start (post_status, start_datetime)\n"
	. ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK' ) );
$ddl_survives_rollback = WP_Markdown_Native_Runtime_Factory::runtime( $root )->execute( new WP_Markdown_Query_Request( 'DESCRIBE wp_ddl_commit' ) );
$temporary_ddl = 'CREATE TEMPORARY TABLE wp_ddl_temporary (id bigint unsigned NOT NULL, PRIMARY KEY (id))';
$runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION' ) );
$temporary_created = $runtime->execute( new WP_Markdown_Query_Request( $temporary_ddl ) );
$temporary_inserted = $runtime->execute( new WP_Markdown_Query_Request( 'INSERT INTO wp_ddl_temporary (id) VALUES (1)' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK' ) );
$temporary_after_rollback = $runtime->execute( new WP_Markdown_Query_Request( 'DESCRIBE wp_ddl_temporary' ) );
$temporary_rows_after_rollback = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_ddl_temporary' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION' ) );
$temporary_dropped = $runtime->execute( new WP_Markdown_Query_Request( 'DROP TEMPORARY TABLE wp_ddl_temporary' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK' ) );
$temporary_after_drop_rollback = $runtime->execute( new WP_Markdown_Query_Request( 'DESCRIBE wp_ddl_temporary' ) );

$checks = array(
	'generic CREATE TABLE returns the WordPress DDL success shape' => true === $created->return_value()
		&& 0 === $created->wpdb_state()['rows_affected'],
	'created schema is atomically persisted as one canonical statement' => $ddl . ";\n" === file_get_contents( $root . '/_schema/plugin_events.sql' )
		&& array() === ( glob( $root . '/_schema/*.tmp-*' ) ?: array() ),
	'created tables are immediately visible through generic introspection' => 'wp_plugin_events' === ( $shown->wpdb_state()['last_result'][0]->{'Tables_in_'} ?? null )
		&& array( 'event_key', 'owner_id', 'payload' ) === array_map( static fn( object $row ): string => $row->Field, $described->wpdb_state()['last_result'] )
		&& array( 'PRIMARY', 'owner_id' ) === array_map( static fn( object $row ): string => $row->Key_name, $indexed->wpdb_state()['last_result'] ),
	'an exact string primary key is executable while a keyless table is not' => 0 === $executable->return_value()
		&& false === $unexecutable->return_value()
		&& 'unsupported_table' === ( $unexecutable->diagnostic()['reason'] ?? null ),
	'duplicate and multi-statement schema mutations fail closed without changing canonical state' => false === $duplicate->return_value()
		&& 'table_exists' === ( $duplicate->diagnostic()['reason'] ?? null )
		&& false === $injected->return_value()
		&& 'unsupported_grammar' === ( $injected->diagnostic()['reason'] ?? null )
		&& $ddl . ";\n" === file_get_contents( $root . '/_schema/plugin_events.sql' ),
	'persisted definitions restore introspection after a cold reload' => 'event_key' === ( $reloaded->wpdb_state()['last_result'][0]->Field ?? null ),
	'table DDL implicitly commits and survives a later rollback' => true === $transactional_ddl->return_value()
		&& 'id' === ( $ddl_survives_rollback->wpdb_state()['last_result'][0]->Field ?? null ),
	'temporary table DDL survives rollback while its transactional rows roll back' => true === $temporary_created->return_value()
		&& 1 === $temporary_inserted->wpdb_state()['rows_affected']
		&& 'id' === ( $temporary_after_rollback->wpdb_state()['last_result'][0]->Field ?? null )
		&& array() === $temporary_rows_after_rollback->wpdb_state()['last_result'],
	'DROP TEMPORARY TABLE survives rollback and refreshes the table registry' => true === $temporary_dropped->return_value()
		&& false === $temporary_after_drop_rollback->return_value(),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	fwrite( $passed ? STDOUT : STDERR, ( $passed ? 'PASS' : 'FAIL' ) . ": {$label}\n" );
	$failed = $failed || ! $passed;
}

mdi_native_create_remove_tree( $root );
exit( $failed ? 1 : 0 );
