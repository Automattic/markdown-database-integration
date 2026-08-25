<?php
/** Native execution over automatically discovered plugin schemas and snapshots. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-query-runtime.php';

function mdi_plugin_schema_remove_tree( string $root ): void {
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

$root = sys_get_temp_dir() . '/mdi-native-plugin-schema-' . bin2hex( random_bytes( 6 ) );
$outside = $root . '-outside.sql';
mkdir( $root . '/_schema', 0755, true );
mkdir( $root . '/_tables', 0755, true );

$schema = "CREATE TABLE `wp_plugin_jobs` (\n"
	. " `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
	. " `owner_id` bigint(20) unsigned NOT NULL DEFAULT '0',\n"
	. " `status` varchar(32) NOT NULL DEFAULT '',\n"
	. " `payload` longtext DEFAULT NULL,\n"
	. " PRIMARY KEY (`id`),\n"
	. " KEY `owner_id` (`owner_id`)\n"
	. ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
file_put_contents( $root . '/_schema/plugin_jobs.sql', $schema );
file_put_contents(
	$root . '/_tables/plugin_jobs.json',
	json_encode(
		array(
			array( 'id' => 10, 'owner_id' => '8', 'status' => 'running', 'payload' => null ),
			array( 'id' => '2', 'owner_id' => 7, 'status' => 'queued', 'payload' => 'work' ),
			array( 'id' => 1, 'owner_id' => '7', 'status' => 'done', 'payload' => '' ),
		),
		JSON_THROW_ON_ERROR
	)
);

file_put_contents( $root . '/_schema/no_identity.sql', 'CREATE TABLE wp_no_identity (value bigint(20) unsigned NOT NULL);' );
file_put_contents( $root . '/_tables/no_identity.json', '[{"value":1}]' );
file_put_contents( $root . '/_schema/composite.sql', 'CREATE TABLE wp_composite (left_id bigint(20) unsigned NOT NULL, right_id bigint(20) unsigned NOT NULL, PRIMARY KEY (left_id,right_id));' );
file_put_contents( $root . '/_schema/unsupported.sql', "CREATE TABLE wp_unsupported (id bigint(20) unsigned NOT NULL, state enum('open','done') NOT NULL, PRIMARY KEY (id));" );
file_put_contents( $root . '/_schema/mismatch.sql', 'CREATE TABLE wp_different_name (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id));' );
file_put_contents( $root . '/_schema/malformed.sql', 'not ddl' );
file_put_contents( $root . '/_schema/inline_items.sql', "CREATE TABLE wp_inline_items (\n id INTEGER PRIMARY KEY,\n value TEXT NOT NULL\n);" );
file_put_contents( $root . '/_tables/inline_items.json', '[{"id":4,"value":"portable"}]' );
file_put_contents( $outside, $schema );
$linked = function_exists( 'symlink' ) && @symlink( $outside, $root . '/_schema/linked.sql' );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$exact = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT status, id FROM wp_plugin_jobs WHERE id = 2 LIMIT 1' ) );
$secondary = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id, status FROM wp_plugin_jobs WHERE owner_id IN (7) ORDER BY id ASC LIMIT 2' ) );
$unfiltered = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_plugin_jobs LIMIT 2' ) );
$string_filter = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_plugin_jobs WHERE status = 'QUEUED'" ) );
$string_order = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_plugin_jobs ORDER BY status ASC' ) );
$no_identity = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT value FROM wp_no_identity' ) );
$composite = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT left_id FROM wp_composite' ) );
$unsupported = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_unsupported' ) );
$mismatch = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_mismatch' ) );
$inline = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT value FROM wp_inline_items WHERE id = 4' ) );
$linked_result = $linked ? $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_linked' ) ) : null;
file_put_contents(
	$root . '/_tables/plugin_jobs.json',
	json_encode(
		array(
			array( 'id' => 2, 'owner_id' => 7, 'status' => 'first', 'payload' => null ),
			array( 'id' => '2', 'owner_id' => '7', 'status' => 'duplicate', 'payload' => null ),
		),
		JSON_THROW_ON_ERROR
	)
);
$duplicate_identity = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_plugin_jobs' ) );

$checks = array(
	'persisted plugin DDL and snapshots register without table-specific code' => 1 === $exact->return_value()
		&& 'queued' === ( $exact->wpdb_state()['last_result'][0]->status ?? null )
		&& '2' === ( $exact->wpdb_state()['last_result'][0]->id ?? null ),
	'primary and secondary numeric indexes derive bounded lookup capabilities' => array( '1', '2' ) === array_map(
		static fn( object $row ): string => $row->id,
		$secondary->wpdb_state()['last_result']
	),
	'mixed SQLite integers and MySQL numeric strings share deterministic natural order' => array( '1', '2' ) === array_map(
		static fn( object $row ): string => $row->id,
		$unfiltered->wpdb_state()['last_result']
	),
	'generated plugin metadata preserves projection order and MySQL field types' => array( 253, 8 ) === array_map(
		static fn( object $column ): int => $column->type,
		$exact->wpdb_state()['col_info']
	),
	'unknown string collation predicates and ordering fail closed' => false === $string_filter->return_value()
		&& 'unsupported_lookup' === ( $string_filter->diagnostic()['reason'] ?? null )
		&& false === $string_order->return_value()
		&& 'unsupported_order' === ( $string_order->diagnostic()['reason'] ?? null ),
	'schemas without one numeric primary identity remain unsupported' => false === $no_identity->return_value()
		&& false === $composite->return_value(),
	'unsupported DDL, mismatched names, and malformed neighbors do not weaken valid tables' => false === $unsupported->return_value()
		&& false === $mismatch->return_value()
		&& 1 === $exact->return_value(),
	'SQLite inline integer primary keys normalize into the generic execution contract' => 'portable' === ( $inline->wpdb_state()['last_result'][0]->value ?? null ),
	'cross-backend numeric representations cannot duplicate one natural identity' => false === $duplicate_identity->return_value()
		&& 'duplicate_natural_identity' === ( $duplicate_identity->diagnostic()['reason'] ?? null ),
	'linked persisted schemas fail closed' => ! $linked || ( false === $linked_result->return_value()
		&& 'unsupported_table' === ( $linked_result->diagnostic()['reason'] ?? null ) ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	fwrite( $passed ? STDOUT : STDERR, ( $passed ? 'PASS' : 'FAIL' ) . ": {$label}\n" );
	$failed = $failed || ! $passed;
}

mdi_plugin_schema_remove_tree( $root );
@unlink( $outside );
exit( $failed ? 1 : 0 );
