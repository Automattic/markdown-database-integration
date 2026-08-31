<?php
/** Seeded behavioral differential between the MDI native runtime and SQLite. */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

const MDI_FUZZ_DEFAULT_SEED = 'mdi-232-native-sqlite-v1';
const MDI_FUZZ_CASES        = 256;
const MDI_FUZZ_ROWS         = 48;

/** Return a stable bounded integer without relying on process-global PRNG state. */
function mdi_fuzz_number( string $seed, string $key, int $maximum ): int {
	return hexdec( substr( hash( 'sha256', $seed . ':' . $key ), 0, 7 ) ) % $maximum;
}

function mdi_fuzz_literal( string $value ): string {
	return "'" . str_replace( "'", "''", $value ) . "'";
}

/** Normalize PDO and native scalar types while retaining row and column order. */
function mdi_fuzz_normalize_rows( array $rows ): array {
	return array_map(
		static function ( object|array $row ): array {
			return array_map(
				static fn( mixed $value ): mixed => null === $value ? null : (string) $value,
				(array) $row
			);
		},
		$rows
	);
}

function mdi_fuzz_native_outcome( WP_Markdown_Native_Query_Runtime $runtime, string $query ): array {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $query ) );
	if ( false === $result->return_value() ) {
		$diagnostic = $result->diagnostic();
		return array(
			'status'     => 'error',
			'error_code' => (string) ( $diagnostic['code'] ?? '' ),
			'message'    => (string) ( $diagnostic['message'] ?? '' ),
		);
	}

	return array(
		'status' => 'ok',
		'rows'   => mdi_fuzz_normalize_rows( $result->wpdb_state()['last_result'] ?? array() ),
	);
}

function mdi_fuzz_sqlite_outcome( PDO $pdo, string $query ): array {
	try {
		$statement = $pdo->query( $query );
		return array(
			'status' => 'ok',
			'rows'   => mdi_fuzz_normalize_rows( $statement->fetchAll( PDO::FETCH_ASSOC ) ),
		);
	} catch ( PDOException $error ) {
		return array(
			'status'     => 'error',
			'error_code' => (string) $error->getCode(),
			'message'    => $error->getMessage(),
		);
	}
}

function mdi_fuzz_query( string $seed, int $case, array $rows ): string {
	$first  = mdi_fuzz_number( $seed, "{$case}:first", count( $rows ) );
	$second = mdi_fuzz_number( $seed, "{$case}:second", count( $rows ) );
	$third  = mdi_fuzz_number( $seed, "{$case}:third", count( $rows ) );
	$limit  = 1 + mdi_fuzz_number( $seed, "{$case}:limit", 7 );
	$offset = mdi_fuzz_number( $seed, "{$case}:offset", 6 );

	switch ( mdi_fuzz_number( $seed, "{$case}:shape", 10 ) ) {
		case 0:
			return 'SELECT ID, user_login FROM wp_users WHERE ID = ' . $rows[ $first ]['ID'] . ' ORDER BY ID ASC';
		case 1:
			return 'SELECT user_login, ID FROM wp_users WHERE user_login = ' . mdi_fuzz_literal( strtoupper( $rows[ $first ]['user_login'] ) ) . ' ORDER BY ID ASC';
		case 2:
			return 'SELECT ID FROM wp_users WHERE user_email = ' . mdi_fuzz_literal( strtoupper( $rows[ $first ]['user_email'] ) ) . ' ORDER BY ID ASC';
		case 3:
			return sprintf( 'SELECT ID, user_login FROM wp_users WHERE ID IN (%s, %s, %s) ORDER BY ID ASC', $rows[ $first ]['ID'], $rows[ $second ]['ID'], $rows[ $first ]['ID'] );
		case 4:
			return sprintf( 'SELECT ID, user_login FROM wp_users WHERE user_login IN (%s, %s, %s) ORDER BY user_login ASC', mdi_fuzz_literal( $rows[ $first ]['user_login'] ), mdi_fuzz_literal( strtoupper( $rows[ $second ]['user_login'] ) ), mdi_fuzz_literal( $rows[ $third ]['user_login'] ) );
		case 5:
			return "SELECT ID, user_login FROM wp_users ORDER BY ID DESC LIMIT {$offset}, {$limit}";
		case 6:
			return "SELECT user_login FROM wp_users ORDER BY user_login DESC LIMIT {$limit}";
		case 7:
			return sprintf( 'SELECT COUNT(*) FROM wp_users WHERE ID IN (%s, %s, %s)', $rows[ $first ]['ID'], $rows[ $second ]['ID'], $rows[ $third ]['ID'] );
		case 8:
			return 'SELECT * FROM wp_users WHERE ID = ' . $rows[ $first ]['ID'] . ' ORDER BY ID ASC';
		default:
			return sprintf( 'SELECT ID FROM wp_users WHERE 1=1 AND ((user_login IN (%s, %s))) ORDER BY ID ASC LIMIT %d, %d', mdi_fuzz_literal( $rows[ $first ]['user_login'] ), mdi_fuzz_literal( $rows[ $second ]['user_login'] ), $offset, $limit );
	}
}

function mdi_fuzz_remove_fixture( string $root ): void {
	@unlink( $root . '/_tables/users.json' );
	@rmdir( $root . '/_tables' );
	@rmdir( $root . '/_options' );
	@rmdir( $root );
}

$seed = getenv( 'MDI_FUZZ_SEED' );
$seed = false === $seed || '' === $seed ? MDI_FUZZ_DEFAULT_SEED : $seed;
$root = sys_get_temp_dir() . '/mdi-native-sqlite-fuzz-' . bin2hex( random_bytes( 6 ) );

$report = array(
	'schema'                => 'mdi/native-sqlite-differential/v1',
	'seed'                  => $seed,
	'generated_cases'       => MDI_FUZZ_CASES,
	'fixture_rows'          => MDI_FUZZ_ROWS,
	'status'                => 'failed',
	'passed_cases'          => 0,
	'mismatch_count'        => 0,
	'mismatches'            => array(),
	'unsupported_checks'    => array(),
	'native_duration_ms'    => 0.0,
	'sqlite_duration_ms'    => 0.0,
	'query_corpus_sha256'   => '',
	'replay_command'        => "MDI_FUZZ_SEED=" . escapeshellarg( $seed ) . ' php tests/fuzz-native-sqlite-differential.php',
);

try {
	if ( ! extension_loaded( 'pdo_sqlite' ) ) {
		throw new RuntimeException( 'pdo_sqlite is required for the reference runtime.' );
	}
	if ( ! mkdir( $root . '/_tables', 0777, true ) || ! mkdir( $root . '/_options', 0777, true ) ) {
		throw new RuntimeException( 'Failed to create the differential fixture.' );
	}

	$rows = array();
	for ( $index = 0; $index < MDI_FUZZ_ROWS; ++$index ) {
		$suffix = substr( hash( 'sha256', $seed . ':row:' . $index ), 0, 10 );
		$rows[] = array(
			'ID'                  => (string) ( $index + 1 ),
			'user_login'          => 'user_' . $suffix,
			'user_pass'           => 'hash_' . $suffix,
			'user_nicename'       => 'nice_' . $suffix,
			'user_email'          => 'user_' . $suffix . '@example.test',
			'user_url'            => '',
			'user_registered'     => sprintf( '2026-01-%02d 12:00:00', 1 + ( $index % 28 ) ),
			'user_activation_key' => '',
			'user_status'         => '0',
			'display_name'        => 'User ' . $suffix,
		);
	}
	file_put_contents( $root . '/_tables/users.json', json_encode( $rows, JSON_THROW_ON_ERROR ) );

	$runtime = new WP_Markdown_Native_Query_Runtime( WP_Markdown_Native_Runtime_Factory::registry( $root ) );
	$pdo     = new PDO( 'sqlite::memory:', null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
	$pdo->exec( 'CREATE TABLE wp_users (ID INTEGER PRIMARY KEY, user_login TEXT COLLATE NOCASE, user_pass TEXT, user_nicename TEXT COLLATE NOCASE, user_email TEXT COLLATE NOCASE, user_url TEXT, user_registered TEXT, user_activation_key TEXT, user_status TEXT, display_name TEXT)' );
	$insert = $pdo->prepare( 'INSERT INTO wp_users (ID, user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name) VALUES (:ID, :user_login, :user_pass, :user_nicename, :user_email, :user_url, :user_registered, :user_activation_key, :user_status, :display_name)' );
	foreach ( $rows as $row ) {
		$insert->execute( $row );
	}

	$queries = array();
	for ( $case = 0; $case < MDI_FUZZ_CASES; ++$case ) {
		$query     = mdi_fuzz_query( $seed, $case, $rows );
		$queries[] = $query;

		$started = hrtime( true );
		$native  = mdi_fuzz_native_outcome( $runtime, $query );
		$report['native_duration_ms'] += ( hrtime( true ) - $started ) / 1_000_000;

		$started = hrtime( true );
		$sqlite  = mdi_fuzz_sqlite_outcome( $pdo, $query );
		$report['sqlite_duration_ms'] += ( hrtime( true ) - $started ) / 1_000_000;

		if ( $native === $sqlite ) {
			++$report['passed_cases'];
			continue;
		}

		++$report['mismatch_count'];
		if ( count( $report['mismatches'] ) < 20 ) {
			$report['mismatches'][] = array(
				'case'   => $case,
				'query'  => $query,
				'native' => $native,
				'sqlite' => $sqlite,
			);
		}
	}

	$unsupported = array(
		"SELECT ID FROM wp_users WHERE user_login = 'admiñ'",
		"SELECT ID FROM wp_users WHERE user_login IN 'user_0000000000'",
	);
	foreach ( $unsupported as $query ) {
		$first  = mdi_fuzz_native_outcome( $runtime, $query );
		$second = mdi_fuzz_native_outcome( $runtime, $query );
		$stable = 'error' === $first['status'] && $first === $second && '' !== $first['error_code'];
		$report['unsupported_checks'][] = array(
			'query'      => $query,
			'stable'     => $stable,
			'diagnostic' => $first,
		);
		if ( ! $stable ) {
			++$report['mismatch_count'];
		}
	}

	$report['query_corpus_sha256'] = hash( 'sha256', implode( "\n", $queries ) );
	$report['native_duration_ms']  = round( $report['native_duration_ms'], 3 );
	$report['sqlite_duration_ms']  = round( $report['sqlite_duration_ms'], 3 );
	$report['status']              = 0 === $report['mismatch_count'] ? 'passed' : 'failed';
} catch ( Throwable $error ) {
	$report['failure'] = array(
		'class'   => get_class( $error ),
		'message' => $error->getMessage(),
	);
} finally {
	mdi_fuzz_remove_fixture( $root );
}

$artifact = array( 'artifacts' => array( 'mdi_native_sqlite_differential' => $report ) );
file_put_contents( '/tmp/mdi-native-sqlite-differential.json', json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) );
echo json_encode( $artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . PHP_EOL;
exit( defined( 'WPINC' ) || 'passed' === $report['status'] ? 0 : 1 );
