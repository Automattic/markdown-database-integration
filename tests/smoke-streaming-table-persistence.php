<?php
/**
 * Smoke tests for bounded shutdown table persistence.
 *
 * Usage: php tests/smoke-streaming-table-persistence.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( 'child' === ( $argv[1] ?? '' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
	$surface  = $argv[2];
	$base     = $argv[3];
	$database = $argv[4];
	$filtered = 'filtered' === ( $argv[5] ?? '' );
	function has_filter( string $tag ): bool {
		unset( $tag );
		return false;
	}
	function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
		global $filtered, $base;
		unset( $args );
		if ( 'markdown_db_persistent_table_query' === $tag && $filtered ) {
			file_put_contents( $base . '/query-filter-ran', 'yes' );
			return $value . ' LIMIT 2';
		}
		return $value;
	}
	require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';

	class WP_SQLite_Connection {
		public function __construct( private PDO $pdo ) {}

		public function get_pdo(): PDO {
			return $this->pdo;
		}
	}

	class MDI_Streaming_SQLite_Driver extends PDO {
		private WP_SQLite_Connection $connection;

		public function __construct( string $dsn, ?string $username = null, ?string $password = null, array $options = array() ) {
			unset( $dsn, $username, $password );
			$this->connection = new WP_SQLite_Connection( $options['pdo'] );
		}

		#[ReturnTypeWillChange]
		public function query( string $query, ?int $fetch_mode = null, ...$fetch_mode_args ) {
			if ( null === $fetch_mode ) {
				return $this->connection->get_pdo()->query( $query );
			}
			return $this->connection->get_pdo()->query( $query, $fetch_mode, ...$fetch_mode_args );
		}

		public function get_connection(): WP_SQLite_Connection {
			return $this->connection;
		}
	}

	class_alias( MDI_Streaming_SQLite_Driver::class, 'legacy' === $surface ? 'WP_PDO_MySQL_On_SQLite' : 'WP_MySQL_On_SQLite' );
	require_once __DIR__ . '/../inc/class-wp-markdown-frontmatter-profiles.php';
	require_once __DIR__ . '/../inc/class-wp-markdown-search.php';
	require_once __DIR__ . '/../inc/class-wp-markdown-driver.php';
	require_once __DIR__ . '/../inc/class-wp-markdown-write-engine.php';
	$pdo = new PDO( 'sqlite:' . $database );
	$driver = new WP_Markdown_Driver( new WP_SQLite_Connection( $pdo ), 'wordpress', new WP_Markdown_Storage( $base ) );
	$engine = new WP_Markdown_Write_Engine( $base, new WP_Markdown_Storage( $base ), $driver, 'wp_' );
	$driver->set_write_engine( $engine );
	$ordinary = $driver->query( 'SELECT event_id FROM wp_runtime_events ORDER BY event_id LIMIT 1' );
	file_put_contents( $base . '/ordinary-result', is_array( $ordinary ) ? 'array' : ( $ordinary instanceof PDOStatement ? 'statement' : 'other' ) );
	$driver->query( 'UPDATE wp_runtime_events SET payload = payload' );
	exit( 0 ); // The registered shutdown flush is the production request path.
}

$passed = 0;
$failed = 0;

function mdi_stream_assert_true( bool $condition, string $label, string $detail = '' ): void {
	global $passed, $failed;
	if ( $condition ) {
		echo '✓ ' . $label . PHP_EOL;
		$passed++;
		return;
	}
	echo '✗ ' . $label . ( '' === $detail ? '' : ': ' . $detail ) . PHP_EOL;
	$failed++;
}

function mdi_stream_rm_rf( string $directory ): void {
	foreach ( glob( $directory . '/*' ) ?: array() as $path ) {
		is_dir( $path ) ? mdi_stream_rm_rf( $path ) : @unlink( $path );
	}
	@rmdir( $directory );
}

function mdi_stream_database( string $path, bool $invalid = false, int $row_count = 700 ): void {
	$pdo = new PDO( 'sqlite:' . $path );
	$pdo->exec( 'CREATE TABLE wp_runtime_events (event_id INTEGER PRIMARY KEY, payload TEXT)' );
	$insert = $pdo->prepare( 'INSERT INTO wp_runtime_events (event_id, payload) VALUES (?, ?)' );
	$pdo->beginTransaction();
	for ( $id = 1; $id <= ( $invalid ? 2 : $row_count ); $id++ ) {
		$insert->execute( array( $id, $invalid && 2 === $id ? "\xB1" : str_repeat( 'x', 65536 ) ) );
	}
	$pdo->commit();
}

function mdi_stream_child( string $surface, string $base, string $database, bool $filtered = false ): array {
	$command = array( PHP_BINARY, '-d', 'memory_limit=24M', __FILE__, 'child', $surface, $base, $database, $filtered ? 'filtered' : '' );
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) {
		return array( -1, 'Unable to start child process.' );
	}
	$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	return array( proc_close( $process ), $output );
}

$base = sys_get_temp_dir() . '/mdi-stream-table-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $base . '/_tables', 0755, true );
$path = $base . '/_tables/runtime_events.json';

// A 44 MiB result exceeds this child process's PHP allocation limit when
// fetchAll() is used, but is safe when rows are fetched and serialized singly.
foreach ( array( 'legacy', 'current' ) as $surface ) {
	$database = $base . "/{$surface}-large.sqlite";
	mdi_stream_database( $database );
	file_put_contents( $path, '["previous"]' );
	$orphan = $path . '.tmp.999999.deadbeef';
	file_put_contents( $orphan, 'orphan' );
	touch( $orphan, time() - 600 );
	list( $status, $output ) = mdi_stream_child( $surface, $base, $database );
	$snapshot = file_get_contents( $path );
	$rows = json_decode( is_string( $snapshot ) ? $snapshot : '', true );

	mdi_stream_assert_true( 0 === $status, "{$surface} constrained-memory shutdown persistence completes", $output );
	mdi_stream_assert_true( is_array( $rows ) && 700 === count( $rows ), "{$surface} large streamed snapshot is valid and complete" );
	mdi_stream_assert_true( ( 'legacy' === $surface ? 'array' : 'statement' ) === file_get_contents( $base . '/ordinary-result' ), "{$surface} public query result remains compatible" );
	mdi_stream_assert_true( empty( glob( $path . '.tmp.*' ) ?: array() ), "{$surface} successful shutdown leaves no temporary snapshot" );
	mdi_stream_assert_true( ! file_exists( $orphan ), "{$surface} successful streamed snapshot reclaims an interrupted writer temp" );

	$filtered_database = $base . "/{$surface}-filtered.sqlite";
	mdi_stream_database( $filtered_database, false, 3 );
	@unlink( $base . '/query-filter-ran' );
	list( $status, $output ) = mdi_stream_child( $surface, $base, $filtered_database, true );
	$filtered_rows = json_decode( (string) file_get_contents( $path ), true );
	mdi_stream_assert_true( 0 === $status && file_exists( $base . '/query-filter-ran' ), "{$surface} query filter runs before cursor persistence", $output );
	mdi_stream_assert_true( is_array( $filtered_rows ) && 2 === count( $filtered_rows ), "{$surface} filtered cursor snapshot streams the selected rows" );

	$invalid_database = $base . "/{$surface}-invalid.sqlite";
	mdi_stream_database( $invalid_database, true );
	file_put_contents( $path, '["canonical"]' );
	list( $status, $output ) = mdi_stream_child( $surface, $base, $invalid_database );
	mdi_stream_assert_true( 0 === $status, "{$surface} shutdown absorbs bounded snapshot failures", $output );
	mdi_stream_assert_true( '["canonical"]' === file_get_contents( $path ), "{$surface} failed snapshot preserves the prior valid artifact" );
	mdi_stream_assert_true( empty( glob( $path . '.tmp.*' ) ?: array() ), "{$surface} failed snapshot removes its temporary file" );
}

mdi_stream_rm_rf( $base );

if ( $failed > 0 ) {
	echo PHP_EOL . "Failed: {$failed}" . PHP_EOL;
	exit( 1 );
}

echo PHP_EOL . "All {$passed} assertions passed." . PHP_EOL;
