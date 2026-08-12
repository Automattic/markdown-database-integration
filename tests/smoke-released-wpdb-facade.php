<?php
/**
 * Verify the released SQLite wpdb facade receives legacy result shapes.
 *
 * Usage: php tests/smoke-released-wpdb-facade.php
 */

declare( strict_types=1 );

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	echo "SKIP: pdo_sqlite extension is not available.\n";
	exit( 0 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'SQLITE_DRIVER_VERSION', '3.0.0-rc.6' );

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	unset( $hook, $args );
	return $value;
}

class WP_SQLite_Connection {
	public function __construct( private PDO $pdo ) {}

	public function get_pdo(): PDO {
		return $this->pdo;
	}
}

class WP_PDO_MySQL_On_SQLite extends PDO {
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

	public function get_insert_id(): int {
		return (int) $this->connection->get_pdo()->lastInsertId();
	}
}

require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-frontmatter-profiles.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-storage.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-search.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-driver.php';

$root = sys_get_temp_dir() . '/mdi-released-wpdb-facade-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );
register_shutdown_function(
	static function () use ( $root ): void {
		foreach ( scandir( $root ) ?: array() as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				unlink( $root . '/' . $entry );
			}
		}
		rmdir( $root );
	}
);

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT, option_value TEXT)' );
$pdo->exec( "INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'https://local.test')" );

$driver = new WP_Markdown_Driver(
	new WP_SQLite_Connection( $pdo ),
	'wordpress',
	new WP_Markdown_Storage( $root )
);
$driver->begin_canonical_transaction();
$pdo->exec( "UPDATE wp_options SET option_value = 'https://rolled-back.test' WHERE option_name = 'siteurl'" );
$driver->rollback_canonical_transaction();
if ( 'https://local.test' !== $pdo->query( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" )->fetchColumn() ) {
	fwrite( STDERR, "FAIL: legacy canonical transaction did not use the initialized connection.\n" );
	exit( 1 );
}
$pdo->beginTransaction();
$pdo->exec( "UPDATE wp_options SET option_value = 'https://caller.test' WHERE option_name = 'siteurl'" );
$driver->begin_canonical_transaction();
$pdo->exec( "UPDATE wp_options SET option_value = 'https://nested.test' WHERE option_name = 'siteurl'" );
$driver->rollback_canonical_transaction();
if ( ! $pdo->inTransaction() || 'https://caller.test' !== $pdo->query( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" )->fetchColumn() ) {
	fwrite( STDERR, "FAIL: legacy canonical rollback did not preserve the caller transaction.\n" );
	exit( 1 );
}
$pdo->rollBack();

$rows = $driver->query( "SELECT option_name, option_value FROM wp_options WHERE option_name = 'siteurl'" );
if ( ! is_array( $rows ) || 'https://local.test' !== ( $rows[0]->option_value ?? null ) ) {
	fwrite( STDERR, "FAIL: released SELECT result was not materialized for WP_SQLite_DB.\n" );
	exit( 1 );
}

$affected = $driver->query( "UPDATE wp_options SET option_value = 'https://updated.test' WHERE option_name = 'siteurl'" );
if ( 1 !== $affected || 1 !== $driver->get_last_return_value() || 1 !== $driver->get_query_results() ) {
	fwrite( STDERR, "FAIL: released DML result was not exposed through the wpdb facade.\n" );
	exit( 1 );
}

echo "PASS: released SQLite wpdb facade compatibility verified.\n";
