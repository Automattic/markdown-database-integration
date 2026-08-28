<?php
/** Server-level introspection reports only what a file-backed engine can answer. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'canonical_probe' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-server-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$named = $runtime->execute(
	new WP_Markdown_Query_Request( "SHOW VARIABLES WHERE Variable_name IN ( 'version', 'sql_mode', 'max_allowed_packet' )", 'wp_' )
);
$variables = array();
foreach ( $named->wpdb_state()['last_result'] as $row ) {
	$variables[ (string) $row->Variable_name ] = (string) $row->Value;
}
$liked = $runtime->execute( new WP_Markdown_Query_Request( "SHOW VARIABLES LIKE 'character_set_%'", 'wp_' ) );
$liked_names = array_map( static fn( object $row ): string => (string) $row->Variable_name, $liked->wpdb_state()['last_result'] );
$status = $runtime->execute( new WP_Markdown_Query_Request( "SHOW GLOBAL STATUS LIKE 'Uptime'", 'wp_' ) );
$database = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT DATABASE()', 'wp_' ) );
$columns = array_map( static fn( object $column ): string => $column->name, $named->wpdb_state()['col_info'] );

$checks = array(
	'named variables report engine identity' => '0.0.0-mdi-native' === ( $variables['version'] ?? null )
		&& array_key_exists( 'sql_mode', $variables ),
	'a client/server tuning knob is absent rather than invented' => ! array_key_exists( 'max_allowed_packet', $variables ),
	'LIKE selects matching variables' => array( 'character_set_server' ) === $liked_names,
	'SHOW STATUS answers with a scoped qualifier' => array( 'Uptime' ) === array_map(
		static fn( object $row ): string => (string) $row->Variable_name,
		$status->wpdb_state()['last_result']
	),
	'SELECT DATABASE() names the canonical store' => 'canonical_probe' === (string) ( $database->wpdb_state()['last_result'][0]->{'DATABASE()'} ?? '' ),
	'variable rows use the MySQL column shape' => array( 'Variable_name', 'Value' ) === $columns,
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_options/*' ) ?: array() );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
