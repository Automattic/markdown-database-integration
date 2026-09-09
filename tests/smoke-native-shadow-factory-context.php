<?php
/** Native shadow factory defers WordPress multisite context until query time. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
$root = sys_get_temp_dir() . '/mdi-shadow-factory-' . bin2hex( random_bytes( 6 ) );
define( 'MARKDOWN_DB_STATE_DIR', $root );
define( 'MARKDOWN_DB_CONTENT_DIR', $root );
mkdir( $root . '/_options', 0755, true );
mkdir( $root . '/_tables', 0755, true );
mkdir( $root . '/sites/2/_options', 0755, true );
file_put_contents( $root . '/_options/siteurl.json', json_encode( array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://network.example.test', 'autoload' => 'on' ), JSON_THROW_ON_ERROR ) );
file_put_contents( $root . '/sites/2/_options/siteurl.json', json_encode( array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://site-2.example.test', 'autoload' => 'on' ), JSON_THROW_ON_ERROR ) );
file_put_contents( $root . '/_tables/blogs.json', json_encode( array( array( 'blog_id' => '2', 'site_id' => '1', 'domain' => 'site-2.example.test', 'path' => '/', 'registered' => '2026-01-01 00:00:00', 'last_updated' => '2026-01-01 00:00:00', 'public' => '1', 'archived' => '0', 'mature' => '0', 'spam' => '0', 'deleted' => '0', 'lang_id' => '0' ) ), JSON_THROW_ON_ERROR ) );

require_once __DIR__ . '/../inc/native/class-wp-markdown-native-shadow-verifier.php';

final class MDI_Shadow_Context_Database {
	public string $prefix = 'wptests_';
	public string $base_prefix = 'wptests_';
	public int $num_rows = 1;
	public array $last_result = array();
	protected ?array $col_info = null;
	private array $columns = array();

	public function result( array $rows, array $columns ): void {
		$this->last_result = array_map( static fn( array $row ): object => (object) $row, $rows );
		$this->columns = array_map( static fn( array $column ): object => (object) $column, $columns );
	}

	public function get_col_info( string $field ): array {
		$this->col_info ??= $this->columns;
		return array_map( static fn( object $column ): mixed => $column->{$field} ?? null, $this->col_info );
	}
}

$database = new MDI_Shadow_Context_Database();
$GLOBALS['wpdb'] = $database;
$verifier = WP_Markdown_Native_Shadow_Factory::from_globals( $database );
define( 'MULTISITE', true );
$database->prefix = 'wptests_2_';
$database->result( array( array( 'blog_id' => '2' ) ), array( array( 'name' => 'blog_id', 'type' => '8' ) ) );
$verifier->observe( "SELECT wptests_blogs.blog_id FROM wptests_blogs WHERE domain = 'site-2.example.test' AND path = '/' ORDER BY wptests_blogs.blog_id ASC LIMIT 1", 1, $database );
$report = $verifier->report();

$checks = array(
	'factory resolves the active multisite prefix after db.php bootstrap' => 1 === $report['counts']['compatible'],
	'factory does not retain a verifier failure for the network-global blogs lookup' => 0 === $report['counts']['verifier_failures'],
);
$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	$failed += $passed ? 0 : 1;
}

function mdi_shadow_context_remove( string $path ): void {
	foreach ( scandir( $path ) ?: array() as $child ) {
		if ( '.' === $child || '..' === $child ) {
			continue;
		}
		$child = $path . '/' . $child;
		is_dir( $child ) ? mdi_shadow_context_remove( $child ) : unlink( $child );
	}
	rmdir( $path );
}
mdi_shadow_context_remove( $root );
exit( $failed ? 1 : 0 );
