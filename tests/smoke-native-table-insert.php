<?php
/** Generic persisted snapshot INSERT behavior. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-query-runtime.php';

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
$ambiguous_unique = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_unique_names (name) VALUES ('CaseSensitive')" ) );

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
	'string unique keys fail closed until exact collation semantics are retained' => false === $ambiguous_unique->return_value()
		&& 'unsupported_unique_collation' === ( $ambiguous_unique->diagnostic()['reason'] ?? null ),
	'inserted rows remain queryable after a cold runtime reload' => array( 'first_job', 'second_job' ) === array_map( static fn( object $row ): string => $row->hook, $reloaded->wpdb_state()['last_result'] ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	fwrite( $passed ? STDOUT : STDERR, ( $passed ? 'PASS' : 'FAIL' ) . ": {$label}\n" );
	$failed = $failed || ! $passed;
}

mdi_native_insert_remove_tree( $root );
exit( $failed ? 1 : 0 );
