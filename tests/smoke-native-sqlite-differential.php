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
