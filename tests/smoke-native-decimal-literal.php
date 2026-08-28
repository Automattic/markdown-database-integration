<?php
/** Decimal literals for double/float/decimal columns. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-decimal-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$create = $runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_wc_order_stats (order_id bigint unsigned NOT NULL, total_sales double DEFAULT 0 NOT NULL, PRIMARY KEY (order_id))',
		'wp_'
	)
);
$insert = $runtime->execute(
	new WP_Markdown_Query_Request( 'INSERT INTO wp_wc_order_stats (order_id, total_sales) VALUES (1, 10.5)', 'wp_' )
);
$read = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT total_sales FROM wp_wc_order_stats WHERE order_id = 1', 'wp_' ) );
$filter = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT order_id FROM wp_wc_order_stats WHERE order_id = 1 AND total_sales = 10.5', 'wp_' ) );
$update = $runtime->execute( new WP_Markdown_Query_Request( 'UPDATE wp_wc_order_stats SET total_sales = 11.25 WHERE order_id = 1', 'wp_' ) );
$after = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT total_sales FROM wp_wc_order_stats WHERE order_id = 1', 'wp_' ) );

$checks = array(
	'a double column can be created' => false !== $create->return_value(),
	'a decimal insert persists' => 1 === $insert->return_value()
		&& '10.5' === (string) ( $read->wpdb_state()['last_result'][0]->total_sales ?? '' ),
	'a decimal equality filter matches the stored literal' => array( '1' ) === array_map(
		static fn( object $row ): string => (string) $row->order_id,
		$filter->wpdb_state()['last_result']
	),
	'a decimal assignment updates the stored literal' => 1 === $update->return_value()
		&& '11.25' === (string) ( $after->wpdb_state()['last_result'][0]->total_sales ?? '' ),
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
