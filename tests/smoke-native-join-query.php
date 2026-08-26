<?php
/** Bounded generic native execution for the retained taxonomy equality JOIN. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-join-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0755, true ) || ! mkdir( $root . '/_tables', 0755, true ) ) {
	throw new RuntimeException( 'Failed to create the native JOIN fixture.' );
}

$fixtures = array(
	'term_relationships' => array(
		array( 'object_id' => '99', 'term_taxonomy_id' => '9', 'term_order' => '0' ),
		array( 'object_id' => '41', 'term_taxonomy_id' => '10', 'term_order' => '2' ),
		array( 'object_id' => '41', 'term_taxonomy_id' => '8', 'term_order' => '1' ),
		array( 'object_id' => '41', 'term_taxonomy_id' => '7', 'term_order' => '0' ),
	),
	'term_taxonomy' => array(
		array( 'term_taxonomy_id' => '9', 'term_id' => '5', 'taxonomy' => 'category', 'description' => '', 'parent' => '0', 'count' => '1' ),
		array( 'term_taxonomy_id' => '8', 'term_id' => '4', 'taxonomy' => 'post_tag', 'description' => '', 'parent' => '0', 'count' => '1' ),
		array( 'term_taxonomy_id' => '7', 'term_id' => '3', 'taxonomy' => 'category', 'description' => '', 'parent' => '0', 'count' => '1' ),
	),
	'terms' => array(
		array( 'term_id' => '5', 'name' => 'Other', 'slug' => 'other', 'term_group' => '0' ),
		array( 'term_id' => '4', 'name' => 'Featured', 'slug' => 'featured', 'term_group' => '0' ),
		array( 'term_id' => '3', 'name' => 'News', 'slug' => 'news', 'term_group' => '0' ),
	),
);
foreach ( $fixtures as $table => $rows ) {
	file_put_contents( $root . '/_tables/' . $table . '.json', json_encode( $rows, JSON_THROW_ON_ERROR ) );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$query = 'SELECT tr.object_id, tt.taxonomy, t.slug FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id=tt.term_taxonomy_id JOIN wp_terms t ON tt.term_id=t.term_id WHERE tr.object_id=41';
$plan = ( new WP_Markdown_Native_Query_Parser() )->parse( $query );
$result = $runtime->execute( new WP_Markdown_Query_Request( $query ) );
$state = $result->wpdb_state();
$missing = $runtime->execute( new WP_Markdown_Query_Request( str_replace( '=41', '=404', $query ) ) );
$unbounded = $runtime->execute( new WP_Markdown_Query_Request( substr( $query, 0, strpos( $query, ' WHERE' ) ) ) );
$unqualified = $runtime->execute( new WP_Markdown_Query_Request( str_replace( 'tr.object_id=41', 'object_id=41', $query ) ) );
$unknown_alias = $runtime->execute( new WP_Markdown_Query_Request( str_replace( 't.slug', 'x.slug', $query ) ) );
$limited = $runtime->execute( new WP_Markdown_Query_Request( $query . ' LIMIT 1' ) );

$checks = array(
	'tokenizer and parser lower aliases and chained equality JOINs into typed contracts' => $plan instanceof WP_Markdown_Native_Query_Plan
		&& 'tr' === $plan->table_alias()
		&& array( 'tr', 'tt', 't' ) === $plan->projection_sources()
		&& array( 'tt', 't' ) === array_map( static fn( WP_Markdown_Native_Query_Join $join ): string => $join->alias(), $plan->joins() ),
	'retained taxonomy equality JOIN executes through registered generic providers' => array(
		array( 'object_id' => '41', 'taxonomy' => 'category', 'slug' => 'news' ),
		array( 'object_id' => '41', 'taxonomy' => 'post_tag', 'slug' => 'featured' ),
	) === array_map( 'get_object_vars', $state['last_result'] ),
	'JOIN results preserve source table metadata' => array( 'wp_term_relationships', 'wp_term_taxonomy', 'wp_terms' ) === array_map(
		static fn( object $column ): string => $column->table,
		$state['col_info']
	),
	'bounded JOIN misses return an empty successful result' => 0 === $missing->return_value(),
	'unbounded, unqualified, unknown-alias, and limited JOIN variants fail closed' => false === $unbounded->return_value()
		&& 'unsupported_join_shape' === ( $unbounded->diagnostic()['reason'] ?? null )
		&& false === $unqualified->return_value()
		&& 'unsupported_join_shape' === ( $unqualified->diagnostic()['reason'] ?? null )
		&& false === $unknown_alias->return_value()
		&& 'unsupported_column' === ( $unknown_alias->diagnostic()['reason'] ?? null )
		&& false === $limited->return_value()
		&& 'unsupported_join_shape' === ( $limited->diagnostic()['reason'] ?? null )
		&& isset( $limited->diagnostic()['sql_offset'] ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

foreach ( array_keys( $fixtures ) as $table ) {
	@unlink( $root . '/_tables/' . $table . '.json' );
}
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
