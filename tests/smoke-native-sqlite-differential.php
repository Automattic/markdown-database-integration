<?php
/** Verify the differential generator has stable complete category coverage. */

declare( strict_types=1 );

$workload = require __DIR__ . '/fuzz-native-sqlite-differential.php';
$fixture = mdi_fuzz_fixture( 'generator-smoke' );
$first = mdi_fuzz_cases( 'generator-smoke', $fixture );
$second = mdi_fuzz_cases( 'generator-smoke', $fixture );
$categories = array_count_values( array_column( $first, 'category' ) );
$outcome = static fn( array $rows ): array => array(
	'status' => 'ok',
	'columns' => array( 'item_id', 'label' ),
	'rows' => $rows,
);
$ordered_expected = $outcome( array( array( 'item_id' => '1', 'label' => 'one' ), array( 'item_id' => '2', 'label' => 'two' ) ) );
$reversed = $outcome( array( array( 'item_id' => '2', 'label' => 'two' ), array( 'item_id' => '1', 'label' => 'one' ) ) );
$duplicated = $outcome( array( array( 'item_id' => '1', 'label' => 'one' ), array( 'item_id' => '1', 'label' => 'one' ), array( 'item_id' => '2', 'label' => 'two' ) ) );
$duplicated_reversed = $outcome( array( array( 'item_id' => '2', 'label' => 'two' ), array( 'item_id' => '1', 'label' => 'one' ), array( 'item_id' => '1', 'label' => 'one' ) ) );
$missing_duplicate = $outcome( array( array( 'item_id' => '1', 'label' => 'one' ), array( 'item_id' => '2', 'label' => 'two' ) ) );
$report = $workload(
	array( 'runtime_env' => array( 'MDI_FUZZ_SEED' => 'generator-smoke' ) )
)['artifacts']['mdi_native_sqlite_differential'];
$selected = mdi_fuzz_selected_operations(
	array( 'operation_ids' => array(), 'families' => array( 'create' ) )
);
$bounded = $workload(
	array( 'execution_request' => array( 'schema' => 'homeboy/fuzz-execution-request/v1', 'seed' => 'generator-smoke', 'operation_ids' => array( 'query.read' ), 'case_budget' => 3, 'duration_budget_seconds' => 60 ) )
)['artifacts']['mdi_native_sqlite_differential'];
$skipped = $workload(
	array( 'execution_request' => array( 'schema' => 'homeboy/fuzz-execution-request/v1', 'operation_ids' => array( 'wpdb.observable-state', 'concurrency.deterministic' ) ) )
)['artifacts']['mdi_native_sqlite_differential'];
$homeboy_request = mdi_fuzz_request(
	array(
		'execution_request' => array(
			'schema' => 'homeboy/fuzz-execution-request/v1',
			'id' => 'homeboy-shape',
			'component' => 'markdown-database-integration',
			'sampling' => array(
				'seed' => 'homeboy-seed',
				'case_budget' => 7,
				'duration_budget_seconds' => 12,
				'operation_strata' => array(
					array( 'id' => 'selected-operation-families', 'kind' => 'operation_family', 'values' => array( 'create' ) ),
					array( 'id' => 'selected-operations', 'kind' => 'operation', 'values' => array( 'schema.ddl.introspection', 'constraints.defaults-uniqueness-auto-increment' ) ),
				),
			),
			'max_duration' => '2m',
			'metadata' => array( 'planner' => array( 'profile' => 'full' ) ),
		),
	)
);
$max_duration_request = mdi_fuzz_request(
	array( 'execution_request' => array( 'schema' => 'homeboy/fuzz-execution-request/v1', 'max_duration' => '2m' ) )
);
$profile_requests = array();
foreach ( array( 'quick', 'read-only', 'crud', 'full', 'nightly' ) as $profile ) {
	$profile_requests[ $profile ] = mdi_fuzz_request(
		array( 'execution_request' => array( 'schema' => 'homeboy/fuzz-execution-request/v1', 'metadata' => array( 'planner' => array( 'profile' => $profile ) ) ) )
	);
}

$checks = array(
	'generator replays identical cases' => $first === $second,
	'every covered category has a bounded fixed count' => 30 === count( $categories )
		&& array() === array_filter(
			$categories,
			static fn( int $count ): bool => MDI_FUZZ_CASES_PER_CATEGORY !== $count
		),
	'report retains SQL, warmup, metadata, and timing shape' => isset(
		$report['replay_command'],
		$report['warmups'][0]['native_sql'],
		$report['warmups'][0]['sqlite_sql'],
		$report['warmups'][0]['ordered'],
		$report['categories']['union']['native_duration_ms'],
		$report['categories']['scalar.cast']['sqlite_duration_ms']
	) && isset( $report['mismatches'][0]['native']['columns'] ) === ( 0 < $report['mismatch_count'] )
		&& count( $report['cases'] ) === count( $first ),
	'operation-family filtering uses canonical Homeboy families' => array( 'schema.ddl.introspection', 'constraints.defaults-uniqueness-auto-increment' ) === $selected,
	'Homeboy sampling request drives exact operations, seed, and budgets' => array( 'schema.ddl.introspection', 'constraints.defaults-uniqueness-auto-increment' ) === $homeboy_request['operation_ids']
		&& array( 'create' ) === $homeboy_request['families']
		&& 'homeboy-seed' === $homeboy_request['seed']
		&& 7 === $homeboy_request['case_budget']
		&& 12.0 === $homeboy_request['duration_budget_seconds'],
	'Homeboy duration strings and profiles resolve to distinct bounded campaigns' => 120.0 === $max_duration_request['duration_budget_seconds']
		&& array( 'query.read' ) === $profile_requests['quick']['operation_ids']
		&& array( 'unsupported.error', 'query.read' ) === $profile_requests['read-only']['operation_ids']
		&& array( 'dml.generic', 'constraints.defaults-uniqueness-auto-increment', 'query.read' ) === $profile_requests['crud']['operation_ids']
		&& 256 === $profile_requests['full']['case_budget']
		&& 512 === $profile_requests['nightly']['case_budget'],
	'normalized request bounds cases and retains duration metadata' => 3 === count( $bounded['cases'] )
		&& 60.0 === $bounded['campaign']['duration_budget_seconds'],
	'coverage reports genuine unavailable surfaces as skips' => 9 === $skipped['coverage']['declared_operations']
		&& 2 === $skipped['coverage']['skipped_operations']
		&& 7 === $skipped['coverage']['executable_operations']
		&& 0 === $skipped['coverage']['selected_executable_operations'],
	'canonical Homeboy evidence is embedded without replacing the detailed report' => 'homeboy/fuzz-result-envelope/v1' === $report['result_envelope']['schema']
		&& 'homeboy/fuzz-campaign/v1' === $report['homeboy_campaign']['schema']
		&& 'homeboy/fuzz-coverage-summary/v1' === $report['coverage_summary']['schema']
		&& 'homeboy/fuzz-replay/v1' === $report['replay']['schema']
		&& 'homeboy/fuzz-case-log/v1' === $report['case_log'][0]['schema']
		&& 'case_log' === $report['homeboy_campaign']['artifacts'][1]['kind'],
	'direct default remains the complete 240-query campaign' => 240 === count( $report['cases'] )
		&& array( 'query.read' ) === $report['campaign']['operation_ids'],
	'ordered comparisons retain row sequence' => ! mdi_fuzz_compare( $ordered_expected, $reversed, true )['compatible'],
	'unordered comparisons preserve duplicate multiplicity' => mdi_fuzz_compare( $ordered_expected, $reversed, false )['compatible']
		&& mdi_fuzz_compare( $duplicated, $duplicated_reversed, false )['compatible']
		&& ! mdi_fuzz_compare( $duplicated, $missing_duplicate, false )['compatible'],
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	fwrite( $passed ? STDOUT : STDERR, ( $passed ? 'PASS' : 'FAIL' ) . ": {$label}\n" );
	$failed = $failed || ! $passed;
}

exit( $failed ? 1 : 0 );
