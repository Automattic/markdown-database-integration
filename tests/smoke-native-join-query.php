<?php
/** Bounded generic native execution for the retained taxonomy equality JOIN. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

final class MDI_Native_Join_Array_Provider implements WP_Markdown_Native_Table_Provider {
	/** @param array<int,array<string,mixed>> $rows */
	public function __construct(
		private array $rows,
		private WP_Markdown_Native_Table_Schema $schema
	) {}

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$predicate = $access->predicate();
		$wanted = array();
		foreach ( null === $predicate ? array() : $predicate->values() as $value ) {
			$key = $this->schema->value_key( $predicate->column(), $value );
			if ( null !== $key ) {
				$wanted[ $key ] = true;
			}
		}

		$selected = array();
		foreach ( $this->rows as $source ) {
			$key = null === $predicate ? null : $this->schema->value_key( $predicate->column(), $source[ $predicate->column() ] );
			if ( null !== $predicate && ( null === $key || ! isset( $wanted[ $key ] ) ) ) {
				continue;
			}
			$row = array();
			foreach ( $access->projection() as $column ) {
				$row[ $column ] = $source[ $column ];
			}
			$selected[] = $row;
			if ( count( $selected ) >= $access->limit() ) {
				break;
			}
		}
		return $selected;
	}
}

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
$catalog_query = "SELECT wp_term_relationships.object_id FROM wp_term_relationships LEFT JOIN wp_term_taxonomy ON (wp_term_relationships.term_taxonomy_id = wp_term_taxonomy.term_taxonomy_id) WHERE wp_term_taxonomy.taxonomy IN ('category') GROUP BY wp_term_relationships.object_id ORDER BY wp_term_relationships.object_id DESC LIMIT 0, 5";
$catalog = $runtime->execute( new WP_Markdown_Query_Request( $catalog_query ) );
$counted = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT COUNT(*) FROM wp_term_relationships LEFT JOIN wp_term_taxonomy ON term_taxonomy_id = wp_term_taxonomy.term_taxonomy_id WHERE object_id = 41' )
);
$core_term_ids_query = "SELECT DISTINCT t.term_id, tr.object_id FROM wp_terms AS t INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id INNER JOIN wp_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN ('category', 'post_tag', 'post_format') AND tr.object_id IN (41) ORDER BY t.name ASC";
$core_term_ids_plan = ( new WP_Markdown_Native_Query_Parser() )->parse( $core_term_ids_query );
$core_term_ids = $runtime->execute( new WP_Markdown_Query_Request( $core_term_ids_query ) );
$core_terms_query = 'SELECT t.*, tt.* FROM wp_terms AS t INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id WHERE t.term_id IN (3, 4)';
$core_terms = $runtime->execute( new WP_Markdown_Query_Request( $core_terms_query ) );

$unsigned = static fn( mixed $value ): ?string => WP_Markdown_Native_Runtime_Factory::normalize_unsigned( $value );
$integer = static fn( array $lookups = array() ): WP_Markdown_Native_Column => new WP_Markdown_Native_Column(
	8,
	false,
	static fn( mixed $value ): bool => is_int( $value ) && $value >= 0,
	$unsigned,
	$lookups
);
$right_normalizations = 0;
$counted_unsigned = static function ( mixed $value ) use ( &$right_normalizations ): ?string {
	++$right_normalizations;
	return WP_Markdown_Native_Runtime_Factory::normalize_unsigned( $value );
};
$base_schema = new WP_Markdown_Native_Table_Schema(
	array( 'id' => $integer(), 'join_id' => $integer(), 'group_id' => $integer( array( '=' ) ) ),
	'id'
);
$right_schema = new WP_Markdown_Native_Table_Schema(
	array(
		'id' => new WP_Markdown_Native_Column( 8, false, static fn( mixed $value ): bool => is_int( $value ) && $value >= 0, $counted_unsigned, array( '=', 'IN' ) ),
		'label' => new WP_Markdown_Native_Column( 253, false, 'is_string' ),
	),
	'id'
);
$base_rows = array();
$right_rows = array();
for ( $id = 1; $id <= 1000; ++$id ) {
	$base_rows[] = array( 'id' => $id, 'join_id' => $id, 'group_id' => 1 );
	$right_rows[] = array( 'id' => $id, 'label' => 'row-' . $id );
}
$scale_registry = new WP_Markdown_Native_Table_Registry();
$scale_registry->register( 'wp_scale_base', $base_schema, new MDI_Native_Join_Array_Provider( $base_rows, $base_schema ) );
$scale_registry->register( 'wp_scale_right', $right_schema, new MDI_Native_Join_Array_Provider( $right_rows, $right_schema ) );
$scale_result = ( new WP_Markdown_Native_Query_Runtime( $scale_registry ) )->execute(
	new WP_Markdown_Query_Request( 'SELECT b.id, r.label FROM wp_scale_base b JOIN wp_scale_right r ON b.join_id=r.id WHERE b.group_id=1' )
);
$scale_rows = $scale_result->wpdb_state()['last_result'];

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
	'core DISTINCT taxonomy JOIN seeds from any indexed source and orders joined rows' => $core_term_ids_plan instanceof WP_Markdown_Native_Query_Plan
		&& $core_term_ids_plan->is_distinct()
		&& array(
			array( 'term_id' => '4', 'object_id' => '41' ),
			array( 'term_id' => '3', 'object_id' => '41' ),
		) === array_map( 'get_object_vars', $core_term_ids->wpdb_state()['last_result'] ),
	'core qualified JOIN wildcards expand complete source schemas' => 2 === $core_terms->return_value()
		&& array( 'News', 'Featured' ) === array_map(
			static fn( object $row ): string => $row->name,
			$core_terms->wpdb_state()['last_result']
		)
		&& 10 === count( $core_terms->wpdb_state()['col_info'] ),
	'bounded JOIN misses return an empty successful result' => 0 === $missing->return_value(),
	'large equality JOINs scale by normalized identities rather than row pairs' => 1000 === count( $scale_rows )
		&& array( 'id' => '1', 'label' => 'row-1' ) === get_object_vars( $scale_rows[0] ?? (object) array() )
		&& array( 'id' => '1000', 'label' => 'row-1000' ) === get_object_vars( $scale_rows[999] ?? (object) array() )
		&& $right_normalizations < 10000,
	'LEFT JOIN with identity GROUP BY and LIMIT returns distinct left keys' => array( '99', '41' ) === array_map(
		static fn( object $row ): string => (string) $row->object_id,
		$catalog->wpdb_state()['last_result']
	),
	'JOIN LIMIT returns the bounded prefix' => 1 === $limited->return_value()
		&& array( 'object_id' => '41', 'taxonomy' => 'category', 'slug' => 'news' ) === get_object_vars( $limited->wpdb_state()['last_result'][0] ?? (object) array() ),
	'COUNT(*) over a JOIN counts joined rows' => '3' === (string) ( $counted->wpdb_state()['last_result'][0]->{'COUNT(*)'} ?? '' ),
	'unqualified JOIN columns resolve against the base source' => 2 === $unqualified->return_value()
		&& array( '41', '41' ) === array_map(
			static fn( object $row ): string => (string) $row->object_id,
			$unqualified->wpdb_state()['last_result']
		),
	'unbounded and unknown-alias JOIN variants fail closed' => false === $unbounded->return_value()
		&& 'unsupported_join_shape' === ( $unbounded->diagnostic()['reason'] ?? null )
		&& false === $unknown_alias->return_value()
		&& 'unsupported_column' === ( $unknown_alias->diagnostic()['reason'] ?? null ),
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
