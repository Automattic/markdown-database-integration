<?php
/**
 * Autocommit reads share the canonical root lock.
 *
 * Every statement used to take the root lock exclusively, so reads serialized
 * behind each other and behind writers. A reader that waited out the 5 s
 * budget failed with "The canonical transaction write lock timed out.", and a
 * failed options read is what WordPress reports as "Error establishing a
 * database connection". Reads now hold the lock shared; writers still exclude
 * them for a whole statement or transaction (see
 * smoke-native-transaction-read-isolation.php), and an abandoned journal still
 * gets recovered before anything is read.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$query = static fn( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): WP_Markdown_Query_Result => $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );

if ( isset( $argv[1], $argv[2] ) && 'hold-read' === $argv[1] ) {
	// Hold a shared read lock open the way a slow concurrent reader would.
	$journal = new WP_Markdown_Native_Transaction_Journal( $argv[2] );
	$held    = $journal->begin_read();
	file_put_contents( $argv[2] . '/reader-holding', true === $held ? 'shared' : (string) $held );
	for ( $attempt = 0; $attempt < 500 && ! is_file( $argv[2] . '/release-reader' ); ++$attempt ) {
		usleep( 10000 );
	}
	$journal->finish_read();
	exit( 0 );
}

$root = sys_get_temp_dir() . '/mdi-native-shared-read-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0755, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$query( $runtime, 'CREATE TABLE wp_records (id BIGINT NOT NULL AUTO_INCREMENT, label VARCHAR(40) NULL, PRIMARY KEY (id))' );
$query( $runtime, "INSERT INTO wp_records (label) VALUES ('base')" );

// 1. A read proceeds without waiting while another process holds a shared read.
$holder = proc_open( array( PHP_BINARY, __FILE__, 'hold-read', $root ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
for ( $attempt = 0; $attempt < 500 && ! is_file( $root . '/reader-holding' ); ++$attempt ) {
	usleep( 10000 );
}
$holding  = 'shared' === @file_get_contents( $root . '/reader-holding' );
$started  = microtime( true );
$read     = $query( WP_Markdown_Native_Runtime_Factory::runtime( $root ), 'SELECT label FROM wp_records WHERE id = 1' );
$read_ms  = ( microtime( true ) - $started ) * 1000;
// A write must still wait for the shared holder, then succeed once it leaves.
$writer_journal = new WP_Markdown_Native_Transaction_Journal( $root );
$reflection     = new ReflectionClass( $writer_journal );
$wait_constant  = $reflection->getConstant( 'WRITE_LOCK_WAIT_US' );
file_put_contents( $root . '/release-reader', '1' );
$write = $query( WP_Markdown_Native_Runtime_Factory::runtime( $root ), "UPDATE wp_records SET label = 'written' WHERE id = 1" );
proc_close( $holder );

// 2. Locking reads keep the exclusive path.
$executor = new ReflectionMethod( WP_Markdown_Native_Query_Runtime::class, 'is_plain_read' );
$plain_cases = array(
	'SELECT 1'                                   => true,
	'  select * from wp_records'                 => true,
	'SHOW TABLES'                                => true,
	'DESCRIBE wp_records'                        => true,
	'SELECT * FROM wp_records FOR UPDATE'        => false,
	'SELECT * FROM wp_records LOCK IN SHARE MODE' => false,
	"SELECT GET_LOCK('x', 1)"                    => false,
	"UPDATE wp_records SET label = 'x'"          => false,
);
$classified = true;
foreach ( $plain_cases as $sql => $expected ) {
	$classified = $classified && $expected === $executor->invoke( ( new ReflectionClass( WP_Markdown_Native_Query_Runtime::class ) )->newInstanceWithoutConstructor(), $sql );
}

// 3. A read that finds an abandoned journal recovers it before reading.
$table_file = $root . '/_tables/records.json';
$pristine   = (string) file_get_contents( $table_file );
file_put_contents( $table_file, '[{"id":"1","label":"torn"}]' );
file_put_contents(
	$root . '/_journal/native-transaction-deadbeefdeadbeef.json',
	json_encode( array( array( 'path' => $table_file, 'existed' => true, 'contents' => base64_encode( $pristine ) ) ) )
);
$recovering_read = $query( WP_Markdown_Native_Runtime_Factory::runtime( $root ), 'SELECT label FROM wp_records WHERE id = 1' );
$journals_left   = glob( $root . '/_journal/native-transaction-*.json' ) ?: array();

$checks = array(
	'a read does not wait behind another shared reader'        => $holding && $read->succeeded() && 'base' === ( $read->wpdb_state()['last_result'][0]->label ?? null ) && $read_ms < 1000,
	'a write still succeeds once shared readers leave'         => 1 === $write->return_value() && 5000000 === $wait_constant,
	'locking and mutating statements keep the exclusive path'  => $classified,
	'a read recovers an abandoned journal before reading'      => array() === $journals_left && 'written' === ( $recovering_read->wpdb_state()['last_result'][0]->label ?? null ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}
if ( $failed ) {
	printf( "read %.1f ms holding=%s read=%s write=%s recovering=%s journals=%d\n", $read_ms, var_export( $holding, true ), json_encode( $read->wpdb_state()['last_result'] ?? null ), var_export( $write->return_value(), true ), json_encode( $recovering_read->wpdb_state()['last_result'] ?? $recovering_read->diagnostic() ), count( $journals_left ) );
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $files as $file ) {
	$file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
}
@rmdir( $root );

exit( $failed ? 1 : 0 );
