<?php
/** PHP mysqli integration contract for the embedded native engine sidecar. */

declare( strict_types=1 );

if ( ! extension_loaded( 'mysqli' ) ) {
	throw new RuntimeException( 'The native MySQL sidecar integration requires mysqli.' );
}
$binary = $argv[1] ?? '';
if ( '' === $binary || ! is_file( $binary ) || ! is_executable( $binary ) ) {
	throw new InvalidArgumentException( 'Pass an executable native engine binary.' );
}

$process = proc_open(
	array( $binary, '--listen=127.0.0.1:0', '--database=mdi_native' ),
	array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	),
	$pipes,
	null,
	null,
	array( 'bypass_shell' => true )
);
if ( ! is_resource( $process ) ) {
	throw new RuntimeException( 'Failed to launch the native engine sidecar.' );
}
fclose( $pipes[0] );
stream_set_timeout( $pipes[1], 15 );
$ready_line = fgets( $pipes[1] );
if ( false === $ready_line ) {
	$error = stream_get_contents( $pipes[2] );
	proc_terminate( $process );
	proc_close( $process );
	throw new RuntimeException( 'Native engine did not become ready: ' . trim( (string) $error ) );
}
$ready = json_decode( $ready_line, true, 512, JSON_THROW_ON_ERROR );
if ( 'ready' !== ( $ready['status'] ?? null )
	|| ! is_string( $ready['host'] ?? null )
	|| ! is_int( $ready['port'] ?? null )
	|| ! is_string( $ready['database'] ?? null )
) {
	throw new RuntimeException( 'Native engine emitted an invalid readiness envelope.' );
}

$checks = array();
$connection = null;
try {
	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
	$connection = new mysqli( $ready['host'], 'root', '', $ready['database'], $ready['port'] );
	$connection->set_charset( 'utf8mb4' );

	$result = $connection->query( 'SELECT id, label FROM compat_probe ORDER BY id ASC' );
	$rows = $result->fetch_all( MYSQLI_ASSOC );
	$fields = $result->fetch_fields();
	$checks['mysqli text protocol returns deterministic rows'] = array(
		array( 'id' => '1', 'label' => 'native-one' ),
		array( 'id' => '2', 'label' => 'native-two' ),
	) === $rows;
	$checks['mysqli exposes native MySQL column metadata'] = 8 === ( $fields[0]->type ?? null )
		&& in_array( $fields[1]->type ?? null, array( 252, 253, 254 ), true )
		&& 'compat_probe' === ( $fields[0]->table ?? null );

	$statement = $connection->prepare( 'SELECT label FROM compat_probe WHERE id = ?' );
	$id = 2;
	$statement->bind_param( 'i', $id );
	$statement->execute();
	$prepared = $statement->get_result()->fetch_assoc();
	$statement->close();
	$checks['mysqli prepared statements use the binary protocol'] = array( 'label' => 'native-two' ) === $prepared;

	$session = $connection->query( 'SELECT @@version AS version, @@autocommit AS autocommit' )->fetch_assoc();
	$checks['mysqli observes MySQL session variables'] = '' !== (string) ( $session['version'] ?? '' )
		&& '1' === (string) ( $session['autocommit'] ?? '' );

	$error_code = 0;
	try {
		$connection->query( 'SELECT FROM invalid_native_sql' );
	} catch ( mysqli_sql_exception $error ) {
		$error_code = $error->getCode();
	}
	$checks['invalid SQL returns a MySQL-visible error'] = $error_code > 0;
} finally {
	if ( $connection instanceof mysqli ) {
		$connection->close();
	}
	proc_terminate( $process, defined( 'SIGTERM' ) ? SIGTERM : 15 );
	$deadline = microtime( true ) + 10;
	do {
		$status = proc_get_status( $process );
		if ( ! $status['running'] ) {
			break;
		}
		usleep( 50000 );
	} while ( microtime( true ) < $deadline );
	if ( $status['running'] ?? false ) {
		proc_terminate( $process, defined( 'SIGKILL' ) ? SIGKILL : 9 );
	}
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $process );
	$checks['native engine exits after a graceful termination signal'] = ! ( $status['running'] ?? true );
}

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}
exit( $failed ? 1 : 0 );
