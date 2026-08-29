<?php
/** A bounded read answers from the file without decoding the whole snapshot. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_native_streamed_remove_tree( string $root ): void {
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

/** @return array<int,array<string,mixed>> */
function mdi_native_streamed_rows( WP_Markdown_Query_Result $result ): array {
	$rows = $result->wpdb_state()['last_result'] ?? array();
	return is_array( $rows ) ? array_map( static fn( $row ): array => (array) $row, $rows ) : array();
}

$root = sys_get_temp_dir() . '/mdi-native-streamed-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
mkdir( $root . '/_options', 0755 );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_records (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, label varchar(40) NOT NULL, PRIMARY KEY (id))',
		'wp_'
	)
);
$values = array();
for ( $i = 1; $i <= 40; $i++ ) {
	$values[] = sprintf( "('row-%02d')", $i );
}
$runtime->execute(
	new WP_Markdown_Query_Request( 'INSERT INTO wp_plugin_records (label) VALUES ' . implode( ', ', $values ), 'wp_' )
);

$query = 'SELECT id, label FROM wp_plugin_records LIMIT 3';
$keyed = 'SELECT id, label FROM wp_plugin_records WHERE id = 7';

// A fresh runtime reads the file forwards and stops at the bound.
$streamed = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$streamed_rows = mdi_native_streamed_rows( $streamed->execute( new WP_Markdown_Query_Request( $query, 'wp_' ) ) );
$streamed_keyed = mdi_native_streamed_rows( $streamed->execute( new WP_Markdown_Query_Request( $keyed, 'wp_' ) ) );

// A descending read decodes the snapshot, so the same runtime then answers
// from memory and both routes must agree row for row.
$decoded = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$decoded->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_plugin_records ORDER BY id DESC LIMIT 1', 'wp_' ) );
$decoded_rows = mdi_native_streamed_rows( $decoded->execute( new WP_Markdown_Query_Request( $query, 'wp_' ) ) );
$decoded_keyed = mdi_native_streamed_rows( $decoded->execute( new WP_Markdown_Query_Request( $keyed, 'wp_' ) ) );

// A snapshot the file does not already hold in order still reads correctly.
$path = $root . '/_tables/plugin_records.json';
$shuffled = json_decode( (string) file_get_contents( $path ), true );
$shuffled = array_reverse( $shuffled );
file_put_contents( $path, json_encode( $shuffled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
$reversed = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$reversed_rows = mdi_native_streamed_rows( $reversed->execute( new WP_Markdown_Query_Request( $query, 'wp_' ) ) );

$expected = array(
	array( 'id' => '1', 'label' => 'row-01' ),
	array( 'id' => '2', 'label' => 'row-02' ),
	array( 'id' => '3', 'label' => 'row-03' ),
);

$checks = array(
	'a bounded read returns the rows the bound asked for' => $expected === $streamed_rows,
	'a bounded read agrees with the decoded snapshot' => $decoded_rows === $streamed_rows,
	'a keyed read returns its row' => array( array( 'id' => '7', 'label' => 'row-07' ) ) === $streamed_keyed,
	'a keyed read agrees with the decoded snapshot' => $decoded_keyed === $streamed_keyed,
	'a file held out of order still reads in order' => $expected === $reversed_rows,
);

mdi_native_streamed_remove_tree( $root );

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
