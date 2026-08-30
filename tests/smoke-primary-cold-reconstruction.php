<?php
/**
 * Cold reconstruction from an empty primary index.
 *
 * Usage: php tests/smoke-primary-cold-reconstruction.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

class WP_SQLite_Connection {
	public function __construct( private PDO $pdo ) {}
	public function get_pdo(): PDO { return $this->pdo; }
}

class WP_MySQL_On_SQLite {
	private WP_SQLite_Connection $connection;
	private bool $in_transaction = false;
	public function __construct( string $dsn, ?string $username = null, ?string $password = null, array $options = array() ) {
		unset( $dsn, $username, $password );
		$this->connection = new WP_SQLite_Connection( $options['pdo'] );
	}
	public function get_connection(): WP_SQLite_Connection { return $this->connection; }
	public function get_insert_id(): int { return (int) $this->connection->get_pdo()->lastInsertId(); }
	public function beginTransaction(): bool { $this->connection->get_pdo()->beginTransaction(); $this->in_transaction = true; return true; }
	public function commit(): bool { $this->connection->get_pdo()->commit(); $this->in_transaction = false; return true; }
	public function rollBack(): bool { $this->connection->get_pdo()->rollBack(); $this->in_transaction = false; return true; }
	public function inTransaction(): bool { return $this->in_transaction; }
	public function query( string $sql, $fetch_mode = PDO::FETCH_OBJ, ...$args ) {
		unset( $fetch_mode, $args );
		$wrap = ! $this->in_transaction && 1 === preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql );
		if ( $wrap ) { $this->connection->get_pdo()->beginTransaction(); }
		try {
			$result = $this->connection->get_pdo()->query( $sql );
			if ( $wrap ) { $this->connection->get_pdo()->commit(); }
			return $result;
		} catch ( Throwable $error ) {
			if ( $wrap && $this->connection->get_pdo()->inTransaction() ) { $this->connection->get_pdo()->rollBack(); }
			throw $error;
		}
	}
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { unset( $hook, $args ); return $value; }
function do_action( string $hook, mixed ...$args ): void { unset( $hook, $args ); }

require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-frontmatter-profiles.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-backend-capabilities.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-storage.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-search.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-write-engine.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-driver.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-loader.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-primary-storage-runtime.php';

$failed = 0;
function mdi_cold_assert( bool $condition, string $label ): void {
	global $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $condition ) { $failed++; }
}
function mdi_cold_rm( string $path ): void {
	if ( ! is_dir( $path ) ) { return; }
	foreach ( scandir( $path ) ?: array() as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			$child = $path . '/' . $entry;
			is_dir( $child ) ? mdi_cold_rm( $child ) : unlink( $child );
		}
	}
	rmdir( $path );
}

$root = sys_get_temp_dir() . '/mdi-primary-cold-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$content = $root . '/content';
$state = $root . '/state';
mkdir( $content . '/post', 0755, true );
mkdir( $state . '/_options', 0755, true );
mkdir( $state . '/_tables', 0755, true );
mkdir( $state . '/_schema', 0755, true );

file_put_contents(
	$state . '/_options/blogname.json',
	json_encode( array( 'option_id' => 1, 'option_name' => 'blogname', 'option_value' => 'Cold Site', 'autoload' => 'yes' ) )
);
file_put_contents(
	$state . '/_tables/users.json',
	json_encode( array( array( 'ID' => 1, 'user_login' => 'admin', 'user_pass' => 'hashed' ) ) )
);
file_put_contents(
	$state . '/_schema/events.sql',
	'CREATE TABLE wp_events (id INTEGER PRIMARY KEY, name TEXT);'
);
file_put_contents(
	$state . '/_tables/events.json',
	json_encode( array( array( 'id' => 9, 'name' => 'canonical-event' ) ) )
);
file_put_contents(
	$content . '/post/hello.md',
	<<<'MD'
---
id: 12
title: "Hello"
status: publish
type: post
author: 1
date: "2026-08-30 00:00:00"
date_gmt: "2026-08-30 00:00:00"
modified: "2026-08-30 00:00:00"
modified_gmt: "2026-08-30 00:00:00"
slug: hello
parent: 0
menu_order: 0
comment_status: open
ping_status: open
---

Cold reconstruction body.
MD
);

$cache = $root . '/index.sqlite';
$pdo = new PDO( 'sqlite:' . $cache );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$before = $pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'" )->fetchAll( PDO::FETCH_COLUMN );
mdi_cold_assert( array() === $before, 'cold reconstruction starts from an empty SQLite index without pre-created core tables' );

$runtime = WP_Markdown_Primary_Storage_Runtime::bootstrap(
	array( 'content_root' => $content, 'state_root' => $state ),
	new WP_SQLite_Connection( $pdo ),
	'wordpress',
	null,
	true
);
unset( $runtime );

$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table'" )->fetchAll( PDO::FETCH_COLUMN );
mdi_cold_assert(
	in_array( 'wp_options', $tables, true )
		&& in_array( 'wp_posts', $tables, true )
		&& in_array( 'wp_users', $tables, true )
		&& in_array( 'wp_events', $tables, true ),
	'cold reconstruction creates core WordPress tables and plugin schema tables'
);
mdi_cold_assert(
	'Cold Site' === $pdo->query( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" )->fetchColumn(),
	'cold reconstruction hydrates canonical options'
);
mdi_cold_assert(
	'Hello' === $pdo->query( 'SELECT post_title FROM wp_posts WHERE ID = 12' )->fetchColumn(),
	'cold reconstruction hydrates canonical markdown posts'
);
mdi_cold_assert(
	'admin' === $pdo->query( 'SELECT user_login FROM wp_users WHERE ID = 1' )->fetchColumn(),
	'cold reconstruction hydrates canonical table snapshots'
);
mdi_cold_assert(
	'canonical-event' === $pdo->query( 'SELECT name FROM wp_events WHERE id = 9' )->fetchColumn(),
	'cold reconstruction hydrates plugin table snapshots from _schema'
);
mdi_cold_assert(
	! file_exists( $state . '/_schema/options.sql' ) && ! file_exists( $state . '/_schema/posts.sql' ),
	'core table schemas are not persisted into _schema'
);

mdi_cold_rm( $root );
exit( $failed ? 1 : 0 );
