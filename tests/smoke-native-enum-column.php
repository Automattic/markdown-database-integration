<?php
/** ENUM and SET columns compile, persist, and filter as strings. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-enum-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$created = $runtime->execute(
	new WP_Markdown_Query_Request(
		"CREATE TABLE wp_plugin_secrets (\n  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,\n  `user_id` bigint(20) unsigned NOT NULL,\n  `secret` tinyblob NOT NULL,\n  `mode` enum('authenticator','recovery') NOT NULL DEFAULT 'authenticator',\n  PRIMARY KEY (`id`),\n  KEY `mode` (`mode`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8",
		'wp_'
	)
);
$defaulted = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_secrets (user_id, secret) VALUES (1, 'abc')", 'wp_' ) );
$explicit = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_plugin_secrets (user_id, secret, mode) VALUES (2, 'def', 'recovery')", 'wp_' ) );
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id, mode FROM wp_plugin_secrets WHERE id = 1', 'wp_' ) );
$filtered = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_plugin_secrets WHERE mode = 'recovery'", 'wp_' ) );
$described = $runtime->execute( new WP_Markdown_Query_Request( 'DESCRIBE wp_plugin_secrets', 'wp_' ) );
$types = array();
foreach ( $described->wpdb_state()['last_result'] as $row ) {
	$types[ (string) $row->Field ] = (string) $row->Type;
}

$checks = array(
	'an ENUM column compiles' => false !== $created->return_value(),
	'an omitted ENUM takes its declared default' => 1 === $defaulted->return_value()
		&& 'authenticator' === (string) ( $read->wpdb_state()['last_result'][0]->mode ?? '' ),
	'an explicit ENUM value persists and filters' => 1 === $explicit->return_value()
		&& array( '2' ) === array_map(
			static fn( object $row ): string => (string) $row->id,
			$filtered->wpdb_state()['last_result']
		),
	'introspection reports the ENUM type' => isset( $types['mode'] ) && str_starts_with( $types['mode'], 'enum' ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_schema/*' ) ?: array() );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_schema' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
