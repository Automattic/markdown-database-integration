<?php
/**
 * Smoke coverage for the constrained primary runtime.
 *
 * Usage: php tests/smoke-primary-storage-runtime.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_SQLite_Connection {
	public function __construct( private PDO $pdo ) {}
	public function get_pdo(): PDO { return $this->pdo; }
}

class WP_SQLite_Driver {
	public function __construct( private WP_SQLite_Connection $connection, string $database ) { unset( $database ); }
	public function get_connection(): WP_SQLite_Connection { return $this->connection; }
	public function get_insert_id(): int { return (int) $this->connection->get_pdo()->lastInsertId(); }
	public function query( string $sql, $fetch_mode = PDO::FETCH_OBJ, ...$args ) {
		unset( $args );
		$statement = $this->connection->get_pdo()->query( $sql );
		return false === $statement ? array() : $statement->fetchAll( $fetch_mode );
	}
}

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { unset( $hook, $args ); return $value; }
function do_action( string $hook, mixed ...$args ): void { $GLOBALS['mdi_runtime_actions'][ $hook ][] = $args; }

require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-frontmatter-profiles.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-storage.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-search.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-write-engine.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-driver.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-loader.php';
require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-primary-storage-runtime.php';

$failed = 0;
function mdi_runtime_assert( bool $condition, string $label ): void {
	global $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $condition ) { $failed++; }
}
function mdi_runtime_rm( string $path ): void {
	if ( ! is_dir( $path ) ) { return; }
	foreach ( scandir( $path ) ?: array() as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			$child = $path . '/' . $entry;
			is_dir( $child ) ? mdi_runtime_rm( $child ) : unlink( $child );
		}
	}
	rmdir( $path );
}

$root = sys_get_temp_dir() . '/mdi-primary-runtime-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );
$cache = $root . '/index.sqlite';
$pdo = new PDO( 'sqlite:' . $cache );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_author INTEGER, post_date TEXT, post_date_gmt TEXT, post_content TEXT, post_title TEXT, post_excerpt TEXT, post_status TEXT, comment_status TEXT, ping_status TEXT, post_password TEXT, post_name TEXT, to_ping TEXT, pinged TEXT, post_modified TEXT, post_modified_gmt TEXT, post_content_filtered TEXT, post_parent INTEGER, guid TEXT, menu_order INTEGER, post_type TEXT, post_mime_type TEXT, comment_count INTEGER)' );
$pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)' );
$pdo->exec( 'CREATE TABLE wp_users (ID INTEGER PRIMARY KEY, user_login TEXT, user_pass TEXT)' );
$pdo->exec( 'CREATE TABLE wp_postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$pdo->exec( 'CREATE TABLE wp_terms (term_id INTEGER, slug TEXT)' );
$pdo->exec( 'CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER, term_id INTEGER, taxonomy TEXT)' );
$pdo->exec( 'CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER)' );
$pdo->exec( "INSERT INTO wp_posts (ID, post_date, post_date_gmt, post_modified, post_modified_gmt, post_title, post_status, comment_status, ping_status, post_name, post_type) VALUES (12, '2026-07-18 00:00:00', '2026-07-18 00:00:00', '2026-07-18 00:00:00', '2026-07-18 00:00:00', 'Cold post', 'publish', 'open', 'open', 'cold-post', 'post')" );
$pdo->exec( "INSERT INTO wp_options VALUES (7, 'siteurl', 'https://old.test', 'yes')" );
$pdo->exec( "INSERT INTO wp_options VALUES (8, 'blogname', 'Old name', 'yes')" );
$pdo->exec( "INSERT INTO wp_users VALUES (1, 'admin', 'hashed-password')" );

mkdir( $root . '/existing-state/_tables', 0755, true );
$large_snapshot = $root . '/existing-state/_tables/plugin_snapshot.json';
file_put_contents( $large_snapshot, str_repeat( 'x', 16 * 1024 * 1024 ) );
$old_atime = time() - 7200;
touch( $large_snapshot, $old_atime, $old_atime );
clearstatcache( true, $large_snapshot );

$existing = WP_Markdown_Primary_Storage_Runtime::bootstrap_existing_cache(
	array( 'content_root' => $root . '/existing-content', 'state_root' => $root . '/existing-state' ),
	new WP_SQLite_Connection( $pdo ),
	'wordpress'
);
$existing_driver = $existing->get_driver();
clearstatcache( true, $large_snapshot );
mdi_runtime_assert( $old_atime === fileatime( $large_snapshot ), 'existing-cache bootstrap does not read or hash a large unchanged JSON snapshot' );
$existing_driver->query( "UPDATE wp_options SET option_value = 'Attached name' WHERE option_name = 'blogname'" );
$existing_driver->query( "UPDATE wp_users SET user_login = 'admin-existing' WHERE ID = 1" );
$existing_driver->query( "UPDATE wp_posts SET post_title = 'Attached post' WHERE ID = 12" );
$existing_changes = $existing_driver->flush_canonical_writes();
mdi_runtime_assert( 'Attached name' === $pdo->query( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" )->fetchColumn() && 'admin-existing' === $pdo->query( 'SELECT user_login FROM wp_users WHERE ID = 1' )->fetchColumn() && 'Attached post' === $pdo->query( 'SELECT post_title FROM wp_posts WHERE ID = 12' )->fetchColumn(), 'existing-cache attachment does not hydrate files into or replace populated SQLite rows' );
mdi_runtime_assert( file_exists( $root . '/existing-state/_options/blogname.json' ) && file_exists( $root . '/existing-state/_tables/users.json' ) && file_exists( $root . '/existing-content/post/cold-post.md' ), 'existing-cache attachment flushes populated options users and posts to canonical files' );
mdi_runtime_assert( array( '_options/blogname.json', '_tables/users.json', 'post/cold-post.md' ) === $existing_changes['created'], 'driver flush returns deterministic canonical paths for caller-managed durability' );
mdi_runtime_assert( $existing_changes === $GLOBALS['mdi_runtime_actions']['markdown_database_integration_flushed'][0][0], 'successful flush action exposes the same canonical change set' );
mdi_runtime_assert( array( 'created' => array(), 'changed' => array(), 'deleted' => array() ) === $existing_driver->flush_canonical_writes(), 'driver flush returns an empty change set at a clean boundary' );
$users_path = $root . '/existing-state/_tables/users.json';
unlink( $users_path );
mkdir( $users_path );
$existing_driver->query( "UPDATE wp_users SET user_login = 'blocked-write' WHERE ID = 1" );
$explicit_failure = false;
try {
	$existing_driver->flush_canonical_writes();
} catch ( RuntimeException $exception ) {
	$explicit_failure = str_contains( $exception->getMessage(), 'Failed to rename JSON file' );
}
mdi_runtime_assert( $explicit_failure, 'explicit driver flush propagates canonical persistence failures' );
rmdir( $users_path );

$runtime = WP_Markdown_Primary_Storage_Runtime::bootstrap(
	array( 'content_root' => $root . '/content', 'state_root' => $root . '/state' ),
	new WP_SQLite_Connection( $pdo ),
	'wordpress',
	null,
	true
);
$driver = $runtime->get_driver();
$driver->query( "UPDATE wp_posts SET post_content = 'Canonical body', post_title = 'Written post' WHERE ID = 12" );
$driver->query( "UPDATE wp_options SET option_value = 'https://example.test' WHERE option_name = 'siteurl'" );
$driver->query( "UPDATE wp_options SET option_value = 'Canonical name' WHERE option_name = 'blogname'" );
$first = $runtime->flush();
mdi_runtime_assert( array( '_options/blogname.json', '_options/siteurl.json', 'post/cold-post.md' ) === $first['created'], 'explicit flush persists normal post and option mutations with relative paths' );
$identity = $runtime->get_identity();
mdi_runtime_assert( '' !== $identity['hash'] && isset( $identity['files']['post/cold-post.md'] ), 'cache exposes canonical manifest identity' );

$driver->query( "UPDATE wp_options SET option_value = 'Canonical name two' WHERE option_name = 'blogname'" );
$changed = $runtime->flush();
mdi_runtime_assert( array( '_options/blogname.json' ) === $changed['changed'], 'changed canonical option path is reported' );

$driver->query( "UPDATE wp_options SET option_value = 'Canonical name six' WHERE option_name = 'blogname'" );
$same_size_changed = $runtime->flush();
mdi_runtime_assert( array( '_options/blogname.json' ) === $same_size_changed['changed'], 'same-size canonical option overwrite is reported by content hash' );

$driver->query( "UPDATE wp_posts SET post_name = 'moved-post' WHERE ID = 12" );
$moved = $runtime->flush();
mdi_runtime_assert( array( 'post/moved-post.md' ) === $moved['created'] && array( 'post/cold-post.md' ) === $moved['deleted'], 'slug movement reports canonical paths without a stale file' );
$driver->query( 'DELETE FROM wp_options WHERE option_name = \'siteurl\'' );
$deleted = $runtime->flush();
mdi_runtime_assert( array( '_options/siteurl.json' ) === $deleted['deleted'], 'deleted canonical option path is reported' );

$missing_identity_rejected = false;
try {
	WP_Markdown_Primary_Storage_Runtime::bootstrap( array( 'content_root' => $root . '/content', 'state_root' => $root . '/state' ), new WP_SQLite_Connection( $pdo ), 'wordpress', null, false );
} catch ( RuntimeException $exception ) {
	$missing_identity_rejected = 'A canonical identity is required for a warm SQLite cache.' === $exception->getMessage();
}
mdi_runtime_assert( $missing_identity_rejected, 'warm bootstrap rejects a missing canonical identity' );

$mismatch_rejected = false;
try {
	WP_Markdown_Primary_Storage_Runtime::bootstrap( array( 'content_root' => $root . '/content', 'state_root' => $root . '/state' ), new WP_SQLite_Connection( $pdo ), 'wordpress', $identity, false );
} catch ( RuntimeException $exception ) {
	$mismatch_rejected = 'The supplied SQLite cache identity does not match the canonical files.' === $exception->getMessage();
}
mdi_runtime_assert( $mismatch_rejected, 'warm bootstrap rejects a mismatched canonical identity' );

$pdo = null;
unlink( $cache );
$cold_pdo = new PDO( 'sqlite:' . $cache );
$cold_pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$cold_pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_author INTEGER, post_date TEXT, post_date_gmt TEXT, post_content TEXT, post_title TEXT, post_excerpt TEXT, post_status TEXT, comment_status TEXT, ping_status TEXT, post_password TEXT, post_name TEXT, to_ping TEXT, pinged TEXT, post_modified TEXT, post_modified_gmt TEXT, post_content_filtered TEXT, post_parent INTEGER, guid TEXT, menu_order INTEGER, post_type TEXT, post_mime_type TEXT, comment_count INTEGER)' );
$cold_pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)' );
$cold_pdo->exec( 'CREATE TABLE wp_postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$cold_pdo->exec( 'CREATE TABLE wp_terms (term_id INTEGER, slug TEXT)' );
$cold_pdo->exec( 'CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER, term_id INTEGER, taxonomy TEXT)' );
$cold_pdo->exec( 'CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER)' );
WP_Markdown_Primary_Storage_Runtime::bootstrap( array( 'content_root' => $root . '/content', 'state_root' => $root . '/state' ), new WP_SQLite_Connection( $cold_pdo ), 'wordpress', null, true );
mdi_runtime_assert( 'Canonical name six' === $cold_pdo->query( "SELECT option_value FROM wp_options WHERE option_name = 'blogname'" )->fetchColumn() && 'Written post' === $cold_pdo->query( 'SELECT post_title FROM wp_posts WHERE ID = 12' )->fetchColumn(), 'canonical Markdown and JSON reconstruct mutations after deleting SQLite' );

mdi_runtime_rm( $root );
exit( $failed ? 1 : 0 );
