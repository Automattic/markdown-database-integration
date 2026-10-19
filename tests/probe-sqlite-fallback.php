<?php
/**
 * Exercise SQLite fallback and configured-path precedence with real integrations.
 *
 * Usage: php tests/probe-sqlite-fallback.php /path/to/wordpress /path/to/sqlite-plugin
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
$managed = is_file( $sqlite . '/wp-includes/sqlite/class-wp-sqlite-storage.php' );
$configured_paths = array(
	'new-fqdb'          => array( 'FQDB', 'custom.sqlite', false ),
	'default-fqdb'      => array( 'FQDB', '.ht.sqlite', false ),
	'existing-fqdb'     => array( 'FQDB', 'custom.sqlite', true ),
	'memory-fqdb'       => array( 'FQDB', ':memory:', false ),
	'new-db-path'       => array( 'DB_PATH', 'custom.sqlite', false ),
	'default-db-path'   => array( 'DB_PATH', '.ht.sqlite', false ),
	'existing-db-path'  => array( 'DB_PATH', 'custom.sqlite', true ),
	'memory-db-path'    => array( 'DB_PATH', ':memory:', false ),
	'conflicting-paths' => array( 'DB_PATH', 'custom.sqlite', false ),
	'new-db-file'       => array( 'DB_FILE', 'custom.sqlite', false ),
	'default-db-file'   => array( 'DB_FILE', '.ht.sqlite', false ),
	'existing-db-file'  => array( 'DB_FILE', 'custom.sqlite', true ),
);

if ( 3 === $argc ) {
	$failed = 0;
	foreach ( array( 'primary', 'missing-plugin', 'mirror' ) as $fallback ) {
		$scenarios = 'mirror' === $fallback ? array_merge( array_keys( $configured_paths ), array( 'unknown-backend' ) ) : array( 'missing-name', 'empty-name', 'engine-override' );
		$layouts = array( 'mirror' === $fallback ? 'legacy-php' : 'legacy' );
		if ( $managed ) {
			$layouts[] = 'randomized';
		}
		foreach ( $scenarios as $scenario ) {
			foreach ( $layouts as $layout ) {
				$command = array( PHP_BINARY, __FILE__, $wordpress, $sqlite, $fallback, $scenario, $layout );
				passthru( implode( ' ', array_map( 'escapeshellarg', $command ) ), $status );
				$failed += 0 === $status ? 0 : 1;
			}
		}
	}
	exit( $failed ? 1 : 0 );
}

[ , , , $fallback, $scenario, $layout ] = $argv;
$mirror = 'mirror' === $fallback;
$root = sys_get_temp_dir() . '/mdi-sqlite-fallback-' . getmypid();
$content = $root . '/wp-content';
mkdir( $content . '/plugins', 0755, true );
register_shutdown_function( static function () use ( $root ): void {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) {
		$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $root );
} );
if ( 'missing-plugin' !== $fallback ) {
	symlink( dirname( __DIR__ ), $content . '/plugins/markdown-database-integration' );
}
symlink( $sqlite, $content . '/plugins/sqlite-database-integration' );
copy( dirname( __DIR__ ) . '/db.php', $content . '/db.php' );
chdir( $root );

define( 'ABSPATH', $wordpress . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', $content );
define( 'WP_DEBUG', false );
define( 'MARKDOWN_DB_VERSION', 'test' );
define( 'MARKDOWN_DB_BACKEND', 'unknown-backend' === $scenario ? 'unknown' : 'sqlite' );
define( 'MARKDOWN_DB_MODE', 'primary' === $fallback ? 'primary' : 'mirror' );
if ( 'missing-name' !== $scenario ) {
	define( 'DB_NAME', 'empty-name' === $scenario ? '' : 'wordpress' );
}
if ( 'engine-override' === $scenario ) {
	define( 'DB_ENGINE', 'mysql' );
}
$GLOBALS['table_prefix'] = 'wp_';

$directory = $content . '/database';
if ( 'randomized' === $layout ) {
	require_once $sqlite . '/wp-includes/sqlite/class-wp-sqlite-storage.php';
	$expected_path = WP_SQLite_Storage::with_secret_path( $directory )->initialize();
} else {
	mkdir( $directory, 0755 );
	$expected_path = $directory . ( 'legacy-php' === $layout ? '/.ht.sqlite.php' : '/.ht.sqlite' );
}
$seed = new PDO( 'sqlite:' . $expected_path );
$seed->exec( 'CREATE TABLE fallback_probe (value TEXT)' );
$seed->exec( "INSERT INTO fallback_probe VALUES ('existing database')" );
$seed = null;

$expected_value = 'existing database';
if ( $mirror ) {
	$neighbor_path = $expected_path;
	$neighbor_hash = hash_file( 'sha256', $neighbor_path );
	$directory_before = scandir( $directory );
	$metadata_hash = $managed && 'randomized' === $layout ? hash_file( 'sha256', $directory . '/db-path.php' ) : null;
}
if ( isset( $configured_paths[ $scenario ] ) ) {
	[ $constant, $filename, $existing ] = $configured_paths[ $scenario ];
	$expected_path = ':memory:' === $filename ? $filename : $directory . '/' . $filename;
	define( $constant, 'DB_FILE' === $constant ? $filename : $expected_path );
	if ( 'conflicting-paths' === $scenario ) {
		define( 'FQDB', $neighbor_path );
	}
	$expected_value = null;
	if ( $existing ) {
		$seed = new PDO( 'sqlite:' . $expected_path );
		$seed->exec( 'CREATE TABLE fallback_probe (value TEXT)' );
		$seed->exec( "INSERT INTO fallback_probe VALUES ('configured database')" );
		$seed = null;
		$expected_value = 'configured database';
	}
}

foreach ( array( 'compat.php', 'plugin.php', 'load.php', 'formatting.php', 'class-wpdb.php' ) as $file ) {
	require_once ABSPATH . WPINC . '/' . $file;
}
set_error_handler( static function ( int $severity, string $message, string $file, int $line ) use ( $scenario ): bool {
	if ( ! ( error_reporting() & $severity ) ) {
		return false;
	}
	if ( 'conflicting-paths' === $scenario && E_USER_WARNING === $severity && 'FQDB conflicts with DB_PATH.' === $message ) {
		return true;
	}
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );
try {
	require $content . '/db.php';
} catch ( WP_Markdown_Unknown_Backend $error ) {
	if ( 'unknown-backend' !== $scenario ) {
		throw $error;
	}
	if ( $directory_before !== scandir( $directory ) || $neighbor_hash !== hash_file( 'sha256', $neighbor_path ) ) {
		throw new RuntimeException( 'An unsupported backend changed SQLite storage.' );
	}
	echo "PASS: {$layout} storage is unchanged when backend selection fails.\n";
	exit( 0 );
}
restore_error_handler();
if ( 'unknown-backend' === $scenario ) {
	throw new RuntimeException( 'An unsupported backend did not fail.' );
}

$wpdb = $GLOBALS['wpdb'] ?? null;
$expected_class = $mirror ? 'WP_Markdown_DB' : 'WP_SQLite_DB';
if ( ! $wpdb instanceof WP_SQLite_DB || $expected_class !== get_class( $wpdb ) || ! $wpdb->ready ) {
	throw new RuntimeException( 'The selected SQLite database did not connect.' );
}
if ( 'primary' === $fallback && ! MARKDOWN_DB_INSTALL_FALLBACK ) {
	throw new RuntimeException( 'Primary mode did not select the installation fallback.' );
}
$expected_name = $mirror || 'engine-override' === $scenario ? 'wordpress' : 'database_name_here';
if ( $expected_name !== $wpdb->get_var( 'SELECT DATABASE()' ) ) {
	throw new RuntimeException( 'The fallback used the wrong database name.' );
}
if ( $managed && ! $mirror ) {
	$expected_path = include $directory . '/db-path.php';
}
$pdo = $GLOBALS['@pdo'];
$active_path = $pdo->query( 'PRAGMA database_list' )->fetch( PDO::FETCH_ASSOC )['file'];
if ( ':memory:' === $expected_path ) {
	if ( '' !== $active_path || is_file( $root . '/.htaccess' ) || is_file( $root . '/index.php' ) ) {
		throw new RuntimeException( 'In-memory storage created filesystem artifacts.' );
	}
} else {
	if ( realpath( $expected_path ) !== realpath( $active_path ) ) {
		throw new RuntimeException( 'Opened the wrong database: ' . $active_path );
	}
	if ( ! is_file( dirname( $active_path ) . '/.htaccess' ) || ! is_file( dirname( $active_path ) . '/index.php' ) ) {
		throw new RuntimeException( 'The active database directory lacks protections.' );
	}
}
if ( null === $expected_value ) {
	if ( 0 !== (int) $pdo->query( "SELECT COUNT(*) FROM sqlite_master WHERE name = 'fallback_probe'" )->fetchColumn() ) {
		throw new RuntimeException( 'A new configured database inherited neighboring data.' );
	}
} elseif ( $expected_value !== $pdo->query( 'SELECT value FROM fallback_probe' )->fetchColumn() ) {
	throw new RuntimeException( 'The selected database content was not preserved.' );
}
if ( $mirror ) {
	if ( DB_PATH !== $expected_path || $neighbor_hash !== hash_file( 'sha256', $neighbor_path ) ) {
		throw new RuntimeException( 'The configured path changed or neighboring storage was modified.' );
	}
	if ( null !== $metadata_hash && $metadata_hash !== hash_file( 'sha256', $directory . '/db-path.php' ) ) {
		throw new RuntimeException( 'An explicit path changed managed storage metadata.' );
	}
}

echo "PASS: {$fallback}/{$scenario}/{$layout} uses the selected SQLite storage.\n";
