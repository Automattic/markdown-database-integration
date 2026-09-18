<?php
/** DELETE against canonical row-partitioned generic tables. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'wordpress' );

require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_partition_delete_rm( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $path );
}

function mdi_partition_delete_write( string $generation, string $identity, array $row ): string {
	$path = $generation . '/' . hash( 'sha256', $identity ) . '.json';
	file_put_contents(
		$path,
		json_encode(
			array(
				'_mdi_partition' => array( 'version' => 1, 'identity_column' => 'job_id', 'identity' => $identity ),
				'row'            => $row,
			),
			JSON_THROW_ON_ERROR
		)
	);
	return $path;
}

$root = sys_get_temp_dir() . '/mdi-native-partition-delete-' . bin2hex( random_bytes( 6 ) );
$generation_name = 'generation-' . str_repeat( 'a', 24 );
$generation = $root . '/_tables/jobs/' . $generation_name;
if ( ! mkdir( $root . '/_schema', 0755, true ) || ! mkdir( $generation, 0755, true ) ) {
	throw new RuntimeException( 'Failed to create the partitioned DELETE fixture.' );
}
file_put_contents(
	$root . '/_schema/jobs.sql',
	'CREATE TABLE wp_jobs (job_id bigint(20) unsigned NOT NULL, status varchar(32) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (job_id));'
);
file_put_contents(
	$root . '/_tables/jobs/.mdi-partition.json',
	json_encode( array( 'version' => 1, 'table' => 'jobs', 'identity_column' => 'job_id', 'generation' => $generation_name ), JSON_THROW_ON_ERROR )
);
$paths = array(
	'1' => mdi_partition_delete_write( $generation, '1', array( 'job_id' => 1, 'status' => 'completed', 'created_at' => '2020-01-01 00:00:00' ) ),
	'2' => mdi_partition_delete_write( $generation, '2', array( 'job_id' => 2, 'status' => 'completed', 'created_at' => '2020-01-02 00:00:00' ) ),
	'3' => mdi_partition_delete_write( $generation, '3', array( 'job_id' => 3, 'status' => 'pending', 'created_at' => '2026-01-01 00:00:00' ) ),
	'4' => mdi_partition_delete_write( $generation, '4', array( 'job_id' => 4, 'status' => 'completed', 'created_at' => '2020-01-03 00:00:00' ) ),
	'5' => mdi_partition_delete_write( $generation, '5', array( 'job_id' => 5, 'status' => 'completed', 'created_at' => '2022-01-01 00:00:00' ) ),
);

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$identity_delete = $runtime->execute( new WP_Markdown_Query_Request( 'DELETE FROM wp_jobs WHERE job_id = 2', 'wp_' ) );
$after_identity_files = array(
	'1' => file_exists( $paths['1'] ),
	'2' => file_exists( $paths['2'] ),
	'3' => file_exists( $paths['3'] ),
	'4' => file_exists( $paths['4'] ),
	'5' => file_exists( $paths['5'] ),
);
$after_identity = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT job_id FROM wp_jobs ORDER BY job_id ASC', 'wp_' ) );
$missing_delete = $runtime->execute( new WP_Markdown_Query_Request( 'DELETE FROM wp_jobs WHERE job_id = 2', 'wp_' ) );
$predicate_delete = $runtime->execute(
	new WP_Markdown_Query_Request( "DELETE FROM wp_jobs WHERE status = 'completed' AND created_at < '2021-01-01 00:00:00'", 'wp_' )
);
$after_predicate_files = array(
	'1' => file_exists( $paths['1'] ),
	'3' => file_exists( $paths['3'] ),
	'4' => file_exists( $paths['4'] ),
	'5' => file_exists( $paths['5'] ),
);
$after_predicate = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT job_id FROM wp_jobs ORDER BY job_id ASC', 'wp_' ) );
$bounded_delete = $runtime->execute(
	new WP_Markdown_Query_Request( "DELETE FROM wp_jobs WHERE status = 'completed' LIMIT 1", 'wp_' )
);
$after_bounded = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT job_id FROM wp_jobs ORDER BY job_id ASC', 'wp_' ) );

$after_identity_ids = array_map( static fn( object $row ): string => (string) $row->job_id, $after_identity->wpdb_state()['last_result'] );
$after_predicate_ids = array_map( static fn( object $row ): string => (string) $row->job_id, $after_predicate->wpdb_state()['last_result'] );
$after_bounded_ids = array_map( static fn( object $row ): string => (string) $row->job_id, $after_bounded->wpdb_state()['last_result'] );

$checks = array(
	'delete by identity on a partitioned table removes exactly that row and returns 1' => 1 === $identity_delete->return_value()
		&& false === $after_identity_files['2']
		&& true === $after_identity_files['1']
		&& true === $after_identity_files['3']
		&& true === $after_identity_files['4']
		&& true === $after_identity_files['5'],
	'a row deleted from a partitioned table is absent from subsequent SELECTs' => array( '1', '3', '4', '5' ) === $after_identity_ids,
	'deleting a non-existent row returns 0, not false' => 0 === $missing_delete->return_value()
		&& false !== $missing_delete->return_value()
		&& $missing_delete->succeeded(),
	'predicate delete removes all matching rows and returns an accurate count' => 2 === $predicate_delete->return_value()
		&& false === $after_predicate_files['1']
		&& false === $after_predicate_files['4']
		&& true === $after_predicate_files['3']
		&& true === $after_predicate_files['5']
		&& array( '3', '5' ) === $after_predicate_ids,
	'bounded predicate delete returns the limited affected count' => 1 === $bounded_delete->return_value()
		&& array( '3' ) === $after_bounded_ids
		&& file_exists( $paths['3'] )
		&& ! file_exists( $paths['5'] ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

mdi_partition_delete_rm( $root );
exit( $failed ? 1 : 0 );
