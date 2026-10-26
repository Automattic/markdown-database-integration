<?php
/** Canonical streaming shutdown persistence without a SQL adapter. */
declare( strict_types=1 );

if ( 'child' === ( $argv[1] ?? '' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
	require_once __DIR__ . '/stubs/stub-wp-markdown-storage.php';
	require_once __DIR__ . '/stubs/stub-backend-operations.php';
	require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';
	function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed { return $value; }
	function has_filter( string $tag ): bool { return false; }
	$operations = new class extends MDI_Stub_Backend_Operations {
		public function table_rows( string $table_suffix, ?array $policy = null ): iterable {
			for ( $id = 1; $id <= 700; $id++ ) {
				yield array( 'event_id' => $id, 'payload' => 'invalid' === $GLOBALS['argv'][3] && 2 === $id ? "\xB1" : str_repeat( 'x', 65536 ) );
			}
		}
	};
	$root = $argv[2];
	$engine = new WP_Markdown_Canonical_Persistence( $root, new WP_Markdown_Storage( $root ), $operations );
	$engine->persist_mutation( array( 'key' => 'events', 'resource' => 'events', 'operation' => 'UPDATE', 'table' => 'wp_runtime_events', 'context' => array() ) );
	exit; // Registered production shutdown flush, under a 24 MiB memory limit.
}
$root = sys_get_temp_dir() . '/mdi-stream-' . getmypid();
mkdir( $root . '/_tables', 0755, true );
register_shutdown_function( static function () use ( $root ): void {
	foreach ( glob( $root . '/_tables/*' ) ?: array() as $file ) { unlink( $file ); }
	rmdir( $root . '/_tables' ); rmdir( $root );
} );
$path = $root . '/_tables/runtime_events.json';
foreach ( array( 'valid', 'invalid' ) as $mode ) {
	file_put_contents( $path, '["previous"]' );
	$orphan = $path . '.tmp.999999.deadbeef';
	if ( 'valid' === $mode ) { file_put_contents( $orphan, 'orphan' ); touch( $orphan, time() - 600 ); }
	$process = proc_open( array( PHP_BINARY, '-d', 'memory_limit=24M', __FILE__, 'child', $root, $mode ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] );
	if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( $output ); }
	$value = file_get_contents( $path );
	if ( 'valid' === $mode ? 700 !== count( json_decode( $value, true ) ) : '["previous"]' !== $value ) { throw new RuntimeException( 'Streaming publication failed: ' . $mode ); }
	if ( glob( $path . '.tmp.*' ) ) { throw new RuntimeException( 'Streaming publication left temporary files.' ); }
	echo 'PASS: ' . $mode . " constrained-memory shutdown preserves canonical integrity\n";
}
