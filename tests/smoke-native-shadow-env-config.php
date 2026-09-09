<?php
/** db.php imports valid shadow input modes and rejects invalid environment values. */

declare( strict_types=1 );

$db = realpath( __DIR__ . '/../db.php' );
$content = sys_get_temp_dir() . '/mdi-shadow-env-' . bin2hex( random_bytes( 6 ) );
mkdir( $content, 0755, true );
$bootstrap = static function ( string $mode ) use ( $db, $content ): string {
	return 'define("WP_CONTENT_DIR", ' . var_export( $content, true ) . '); putenv("MARKDOWN_DB_BACKEND=sqlite"); putenv("MARKDOWN_DB_NATIVE_SHADOW_INPUT_MODE=' . $mode . '"); require ' . var_export( $db, true ) . '; var_export(defined("MARKDOWN_DB_NATIVE_SHADOW_INPUT_MODE") ? MARKDOWN_DB_NATIVE_SHADOW_INPUT_MODE : null);';
};
$run = static function ( string $code ): array {
	$output = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $output, $status );
	return array( $status, implode( "\n", $output ) );
};
list( $valid_status, $valid_output ) = $run( $bootstrap( 'sql_snapshot' ) );
list( $invalid_status, $invalid_output ) = $run( $bootstrap( 'invalid' ) );
$checks = array(
	'db.php imports the requested sql_snapshot input mode from the environment' => 0 === $valid_status && "'sql_snapshot'" === $valid_output,
	'db.php rejects an invalid shadow input mode before database bootstrap' => 0 !== $invalid_status && str_contains( $invalid_output, 'native shadow input mode must be canonical or sql_snapshot' ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	$failed += $passed ? 0 : 1;
}
rmdir( $content );
exit( $failed ? 1 : 0 );
