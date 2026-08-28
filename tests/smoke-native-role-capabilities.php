<?php
/** Role and capability round-trip through canonical options and usermeta. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-roles-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
file_put_contents(
	$root . '/_tables/users.json',
	json_encode(
		array(
			array(
				'ID' => '1',
				'user_login' => 'admin',
				'user_pass' => 'hash',
				'user_nicename' => 'admin',
				'user_email' => 'admin@example.test',
				'user_url' => '',
				'user_registered' => '2026-01-01 00:00:00',
				'user_activation_key' => '',
				'user_status' => '0',
				'display_name' => 'admin',
			),
		),
		JSON_THROW_ON_ERROR
	)
);
file_put_contents( $root . '/_tables/usermeta.json', "[]\n" );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$roles = serialize(
	array(
		'administrator' => array( 'name' => 'Administrator', 'capabilities' => array( 'manage_options' => true ) ),
		'subscriber' => array( 'name' => 'Subscriber', 'capabilities' => array( 'read' => true ) ),
	)
);
$store_roles = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('wp_user_roles', '" . str_replace( "'", "''", $roles ) . "', 'on') ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`), `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)",
		'wp_'
	)
);
$read_roles = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'wp_user_roles' LIMIT 1", 'wp_' ) );
$decoded = unserialize( (string) ( $read_roles->wpdb_state()['last_result'][0]->option_value ?? '' ) );

$grant = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'wp_capabilities', '" . str_replace( "'", "''", serialize( array( 'administrator' => true ) ) ) . "')",
		'wp_'
	)
);
$capabilities = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT meta_value FROM wp_usermeta WHERE user_id = 1 AND meta_key = 'wp_capabilities'", 'wp_' )
);
$stored = unserialize( (string) ( $capabilities->wpdb_state()['last_result'][0]->meta_value ?? '' ) );

$promote = $runtime->execute(
	new WP_Markdown_Query_Request(
		"UPDATE wp_usermeta SET meta_value = '" . str_replace( "'", "''", serialize( array( 'subscriber' => true ) ) ) . "' WHERE user_id = 1 AND meta_key = 'wp_capabilities'",
		'wp_'
	)
);
$after = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT meta_value FROM wp_usermeta WHERE user_id = 1 AND meta_key = 'wp_capabilities'", 'wp_' )
);
$demoted = unserialize( (string) ( $after->wpdb_state()['last_result'][0]->meta_value ?? '' ) );

$checks = array(
	'serialized role definitions round-trip through canonical options' => false !== $store_roles->return_value()
		&& is_array( $decoded )
		&& array( 'administrator', 'subscriber' ) === array_keys( $decoded )
		&& true === ( $decoded['administrator']['capabilities']['manage_options'] ?? null ),
	'a capability grant persists in usermeta' => 1 === $grant->return_value()
		&& array( 'administrator' => true ) === $stored,
	'a role change rewrites the stored capability' => 1 === $promote->return_value()
		&& array( 'subscriber' => true ) === $demoted,
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
