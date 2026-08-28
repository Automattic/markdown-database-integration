<?php
/** INSERT … SELECT literals FROM DUAL WHERE (subquery) IS NULL. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-dual-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_actionscheduler_actions (action_id bigint unsigned NOT NULL auto_increment, hook varchar(191) NOT NULL, status varchar(20) NOT NULL, group_id bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY (action_id))',
		'wp_'
	)
);

$always = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_actionscheduler_actions ( hook, status, group_id )\nSELECT 'woocommerce_run_product_attribute_lookup_update_callback', 'pending', 2 FROM DUAL\nWHERE ( SELECT NULL FROM DUAL ) IS NULL",
		'wp_'
	)
);
$unique = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_actionscheduler_actions ( hook, status, group_id )\nSELECT 'unique_hook', 'pending', 2 FROM DUAL\nWHERE ( SELECT action_id FROM wp_actionscheduler_actions WHERE status IN ('pending', 'in-progress') AND hook = 'unique_hook' AND `group_id` = 2 LIMIT 1 ) IS NULL",
		'wp_'
	)
);
$duplicate = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_actionscheduler_actions ( hook, status, group_id )\nSELECT 'unique_hook', 'pending', 2 FROM DUAL\nWHERE ( SELECT action_id FROM wp_actionscheduler_actions WHERE status IN ('pending', 'in-progress') AND hook = 'unique_hook' AND `group_id` = 2 LIMIT 1 ) IS NULL",
		'wp_'
	)
);
$rows = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT hook FROM wp_actionscheduler_actions WHERE action_id IN (1, 2, 3)', 'wp_' ) );

$checks = array(
	'INSERT SELECT FROM DUAL inserts one row' => 1 === $always->return_value() && 1 === $always->wpdb_state()['insert_id'],
	'INSERT SELECT FROM DUAL inserts when the absent subquery is empty' => 1 === $unique->return_value() && 2 === $unique->wpdb_state()['insert_id'],
	'INSERT SELECT FROM DUAL inserts zero rows when the absent subquery matches' => 0 === $duplicate->return_value(),
	'both inserted hooks are readable' => array( 'woocommerce_run_product_attribute_lookup_update_callback', 'unique_hook' ) === array_map(
		static fn( object $row ): string => (string) $row->hook,
		$rows->wpdb_state()['last_result']
	),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_schema/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_options/*' ) ?: array() );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_schema' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
