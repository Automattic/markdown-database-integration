<?php
/** Independent collection of inactive partitioned-table generations. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';
require_once __DIR__ . '/../inc/class-wp-markdown-cli.php';

function mdi_generation_collection_rm( string $path ): void {
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

function mdi_generation_payload( string $directory, string $name, string $contents ): string {
	$path = $directory . '/' . $name;
	file_put_contents( $path, $contents );
	return $path;
}

$root = sys_get_temp_dir() . '/mdi-partition-generation-collection-' . bin2hex( random_bytes( 6 ) );
$active = 'generation-' . str_repeat( 'a', 24 );
$orphan_full = 'generation-' . str_repeat( 'b', 24 );
$orphan_partial = 'generation-' . str_repeat( 'c', 24 );
$orphan_empty = 'generation-' . str_repeat( 'd', 24 );
$orphan_tmp = 'generation-' . str_repeat( '9', 24 );
$jobs = $root . '/_tables/jobs';
foreach ( array( $active, $orphan_full, $orphan_partial, $orphan_empty, $orphan_tmp ) as $generation ) {
	if ( ! mkdir( $jobs . '/' . $generation, 0755, true ) ) {
		throw new RuntimeException( 'Failed to create the generation collection fixture.' );
	}
}
file_put_contents(
	$jobs . '/.mdi-partition.json',
	json_encode( array( 'version' => 1, 'table' => 'jobs', 'identity_column' => 'job_id', 'generation' => $active ), JSON_THROW_ON_ERROR )
);
$active_payload = '{"row":1}';
$orphan_full_a = str_repeat( 'a', 40 );
$orphan_full_b = str_repeat( 'b', 25 );
$orphan_partial_payload = str_repeat( 'c', 17 );
mdi_generation_payload( $jobs . '/' . $active, hash( 'sha256', '1' ) . '.json', $active_payload );
mdi_generation_payload( $jobs . '/' . $orphan_full, hash( 'sha256', '1' ) . '.json', $orphan_full_a );
mdi_generation_payload( $jobs . '/' . $orphan_full, hash( 'sha256', '2' ) . '.json', $orphan_full_b );
mdi_generation_payload( $jobs . '/' . $orphan_partial, hash( 'sha256', '1' ) . '.json', $orphan_partial_payload );
// An interrupted write leaves `<sha256>.json.tmp.<pid>.<suffix>` behind. Collection must
// remove it too: a bare `*.json` sweep strands the temp file, rmdir() then fails, and the
// generation is retained forever.
$orphan_tmp_payload = str_repeat( 't', 23 );
mdi_generation_payload( $jobs . '/' . $orphan_tmp, hash( 'sha256', '1' ) . '.json.tmp.40311.3871b3f7', $orphan_tmp_payload );
$expected_bytes = strlen( $orphan_full_a ) + strlen( $orphan_full_b ) + strlen( $orphan_partial_payload ) + strlen( $orphan_tmp_payload );

$malformed = $root . '/_tables/malformed';
mkdir( $malformed . '/generation-' . str_repeat( 'e', 24 ), 0755, true );
file_put_contents( $malformed . '/.mdi-partition.json', '{not-json' );
file_put_contents( $malformed . '/generation-' . str_repeat( 'e', 24 ) . '/keep.json', str_repeat( 'z', 12 ) );

$missing = $root . '/_tables/missing';
mkdir( $missing . '/generation-' . str_repeat( 'f', 24 ), 0755, true );
file_put_contents( $missing . '/generation-' . str_repeat( 'f', 24 ) . '/keep.json', str_repeat( 'y', 8 ) );

$dry = WP_Markdown_CLI::collect_partition_generations(
	array(
		'state_dir' => $root,
		'table'     => 'jobs',
		'dry_run'   => true,
	)
);
$dry_jobs = $dry['tables'][0] ?? array();
$dry_active_survives = is_dir( $jobs . '/' . $active ) && is_file( $jobs . '/' . $active . '/' . hash( 'sha256', '1' ) . '.json' );
$dry_orphans_remain = is_dir( $jobs . '/' . $orphan_full ) && is_dir( $jobs . '/' . $orphan_partial ) && is_dir( $jobs . '/' . $orphan_empty ) && is_dir( $jobs . '/' . $orphan_tmp );

$collected = WP_Markdown_Canonical_Persistence::collect_partition_generations( $root, false, 'jobs' );
$collected_jobs = $collected['tables'][0] ?? array();

$malformed_report = WP_Markdown_Canonical_Persistence::collect_partition_generations( $root, false, 'malformed' );
$missing_report = WP_Markdown_Canonical_Persistence::collect_partition_generations( $root, false, 'missing' );

$checks = array(
	'dry-run collection removes nothing and reports accurate counts and byte totals' => true === ( $dry['dry_run'] ?? null )
		&& 'dry_run' === ( $dry_jobs['status'] ?? null )
		&& 4 === (int) ( $dry_jobs['generations'] ?? -1 )
		&& 4 === (int) ( $dry_jobs['files'] ?? -1 )
		&& $expected_bytes === (int) ( $dry_jobs['bytes'] ?? -1 )
		&& 4 === (int) ( $dry['generations'] ?? -1 )
		&& 4 === (int) ( $dry['files'] ?? -1 )
		&& $expected_bytes === (int) ( $dry['bytes'] ?? -1 )
		&& $dry_active_survives
		&& $dry_orphans_remain,
	'orphaned generations are collected while the marker\'s active generation survives' => 'collected' === ( $collected_jobs['status'] ?? null )
		&& 4 === (int) ( $collected_jobs['generations'] ?? -1 )
		&& 4 === (int) ( $collected_jobs['files'] ?? -1 )
		&& $expected_bytes === (int) ( $collected_jobs['bytes'] ?? -1 )
		&& is_dir( $jobs . '/' . $active )
		&& is_file( $jobs . '/' . $active . '/' . hash( 'sha256', '1' ) . '.json' )
		&& is_file( $jobs . '/.mdi-partition.json' )
		&& ! is_dir( $jobs . '/' . $orphan_full )
		&& ! is_dir( $jobs . '/' . $orphan_partial )
		&& ! is_dir( $jobs . '/' . $orphan_empty ),
	'a generation holding only an interrupted write\'s .json.tmp file is collected' => ! is_dir( $jobs . '/' . $orphan_tmp ),
	'a malformed or absent marker collects nothing and reports the condition' => 'malformed_marker' === ( $malformed_report['tables'][0]['status'] ?? null )
		&& 0 === (int) ( $malformed_report['generations'] ?? -1 )
		&& 0 === (int) ( $malformed_report['files'] ?? -1 )
		&& 0 === (int) ( $malformed_report['bytes'] ?? -1 )
		&& is_file( $malformed . '/generation-' . str_repeat( 'e', 24 ) . '/keep.json' )
		&& 'missing_marker' === ( $missing_report['tables'][0]['status'] ?? null )
		&& 0 === (int) ( $missing_report['generations'] ?? -1 )
		&& is_file( $missing . '/generation-' . str_repeat( 'f', 24 ) . '/keep.json' ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

mdi_generation_collection_rm( $root );
exit( $failed ? 1 : 0 );
