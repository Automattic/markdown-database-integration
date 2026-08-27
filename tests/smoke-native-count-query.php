<?php
/** Generic COUNT(*) planning, execution, bounds, metadata, and failures. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

final class MDI_Count_Provider implements WP_Markdown_Native_Table_Provider {
	/** @var array<int,WP_Markdown_Native_Table_Access> */
	public array $accesses = array();
	public bool $malformed = false;
	public bool $fail = false;

	public function __construct( private array $rows ) {}

	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$this->accesses[] = $access;
		if ( $this->fail ) {
			return WP_Markdown_Query_Result::failure(
				array(
					'code' => 'markdown_db_native_malformed_table',
					'reason' => 'malformed_table',
					'message' => 'The canonical table snapshot is malformed.',
				)
			);
		}

		$predicate = $access->predicate();
		$selected = array();
		foreach ( $this->rows as $source ) {
			if ( null !== $predicate && ! in_array( $source[ $predicate->column() ], $predicate->values(), true ) ) {
				continue;
			}
			$row = array();
			foreach ( $access->projection() as $column ) {
				$row[ $column ] = $source[ $column ];
			}
			$selected[] = $row;
		}
		if ( $this->malformed && array() !== $selected ) {
			$selected[ count( $selected ) - 1 ] = array( 'unexpected' => 'row' );
		}
		return $selected;
	}
}

$schema = new WP_Markdown_Native_Table_Schema(
	array(
		'row_id' => new WP_Markdown_Native_Column( 8, false, 'is_int' ),
		'kind' => new WP_Markdown_Native_Column( 253, false, 'is_string', null, array( 'IN' ) ),
		'status' => new WP_Markdown_Native_Column( 253, false, 'is_string', null, array( '=' ) ),
	),
	'row_id'
);
$rows = array(
	array( 'row_id' => 1, 'kind' => 'post', 'status' => 'publish' ),
	array( 'row_id' => 2, 'kind' => 'post', 'status' => 'draft' ),
	array( 'row_id' => 3, 'kind' => 'page', 'status' => 'publish' ),
	array( 'row_id' => 4, 'kind' => 'post', 'status' => 'publish' ),
);
$provider = new MDI_Count_Provider( $rows );
$empty_provider = new MDI_Count_Provider( array() );
$registry = new WP_Markdown_Native_Table_Registry();
$registry->register( 'wp_rows', $schema, $provider );
$registry->register( 'wp_empty_rows', $schema, $empty_provider );
$runtime = new WP_Markdown_Native_Query_Runtime( $registry );

$all = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT COUNT(*) FROM wp_rows' ) );
$filtered = $runtime->execute( new WP_Markdown_Query_Request( "SELECT COUNT(*) FROM wp_rows WHERE kind IN ('post') AND status = 'publish' LIMIT 1" ) );
$none = $runtime->execute( new WP_Markdown_Query_Request( "SELECT COUNT(*) FROM wp_rows WHERE status = 'missing'" ) );
$empty = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT COUNT(*) FROM wp_empty_rows' ) );
$zero_limit = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT COUNT(*) FROM wp_rows LIMIT 0' ) );
$large_limit = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT COUNT(*) FROM wp_rows LIMIT 99' ) );

$filtered_access = $provider->accesses[1] ?? null;
$provider->malformed = true;
$malformed = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT COUNT(*) FROM wp_rows' ) );
$provider->malformed = false;
$provider->fail = true;
$failed = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT COUNT(*) FROM wp_rows' ) );

$checks = array(
	'unfiltered count returns one MySQL string row with expression metadata' => 1 === $all->return_value()
		&& '4' === ( $all->wpdb_state()['last_result'][0]->{'COUNT(*)'} ?? null )
		&& 'COUNT(*)' === ( $all->wpdb_state()['col_info'][0]->name ?? null )
		&& '' === ( $all->wpdb_state()['col_info'][0]->table ?? null )
		&& 8 === ( $all->wpdb_state()['col_info'][0]->type ?? null ),
	'indexed pushdown plus residual filters count all matching source rows' => '2' === ( $filtered->wpdb_state()['last_result'][0]->{'COUNT(*)'} ?? null )
		&& $filtered_access instanceof WP_Markdown_Native_Table_Access
		&& 'status' === $filtered_access->predicate()?->column()
		&& array( 'kind' ) === $filtered_access->projection()
		&& PHP_INT_MAX === $filtered_access->limit(),
	'empty matches and empty registered tables return a single zero row' => '0' === ( $none->wpdb_state()['last_result'][0]->{'COUNT(*)'} ?? null )
		&& '0' === ( $empty->wpdb_state()['last_result'][0]->{'COUNT(*)'} ?? null ),
	'aggregate LIMIT applies after counting and LIMIT zero preserves metadata' => 0 === $zero_limit->return_value()
		&& array() === $zero_limit->wpdb_state()['last_result']
		&& 'COUNT(*)' === ( $zero_limit->wpdb_state()['col_info'][0]->name ?? null )
		&& '4' === ( $large_limit->wpdb_state()['last_result'][0]->{'COUNT(*)'} ?? null ),
	'count-only scans request one internal schema column without a source bound' => array( 'row_id' ) === ( $provider->accesses[0] ?? null )?->projection()
		&& PHP_INT_MAX === ( $provider->accesses[0] ?? null )?->limit(),
	'malformed rows and provider failures return no partial aggregate result' => false === $malformed->return_value()
		&& array() === $malformed->wpdb_state()['last_result']
		&& 'invalid_provider_row' === ( $malformed->diagnostic()['reason'] ?? null )
		&& false === $failed->return_value()
		&& array() === $failed->wpdb_state()['last_result']
		&& 'markdown_db_native_malformed_table' === ( $failed->diagnostic()['code'] ?? null ),
);

$failure_count = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failure_count;
	}
}
exit( $failure_count ? 1 : 0 );
