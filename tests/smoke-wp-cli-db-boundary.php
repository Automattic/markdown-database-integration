<?php
/**
 * Smoke test the typed WP-CLI boundary for MySQL-only database commands.
 *
 * Usage: php tests/smoke-wp-cli-db-boundary.php
 */

declare( strict_types=1 );

if ( isset( $argv[1] ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
	if ( 'mdi-native' === $argv[1] ) {
		define( 'MARKDOWN_DB_DROPIN', true );
		define( 'MARKDOWN_DB_BACKEND', 'mdi-native' );
	} else {
		define( 'DB_ENGINE', 'mysql' );
	}

	class WP_CLI {
		public static ?Throwable $error = null;
		public static array $hooks = array();

		public static function add_hook( string $name, callable $callback ): void {
			self::$hooks[ $name ] = $callback;
		}

		public static function error( Throwable $error ): void {
			self::$error = $error;
			throw new RuntimeException( 'WP-CLI stopped command execution.' );
		}
	}

	require_once __DIR__ . '/../inc/class-wp-markdown-cli.php';
	WP_Markdown_CLI::register_db_command_boundary();

	try {
		$result = WP_CLI::$hooks['before_invoke:db check']( 'db check' );
		echo json_encode( array( 'registered' => true, 'result' => $result ) );
	} catch ( RuntimeException $error ) {
		$typed = WP_CLI::$error;
		echo json_encode(
			array(
				'registered' => isset( WP_CLI::$hooks['before_invoke:db check'] ),
				'stopped'    => 'WP-CLI stopped command execution.' === $error->getMessage(),
				'class'      => is_object( $typed ) ? get_class( $typed ) : null,
				'diagnostic' => $typed instanceof WP_Markdown_Unsupported_WP_CLI_DB_Command ? $typed->get_diagnostic() : null,
			)
		);
	}
	exit;
}

$failures = array();
function mdi_wp_cli_db_assert( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$run = static function ( string $backend ): array {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $backend );
	$output  = shell_exec( $command );
	$result  = json_decode( (string) $output, true );
	return is_array( $result ) ? $result : array();
};

$native = $run( 'mdi-native' );
mdi_wp_cli_db_assert( true === ( $native['registered'] ?? false ), 'the exact db check invocation hook is registered' );
mdi_wp_cli_db_assert( true === ( $native['stopped'] ?? false ), 'native runtime stops db check before the MySQL command runs' );
mdi_wp_cli_db_assert( WP_Markdown_Unsupported_WP_CLI_DB_Command::class === ( $native['class'] ?? null ), 'native runtime emits the typed unsupported-command outcome' );
$diagnostic = $native['diagnostic'] ?? array();
mdi_wp_cli_db_assert( 'markdown_db_unsupported_wp_cli_db_command' === ( $diagnostic['code'] ?? null ), 'diagnostic code is stable' );
mdi_wp_cli_db_assert( 'mdi-native' === ( $diagnostic['backend'] ?? null ) && 'wp db check' === ( $diagnostic['command'] ?? null ), 'diagnostic identifies backend and command' );
mdi_wp_cli_db_assert( 'wp markdown-db doctor' === ( $diagnostic['remediation'] ?? null ), 'diagnostic names the canonical remediation' );
mdi_wp_cli_db_assert( str_contains( (string) ( $diagnostic['message'] ?? '' ), 'requires a MySQL backend' ), 'operator message explains the incompatibility' );

$mysql = $run( 'mysql' );
mdi_wp_cli_db_assert( 'db check' === ( $mysql['result'] ?? null ), 'MySQL runtime passes through unchanged' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo "All WP-CLI database boundary checks passed.\n";
