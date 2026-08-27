<?php
/** Native execution over automatically discovered plugin schemas and snapshots. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

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
$outside_marker = $root . '-outside-marker.json';
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
file_put_contents( $root . '/_tables/composite.json', '[{"left_id":3,"right_id":"9"},{"left_id":"3","right_id":7}]' );
file_put_contents( $root . '/_schema/unsupported.sql', "CREATE TABLE wp_unsupported (id bigint(20) unsigned NOT NULL, state enum('open','done') NOT NULL, PRIMARY KEY (id));" );
file_put_contents( $root . '/_schema/mismatch.sql', 'CREATE TABLE wp_different_name (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id));' );
file_put_contents( $root . '/_schema/malformed.sql', 'not ddl' );
file_put_contents( $root . '/_schema/inline_items.sql', "CREATE TABLE wp_inline_items (\n id INTEGER PRIMARY KEY,\n value TEXT NOT NULL\n);" );
file_put_contents( $root . '/_tables/inline_items.json', '[{"id":4,"value":"portable"}]' );
file_put_contents( $root . '/_schema/partitioned.sql', 'CREATE TABLE wp_partitioned (id bigint(20) unsigned NOT NULL, owner_id bigint(20) unsigned NOT NULL, status varchar(32) NOT NULL, PRIMARY KEY (id), KEY owner_id (owner_id));' );
$partition_generation = 'generation-1234567890abcdef12345678';
mkdir( $root . '/_tables/partitioned/' . $partition_generation, 0755, true );
file_put_contents( $root . '/_tables/partitioned/.mdi-partition.json', '{"version":1,"table":"partitioned","identity_column":"id","generation":"' . $partition_generation . '"}' );
foreach ( array( 1 => array( 'id' => 1, 'owner_id' => 7, 'status' => 'ready' ), 2 => array( 'id' => 2, 'owner_id' => 8, 'status' => 'waiting' ) ) as $identity => $row ) {
	file_put_contents(
		$root . '/_tables/partitioned/' . $partition_generation . '/' . hash( 'sha256', (string) $identity ) . '.json',
		json_encode( array( '_mdi_partition' => array( 'version' => 1, 'identity_column' => 'id', 'identity' => (string) $identity ), 'row' => $row ), JSON_THROW_ON_ERROR )
	);
}
file_put_contents( $root . '/_schema/partition_mismatch.sql', 'CREATE TABLE wp_partition_mismatch (id bigint(20) unsigned NOT NULL, owner_id bigint(20) unsigned NOT NULL, PRIMARY KEY (id));' );
mkdir( $root . '/_tables/partition_mismatch', 0755 );
file_put_contents( $root . '/_tables/partition_mismatch/.mdi-partition.json', '{"version":1,"table":"partition_mismatch","identity_column":"owner_id","generation":"generation-1234567890abcdef12345678"}' );
file_put_contents( $root . '/_schema/partition_invalid.sql', 'CREATE TABLE wp_partition_invalid (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id));' );
mkdir( $root . '/_tables/partition_invalid', 0755 );
file_put_contents( $root . '/_tables/partition_invalid/.mdi-partition.json', '{"version":1,"table":"partition_invalid","identity_column":"id","generation":"invalid"}' );
file_put_contents( $root . '/_schema/partition_linked.sql', 'CREATE TABLE wp_partition_linked (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id));' );
mkdir( $root . '/_tables/partition_linked', 0755 );
file_put_contents( $outside_marker, '{"version":1,"table":"partition_linked","identity_column":"id","generation":"generation-1234567890abcdef12345678"}' );
$linked_marker = function_exists( 'symlink' ) && @symlink( $outside_marker, $root . '/_tables/partition_linked/.mdi-partition.json' );
file_put_contents( $outside, $schema );
$linked = function_exists( 'symlink' ) && @symlink( $outside, $root . '/_schema/linked.sql' );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$exact = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT status, id FROM wp_plugin_jobs WHERE id = 2 LIMIT 1' ) );
$secondary = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id, status FROM wp_plugin_jobs WHERE owner_id IN (7) ORDER BY id ASC LIMIT 2' ) );
$unfiltered = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_plugin_jobs LIMIT 2' ) );
$string_filter = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_plugin_jobs WHERE status = 'QUEUED'" ) );
$string_order = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_plugin_jobs ORDER BY status ASC' ) );
$no_identity = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT value FROM wp_no_identity' ) );
$composite = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT right_id FROM wp_composite WHERE left_id = 3' ) );
$unsupported = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_unsupported' ) );
$mismatch = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_mismatch' ) );
$inline = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT value FROM wp_inline_items WHERE id = 4' ) );
$partitioned = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT status, id FROM wp_partitioned WHERE id = 1 AND owner_id IN (7) ORDER BY id ASC LIMIT 1' ) );
$partition_count = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT COUNT(*) FROM wp_partitioned WHERE id IN (2, 1)' ) );
$partition_scan = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_partitioned' ) );
$partition_mismatch = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_partition_mismatch WHERE owner_id = 7' ) );
$partition_invalid = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_partition_invalid WHERE id = 1' ) );
$partition_linked = $linked_marker ? $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_partition_linked WHERE id = 1' ) ) : null;
$linked_result = $linked ? $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_linked' ) ) : null;
$show_table = $runtime->execute( new WP_Markdown_Query_Request( "SHOW TABLES LIKE 'wp_plugin_jobs'" ) );
$show_table_wildcard = $runtime->execute( new WP_Markdown_Query_Request( "SHOW TABLES LIKE 'wp_plugin_%'" ) );
$show_table_escaped = $runtime->execute( new WP_Markdown_Query_Request( "SHOW TABLES LIKE 'wp\\_plugin\\_jobs'" ) );
$show_missing_table = $runtime->execute( new WP_Markdown_Query_Request( "SHOW TABLES LIKE 'wp_missing'" ) );
$describe = $runtime->execute( new WP_Markdown_Query_Request( 'DESCRIBE `wp_plugin_jobs`;' ) );
$show_column = $runtime->execute( new WP_Markdown_Query_Request( "SHOW COLUMNS FROM wp_plugin_jobs LIKE 'status'" ) );
$show_indexes = $runtime->execute( new WP_Markdown_Query_Request( 'SHOW INDEX FROM `wp_plugin_jobs`' ) );
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
	'generic table introspection exposes registered tables with MySQL LIKE semantics' => 1 === $show_table->return_value()
		&& 'wp_plugin_jobs' === ( $show_table->wpdb_state()['last_result'][0]->Table ?? null )
		&& 1 === $show_table_wildcard->return_value()
		&& 1 === $show_table_escaped->return_value()
		&& 0 === $show_missing_table->return_value(),
	'generic column introspection derives MySQL-visible schema rows from persisted DDL' => array( 'id', 'owner_id', 'status', 'payload' ) === array_map(
		static fn( object $row ): string => $row->Field,
		$describe->wpdb_state()['last_result']
	)
		&& 'bigint(20) unsigned' === ( $describe->wpdb_state()['last_result'][0]->Type ?? null )
		&& 'PRI' === ( $describe->wpdb_state()['last_result'][0]->Key ?? null )
		&& 'auto_increment' === ( $describe->wpdb_state()['last_result'][0]->Extra ?? null )
		&& 'status' === ( $show_column->wpdb_state()['last_result'][0]->Field ?? null ),
	'generic index introspection preserves names, order, uniqueness, and prefix lengths' => array( 'PRIMARY', 'owner_id' ) === array_map(
		static fn( object $row ): string => $row->Key_name,
		$show_indexes->wpdb_state()['last_result']
	)
		&& array( '0', '1' ) === array_map( static fn( object $row ): string => $row->Non_unique, $show_indexes->wpdb_state()['last_result'] ),
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
	'schemas without a numeric primary identity remain unsupported' => false === $no_identity->return_value(),
	'composite numeric plugin identities execute without table-specific code' => array( '7', '9' ) === array_map(
		static fn( object $row ): string => $row->right_id,
		$composite->wpdb_state()['last_result']
	),
	'unsupported DDL, mismatched names, and malformed neighbors do not weaken valid tables' => false === $unsupported->return_value()
		&& false === $mismatch->return_value()
		&& 1 === $exact->return_value(),
	'SQLite inline integer primary keys normalize into the generic execution contract' => 'portable' === ( $inline->wpdb_state()['last_result'][0]->value ?? null ),
	'partitioned plugin tables compose lookup, residual filter, ordering, and limit execution' => 1 === $partitioned->return_value()
		&& 'ready' === ( $partitioned->wpdb_state()['last_result'][0]->status ?? null )
		&& '1' === ( $partitioned->wpdb_state()['last_result'][0]->id ?? null ),
	'partitioned plugin tables compose exact count execution' => '2' === ( $partition_count->wpdb_state()['last_result'][0]->{'COUNT(*)'} ?? null ),
	'partitioned plugin tables keep scans and incompatible identity markers fail closed' => false === $partition_scan->return_value()
		&& 'unsupported_partition_access' === ( $partition_scan->diagnostic()['reason'] ?? null )
		&& false === $partition_mismatch->return_value()
		&& 'unsupported_table' === ( $partition_mismatch->diagnostic()['reason'] ?? null ),
	'invalid and linked partition markers fail closed during composition' => false === $partition_invalid->return_value()
		&& 'unsupported_table' === ( $partition_invalid->diagnostic()['reason'] ?? null )
		&& ( ! $linked_marker || ( false === $partition_linked->return_value()
			&& 'unsupported_table' === ( $partition_linked->diagnostic()['reason'] ?? null ) ) ),
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
@unlink( $outside_marker );
exit( $failed ? 1 : 0 );
