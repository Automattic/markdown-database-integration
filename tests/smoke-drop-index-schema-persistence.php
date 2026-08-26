<?php
/**
 * Smoke coverage for standalone MySQL DROP INDEX schema persistence.
 *
 * Usage: php tests/smoke-drop-index-schema-persistence.php
 */

declare( strict_types=1 );

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	echo "SKIP: pdo_sqlite extension is not available.\n";
	exit( 0 );
}

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

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

class WP_MySQL_On_SQLite {
	public bool $fail_drop = false;

	public function __construct( string $dsn, ?string $username = null, ?string $password = null, array $options = array() ) {
		unset( $dsn, $username, $password );
		$this->connection = new WP_SQLite_Connection( $options['pdo'] );
	}

	private WP_SQLite_Connection $connection;

	public function get_connection(): WP_SQLite_Connection {
		return $this->connection;
	}

	public function query( string $sql, $fetch_mode = PDO::FETCH_OBJ, ...$args ) {
		unset( $args );
		if ( preg_match( '/^SHOW CREATE TABLE `?(\w+)`?$/i', $sql, $matches ) ) {
			$table   = $matches[1];
			$indexes = $this->connection->get_pdo()->query( "PRAGMA index_list(`{$table}`)" )->fetchAll( PDO::FETCH_ASSOC );
			$schema  = "CREATE TABLE `{$table}` (`id` INTEGER PRIMARY KEY, `payload` TEXT)";
			foreach ( $indexes as $index ) {
				$schema .= ";\nCREATE INDEX `{$index['name']}` ON `{$table}` (`payload`)";
			}
			return $this->connection->get_pdo()->query( 'SELECT ' . $this->connection->get_pdo()->quote( $schema ) . ' AS "Create Table"' );
		}

		if ( preg_match( '/^DROP INDEX `?(\w+)`? ON `?(\w+)`?$/i', $sql, $matches ) ) {
			if ( $this->fail_drop ) {
				throw new RuntimeException( 'Simulated DROP INDEX failure.' );
			}
			$this->connection->get_pdo()->exec( "DROP INDEX `{$matches[1]}`" );
			return $this->connection->get_pdo()->query( 'SELECT 1 WHERE 0' );
		}

		$statement = $this->connection->get_pdo()->query( $sql );
		return $statement;
	}
}

require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-frontmatter-profiles.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-storage.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-search.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-write-engine.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-driver.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-loader.php';

$failed = 0;

function mdi_drop_index_assert( bool $condition, string $label ): void {
	global $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $condition ) {
		$failed++;
	}
}

function mdi_drop_index_rm( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( scandir( $path ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$child = $path . '/' . $entry;
		is_dir( $child ) ? mdi_drop_index_rm( $child ) : unlink( $child );
	}
	rmdir( $path );
}

$root = sys_get_temp_dir() . '/mdi-drop-index-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root . '/_tables', 0755, true );
file_put_contents( $root . '/_tables/issue_144_jobs.json', '[]' );

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE wp_issue_144_jobs (id INTEGER PRIMARY KEY, payload TEXT)' );
$pdo->exec( 'CREATE INDEX obsolete_payload_idx ON wp_issue_144_jobs (payload)' );
$pdo->exec( 'CREATE INDEX retained_payload_idx ON wp_issue_144_jobs (payload)' );

$connection = new WP_SQLite_Connection( $pdo );
$storage    = new WP_Markdown_Storage( $root );
$driver     = new WP_Markdown_Driver( $connection, 'wordpress', $storage );
$engine     = new WP_Markdown_Write_Engine( $root, $storage, $driver, 'wp_' );
$driver->set_write_engine( $engine );

// Failed parent DDL must not persist a schema snapshot.
$driver->fail_drop = true;
try {
	$driver->query( 'DROP INDEX `obsolete_payload_idx` ON `wp_issue_144_jobs`' );
} catch ( RuntimeException $error ) {
	mdi_drop_index_assert( 'Simulated DROP INDEX failure.' === $error->getMessage(), 'failed DROP INDEX surfaces the driver error' );
}
mdi_drop_index_assert( ! file_exists( $root . '/_schema/issue_144_jobs.sql' ), 'failed DROP INDEX does not persist schema' );
$driver->fail_drop = false;

// This is the normal identifier-quoted form emitted by wpdb::prepare( '%i' ).
$driver->query( 'DROP INDEX `obsolete_payload_idx` ON `wp_issue_144_jobs`' );

$live_indexes = $pdo->query( 'PRAGMA index_list(`wp_issue_144_jobs`)' )->fetchAll( PDO::FETCH_COLUMN, 1 );
mdi_drop_index_assert( ! in_array( 'obsolete_payload_idx', $live_indexes, true ), 'successful DROP INDEX removes the live obsolete index' );
mdi_drop_index_assert( in_array( 'retained_payload_idx', $live_indexes, true ), 'successful DROP INDEX retains unrelated live indexes' );

$schema_path = $root . '/_schema/issue_144_jobs.sql';
$schema_sql  = (string) file_get_contents( $schema_path );
mdi_drop_index_assert( ! str_contains( $schema_sql, 'obsolete_payload_idx' ), 'persisted schema excludes the dropped index' );
mdi_drop_index_assert( str_contains( $schema_sql, 'retained_payload_idx' ), 'persisted schema retains unaffected indexes' );

$cold_pdo = new PDO( 'sqlite::memory:' );
$cold_pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$cold_driver = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:dbname=wordpress', null, null, array( 'pdo' => $cold_pdo ) );
$loader = new WP_Markdown_Loader( $root, $cold_driver, new WP_Markdown_Storage( $root ), 'wp_' );
$load_plugin_tables = new ReflectionMethod( $loader, 'load_plugin_tables' );
$load_plugin_tables->invoke( $loader );
$cold_indexes = $cold_pdo->query( 'PRAGMA index_list(`wp_issue_144_jobs`)' )->fetchAll( PDO::FETCH_COLUMN, 1 );
mdi_drop_index_assert( ! in_array( 'obsolete_payload_idx', $cold_indexes, true ), 'cold schema reload does not restore the dropped index' );
mdi_drop_index_assert( in_array( 'retained_payload_idx', $cold_indexes, true ), 'cold schema reload restores retained indexes' );

$pdo->exec( 'CREATE TABLE wp_posts (id INTEGER PRIMARY KEY, payload TEXT)' );
$pdo->exec( 'CREATE INDEX core_payload_idx ON wp_posts (payload)' );
$driver->query( 'DROP INDEX `core_payload_idx` ON `wp_posts`' );
mdi_drop_index_assert( ! file_exists( $root . '/_schema/posts.sql' ), 'core table index drops are not persisted as plugin schemas' );

mdi_drop_index_rm( $root );
exit( $failed ? 1 : 0 );
