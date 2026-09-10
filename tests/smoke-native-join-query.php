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
$hinted = $runtime->execute( new WP_Markdown_Query_Request( str_replace( 'tr JOIN', 'tr FORCE INDEX (term_taxonomy_id) JOIN', $query ) ) );
$bad_hint = $runtime->execute( new WP_Markdown_Query_Request( str_replace( 'tr JOIN', 'tr FORCE INDEX (missing_index) JOIN', $query ) ) );
$joined_hint = $runtime->execute( new WP_Markdown_Query_Request( str_replace( 'tt ON', 'tt USE KEY FOR JOIN (PRIMARY) ON', $query ) ) );
$missing = $runtime->execute( new WP_Markdown_Query_Request( str_replace( '=41', '=404', $query ) ) );
$unbounded = $runtime->execute( new WP_Markdown_Query_Request( substr( $query, 0, strpos( $query, ' WHERE' ) ) ) );
$unindexed_filter = $runtime->execute( new WP_Markdown_Query_Request( substr( $query, 0, strpos( $query, ' WHERE' ) ) . " WHERE tt.description = ''" ) );
$unbounded_left = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT tr.object_id, tt.taxonomy FROM wp_term_relationships tr LEFT JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id=tt.term_taxonomy_id' ) );
$unqualified = $runtime->execute( new WP_Markdown_Query_Request( str_replace( 'tr.object_id=41', 'object_id=41', $query ) ) );
$unknown_alias = $runtime->execute( new WP_Markdown_Query_Request( str_replace( 't.slug', 'x.slug', $query ) ) );
$limited = $runtime->execute( new WP_Markdown_Query_Request( $query . ' LIMIT 1' ) );
$catalog_query = "SELECT wp_term_relationships.object_id FROM wp_term_relationships LEFT JOIN wp_term_taxonomy ON (wp_term_relationships.term_taxonomy_id = wp_term_taxonomy.term_taxonomy_id) WHERE wp_term_taxonomy.taxonomy IN ('category') GROUP BY wp_term_relationships.object_id ORDER BY wp_term_relationships.object_id DESC LIMIT 0, 5";
$catalog = $runtime->execute( new WP_Markdown_Query_Request( $catalog_query ) );
$distinct_identity_group_query = "SELECT DISTINCT tr.object_id FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tt.taxonomy='category' GROUP BY tr.object_id";
$distinct_identity_group = $runtime->execute( new WP_Markdown_Query_Request( $distinct_identity_group_query ) );
$counted = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT COUNT(*) FROM wp_term_relationships LEFT JOIN wp_term_taxonomy ON term_taxonomy_id = wp_term_taxonomy.term_taxonomy_id WHERE object_id = 41' )
);
$core_term_ids_query = "SELECT DISTINCT t.term_id, tr.object_id FROM wp_terms AS t INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id INNER JOIN wp_term_relationships AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN ('category', 'post_tag', 'post_format') AND tr.object_id IN (41) ORDER BY t.name ASC";
$core_term_ids_plan = ( new WP_Markdown_Native_Query_Parser() )->parse( $core_term_ids_query );
$core_term_ids = $runtime->execute( new WP_Markdown_Query_Request( $core_term_ids_query ) );
$core_terms_query = 'SELECT t.*, tt.* FROM wp_terms AS t INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id WHERE t.term_id IN (3, 4)';
$core_terms = $runtime->execute( new WP_Markdown_Query_Request( $core_terms_query ) );
$derived_base_query = "SELECT d.object_id, d.term_taxonomy_id FROM ( SELECT object_id, term_taxonomy_id FROM wp_term_relationships WHERE object_id=41 UNION ALL SELECT object_id, term_taxonomy_id FROM wp_term_relationships WHERE object_id=41 ) AS d";
$derived_base = $runtime->execute( new WP_Markdown_Query_Request( $derived_base_query ) );
$derived_distinct_after_all = $runtime->execute( new WP_Markdown_Query_Request( "SELECT d.object_id, d.term_taxonomy_id FROM ( SELECT object_id, term_taxonomy_id FROM wp_term_relationships WHERE object_id=41 UNION ALL SELECT object_id, term_taxonomy_id FROM wp_term_relationships WHERE object_id=41 UNION SELECT object_id, term_taxonomy_id FROM wp_term_relationships WHERE object_id=41 ) AS d" ) );
$derived_join_query = "SELECT tr.object_id, d.taxonomy FROM wp_term_relationships tr JOIN ( SELECT term_taxonomy_id, taxonomy FROM wp_term_taxonomy WHERE taxonomy='category' UNION ALL SELECT term_taxonomy_id, taxonomy FROM wp_term_taxonomy WHERE taxonomy='category' ) AS d ON tr.term_taxonomy_id=d.term_taxonomy_id WHERE tr.object_id=41";
$derived_join_plan = ( new WP_Markdown_Native_Query_Parser() )->parse( $derived_join_query );
$derived_join = $runtime->execute( new WP_Markdown_Query_Request( $derived_join_query ) );
$calendar_schema = new WP_Markdown_Native_Table_Schema(
	array(
		'event_id' => new WP_Markdown_Native_Column( 8, false, 'is_int' ),
		'post_status' => new WP_Markdown_Native_Column( 253, false, 'is_string', null, array( '=' ) ),
		'start_datetime' => new WP_Markdown_Native_Column( 12, false, 'is_string' ),
		'end_datetime' => new WP_Markdown_Native_Column( 12, true, 'is_string' ),
	),
	'event_id'
);
$calendar_registry = new WP_Markdown_Native_Table_Registry();
$calendar_registry->register( 'wp_events', $calendar_schema, new MDI_Native_Join_Array_Provider( array(
	array( 'event_id' => 1, 'post_status' => 'publish', 'start_datetime' => '2026-01-01 09:00:00', 'end_datetime' => '2026-01-01 11:00:00' ),
	array( 'event_id' => 2, 'post_status' => 'publish', 'start_datetime' => '2026-01-02 09:00:00', 'end_datetime' => null ),
	array( 'event_id' => 3, 'post_status' => 'draft', 'start_datetime' => '2026-01-01 08:00:00', 'end_datetime' => '2026-01-01 08:30:00' ),
), $calendar_schema ) );
$window_schema = new WP_Markdown_Native_Table_Schema(
	array(
		'window_id' => new WP_Markdown_Native_Column( 8, false, 'is_int' ),
		'event_id' => new WP_Markdown_Native_Column( 8, false, 'is_int', null, array( '=', 'IN' ) ),
		'start_datetime' => new WP_Markdown_Native_Column( 12, false, 'is_string' ),
		'end_datetime' => new WP_Markdown_Native_Column( 12, false, 'is_string' ),
	),
	'window_id'
);
$calendar_registry->register( 'wp_windows', $window_schema, new MDI_Native_Join_Array_Provider( array(
	array( 'window_id' => 1, 'event_id' => 1, 'start_datetime' => '2026-01-01 10:00:00', 'end_datetime' => '2026-01-01 12:00:00' ),
	array( 'window_id' => 2, 'event_id' => 2, 'start_datetime' => '2026-01-02 10:00:00', 'end_datetime' => '2026-01-02 12:00:00' ),
	array( 'window_id' => 3, 'event_id' => 3, 'start_datetime' => '2026-01-01 10:00:00', 'end_datetime' => '2026-01-01 12:00:00' ),
), $window_schema ) );
$calendar_runtime = new WP_Markdown_Native_Query_Runtime( $calendar_registry );
$calendar_query = "SELECT MIN(transition_datetime) FROM ( SELECT MIN(end_datetime) AS transition_datetime FROM wp_events WHERE post_status='publish' AND end_datetime >= '2026-01-01 00:00:00' UNION ALL SELECT MIN(start_datetime) AS transition_datetime FROM wp_events WHERE post_status='publish' AND end_datetime IS NULL AND start_datetime >= '2026-01-01 00:00:00' ) upcoming_transitions";
$calendar = $calendar_runtime->execute( new WP_Markdown_Query_Request( $calendar_query ) );
$calendar_none = $calendar_runtime->execute( new WP_Markdown_Query_Request( str_replace( '2026-01-01 00:00:00', '2027-01-01 00:00:00', $calendar_query ) ) );
$calendar_overlap = $calendar_runtime->execute( new WP_Markdown_Query_Request( "SELECT e.event_id, w.window_id FROM wp_events e STRAIGHT_JOIN wp_windows w ON e.event_id=w.event_id WHERE e.post_status='publish' AND ((e.start_datetime < w.end_datetime AND e.end_datetime > w.start_datetime) OR (e.end_datetime IS NULL AND e.start_datetime < w.end_datetime)) ORDER BY e.event_id" ) );
$calendar_buckets = $calendar_runtime->execute( new WP_Markdown_Query_Request( "SELECT DATE(e.start_datetime - INTERVAL 12 HOUR) AS start_date, DATE(e.end_datetime) AS end_date, COUNT(DISTINCT e.event_id) AS bucket_count FROM wp_events e STRAIGHT_JOIN wp_windows w ON e.event_id=w.event_id WHERE e.post_status='publish' GROUP BY DATE(e.start_datetime - INTERVAL 12 HOUR), DATE(e.end_datetime)" ) );
$comma_join = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT tr.object_id, tt.taxonomy FROM wp_term_relationships tr, wp_term_taxonomy tt WHERE tr.term_taxonomy_id=tt.term_taxonomy_id AND tr.object_id=41' ) );

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

$multiplicity_registry = new WP_Markdown_Native_Table_Registry();
$multiplicity_left_schema = new WP_Markdown_Native_Table_Schema(
	array( 'id' => $integer( array( '=' ) ) ),
	'id'
);
$multiplicity_right_schema = new WP_Markdown_Native_Table_Schema(
	array( 'row_id' => $integer(), 'left_id' => $integer( array( '=', 'IN' ) ), 'label' => new WP_Markdown_Native_Column( 253, true, static fn( mixed $value ): bool => is_string( $value ) ) ),
	'row_id'
);
$multiplicity_registry->register( 'wp_multiplicity_left', $multiplicity_left_schema, new MDI_Native_Join_Array_Provider( array( array( 'id' => 1 ), array( 'id' => 2 ) ), $multiplicity_left_schema ) );
$multiplicity_registry->register( 'wp_multiplicity_right', $multiplicity_right_schema, new MDI_Native_Join_Array_Provider( array( array( 'row_id' => 1, 'left_id' => 1, 'label' => 'first' ), array( 'row_id' => 2, 'left_id' => 1, 'label' => 'second' ), array( 'row_id' => 3, 'left_id' => 3, 'label' => 'other' ) ), $multiplicity_right_schema ) );
$multiplicity_runtime = new WP_Markdown_Native_Query_Runtime( $multiplicity_registry );
$multiplicity = $multiplicity_runtime->execute( new WP_Markdown_Query_Request( 'SELECT l.id, r.label FROM wp_multiplicity_left l LEFT JOIN wp_multiplicity_right r ON l.id=r.left_id LIMIT 3' ) );
$chained_outer = $multiplicity_runtime->execute( new WP_Markdown_Query_Request( 'SELECT l.id, r.label, s.label FROM wp_multiplicity_left l LEFT JOIN wp_multiplicity_right r ON l.id=r.left_id LEFT JOIN wp_multiplicity_right s ON r.left_id=s.left_id' ) );
$non_equality_left = $multiplicity_runtime->execute( new WP_Markdown_Query_Request( 'SELECT l.id, r.label FROM wp_multiplicity_left l LEFT JOIN wp_multiplicity_right r ON l.id > r.left_id' ) );
$or_on = $multiplicity_runtime->execute( new WP_Markdown_Query_Request( "SELECT l.id, r.label FROM wp_multiplicity_left l JOIN wp_multiplicity_right r ON l.id=r.left_id OR r.label='other'" ) );
$scalar_join = $multiplicity_runtime->execute( new WP_Markdown_Query_Request( "SELECT l.id, CONCAT(r.label, '!') AS marked FROM wp_multiplicity_left l LEFT JOIN wp_multiplicity_right r ON l.id=r.left_id WHERE LENGTH(r.label) > 5 ORDER BY LENGTH(r.label) DESC LIMIT 1" ) );
$fanout_registry = new WP_Markdown_Native_Table_Registry();
$fanout_registry->register( 'wp_fanout_left', $multiplicity_left_schema, new MDI_Native_Join_Array_Provider( array( array( 'id' => 1 ) ), $multiplicity_left_schema ) );
$fanout_registry->register( 'wp_fanout_right', $multiplicity_right_schema, new MDI_Native_Join_Array_Provider( array_fill( 0, 100001, array( 'row_id' => 1, 'left_id' => 1, 'label' => 'fanout' ) ), $multiplicity_right_schema ) );
$fanout = ( new WP_Markdown_Native_Query_Runtime( $fanout_registry ) )->execute( new WP_Markdown_Query_Request( 'SELECT l.id, r.label FROM wp_fanout_left l JOIN wp_fanout_right r ON l.id=r.left_id' ) );
$meta_query = "SELECT p.ID, mt1.meta_value, mt2.meta_value FROM wp_posts p LEFT JOIN wp_postmeta mt1 ON (p.ID = mt1.post_id AND mt1.meta_key = 'color') LEFT JOIN wp_postmeta mt2 ON (p.ID = mt2.post_id AND mt2.meta_key = 'size') WHERE p.ID = 41";
$meta_plan = ( new WP_Markdown_Native_Query_Parser() )->parse( $meta_query );
$meta_registry = new WP_Markdown_Native_Table_Registry();
$posts_schema = new WP_Markdown_Native_Table_Schema( array( 'ID' => $integer( array( '=' ) ) ), 'ID' );
$postmeta_schema = new WP_Markdown_Native_Table_Schema(
	array(
		'meta_id' => $integer(),
		'post_id' => $integer( array( '=', 'IN' ) ),
		'meta_key' => new WP_Markdown_Native_Column( 253, false, 'is_string', null, array( '=', 'IN' ) ),
		'meta_value' => new WP_Markdown_Native_Column( 253, true, 'is_string' ),
	),
	'meta_id'
);
$meta_registry->register( 'wp_posts', $posts_schema, new MDI_Native_Join_Array_Provider( array( array( 'ID' => 41 ) ), $posts_schema ) );
$meta_registry->register( 'wp_postmeta', $postmeta_schema, new MDI_Native_Join_Array_Provider( array( array( 'meta_id' => 1, 'post_id' => 41, 'meta_key' => 'color', 'meta_value' => 'blue' ), array( 'meta_id' => 2, 'post_id' => 41, 'meta_key' => 'size', 'meta_value' => 'large' ) ), $postmeta_schema ) );
$meta_result = ( new WP_Markdown_Native_Query_Runtime( $meta_registry ) )->execute( new WP_Markdown_Query_Request( $meta_query ) );

$checks = array(
	'validated source index hints preserve taxonomy JOIN results' => $hinted->succeeded() && $result->corpus_result() === $hinted->corpus_result() && $joined_hint->succeeded() && $result->corpus_result() === $joined_hint->corpus_result(),
	'unknown hinted indexes fail instead of silently executing' => ! $bad_hint->succeeded() && 'unsupported_index_hint' === ( $bad_hint->diagnostic()['reason'] ?? null ),
	'tokenizer and parser lower aliases and chained equality JOINs into typed contracts' => $plan instanceof WP_Markdown_Native_Query_Plan
		&& 'tr' === $plan->table_alias()
		&& array( 'tr', 'tt', 't' ) === $plan->projection_sources()
		&& array( 'tt', 't' ) === array_map( static fn( WP_Markdown_Native_Query_Join $join ): string => $join->alias(), $plan->joins() ),
	'parser accepts WordPress meta-query self-joins with constant ON filters' => $meta_plan instanceof WP_Markdown_Native_Query_Plan
		&& array( 'p', 'mt1', 'mt2' ) === $meta_plan->projection_sources()
		&& array( 2, 2 ) === array_map( static fn( WP_Markdown_Native_Query_Join $join ): int => count( $join->on_filters() ), $meta_plan->joins() ),
	'WordPress meta-query self-joins retain each alias-specific ON filter' => array(
		array( 'ID' => '41', 'meta_value' => 'large' ),
	) === array_map( 'get_object_vars', $meta_result->wpdb_state()['last_result'] ),
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
	'parenthesized derived SELECT UNION ALL sources preserve duplicate rows under their alias' => array(
		array( 'object_id' => '41', 'term_taxonomy_id' => '7' ),
		array( 'object_id' => '41', 'term_taxonomy_id' => '8' ),
		array( 'object_id' => '41', 'term_taxonomy_id' => '10' ),
		array( 'object_id' => '41', 'term_taxonomy_id' => '7' ),
		array( 'object_id' => '41', 'term_taxonomy_id' => '8' ),
		array( 'object_id' => '41', 'term_taxonomy_id' => '10' ),
	) === array_map( 'get_object_vars', $derived_base->wpdb_state()['last_result'] ),
	'UNION DISTINCT removes duplicates retained by a preceding UNION ALL' => array(
		array( 'object_id' => '41', 'term_taxonomy_id' => '7' ),
		array( 'object_id' => '41', 'term_taxonomy_id' => '8' ),
		array( 'object_id' => '41', 'term_taxonomy_id' => '10' ),
	) === array_map( 'get_object_vars', $derived_distinct_after_all->wpdb_state()['last_result'] ),
	'parenthesized derived JOIN sources expose columns only through their alias' => $derived_join_plan instanceof WP_Markdown_Native_Query_Plan
		&& array( 'tr', 'd' ) === $derived_join_plan->projection_sources()
		&& array(
			array( 'object_id' => '41', 'taxonomy' => 'category' ),
			array( 'object_id' => '41', 'taxonomy' => 'category' ),
		) === array_map( 'get_object_vars', $derived_join->wpdb_state()['last_result'] ),
	'calendar transition query aggregates nullable UNION ALL branch minima' => array(
		array( 'MIN(transition_datetime)' => '2026-01-01 11:00:00' ),
	) === array_map( 'get_object_vars', $calendar->wpdb_state()['last_result'] ),
	'calendar transition query returns NULL when neither branch has a transition' => array(
		array( 'MIN(transition_datetime)' => null ),
	) === array_map( 'get_object_vars', $calendar_none->wpdb_state()['last_result'] ),
	'calendar overlap OR trees retain both bounded predicate branches after STRAIGHT_JOIN' => array(
		array( 'event_id' => '1', 'window_id' => '1' ),
		array( 'event_id' => '2', 'window_id' => '2' ),
	) === array_map( 'get_object_vars', $calendar_overlap->wpdb_state()['last_result'] ),
	'calendar buckets support multiple scalar groups and COUNT DISTINCT after datetime arithmetic' => array(
		array( 'start_date' => '2025-12-31', 'end_date' => '2026-01-01', 'bucket_count' => '1' ),
		array( 'start_date' => '2026-01-01', 'end_date' => null, 'bucket_count' => '1' ),
	) === array_map( 'get_object_vars', $calendar_buckets->wpdb_state()['last_result'] ),
	'comma FROM sources execute as a bounded Cartesian join before WHERE filtering' => array(
		array( 'object_id' => '41', 'taxonomy' => 'category' ),
		array( 'object_id' => '41', 'taxonomy' => 'post_tag' ),
	) === array_map( 'get_object_vars', $comma_join->wpdb_state()['last_result'] ),
	'bounded JOIN misses return an empty successful result' => 0 === $missing->return_value(),
	'large equality JOINs scale by normalized identities rather than row pairs' => 1000 === count( $scale_rows )
		&& array( 'id' => '1', 'label' => 'row-1' ) === get_object_vars( $scale_rows[0] ?? (object) array() )
		&& array( 'id' => '1000', 'label' => 'row-1000' ) === get_object_vars( $scale_rows[999] ?? (object) array() )
		&& $right_normalizations < 10000,
	'indexed equality JOIN fan-out is bounded before materializing row pairs' => false === $fanout->return_value()
		&& 'unsupported_join_cost' === ( $fanout->diagnostic()['reason'] ?? null ),
	'LEFT JOIN with identity GROUP BY and LIMIT returns distinct left keys' => array( '99', '41' ) === array_map(
		static fn( object $row ): string => (string) $row->object_id,
		$catalog->wpdb_state()['last_result']
	),
	'DISTINCT identity GROUP BY remains available for canonical joined post queries' => array( '41', '99' ) === array_map(
		static fn( object $row ): string => (string) $row->object_id,
		$distinct_identity_group->wpdb_state()['last_result']
	),
	'JOIN LIMIT returns the bounded prefix' => 1 === $limited->return_value()
		&& array( 'object_id' => '41', 'taxonomy' => 'category', 'slug' => 'news' ) === get_object_vars( $limited->wpdb_state()['last_result'][0] ?? (object) array() ),
	'COUNT(*) over a JOIN counts joined rows' => '3' === (string) ( $counted->wpdb_state()['last_result'][0]->{'COUNT(*)'} ?? '' ),
	'unqualified JOIN columns resolve against the base source' => 2 === $unqualified->return_value()
		&& array( '41', '41' ) === array_map(
			static fn( object $row ): string => (string) $row->object_id,
			$unqualified->wpdb_state()['last_result']
		),
	'unfiltered chained INNER JOIN scans each source and preserves projection metadata' => array(
		array( 'object_id' => '41', 'taxonomy' => 'category', 'slug' => 'news' ),
		array( 'object_id' => '41', 'taxonomy' => 'post_tag', 'slug' => 'featured' ),
		array( 'object_id' => '99', 'taxonomy' => 'category', 'slug' => 'other' ),
	) === array_map( 'get_object_vars', $unbounded->wpdb_state()['last_result'] )
		&& array( 'wp_term_relationships', 'wp_term_taxonomy', 'wp_terms' ) === array_map( static fn( object $column ): string => $column->table, $unbounded->wpdb_state()['col_info'] ),
	'non-indexed JOIN filters scan their owning source without changing multiplicity' => array_map( 'get_object_vars', $unbounded->wpdb_state()['last_result'] )
		=== array_map( 'get_object_vars', $unindexed_filter->wpdb_state()['last_result'] ),
	'unfiltered LEFT JOIN preserves unmatched NULL rows and duplicate matches before LIMIT' => array(
		array( 'object_id' => '41', 'taxonomy' => 'category' ),
		array( 'object_id' => '41', 'taxonomy' => 'post_tag' ),
		array( 'object_id' => '41', 'taxonomy' => null ),
		array( 'object_id' => '99', 'taxonomy' => 'category' ),
	) === array_map( 'get_object_vars', $unbounded_left->wpdb_state()['last_result'] )
		&& array(
			array( 'id' => '1', 'label' => 'first' ),
			array( 'id' => '1', 'label' => 'second' ),
			array( 'id' => '2', 'label' => null ),
		) === array_map( 'get_object_vars', $multiplicity->wpdb_state()['last_result'] ),
	'chained LEFT JOINs carry unmatched NULL keys into later equality joins' => array(
		array( 'id' => '1', 'label' => 'first' ),
		array( 'id' => '1', 'label' => 'second' ),
		array( 'id' => '1', 'label' => 'first' ),
		array( 'id' => '1', 'label' => 'second' ),
		array( 'id' => '2', 'label' => null ),
	) === array_map( 'get_object_vars', $chained_outer->wpdb_state()['last_result'] ),
	'non-equality ON predicates evaluate before LEFT JOIN NULL extension' => array(
		array( 'id' => '1', 'label' => null ),
		array( 'id' => '2', 'label' => 'first' ),
		array( 'id' => '2', 'label' => 'second' ),
	) === array_map( 'get_object_vars', $non_equality_left->wpdb_state()['last_result'] ),
	'OR ON predicates evaluate against the combined alias row map' => array(
		array( 'id' => '1', 'label' => 'first' ),
		array( 'id' => '1', 'label' => 'second' ),
		array( 'id' => '1', 'label' => 'other' ),
		array( 'id' => '2', 'label' => 'other' ),
	) === array_map( 'get_object_vars', $or_on->wpdb_state()['last_result'] ),
	'scalar WHERE, projection, hidden ORDER BY, and LIMIT share the joined row stage' => array(
		array( 'id' => '1', 'marked' => 'second!' ),
	) === array_map( 'get_object_vars', $scalar_join->wpdb_state()['last_result'] ),
	'unknown JOIN aliases fail closed' => false === $unknown_alias->return_value()
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
