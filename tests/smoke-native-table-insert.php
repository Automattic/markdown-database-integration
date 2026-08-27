<?php
/** Generic persisted snapshot INSERT behavior. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_native_insert_remove_tree( string $root ): void {
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

$root = sys_get_temp_dir() . '/mdi-native-insert-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$ddl = "CREATE TABLE wp_plugin_jobs (\n"
	. " job_id bigint(20) unsigned NOT NULL auto_increment,\n"
	. " hook varchar(191) NOT NULL,\n"
	. " priority tinyint unsigned NOT NULL default '10',\n"
	. " payload longtext DEFAULT NULL,\n"
	. " PRIMARY KEY (job_id)\n"
	. ') DEFAULT CHARACTER SET utf8mb4';
$runtime->execute( new WP_Markdown_Query_Request( $ddl ) );

$first = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO `wp_plugin_jobs` (`job_id`, `hook`, `payload`) VALUES (0, 'first_job', NULL)" ) );
$second = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_jobs (job_id, hook, priority) VALUES (8, 'second_job', 2);" ) );
$duplicate = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_jobs (job_id, hook) VALUES (8, 'duplicate_job')" ) );
$missing = $runtime->execute( new WP_Markdown_Query_Request( 'INSERT INTO wp_plugin_jobs (priority) VALUES (3)' ) );
$multi = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_jobs (hook) VALUES ('third'); INSERT INTO wp_plugin_jobs (hook) VALUES ('fourth')" ) );
$selected = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT * FROM wp_plugin_jobs ORDER BY job_id ASC' ) );
$reloaded = WP_Markdown_Native_Runtime_Factory::runtime( $root )->execute( new WP_Markdown_Query_Request( "SELECT hook, priority FROM wp_plugin_jobs WHERE job_id IN (1, 8) ORDER BY job_id ASC" ) );
$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_plugin_unique_names (id bigint unsigned NOT NULL auto_increment, name varchar(191) NOT NULL, PRIMARY KEY (id), UNIQUE KEY name (name))' ) );
$ascii_unique = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_unique_names (name) VALUES ('CaseSensitive')" ) );
$ascii_duplicate = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_unique_names (name) VALUES ('CaseSensitive')" ) );
$ascii_distinct = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_unique_names (name) VALUES ('casesensitive')" ) );
$unicode_unique = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_unique_names (name) VALUES ('Café')" ) );
$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_plugin_unique_prefix (id bigint unsigned NOT NULL auto_increment, name varchar(191) NOT NULL, PRIMARY KEY (id), UNIQUE KEY name (name(32)))' ) );
$prefix_unique = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_unique_prefix (name) VALUES ('prefix')" ) );
$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_plugin_unique_scope (id bigint unsigned NOT NULL auto_increment, agent_slug varchar(200) NOT NULL, owner_id bigint unsigned NOT NULL, instance_key_hash char(64) NOT NULL, PRIMARY KEY (id), UNIQUE KEY agent_identity_scope_hash (agent_slug, owner_id, instance_key_hash))' ) );
$scope = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_unique_scope (agent_slug, owner_id, instance_key_hash) VALUES ('admin', 1, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')" ) );
$scope_duplicate = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_unique_scope (agent_slug, owner_id, instance_key_hash) VALUES ('admin', 1, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')" ) );
$ascii_lookup = $runtime->execute( new WP_Markdown_Query_Request( "SELECT name FROM wp_plugin_unique_names WHERE name = 'CaseSensitive'" ) );
$unicode_lookup = $runtime->execute( new WP_Markdown_Query_Request( "SELECT name FROM wp_plugin_unique_names WHERE name = 'Café'" ) );
$scope_lookup = $runtime->execute( new WP_Markdown_Query_Request( "SELECT agent_slug FROM wp_plugin_unique_scope WHERE agent_slug = 'admin' AND owner_id = 1 AND instance_key_hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'" ) );

$checks = array(
	'generic INSERT fills defaults and applies MySQL zero auto-increment semantics' => 1 === $first->return_value()
		&& 1 === $first->wpdb_state()['insert_id']
		&& 1 === $first->wpdb_state()['rows_affected'],
	'explicit identities preserve WordPress mutation state' => 1 === $second->return_value()
		&& 8 === $second->wpdb_state()['insert_id'],
	'persisted rows retain schema order and typed values' => array( '1', '8' ) === array_map( static fn( object $row ): string => $row->job_id, $selected->wpdb_state()['last_result'] )
		&& '10' === $selected->wpdb_state()['last_result'][0]->priority
		&& null === $selected->wpdb_state()['last_result'][0]->payload,
	'unique conflicts and missing required columns fail without mutation' => false === $duplicate->return_value()
		&& 'duplicate_key' === ( $duplicate->diagnostic()['reason'] ?? null )
		&& false === $missing->return_value()
		&& 'missing_required_column' === ( $missing->diagnostic()['reason'] ?? null )
		&& 2 === count( json_decode( (string) file_get_contents( $root . '/_tables/plugin_jobs.json' ), true ) ),
	'multi-statement INSERT fails closed without temporary files' => false === $multi->return_value()
		&& 'unsupported_grammar' === ( $multi->diagnostic()['reason'] ?? null )
		&& array() === ( glob( $root . '/_tables/*.tmp-*' ) ?: array() ),
	'ASCII unique keys use exact identity' => 1 === $ascii_unique->return_value()
		&& false === $ascii_duplicate->return_value()
		&& 'duplicate_key' === ( $ascii_duplicate->diagnostic()['reason'] ?? null )
		&& 1 === $ascii_distinct->return_value(),
	'non-ASCII unique keys fail closed' => false === $unicode_unique->return_value()
		&& 'unsupported_unique_collation' === ( $unicode_unique->diagnostic()['reason'] ?? null ),
	'prefix unique keys still fail closed' => false === $prefix_unique->return_value()
		&& 'unsupported_unique_collation' === ( $prefix_unique->diagnostic()['reason'] ?? null ),
	'composite ASCII unique keys match WordPress identity scopes' => 1 === $scope->return_value()
		&& false === $scope_duplicate->return_value()
		&& 'duplicate_key' === ( $scope_duplicate->diagnostic()['reason'] ?? null ),
	'a unique varchar column is an equality lookup' => 1 === $ascii_lookup->return_value()
		&& 'CaseSensitive' === ( $ascii_lookup->wpdb_state()['last_result'][0]->name ?? null )
		&& false === $unicode_lookup->return_value()
		&& 'unsupported_lookup' === ( $unicode_lookup->diagnostic()['reason'] ?? null ),
	'a composite unique scope is an equality lookup' => 1 === $scope_lookup->return_value(),
	'inserted rows remain queryable after a cold runtime reload' => array( 'first_job', 'second_job' ) === array_map( static fn( object $row ): string => $row->hook, $reloaded->wpdb_state()['last_result'] ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	fwrite( $passed ? STDOUT : STDERR, ( $passed ? 'PASS' : 'FAIL' ) . ": {$label}\n" );
	$failed = $failed || ! $passed;
}

mdi_native_insert_remove_tree( $root );
exit( $failed ? 1 : 0 );
