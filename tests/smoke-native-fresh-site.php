<?php
/** Canonical storage reports core tables only once it holds a site. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_native_fresh_site_remove_tree( string $root ): void {
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
function mdi_native_fresh_site_rows( WP_Markdown_Query_Result $result ): array {
	$rows = $result->wpdb_state()['last_result'] ?? array();
	return is_array( $rows ) ? $rows : array();
}

$root = sys_get_temp_dir() . '/mdi-native-fresh-site-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );

// An untouched directory holds no site, so WordPress may install one here.
$empty = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$fresh_describe = $empty->execute( new WP_Markdown_Query_Request( 'DESCRIBE wp_terms;', 'wp_' ) );
$fresh_listed = $empty->execute( new WP_Markdown_Query_Request( "SHOW TABLES LIKE 'wp_terms'", 'wp_' ) );

// Writing the first option is what brings a site into being.
$installed = $empty->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('siteurl', 'http://example.test', 'yes')",
		'wp_'
	)
);
$store_created = is_dir( $root . '/_options' );

// A later boot finds the site that was installed into the directory.
$booted = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$site_describe = $booted->execute( new WP_Markdown_Query_Request( 'DESCRIBE wp_terms;', 'wp_' ) );
$site_listed = $booted->execute( new WP_Markdown_Query_Request( "SHOW TABLES LIKE 'wp_terms'", 'wp_' ) );
$read_back = $booted->execute(
	new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'", 'wp_' )
);

$checks = array(
	'an empty directory describes no core table' => array() === mdi_native_fresh_site_rows( $fresh_describe ),
	'an empty directory lists no core table' => array() === mdi_native_fresh_site_rows( $fresh_listed ),
	'the first option write succeeds' => 1 === $installed->return_value(),
	'the first option write creates the canonical store' => true === $store_created,
	'an installed directory describes its core tables' => array() !== mdi_native_fresh_site_rows( $site_describe ),
	'an installed directory lists its core tables' => array( array( 'Tables_in_' => 'wp_terms' ) ) === array_map(
		static fn( $row ): array => (array) $row,
		mdi_native_fresh_site_rows( $site_listed )
	),
	'the installed option reads back' => 'http://example.test' === ( ( (array) ( mdi_native_fresh_site_rows( $read_back )[0] ?? array() ) )['option_value'] ?? null ),
);

mdi_native_fresh_site_remove_tree( $root );

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
