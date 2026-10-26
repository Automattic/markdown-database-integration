<?php
/** A legacy row-partitioned table folds into a writable snapshot on first bootstrap. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'wordpress' );

require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-partition-fold-' . bin2hex( random_bytes( 6 ) );
$generation_name = 'generation-' . str_repeat( 'a', 24 );
$generation = $root . '/_tables/jobs/' . $generation_name;
mkdir( $root . '/_schema', 0755, true );
mkdir( $generation, 0755, true );
// The schema gained status_reason after the partition rows were written, as Data Machine's did.
file_put_contents( $root . '/_schema/jobs.sql', "CREATE TABLE wp_jobs (job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, status varchar(32) NOT NULL, status_reason longtext NULL, attempts int NOT NULL DEFAULT 0, PRIMARY KEY (job_id));" );
file_put_contents( $root . '/_tables/jobs/.mdi-partition.json', json_encode( array( 'version' => 1, 'table' => 'jobs', 'identity_column' => 'job_id', 'generation' => $generation_name ) ) );
foreach ( array( 10 => 'completed', 2 => 'pending', 3 => 'completed' ) as $id => $status ) {
	file_put_contents(
		$generation . '/' . hash( 'sha256', (string) $id ) . '.json',
		json_encode( array( '_mdi_partition' => array( 'version' => 1, 'identity_column' => 'job_id', 'identity' => (string) $id ), 'row' => array( 'job_id' => $id, 'status' => $status ) ) )
	);
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$results = array();
foreach ( array(
	'select'  => 'SELECT job_id, status, status_reason, attempts FROM wp_jobs ORDER BY job_id',
	'insert'  => "INSERT INTO wp_jobs (status) VALUES ('pending')",
	'update'  => "UPDATE wp_jobs SET status = 'failed', status_reason = 'timeout' WHERE job_id = 2",
	'delete'  => 'DELETE FROM wp_jobs WHERE job_id = 3',
	'alter'   => 'ALTER TABLE wp_jobs ADD COLUMN owner_id bigint(20) unsigned NOT NULL DEFAULT 7',
	'after'   => 'SELECT job_id, status, status_reason, owner_id FROM wp_jobs ORDER BY job_id',
) as $name => $sql ) {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
	$results[ $name ] = null === $result->diagnostic() ? json_decode( json_encode( $result->wpdb_state()['last_result'] ?? array() ), true ) : $result->diagnostic();
}
$fresh = WP_Markdown_Native_Runtime_Factory::runtime( $root )->execute( new WP_Markdown_Query_Request( 'SELECT COUNT(*) AS n FROM wp_jobs', 'wp_' ) );

$checks = array(
	'rows fold with new columns defaulted' => array(
		array( 'job_id' => '2', 'status' => 'pending', 'status_reason' => null, 'attempts' => '0' ),
		array( 'job_id' => '3', 'status' => 'completed', 'status_reason' => null, 'attempts' => '0' ),
		array( 'job_id' => '10', 'status' => 'completed', 'status_reason' => null, 'attempts' => '0' ),
	) === $results['select'],
	'partition directory is replaced by the snapshot' => ! file_exists( $root . '/_tables/jobs' ) && is_file( $root . '/_tables/jobs.json' ),
	'folded table accepts INSERT, UPDATE, DELETE and ALTER' => array(
		array( 'job_id' => '2', 'status' => 'failed', 'status_reason' => 'timeout', 'owner_id' => '7' ),
		array( 'job_id' => '10', 'status' => 'completed', 'status_reason' => null, 'owner_id' => '7' ),
		array( 'job_id' => '11', 'status' => 'pending', 'status_reason' => null, 'owner_id' => '7' ),
	) === $results['after'],
	'a fresh bootstrap reads the folded snapshot' => '3' === ( $fresh->wpdb_state()['last_result'][0]->n ?? null ),
);

$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $entries as $entry ) {
	$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
}
rmdir( $root );

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ), ": {$label}\n";
	$failed = $failed || ! $passed;
}
if ( $failed ) {
	echo json_encode( $results, JSON_PRETTY_PRINT ), "\n";
	exit( 1 );
}
