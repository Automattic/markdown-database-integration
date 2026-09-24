<?php
/**
 * A reused option snapshot stays correct while being cheap to validate.
 *
 * The snapshot used to be validated by hashing every option file and was fully
 * rebuilt after any single change. It is now validated by lstat identity and
 * the catalogue re-reads only changed files. These checks pin freshness: every
 * kind of change, including ones made outside MDI, is visible on the next read
 * of the same runtime, and the catalogue is brought up to date.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-option-snapshot-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the option snapshot fixture.' );
}
$write = static function ( string $name, int $id, string $value, string $autoload = 'on' ) use ( $root ): string {
	$path = $root . '/_options/' . WP_Markdown_Canonical_Option_Path::filename( $name );
	file_put_contents( $path, json_encode( array( 'option_id' => $id, 'option_name' => $name, 'option_value' => $value, 'autoload' => $autoload ) ) );
	return $path;
};
for ( $i = 1; $i <= 40; $i++ ) {
	$write( "opt_{$i}", $i, "v{$i}", 0 === $i % 3 ? 'off' : 'on' );
}

$runtime  = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_' );
$autoload = static function () use ( $runtime ): array {
	$result = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_name, option_value FROM wp_options WHERE autoload IN ( 'yes', 'on', 'auto-on', 'auto' )", 'wp_' ) );
	$out    = array();
	foreach ( $result->wpdb_state()['last_result'] ?? array() as $row ) {
		$out[ (string) $row->option_name ] = (string) $row->option_value;
	}
	return $out;
};
$catalogue_entry = static function ( string $name ) use ( $root ): ?array {
	$decoded = json_decode( (string) @file_get_contents( $root . '/_indexes/options.json' ), true );
	foreach ( (array) ( $decoded['entries'] ?? array() ) as $entry ) {
		if ( WP_Markdown_Canonical_Option_Path::filename( $name ) === ( $entry['filename'] ?? null ) ) {
			return $entry;
		}
	}
	return null;
};

$initial   = $autoload();
$repeat    = $autoload();

// Through MDI: an UPDATE is visible on the next read of the same runtime.
$runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_options SET option_value = 'mdi-updated' WHERE option_name = 'opt_1'", 'wp_' ) );
$after_update = $autoload();
$entry_after_update = $catalogue_entry( 'opt_1' );

// Outside MDI: an in-place edit, a new file, a deletion, and an autoload flip.
sleep( 1 ); // Let mtime advance so an in-place edit is detectable by identity.
$edited = $root . '/_options/' . WP_Markdown_Canonical_Option_Path::filename( 'opt_2' );
file_put_contents( $edited, json_encode( array( 'option_id' => 2, 'option_name' => 'opt_2', 'option_value' => 'edited-outside', 'autoload' => 'on' ) ) );
$write( 'opt_new', 99, 'added-outside', 'on' );
unlink( $root . '/_options/' . WP_Markdown_Canonical_Option_Path::filename( 'opt_4' ) );
$write( 'opt_3', 3, 'v3', 'on' ); // was autoload off
$after_external = $autoload();

$checks = array(
	'the initial snapshot returns only autoloaded options'   => 27 === count( $initial ) && ! isset( $initial['opt_3'] ) && 'v1' === ( $initial['opt_1'] ?? null ),
	'a reused snapshot returns the same rows'                => $initial === $repeat,
	'an UPDATE through MDI is visible on the next read'      => 'mdi-updated' === ( $after_update['opt_1'] ?? null ),
	'the catalogue is refreshed for the changed file'        => is_array( $entry_after_update ) && 'mdi-updated' === ( $entry_after_update['row']['option_value'] ?? null ),
	'an in-place edit outside MDI is visible'                => 'edited-outside' === ( $after_external['opt_2'] ?? null ),
	'a file added outside MDI is visible'                    => 'added-outside' === ( $after_external['opt_new'] ?? null ),
	'a file deleted outside MDI disappears'                  => ! isset( $after_external['opt_4'] ),
	'an autoload change outside MDI is visible'              => 'v3' === ( $after_external['opt_3'] ?? null ),
	'the catalogue drops deleted files'                      => null === $catalogue_entry( 'opt_4' ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $files as $file ) {
	$file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
}
@rmdir( $root );

exit( $failed ? 1 : 0 );
