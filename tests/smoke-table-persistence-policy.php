<?php
/**
 * Smoke tests for table persistence policy hooks.
 *
 * Usage: php tests/smoke-table-persistence-policy.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['mdi_smoke_filters'] = array();

function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['mdi_smoke_filters'][ $tag ][ $priority ][] = array( $callback, $accepted_args );
}

function remove_all_filters( string $tag ): void {
	unset( $GLOBALS['mdi_smoke_filters'][ $tag ] );
}

function has_filter( string $tag ): bool {
	return ! empty( $GLOBALS['mdi_smoke_filters'][ $tag ] );
}

function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
	if ( empty( $GLOBALS['mdi_smoke_filters'][ $tag ] ) ) {
		return $value;
	}

	ksort( $GLOBALS['mdi_smoke_filters'][ $tag ] );
	foreach ( $GLOBALS['mdi_smoke_filters'][ $tag ] as $callbacks ) {
		foreach ( $callbacks as $registered ) {
			$callback      = $registered[0];
			$accepted_args = (int) $registered[1];
			$value         = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}

	return $value;
}

require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';

require_once __DIR__ . '/stubs/stub-backend-operations.php';
require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';

class MDI_Policy_Operations extends MDI_Stub_Backend_Operations {
	public array $queries = array();
	public int $rows_materialized = 0;
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable {
		$query = apply_filters( 'markdown_db_persistent_table_query', 'SELECT * FROM `wp_' . $table_suffix . '` ORDER BY 1', $table_suffix, 'wp_' . $table_suffix, $policy );
		$this->queries[] = $query;
		$bounded = str_contains( $query, 'LIMIT 1' );
		$this->rows_materialized += $bounded ? 1 : 10000;
		for ( $id = $bounded ? 10000 : 1; $id <= 10000; $id++ ) {
			yield array( 'job_id' => $id, 'flow_id' => 10, 'created_at' => '2026-05-09 21:00:00' );
		}
	}
}

$passed = 0;
$failed = 0;

function mdi_policy_assert_true( bool $cond, string $label, string $detail = '' ): void {
	global $passed, $failed;
	if ( $cond ) {
		echo '✓ ' . $label . PHP_EOL;
		$passed++;
		return;
	}

	echo '✗ ' . $label . ( '' !== $detail ? ' — ' . $detail : '' ) . PHP_EOL;
	$failed++;
}

function mdi_policy_assert_eq( mixed $actual, mixed $expected, string $label ): void {
	global $passed, $failed;
	if ( $actual === $expected ) {
		echo '✓ ' . $label . PHP_EOL;
		$passed++;
		return;
	}

	echo '✗ ' . $label . PHP_EOL;
	echo '  expected: ' . var_export( $expected, true ) . PHP_EOL;
	echo '  actual:   ' . var_export( $actual, true ) . PHP_EOL;
	$failed++;
}

function mdi_policy_rm_rf( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = scandir( $dir );
	if ( ! is_array( $items ) ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			mdi_policy_rm_rf( $path );
			continue;
		}
		@unlink( $path );
	}

	@rmdir( $dir );
}

function mdi_policy_engine( string $content_dir, ?MDI_Policy_Operations $driver = null ): WP_Markdown_Canonical_Persistence {
	return new WP_Markdown_Canonical_Persistence(
		$content_dir,
		new WP_Markdown_Storage( $content_dir ),
		$driver ?? new MDI_Policy_Operations(),
		'wp_'
	);
}

$base = sys_get_temp_dir() . '/mdi-table-policy-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $base, 0755, true );

$persist_table = new ReflectionMethod( WP_Markdown_Canonical_Persistence::class, 'persist_table' );
$persist_schema = new ReflectionMethod( WP_Markdown_Canonical_Persistence::class, 'persist_schema' );

// 1. Existing behavior remains: unconfigured plugin/runtime tables persist.
$persist_table->invoke( mdi_policy_engine( $base ), 'datamachine_jobs' );
$json_path = $base . '/_tables/datamachine_jobs.json';
mdi_policy_assert_true( file_exists( $json_path ), 'unconfigured non-core table still persists by default' );

// 2. Site policy can explicitly opt a table out.
@unlink( $json_path );
add_filter(
	'markdown_db_table_persistence_policy',
	static function ( array $policy ): array {
		$policy['datamachine_jobs'] = false;
		return $policy;
	}
);
$persist_table->invoke( mdi_policy_engine( $base ), 'datamachine_jobs' );
mdi_policy_assert_true( ! file_exists( $json_path ), 'false policy skips table JSON persistence' );

$schema_path = $base . '/_schema/datamachine_jobs.sql';
$persist_schema->invoke( mdi_policy_engine( $base ), 'CREATE TABLE wp_datamachine_jobs', 'wp_datamachine_jobs', 'CREATE' );
mdi_policy_assert_true( ! file_exists( $schema_path ), 'false policy skips schema persistence' );
remove_all_filters( 'markdown_db_table_persistence_policy' );

// 3. A non-persistent dirty table does not issue a table read or rewrite an
// existing snapshot, regardless of historical fixture size.
file_put_contents( $json_path, '["canonical"]' );
$driver = new MDI_Policy_Operations();
add_filter(
	'markdown_db_table_persistence_policy',
	static function ( array $policy ): array {
		$policy['datamachine_jobs'] = false;
		return $policy;
	}
);
$persist_table->invoke( mdi_policy_engine( $base, $driver ), 'datamachine_jobs' );
mdi_policy_assert_eq( $driver->queries, array(), 'false policy performs no table SELECT' );
mdi_policy_assert_eq( file_get_contents( $json_path ), '["canonical"]', 'false policy performs no JSON rewrite' );
remove_all_filters( 'markdown_db_table_persistence_policy' );

// 4. A query-level policy bounds rows before PHP materializes the fixture.
add_filter(
	'markdown_db_table_persistence_policy',
	static function ( array $policy ): array {
		$policy['datamachine_jobs'] = array(
			'persist' => true,
			'keep'    => 'latest',
		);
		return $policy;
	}
);
add_filter(
	'markdown_db_persistent_table_query',
	static function ( string $query, string $table_suffix, string $table, ?array $policy ): string {
		unset( $table );
		return 'datamachine_jobs' === $table_suffix && 'latest' === ( $policy['keep'] ?? null )
			? str_replace( 'ORDER BY 1', 'ORDER BY 1 DESC', $query ) . ' LIMIT 1'
			: $query;
	},
	10,
	4
);
$row_filter_runs = 0;
add_filter(
	'markdown_db_persistent_table_rows',
	static function ( array $rows, string $table_suffix, string $table, ?array $policy ) use ( &$row_filter_runs ): array {
		unset( $table );
		$row_filter_runs++;
		if ( 'datamachine_jobs' !== $table_suffix || 'latest' !== ( $policy['keep'] ?? null ) ) {
			return $rows;
		}
		return $rows;
	},
	10,
	4
);

$driver = new MDI_Policy_Operations();
$persist_table->invoke( mdi_policy_engine( $base, $driver ), 'datamachine_jobs' );
$rows = json_decode( (string) file_get_contents( $json_path ), true );
mdi_policy_assert_true( str_contains( $driver->queries[0] ?? '', 'LIMIT 1' ), 'bounded policy applies LIMIT before query execution' );
mdi_policy_assert_eq( $driver->rows_materialized, 1, 'bounded policy materializes one row from a 10,000-row fixture' );
mdi_policy_assert_eq( count( is_array( $rows ) ? $rows : array() ), 1, 'query-level policy compacts persisted runtime table' );
mdi_policy_assert_eq( (int) ( $rows[0]['job_id'] ?? 0 ), 10000, 'bounded query kept the selected latest row' );
mdi_policy_assert_eq( $row_filter_runs, 1, 'row filter retains its complete-array invocation contract' );

mdi_policy_rm_rf( $base );

if ( $failed > 0 ) {
	echo PHP_EOL . "Failed: {$failed}" . PHP_EOL;
	exit( 1 );
}

echo PHP_EOL . "All {$passed} assertions passed." . PHP_EOL;
