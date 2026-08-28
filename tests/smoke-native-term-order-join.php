<?php
/** WP_Term_Query LEFT JOIN termmeta ON key, IS NULL, ORDER BY meta_value+0. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-term-order-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0755, true );
mkdir( $root . '/_tables', 0755, true );
file_put_contents(
	$root . '/_tables/terms.json',
	json_encode(
		array(
			array( 'term_id' => '5', 'name' => 'Other', 'slug' => 'other', 'term_group' => '0' ),
			array( 'term_id' => '3', 'name' => 'News', 'slug' => 'news', 'term_group' => '0' ),
		),
		JSON_THROW_ON_ERROR
	)
);
file_put_contents(
	$root . '/_tables/term_taxonomy.json',
	json_encode(
		array(
			array( 'term_taxonomy_id' => '9', 'term_id' => '5', 'taxonomy' => 'product_cat', 'description' => '', 'parent' => '0', 'count' => '0' ),
			array( 'term_taxonomy_id' => '7', 'term_id' => '3', 'taxonomy' => 'product_cat', 'description' => '', 'parent' => '0', 'count' => '0' ),
		),
		JSON_THROW_ON_ERROR
	)
);
file_put_contents(
	$root . '/_tables/termmeta.json',
	json_encode(
		array(
			array( 'meta_id' => '1', 'term_id' => '5', 'meta_key' => 'order', 'meta_value' => '10' ),
		),
		JSON_THROW_ON_ERROR
	)
);

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$sql = "SELECT DISTINCT t.term_id FROM wp_terms AS t LEFT JOIN wp_termmeta ON ( t.term_id = wp_termmeta.term_id AND wp_termmeta.meta_key='order') INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id WHERE tt.taxonomy IN ('product_cat') AND ( ( wp_termmeta.meta_key = 'order' OR wp_termmeta.meta_key IS NULL ) ) ORDER BY wp_termmeta.meta_value+0 ASC, t.name ASC";
$result = $runtime->execute( new WP_Markdown_Query_Request( $sql ) );

$checks = array(
	'WP_Term_Query LEFT JOIN termmeta order executes' => false !== $result->return_value()
		&& '' === $result->wpdb_state()['last_error'],
	'terms without order meta are kept and sort first' => array( '3', '5' ) === array_map(
		static fn( object $row ): string => (string) $row->term_id,
		$result->wpdb_state()['last_result']
	),
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
