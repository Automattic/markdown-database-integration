<?php
/** False constant predicates and identity GROUP BY. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-false-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_tables', 0777, true );
mkdir( $root . '/_options', 0777, true );
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
				'user_registered' => '0000-00-00 00:00:00',
				'user_activation_key' => '',
				'user_status' => '0',
				'display_name' => 'Admin',
			),
		),
		JSON_THROW_ON_ERROR
	)
);
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$empty = $runtime->execute(
	new WP_Markdown_Query_Request(
		"SELECT   wp_users.ID FROM wp_users WHERE 1=1  AND ( \n  0 = 1\n) AND wp_users.ID = 1 GROUP BY wp_users.ID ORDER BY wp_users.ID DESC LIMIT 0, 5"
	)
);
$grouped = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT ID FROM wp_users WHERE ID = 1 GROUP BY ID' )
);
$calc = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT SQL_CALC_FOUND_ROWS ID FROM wp_users WHERE 1=1 AND (0 = 1) AND ID = 1 LIMIT 0, 5' )
);
$found = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT FOUND_ROWS()' ) );

$checks = array(
	'0 = 1 returns no rows instead of failing closed' => array() === $empty->wpdb_state()['last_result']
		&& false !== $empty->return_value(),
	'identity GROUP BY of the selected column is a no-op' => array( '1' ) === array_map(
		static fn( object $row ): string => (string) $row->ID,
		$grouped->wpdb_state()['last_result']
	),
	'SQL_CALC_FOUND_ROWS with 0 = 1 reports zero matches' => array() === $calc->wpdb_state()['last_result']
		&& '0' === (string) ( $found->wpdb_state()['last_result'][0]->{'FOUND_ROWS()'} ?? '' ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
