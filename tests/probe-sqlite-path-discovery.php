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
$managed = is_file( $sqlite . '/wp-includes/sqlite/class-wp-sqlite-storage.php' );

if ( 3 === $argc ) {
	$failed = 0;
	foreach ( array( 'mirror', 'primary' ) as $mode ) {
		$scenarios = array( 'legacy', 'legacy-php', 'fqdb', 'db-file', 'db-path', 'new-db-path' );
		if ( 'mirror' === $mode ) {
			$scenarios = array_merge( $scenarios, array( 'conflicting-paths', 'both-paths', 'memory' ) );
		}
		if ( $managed ) {
			$scenarios = array_merge( $scenarios, array( 'randomized', 'custom-managed', 'fresh-managed', 'missing-recorded' ) );
		}
		foreach ( $scenarios as $scenario ) {
			$command = array( PHP_BINARY, __FILE__, $wordpress, $sqlite, $scenario, $mode );
			passthru( implode( ' ', array_map( 'escapeshellarg', $command ) ), $status );
			$failed += 0 === $status ? 0 : 1;
		}
	}
	exit( $failed ? 1 : 0 );
}

$scenario = $argv[3];
$mode = $argv[4] ?? 'mirror';
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
define( 'MARKDOWN_DB_MODE', $mode );
$GLOBALS['table_prefix'] = 'wp_';

$database_root = $content . '/database';
$expected_path = $database_root . '/.ht.sqlite';
switch ( $scenario ) {
	case 'fqdb':
		$expected_path = $root . '/configured/legacy.sqlite';
		define( 'FQDB', $expected_path );
		break;
	case 'db-file':
		$database_root = $root . '/configured';
		$expected_path = $database_root . '/custom.sqlite';
		define( 'DB_DIR', $database_root );
		define( 'DB_FILE', 'custom.sqlite' );
		break;
	case 'db-path':
	case 'conflicting-paths':
	case 'both-paths':
	case 'new-db-path':
		$expected_path = $root . '/configured/active.sqlite';
		define( 'DB_PATH', $expected_path );
		if ( in_array( $scenario, array( 'conflicting-paths', 'both-paths' ), true ) ) {
			define( 'FQDB', $root . '/unused/wrong.sqlite' );
			define( 'FQDBDIR', $root . '/unused/' );
			if ( 'both-paths' === $scenario ) {
				mkdir( dirname( FQDB ), 0755 );
				$wrong = new PDO( 'sqlite:' . FQDB );
				$wrong->exec( 'CREATE TABLE wrong_database (value TEXT)' );
				$wrong = null;
				$wrong_hash = hash_file( 'sha256', FQDB );
			}
		}
		break;
	case 'memory':
		$expected_path = ':memory:';
		define( 'DB_PATH', $expected_path );
		break;
	case 'legacy-php':
		$expected_path .= '.php';
		break;
	case 'custom-managed':
		$database_root = $root . '/configured';
		define( 'DB_DIR', $database_root );
		// Fall through to create real managed storage.
	case 'randomized':
	case 'missing-recorded':
		require_once $sqlite . '/wp-includes/sqlite/class-wp-sqlite-storage.php';
		$expected_path = WP_SQLite_Storage::with_secret_path( $database_root )->initialize();
		if ( 'missing-recorded' === $scenario ) {
			unlink( $expected_path );
		}
		break;
}

$existing = ! in_array( $scenario, array( 'new-db-path', 'memory', 'fresh-managed', 'missing-recorded' ), true );
if ( $existing ) {
	if ( ! is_dir( dirname( $expected_path ) ) ) {
		mkdir( dirname( $expected_path ), 0755, true );
	}
	$seed = new PDO( 'sqlite:' . $expected_path );
	$seed->exec( 'CREATE TABLE path_probe (value TEXT)' );
	$seed->exec( "INSERT INTO path_probe VALUES ('configured database')" );
	$seed = null;
} elseif ( 'missing-recorded' !== $scenario ) {
	define( 'MARKDOWN_DB_BACKEND', 'sqlite' );
}

require_once ABSPATH . WPINC . '/compat.php';
require_once ABSPATH . WPINC . '/plugin.php';
require_once ABSPATH . WPINC . '/load.php';
require_once ABSPATH . WPINC . '/formatting.php';
require_once ABSPATH . WPINC . '/class-wpdb.php';

set_error_handler( static function ( int $severity, string $message, string $file, int $line ): bool {
	if ( ! ( error_reporting() & $severity ) ) {
		return false;
	}
	if ( E_USER_WARNING === $severity && in_array( $message, array( 'FQDB conflicts with DB_PATH.', 'FQDBDIR conflicts with DB_PATH.' ), true ) ) {
		return true;
	}
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );
require $content . '/db.php';
restore_error_handler();

if ( 'sqlite' !== MARKDOWN_DB_BACKEND || ! $GLOBALS['wpdb'] instanceof WP_SQLite_DB || ! $GLOBALS['wpdb']->ready ) {
	throw new RuntimeException( 'The SQLite database did not connect: ' . $GLOBALS['wpdb']->last_error );
}
if ( 'primary' === $mode ) {
	if ( ! defined( 'MARKDOWN_DB_INSTALL_FALLBACK' ) || $GLOBALS['wpdb'] instanceof WP_Markdown_DB ) {
		throw new RuntimeException( 'Primary mode did not use the SQLite installation fallback.' );
	}
} elseif ( ! $GLOBALS['wpdb'] instanceof WP_Markdown_DB ) {
	throw new RuntimeException( 'Mirror mode did not use the Markdown database.' );
}
$pdo = $GLOBALS['@pdo'];
$active_path = $pdo->query( 'PRAGMA database_list' )->fetch( PDO::FETCH_ASSOC )['file'];
if ( 'memory' === $scenario ) {
	if ( '' !== $active_path || is_dir( $database_root ) || is_file( getcwd() . '/.htaccess' ) ) {
		throw new RuntimeException( 'In-memory storage created filesystem artifacts.' );
	}
} else {
	if ( $managed && in_array( $scenario, array( 'legacy', 'legacy-php', 'fresh-managed' ), true ) ) {
		$expected_path = include $database_root . '/db-path.php';
	} elseif ( 'primary' === $mode && 'legacy-php' === $scenario ) {
		$expected_path = FQDB;
	}
	if ( realpath( $expected_path ) !== realpath( $active_path ) ) {
		throw new RuntimeException( 'Opened the wrong database: ' . json_encode( array( 'expected' => $expected_path, 'databases' => $pdo->query( 'PRAGMA database_list' )->fetchAll( PDO::FETCH_ASSOC ) ) ) );
	}
	if ( ! is_file( dirname( $active_path ) . '/.htaccess' ) || ! is_file( dirname( $active_path ) . '/index.php' ) ) {
		throw new RuntimeException( 'The active database directory lacks protections.' );
	}
}
if ( $existing && 'configured database' !== $pdo->query( 'SELECT value FROM path_probe' )->fetchColumn() ) {
	throw new RuntimeException( 'The existing database content was not preserved.' );
}
if ( 'both-paths' === $scenario ) {
	if ( $wrong_hash !== hash_file( 'sha256', FQDB ) || is_file( dirname( FQDB ) . '/.htaccess' ) || is_file( dirname( FQDB ) . '/index.php' ) ) {
		throw new RuntimeException( 'Mirror changed the conflicting FQDB storage.' );
	}
} elseif ( is_dir( $root . '/unused' ) ) {
	throw new RuntimeException( 'Mirror created the conflicting FQDB directory.' );
}

echo "PASS: {$mode}/{$scenario} connects to the selected database and protects its directory.\n";
