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
	/** @var string[] */
	public static array $queries = array();
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
		self::$queries[] = $sql;
		$wrap = ! $this->in_transaction && 1 === preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $sql );
		$pdo = $this->connection->get_pdo();
		if ( $wrap ) { $pdo->beginTransaction(); }
		try {
			$statements = $this->sqlite_statements( $sql );
			$result = null;
			foreach ( $statements as $statement ) {
				$result = $pdo->query( $statement );
			}
			if ( $wrap ) { $pdo->commit(); }
			return $result;
		} catch ( Throwable $error ) {
			if ( $wrap && $pdo->inTransaction() ) { $pdo->rollBack(); }
			throw $error;
		}
	}
	/** @return string[] */
	private function sqlite_statements( string $sql ): array {
		$trimmed = trim( $sql, " \t\n\r\0\x0B;" );
		if ( ! preg_match( '/^\s*CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*)\)\s*(?:DEFAULT\s+CHARSET\s*=\s*\w+\s*(?:COLLATE\s*=\s*\w+)?)?\s*$/is', $trimmed, $match ) ) {
			return array( $sql );
		}
		if ( ! preg_match( '/\b(?:AUTO_INCREMENT|varchar\s*\(|longtext|tinytext|mediumtext|UNIQUE\s+KEY|\bKEY\s+`)/i', $trimmed ) ) {
			return array( $sql );
		}
		$exists = '' !== trim( $match[1] );
		$table = $match[2];
		$columns = array();
		$indexes = array();
		$inline_pk = false;
		foreach ( preg_split( '/\n/', $match[3] ) as $line ) {
			$line = rtrim( trim( $line ), ',' );
			if ( '' === $line ) { continue; }
			if ( preg_match( '/^PRIMARY\s+KEY\s*\((.+)\)$/i', $line, $pk ) ) {
				$columns[] = 'PRIMARY KEY (' . preg_replace( '/\s*\(\d+\)/', '', $pk[1] ) . ')';
				continue;
			}
			if ( preg_match( '/^UNIQUE\s+KEY\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$/i', $line, $unique ) ) {
				$indexes[] = 'CREATE UNIQUE INDEX ' . ( $exists ? 'IF NOT EXISTS ' : '' ) . '`' . $table . '_' . $unique[1] . '` ON `' . $table . '` (' . preg_replace( '/\s*\(\d+\)/', '', $unique[2] ) . ')';
				continue;
			}
			if ( preg_match( '/^KEY\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$/i', $line, $key ) ) {
				$indexes[] = 'CREATE INDEX ' . ( $exists ? 'IF NOT EXISTS ' : '' ) . '`' . $table . '_' . $key[1] . '` ON `' . $table . '` (' . preg_replace( '/\s*\(\d+\)/', '', $key[2] ) . ')';
				continue;
			}
			$line = preg_replace( '/\bbigint\(\d+\)(?:\s+unsigned)?/i', 'INTEGER', $line );
			$line = preg_replace( '/\bint\(\d+\)(?:\s+unsigned)?/i', 'INTEGER', $line );
			$line = preg_replace( '/\bvarchar\(\d+\)/i', 'TEXT', $line );
			$line = preg_replace( '/\b(?:longtext|mediumtext|tinytext|datetime|text)\b/i', 'TEXT', $line );
			$auto = (bool) preg_match( '/\bAUTO_INCREMENT\b/i', $line );
			$line = preg_replace( '/\s*AUTO_INCREMENT\b/i', '', $line );
			if ( $auto ) {
				$line = preg_replace( '/\bINTEGER\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $line, 1 );
				$inline_pk = true;
			}
			$columns[] = $line;
		}
		if ( $inline_pk ) {
			$columns = array_values( array_filter( $columns, static fn( string $column ): bool => 1 !== preg_match( '/^PRIMARY KEY\s*\(/i', $column ) ) );
		}
		$create = 'CREATE TABLE ' . ( $exists ? 'IF NOT EXISTS ' : '' ) . '`' . $table . '` (' . implode( ', ', $columns ) . ')';
		return array_merge( array( $create ), $indexes );
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

$driver_sql = implode( "\n", WP_MySQL_On_SQLite::$queries );
mdi_cold_assert(
	str_contains( $driver_sql, 'CREATE TABLE IF NOT EXISTS `wp_options`' )
		&& str_contains( $driver_sql, 'CREATE TABLE IF NOT EXISTS `wp_posts`' )
		&& str_contains( strtoupper( $driver_sql ), 'AUTO_INCREMENT' )
		&& str_contains( $driver_sql, 'UNIQUE KEY `option_name`' )
		&& str_contains( $driver_sql, 'KEY `type_status_date`' )
		&& str_contains( $driver_sql, 'DEFAULT CHARSET' ),
	'core DDL passes through the owning SQLite driver rather than a raw-PDO-only path'
);

$column = static function ( PDO $pdo, string $table, string $name ): array {
	foreach ( $pdo->query( 'PRAGMA table_info(`' . $table . '`)' )->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
		if ( $name === $row['name'] ) {
			return $row;
		}
	}
	return array();
};
$option_name = $column( $pdo, 'wp_options', 'option_name' );
$autoload = $column( $pdo, 'wp_options', 'autoload' );
$option_id = $column( $pdo, 'wp_options', 'option_id' );
$post_status = $column( $pdo, 'wp_posts', 'post_status' );
$post_id = $column( $pdo, 'wp_posts', 'ID' );
$meta_key = $column( $pdo, 'wp_postmeta', 'meta_key' );
mdi_cold_assert(
	1 === (int) ( $option_id['pk'] ?? 0 )
		&& 1 === (int) ( $post_id['pk'] ?? 0 )
		&& 1 === (int) ( $option_name['notnull'] ?? 0 )
		&& 1 === (int) ( $autoload['notnull'] ?? 0 )
		&& 1 === (int) ( $post_status['notnull'] ?? 0 )
		&& 0 === (int) ( $meta_key['notnull'] ?? 1 )
		&& str_contains( (string) ( $option_name['dflt_value'] ?? '' ), "''" )
		&& str_contains( (string) ( $autoload['dflt_value'] ?? '' ), 'yes' )
		&& str_contains( (string) ( $post_status['dflt_value'] ?? '' ), 'publish' )
		&& str_contains( (string) $pdo->query( "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'wp_options'" )->fetchColumn(), 'AUTOINCREMENT' ),
	'cold reconstruction restores WordPress core defaults, nullability, and auto-increment'
);

$index_sql = implode( "\n", $pdo->query( "SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL" )->fetchAll( PDO::FETCH_COLUMN ) );
mdi_cold_assert(
	str_contains( $index_sql, 'wp_options_autoload' )
		&& str_contains( $index_sql, 'wp_options_option_name' )
		&& str_contains( $index_sql, 'wp_posts_type_status_date' )
		&& str_contains( $index_sql, 'wp_posts_post_parent' )
		&& str_contains( $index_sql, 'wp_posts_post_author' )
		&& str_contains( $index_sql, 'wp_posts_post_name' )
		&& str_contains( $index_sql, 'wp_users_user_login_key' )
		&& str_contains( $index_sql, 'wp_postmeta_post_id' )
		&& str_contains( $index_sql, 'wp_term_relationships_term_taxonomy_id' ),
	'cold reconstruction restores representative WordPress secondary indexes'
);

$pdo->exec( "INSERT INTO wp_options (option_name, option_value) VALUES ('_mdi_autoincrement', '1')" );
$pdo->exec( "INSERT INTO wp_posts (post_content, post_title, post_excerpt, to_ping, pinged, post_content_filtered) VALUES ('', '', '', '', '', '')" );
mdi_cold_assert(
	(int) $pdo->query( "SELECT option_id FROM wp_options WHERE option_name = '_mdi_autoincrement'" )->fetchColumn() > 0
		&& 'publish' === $pdo->query( 'SELECT post_status FROM wp_posts ORDER BY ID DESC LIMIT 1' )->fetchColumn()
		&& 'open' === $pdo->query( 'SELECT comment_status FROM wp_posts ORDER BY ID DESC LIMIT 1' )->fetchColumn()
		&& 'yes' === $pdo->query( "SELECT autoload FROM wp_options WHERE option_name = '_mdi_autoincrement'" )->fetchColumn(),
	'cold reconstruction applies auto-increment and WordPress column defaults on insert'
);

mdi_cold_rm( $root );
exit( $failed ? 1 : 0 );
