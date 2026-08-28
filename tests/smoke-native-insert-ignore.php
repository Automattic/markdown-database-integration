<?php
/** INSERT IGNORE skips a duplicate unique key instead of failing closed. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-ignore-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_wc_category_lookup (category_tree_id bigint unsigned NOT NULL, category_id bigint unsigned NOT NULL, PRIMARY KEY (category_tree_id, category_id))',
		'wp_'
	)
);

$first = $runtime->execute( new WP_Markdown_Query_Request( 'INSERT INTO wp_wc_category_lookup (category_id, category_tree_id) VALUES (15, 15)', 'wp_' ) );
$ignored = $runtime->execute( new WP_Markdown_Query_Request( 'INSERT IGNORE INTO wp_wc_category_lookup (category_id, category_tree_id) VALUES (15, 15)', 'wp_' ) );
$conflict = $runtime->execute( new WP_Markdown_Query_Request( 'INSERT INTO wp_wc_category_lookup (category_id, category_tree_id) VALUES (15, 15)', 'wp_' ) );
$other = $runtime->execute( new WP_Markdown_Query_Request( 'INSERT IGNORE INTO wp_wc_category_lookup (category_id, category_tree_id) VALUES (16, 15)', 'wp_' ) );
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT category_id FROM wp_wc_category_lookup WHERE category_tree_id = 15', 'wp_' ) );

$checks = array(
	'the first insert persists' => 1 === $first->return_value(),
	'INSERT IGNORE on a duplicate unique key inserts zero rows' => 0 === $ignored->return_value()
		&& '' === $ignored->wpdb_state()['last_error'],
	'INSERT without IGNORE still fail-closes on a duplicate unique key' => false === $conflict->return_value()
		&& 'duplicate_key' === ( $conflict->diagnostic()['reason'] ?? null ),
	'INSERT IGNORE of a new row persists' => 1 === $other->return_value()
		&& array( '15', '16' ) === array_map(
			static fn( object $row ): string => (string) $row->category_id,
			$read->wpdb_state()['last_result']
		),
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
