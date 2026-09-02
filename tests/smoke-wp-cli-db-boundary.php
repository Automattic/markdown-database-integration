<?php
/**
 * Smoke test the typed WP-CLI boundary for MySQL-only database commands.
 *
 * Usage: php tests/smoke-wp-cli-db-boundary.php
 */

declare( strict_types=1 );

if ( 2 === $argc && 'early-bootstrap' === $argv[1] ) {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() );

	function markdown_db_default_content_dir(): string {
		return WP_CONTENT_DIR . '/db';
	}

	function add_action(): void {}

	require_once __DIR__ . '/../markdown-database-integration.php';
	echo json_encode(
		array(
			'version'    => defined( 'MARKDOWN_DB_VERSION' ),
			'plugin_dir' => defined( 'MARKDOWN_DB_PLUGIN_DIR' ) ? MARKDOWN_DB_PLUGIN_DIR : null,
		)
	);
	exit;
}

if ( isset( $argv[1] ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
	if ( 'sqlite' === $argv[1] ) {
		define( 'MARKDOWN_DB_DROPIN', true );
		define( 'DB_ENGINE', 'sqlite' );
	} elseif ( 'sqlite-unowned' === $argv[1] ) {
		define( 'DB_ENGINE', 'sqlite' );
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

$sqlite = $run( 'sqlite' );
mdi_wp_cli_db_assert( true === ( $sqlite['registered'] ?? false ), 'the exact db check invocation hook is registered' );
mdi_wp_cli_db_assert( true === ( $sqlite['stopped'] ?? false ), 'SQLite stops db check before the MySQL command runs' );
mdi_wp_cli_db_assert( WP_Markdown_Unsupported_WP_CLI_DB_Command::class === ( $sqlite['class'] ?? null ), 'SQLite emits the typed unsupported-command outcome' );
$diagnostic = $sqlite['diagnostic'] ?? array();
mdi_wp_cli_db_assert( 'markdown_db_unsupported_wp_cli_db_command' === ( $diagnostic['code'] ?? null ), 'diagnostic code is stable' );
mdi_wp_cli_db_assert( 'sqlite' === ( $diagnostic['backend'] ?? null ) && 'wp db check' === ( $diagnostic['command'] ?? null ), 'diagnostic identifies backend and command' );
mdi_wp_cli_db_assert( 'wp markdown-db doctor' === ( $diagnostic['remediation'] ?? null ), 'diagnostic names the canonical remediation' );
mdi_wp_cli_db_assert( str_contains( (string) ( $diagnostic['message'] ?? '' ), 'requires a MySQL backend' ), 'operator message explains the incompatibility' );

$mysql = $run( 'mysql' );
mdi_wp_cli_db_assert( 'db check' === ( $mysql['result'] ?? null ), 'non-SQLite runtimes pass through unchanged' );
$unowned_sqlite = $run( 'sqlite-unowned' );
mdi_wp_cli_db_assert( 'db check' === ( $unowned_sqlite['result'] ?? null ), 'MDI does not claim another SQLite drop-in boundary' );
$early_bootstrap = $run( 'early-bootstrap' );
mdi_wp_cli_db_assert( true === ( $early_bootstrap['version'] ?? false ), 'the plugin entrypoint loads before WordPress formatting helpers are available' );
mdi_wp_cli_db_assert( dirname( __DIR__ ) . '/' === ( $early_bootstrap['plugin_dir'] ?? null ), 'early bootstrap resolves the plugin directory without plugin_dir_path()' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo "All WP-CLI database boundary checks passed.\n";
