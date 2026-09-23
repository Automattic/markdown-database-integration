<?php
/**
 * Option insert identifier allocation without a full store scan.
 *
 * Every new option used to read every canonical option row under the exclusive
 * mutation lock to find max(option_id) + 1, so inserts cost time proportional
 * to the whole store (0.7-1.1 s on a 7,600-option site) and stalled all other
 * writers. Inserts now read one allocator record and only the filenames that
 * could hold a collated duplicate. These checks pin the correctness edges:
 * identifiers stay unique and monotonic across deletes, external writers,
 * corrupt records, and rolled-back transactions.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-option-allocator-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the option allocator fixture.' );
}
$seed = static function ( string $name, int $id ) use ( $root ): void {
	file_put_contents(
		$root . '/_options/' . WP_Markdown_Canonical_Option_Path::filename( $name ),
		json_encode( array( 'option_id' => $id, 'option_name' => $name, 'option_value' => 'seed', 'autoload' => 'off' ) )
	);
};
$seed( 'siteurl', 1 );
$seed( 'home', 40 );
$seed( 'Spaced Option', 12 ); // A hashed canonical filename.

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$run     = static fn( string $sql ): WP_Markdown_Query_Result => $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
$insert  = static fn( string $name ): WP_Markdown_Query_Result => $run( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('{$name}', 'x', 'off')" );
$id_of   = static fn( WP_Markdown_Query_Result $result ): int => (int) ( $result->wpdb_state()['insert_id'] ?? 0 );
$record  = static fn(): ?array => json_decode( (string) @file_get_contents( $root . '/_indexes/option-id-allocator.json' ), true );
$ids     = static function () use ( $root ): array {
	$ids = array();
	foreach ( glob( $root . '/_options/*.json' ) ?: array() as $path ) {
		$ids[] = (int) ( json_decode( (string) file_get_contents( $path ), true )['option_id'] ?? 0 );
	}
	sort( $ids );
	return $ids;
};

$first   = $insert( 'alpha' );
$seeded  = $record();
$second  = $insert( 'beta' );
$deleted = $run( "DELETE FROM wp_options WHERE option_name = 'beta'" );
$after_delete_record = $record();
$third   = $insert( 'gamma' );

// Another writer adds a row with a higher identifier behind the allocator's back.
$seed( 'external', 90 );
$after_external = $insert( 'delta' );

// A corrupt record is ignored rather than trusted.
file_put_contents( $root . '/_indexes/option-id-allocator.json', '{"version":1,"high_water":"nope"' );
$after_corrupt = $insert( 'epsilon' );

// A rolled-back insert leaves the file count behind the record.
$run( 'START TRANSACTION' );
$rolled_back_insert = $insert( 'rolled_back' );
$run( 'ROLLBACK' );
$after_rollback = $insert( 'zeta' );

// Collated duplicates are still found under a noncanonical (hashed) filename.
$duplicate    = $insert( 'SPACED OPTION' );
$upsert       = $run( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('spaced option', 'updated', 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)" );
$spaced_value = $run( "SELECT option_id, option_value FROM wp_options WHERE option_name = 'Spaced Option'" )->wpdb_state()['last_result'][0] ?? null;

$all_ids = $ids();

$checks = array(
	'the first insert seeds the allocator from a full scan'          => 41 === $id_of( $first ) && 41 === ( $seeded['high_water'] ?? null ) && 4 === ( $seeded['count'] ?? null ),
	'later inserts continue from the recorded high-water mark'        => 42 === $id_of( $second ),
	'a delete keeps the record trusted and identifiers monotonic'     => 1 === $deleted->return_value() && 42 === ( $after_delete_record['high_water'] ?? null ) && 43 === $id_of( $third ),
	'an external higher identifier forces a rescan, never a duplicate' => 91 === $id_of( $after_external ),
	'a corrupt allocator record is ignored'                          => 92 === $id_of( $after_corrupt ),
	'a rolled-back insert cannot cause identifier reuse'              => 93 === $id_of( $rolled_back_insert ) && 94 === $id_of( $after_rollback ),
	'collated duplicates under hashed filenames are rejected'         => false === $duplicate->return_value() && 'duplicate_key' === ( $duplicate->diagnostic()['reason'] ?? null ),
	'collated upserts still find the hashed row and fail closed'       => false === $upsert->return_value() && 'noncanonical_option_identity' === ( $upsert->diagnostic()['reason'] ?? null ) && 'seed' === ( $spaced_value->option_value ?? null ) && '12' === (string) ( $spaced_value->option_id ?? '' ),
	'every stored identifier is unique'                               => count( $all_ids ) === count( array_unique( $all_ids ) ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}
if ( $failed ) {
	echo 'ids: ', implode( ',', $all_ids ), "\n", 'record: ', json_encode( $record() ), "\n";
	foreach ( array( 'first' => $first, 'second' => $second, 'third' => $third, 'external' => $after_external, 'corrupt' => $after_corrupt, 'rolled_back' => $rolled_back_insert, 'rollback' => $after_rollback ) as $k => $r ) { echo "  $k id=", $id_of( $r ), ' ', $r->succeeded() ? '' : json_encode( $r->diagnostic() ), "\n"; }
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $files as $file ) {
	$file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
}
@rmdir( $root );

exit( $failed ? 1 : 0 );
