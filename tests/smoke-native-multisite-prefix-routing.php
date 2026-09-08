<?php
/** Multisite prefix routing keeps site state separate from network-global state. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-multisite-prefix-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0755, true );
mkdir( $root . '/_tables', 0755, true );
mkdir( $root . '/sites/2/_options', 0755, true );
mkdir( $root . '/sites/2/_tables', 0755, true );
file_put_contents( $root . '/_options/siteurl.json', json_encode( array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://network.example.test', 'autoload' => 'on' ), JSON_THROW_ON_ERROR ) );
file_put_contents( $root . '/sites/2/_options/siteurl.json', json_encode( array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://site-2.example.test', 'autoload' => 'on' ), JSON_THROW_ON_ERROR ) );
file_put_contents( $root . '/_tables/blogs.json', json_encode( array( array( 'blog_id' => '2', 'site_id' => '1', 'domain' => 'site-2.example.test', 'path' => '/', 'registered' => '2026-01-01 00:00:00', 'last_updated' => '2026-01-01 00:00:00', 'public' => '1', 'archived' => '0', 'mature' => '0', 'spam' => '0', 'deleted' => '0', 'lang_id' => '0' ) ), JSON_THROW_ON_ERROR ) );

$runtime = WP_Markdown_Native_Runtime_Factory::multisite_runtime( $root, 'wp_' );
$site = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_2_options WHERE option_name = 'siteurl'", 'wp_2_' ) );
$network = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT blog_id FROM wp_blogs WHERE blog_id = 2', 'wp_2_' ) );
$create = $runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_2_probe (id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))', 'wp_2_' ) );
$tables = $runtime->execute( new WP_Markdown_Query_Request( "SHOW TABLES LIKE 'wp\\_2\\_%'", 'wp_2_' ) );
$invalid = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT option_value FROM wp_1_options', 'wp_1_' ) );

$listed = array_map( static fn( object $row ): string => (string) array_values( get_object_vars( $row ) )[0], $tables->wpdb_state()['last_result'] );
$checks = array(
	'switched blog reads its site-local option root' => 'https://site-2.example.test' === ( $site->wpdb_state()['last_result'][0]->option_value ?? null ),
	'switched blog retains network-global table access at the base root' => '2' === ( $network->wpdb_state()['last_result'][0]->blog_id ?? null ),
	'site-local CREATE TABLE registers and SHOW TABLES enumerates the scoped schema' => $create->succeeded() && in_array( 'wp_2_probe', $listed, true ),
	'non-WordPress table prefixes fail closed' => ! $invalid->succeeded() && 'unsupported_table_prefix' === ( $invalid->diagnostic()['reason'] ?? null ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	$failed += $passed ? 0 : 1;
}

function mdi_native_multisite_remove( string $path ): void {
	foreach ( scandir( $path ) ?: array() as $child ) {
		if ( '.' === $child || '..' === $child ) {
			continue;
		}
		$child = $path . '/' . $child;
		is_dir( $child ) ? mdi_native_multisite_remove( $child ) : unlink( $child );
	}
	rmdir( $path );
}
mdi_native_multisite_remove( $root );
exit( $failed ? 1 : 0 );
