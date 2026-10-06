<?php
/**
 * Exercise the real WordPress and SQLite integration classes in disposable sites.
 *
 * Usage: php tests/probe-sqlite-path-discovery.php /path/to/wordpress /path/to/sqlite-plugin
 * For a SQLite source checkout, pass packages/plugin-sqlite-database-integration.
 */

declare( strict_types=1 );

if ( $argc < 3 ) {
	fwrite( STDERR, "Pass the WordPress directory and SQLite integration plugin directory.\n" );
	exit( 1 );
}

$wordpress = realpath( $argv[1] );
$sqlite = realpath( $argv[2] );
if ( ! $wordpress || ! is_file( $wordpress . '/wp-includes/class-wpdb.php' ) || ! $sqlite || ! is_file( $sqlite . '/constants.php' ) ) {
	throw new RuntimeException( 'WordPress or SQLite integration source is missing.' );
}

if ( 3 === $argc ) {
	$scenarios = array( 'fqdb' );
	$failed = 0;
	foreach ( $scenarios as $scenario ) {
		$command = array( PHP_BINARY, __FILE__, $wordpress, $sqlite, $scenario );
		passthru( implode( ' ', array_map( 'escapeshellarg', $command ) ), $status );
		$failed += 0 === $status ? 0 : 1;
	}
	exit( $failed ? 1 : 0 );
}

$scenario = $argv[3];
$root = sys_get_temp_dir() . '/mdi-sqlite-path-' . $scenario . '-' . getmypid();
$content = $root . '/wp-content';
mkdir( $content . '/plugins', 0755, true );
register_shutdown_function( static function () use ( $root ): void {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) {
		$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $root );
} );
symlink( dirname( __DIR__ ), $content . '/plugins/markdown-database-integration' );
symlink( $sqlite, $content . '/plugins/sqlite-database-integration' );
copy( dirname( __DIR__ ) . '/db.php', $content . '/db.php' );
chdir( $root );

define( 'ABSPATH', $wordpress . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', $content );
define( 'WP_DEBUG', false );
define( 'DB_NAME', 'wordpress' );
define( 'MARKDOWN_DB_VERSION', 'test' );
define( 'MARKDOWN_DB_MODE', 'mirror' );
$GLOBALS['table_prefix'] = 'wp_';

$expected_path = $root . '/configured/legacy.sqlite';
define( 'FQDB', $expected_path );
mkdir( dirname( $expected_path ), 0755, true );
$seed = new PDO( 'sqlite:' . $expected_path );
$seed->exec( 'CREATE TABLE path_probe (value TEXT)' );
$seed->exec( "INSERT INTO path_probe VALUES ('configured database')" );
$seed = null;

require_once ABSPATH . WPINC . '/compat.php';
require_once ABSPATH . WPINC . '/plugin.php';
require_once ABSPATH . WPINC . '/load.php';
require_once ABSPATH . WPINC . '/formatting.php';
require_once ABSPATH . WPINC . '/class-wpdb.php';

set_error_handler( static function ( int $severity, string $message, string $file, int $line ): bool {
	if ( ! ( error_reporting() & $severity ) ) {
		return false;
	}
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );
require $content . '/db.php';
restore_error_handler();

if ( 'sqlite' !== MARKDOWN_DB_BACKEND || ! $GLOBALS['wpdb'] instanceof WP_Markdown_DB || ! $GLOBALS['wpdb']->ready ) {
	throw new RuntimeException( 'The SQLite mirror did not connect.' );
}
$pdo = $GLOBALS['@pdo'];
$active_path = $pdo->query( 'PRAGMA database_list' )->fetch( PDO::FETCH_ASSOC )['file'];
if ( realpath( $expected_path ) !== realpath( $active_path ) ) {
	throw new RuntimeException( 'Mirror opened the wrong database: ' . json_encode( array( 'expected' => $expected_path, 'databases' => $pdo->query( 'PRAGMA database_list' )->fetchAll( PDO::FETCH_ASSOC ) ) ) );
}
if ( 'configured database' !== $pdo->query( 'SELECT value FROM path_probe' )->fetchColumn() ) {
	throw new RuntimeException( 'The existing database content was not preserved.' );
}

echo "PASS: the SQLite driver retains the supplied database connection.\n";
