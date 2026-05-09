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

if ( ! class_exists( 'WP_SQLite_Driver' ) ) {
	class WP_SQLite_Driver {
		public function query( string $sql ): array {
			if ( str_contains( $sql, 'SHOW CREATE TABLE' ) ) {
				return array(
					(object) array(
						'Create Table' => 'CREATE TABLE `wp_datamachine_jobs` (`job_id` bigint(20), `flow_id` bigint(20), `created_at` datetime)',
					),
				);
			}

			return array(
				(object) array(
					'job_id'     => 1,
					'flow_id'    => 10,
					'created_at' => '2026-05-09 20:00:00',
				),
				(object) array(
					'job_id'     => 2,
					'flow_id'    => 10,
					'created_at' => '2026-05-09 21:00:00',
				),
			);
		}
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-write-engine.php';

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

function mdi_policy_engine( string $content_dir ): WP_Markdown_Write_Engine {
	return new WP_Markdown_Write_Engine(
		$content_dir,
		new WP_Markdown_Storage( $content_dir ),
		new WP_SQLite_Driver(),
		'wp_'
	);
}

$base = sys_get_temp_dir() . '/mdi-table-policy-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $base, 0755, true );

$persist_table = new ReflectionMethod( WP_Markdown_Write_Engine::class, 'persist_table' );
$persist_schema = new ReflectionMethod( WP_Markdown_Write_Engine::class, 'persist_schema' );

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

// 3. Site policy can keep a table and compact rows before persistence.
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
	'markdown_db_persistent_table_rows',
	static function ( array $rows, string $table_suffix, string $table, ?array $policy ): array {
		unset( $table );
		if ( 'datamachine_jobs' !== $table_suffix || 'latest' !== ( $policy['keep'] ?? null ) ) {
			return $rows;
		}
		return array_slice( $rows, -1 );
	},
	10,
	4
);

$persist_table->invoke( mdi_policy_engine( $base ), 'datamachine_jobs' );
$rows = json_decode( (string) file_get_contents( $json_path ), true );
mdi_policy_assert_eq( count( is_array( $rows ) ? $rows : array() ), 1, 'row filter can compact persisted runtime table' );
mdi_policy_assert_eq( (int) ( $rows[0]['job_id'] ?? 0 ), 2, 'compacted runtime table kept latest row' );

mdi_policy_rm_rf( $base );

if ( $failed > 0 ) {
	echo PHP_EOL . "Failed: {$failed}" . PHP_EOL;
	exit( 1 );
}

echo PHP_EOL . "All {$passed} assertions passed." . PHP_EOL;
