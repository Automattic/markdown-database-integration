<?php
/** Generic conjunction planning, provider pushdown, and residual execution. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

final class MDI_Conjunctive_Provider implements WP_Markdown_Native_Table_Provider {
	/** @var array<int,WP_Markdown_Native_Table_Access> */
	public array $accesses = array();

	public function __construct( private array $rows ) {}

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$this->accesses[] = $access;
		$predicate = $access->predicate();
		$selected  = array();
		foreach ( $this->rows as $source ) {
			if ( null !== $predicate && ! in_array( $source[ $predicate->column() ], $predicate->values(), true ) ) {
				continue;
			}
			if ( count( $selected ) >= $access->limit() ) {
				break;
			}
			$row = array();
			foreach ( $access->projection() as $column ) {
				$row[ $column ] = $source[ $column ];
			}
			$selected[] = $row;
		}
		return $selected;
	}
}

$schema = new WP_Markdown_Native_Table_Schema(
	array(
		'row_id' => new WP_Markdown_Native_Column( 8, false, 'is_int' ),
		'kind'   => new WP_Markdown_Native_Column( 253, false, 'is_string', null, array( 'IN' ) ),
		'status' => new WP_Markdown_Native_Column( 253, false, 'is_string', null, array( '=' ) ),
		'label'  => new WP_Markdown_Native_Column( 253, false, 'is_string' ),
	),
	'row_id'
);
$provider = new MDI_Conjunctive_Provider(
	array(
		array( 'row_id' => 1, 'kind' => 'page', 'status' => 'publish', 'label' => 'first' ),
		array( 'row_id' => 2, 'kind' => 'post', 'status' => 'publish', 'label' => 'second' ),
		array( 'row_id' => 3, 'kind' => 'post', 'status' => 'draft', 'label' => 'third' ),
		array( 'row_id' => 4, 'kind' => 'post', 'status' => 'publish', 'label' => 'fourth' ),
	)
);
$registry = new WP_Markdown_Native_Table_Registry();
$registry->register( 'wp_rows', $schema, $provider );
$runtime = new WP_Markdown_Native_Query_Runtime( $registry );

$first = $runtime->execute( new WP_Markdown_Query_Request( "SELECT row_id, label FROM wp_rows WHERE kind IN ('post', 'post') AND status = 'publish' LIMIT 2" ) );
$reversed = $runtime->execute( new WP_Markdown_Query_Request( "SELECT row_id, label FROM wp_rows WHERE status = 'publish' AND kind IN ('post') LIMIT 2" ) );
$nonindexed = $runtime->execute( new WP_Markdown_Query_Request( "SELECT row_id FROM wp_rows WHERE status = 'publish' AND label = 'fourth' LIMIT 1" ) );
$unsupported = $runtime->execute( new WP_Markdown_Query_Request( "SELECT row_id FROM wp_rows WHERE label = 'fourth'" ) );
$unknown = $runtime->execute( new WP_Markdown_Query_Request( "SELECT row_id FROM wp_rows WHERE status = 'publish' AND missing = 'value'" ) );

$first_access    = $provider->accesses[0] ?? null;
$reversed_access = $provider->accesses[1] ?? null;
$checks = array(
	'planner chooses the selective equals lookup independent of predicate order' => 'status' === $first_access?->predicate()?->column()
		&& 'status' === $reversed_access?->predicate()?->column(),
	'residual columns reach the provider while LIMIT is delayed' => array( 'row_id', 'label', 'kind' ) === $first_access?->projection()
		&& PHP_INT_MAX === $first_access?->limit(),
	'conjunction filtering preserves ordered matches and applies LIMIT afterward' => array( '2', '4' ) === array_map( static fn( object $row ): string => $row->row_id, $first->wpdb_state()['last_result'] )
		&& array( '2', '4' ) === array_map( static fn( object $row ): string => $row->row_id, $reversed->wpdb_state()['last_result'] ),
	'public rows and metadata exclude residual-only columns' => array( 'row_id', 'label' ) === array_keys( get_object_vars( $first->wpdb_state()['last_result'][0] ?? (object) array() ) )
		&& array( 'row_id', 'label' ) === array_map( static fn( object $column ): string => $column->name, $first->wpdb_state()['col_info'] ),
	'non-indexed predicates execute only behind an indexable pushdown' => '4' === ( $nonindexed->wpdb_state()['last_result'][0]->row_id ?? null )
		&& false === $unsupported->return_value()
		&& 'unsupported_lookup' === ( $unsupported->diagnostic()['reason'] ?? null ),
	'unknown conjunction columns fail before provider access' => false === $unknown->return_value()
		&& 'unsupported_column' === ( $unknown->diagnostic()['reason'] ?? null )
		&& 3 === count( $provider->accesses ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}
exit( $failed ? 1 : 0 );
