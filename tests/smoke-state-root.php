<?php
/**
 * Smoke coverage for split content and runtime-state roots.
 *
 * Usage: php tests/smoke-state-root.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	echo "SKIP: pdo_sqlite extension is not available.\n";
	exit( 0 );
}

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';

function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	unset( $hook, $args );
	return $value;
}

class MDI_State_Root_Connection {
	public function __construct( private PDO $pdo ) {}

	public function get_pdo(): PDO {
		return $this->pdo;
	}
}

class WP_SQLite_Connection extends MDI_State_Root_Connection {}

class WP_SQLite_Driver {
	private WP_SQLite_Connection $connection;

	public function __construct( WP_SQLite_Connection $connection, string $database ) {
		unset( $database );
		$this->connection = $connection;
	}

	public function get_connection(): WP_SQLite_Connection {
		return $this->connection;
	}

	public function query( string $sql, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		unset( $fetch_mode_args );
		if ( str_starts_with( $sql, 'SHOW CREATE TABLE' ) ) {
			return array( (object) array( 'Create Table' => 'CREATE TABLE `wp_runtime_jobs` (`id` bigint(20))' ) );
		}
		$result = $this->connection->get_pdo()->query( $sql );
		return false === $result ? array() : $result->fetchAll( $fetch_mode );
	}

	public function get_insert_id(): int {
		return (int) $this->connection->get_pdo()->lastInsertId();
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-frontmatter-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';
require_once __DIR__ . '/../inc/class-wp-markdown-write-engine.php';
require_once __DIR__ . '/../inc/class-wp-markdown-search.php';
require_once __DIR__ . '/../inc/class-wp-markdown-driver.php';

$failures = array();

function mdi_state_assert( bool $condition, string $message ): void {
	global $failures;
	if ( $condition ) {
		echo 'PASS: ' . $message . PHP_EOL;
		return;
	}
	$failures[] = $message;
	echo 'FAIL: ' . $message . PHP_EOL;
}

function mdi_state_rm_rf( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? mdi_state_rm_rf( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
}

function mdi_state_schema( PDO $pdo ): void {
	$pdo->exec( 'CREATE TABLE wp_posts (
		ID INTEGER PRIMARY KEY, post_author INTEGER, post_date TEXT, post_date_gmt TEXT,
		post_content TEXT, post_title TEXT, post_excerpt TEXT, post_status TEXT,
		comment_status TEXT, ping_status TEXT, post_password TEXT, post_name TEXT,
		to_ping TEXT, pinged TEXT, post_modified TEXT, post_modified_gmt TEXT,
		post_content_filtered TEXT, post_parent INTEGER, guid TEXT, menu_order INTEGER,
		post_type TEXT, post_mime_type TEXT, comment_count INTEGER
	)' );
	$pdo->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
	$pdo->exec( 'CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY, slug TEXT)' );
	$pdo->exec( 'CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT)' );
	$pdo->exec( 'CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER, term_order INTEGER, PRIMARY KEY (object_id, term_taxonomy_id))' );
	$pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)' );
	$pdo->exec( 'CREATE TABLE wp_runtime_jobs (id INTEGER PRIMARY KEY)' );
}

$root        = sys_get_temp_dir() . '/mdi-state-root-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$content_dir = $root . '/git-content';
$state_dir   = $root . '/local-state';
mkdir( $content_dir . '/wiki', 0755, true );
mkdir( $state_dir . '/_options', 0755, true );
mkdir( $state_dir . '/_tables', 0755, true );

$post_file = $content_dir . '/wiki/guide.md';
file_put_contents(
	$post_file,
	"---\nid: 41\ntitle: Guide\nstatus: publish\ntype: wiki\nslug: guide\n---\n\nCold content\n"
);
file_put_contents(
	$state_dir . '/_options/siteurl.json',
	json_encode( array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://local.test', 'autoload' => 'yes' ) )
);
file_put_contents( $state_dir . '/_tables/runtime_jobs.json', '[{"id":5}]' );

mdi_state_assert( markdown_database_integration_store_has_siteurl( $state_dir ), 'installed-site detection reads the state root' );
mdi_state_assert( ! markdown_database_integration_store_has_siteurl( $content_dir ), 'installed-site detection does not require siteurl in the content root' );
mdi_state_assert( $root . '/markdown-index.sqlite' === markdown_database_integration_primary_index_path( $content_dir, $content_dir ), 'default index path remains beside the single content root' );
mdi_state_assert( $state_dir . '/markdown-index.sqlite' === markdown_database_integration_primary_index_path( $content_dir, $state_dir ), 'split-root index is owned by the state root' );

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
mdi_state_schema( $pdo );
$connection = new WP_SQLite_Connection( $pdo );
$driver  = new WP_SQLite_Driver( $connection, 'wordpress' );
$storage = new WP_Markdown_Storage( $content_dir );
$loader  = new WP_Markdown_Loader( $content_dir, $driver, $storage, 'wp_', $state_dir );

$load_options = new ReflectionMethod( $loader, 'load_options' );
$load_posts   = new ReflectionMethod( $loader, 'load_posts' );
$sync_posts   = new ReflectionMethod( $loader, 'sync_markdown_posts' );
$load_table   = new ReflectionMethod( $loader, 'load_table_from_json' );
$create_manifest = new ReflectionMethod( $loader, 'create_json_manifest_table' );
$save_manifest   = new ReflectionMethod( $loader, 'save_json_manifest' );
$load_options->invoke( $loader );
$load_table->invoke( $loader, 'runtime_jobs' );
$create_manifest->invoke( $loader );
$save_manifest->invoke( $loader );
$load_posts->invoke( $loader );

mdi_state_assert( 'https://local.test' === $pdo->query( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" )->fetchColumn(), 'cold load reads options from the state root' );
mdi_state_assert( 5 === (int) $pdo->query( 'SELECT id FROM wp_runtime_jobs' )->fetchColumn(), 'cold load reads table snapshots from the state root' );
mdi_state_assert( 1 === (int) $pdo->query( "SELECT COUNT(*) FROM _json_file_manifest WHERE file_name = '_tables/runtime_jobs.json'" )->fetchColumn(), 'JSON manifests track files in the state root' );
mdi_state_assert( 'Guide' === $pdo->query( 'SELECT post_title FROM wp_posts WHERE ID = 41' )->fetchColumn(), 'cold load reads posts from the content root' );

$pdo->exec( "UPDATE wp_posts SET post_content = 'Stale indexed content' WHERE ID = 41" );
file_put_contents(
	$post_file,
	"---\nid: 41\ntitle: Updated guide\nstatus: publish\ntype: wiki\nslug: guide\n---\n\nWarm content is longer\n"
);
clearstatcache( true, $post_file );
$sync_posts->invoke( $loader );
mdi_state_assert( 'Updated guide' === $pdo->query( 'SELECT post_title FROM wp_posts WHERE ID = 41' )->fetchColumn(), 'warm sync reads changed posts from the content root' );
mdi_state_assert( '' === $pdo->query( 'SELECT post_content FROM wp_posts WHERE ID = 41' )->fetchColumn(), 'warm sync clears stale indexed content for changed posts' );
$markdown_driver = new WP_Markdown_Driver( $connection, 'wordpress', $storage );
$read_post       = $markdown_driver->query( 'SELECT ID, post_content FROM wp_posts WHERE ID = 41' );
mdi_state_assert( 'Warm content is longer' === ( $read_post[0]->post_content ?? null ), 'driver lazy-loads changed Markdown content after warm sync' );

$pdo->exec( "UPDATE wp_posts SET post_content = 'Written by WordPress', post_title = 'Written guide' WHERE ID = 41" );
$pdo->exec( "INSERT INTO wp_runtime_jobs (id) VALUES (7)" );
$engine = new WP_Markdown_Write_Engine( $content_dir, $storage, $driver, 'wp_', $state_dir );

$persist_post = new ReflectionMethod( $engine, 'persist_single_post' );
$persist_post->invoke( $engine, 41 );
mdi_state_assert( str_contains( (string) file_get_contents( $post_file ), 'Written by WordPress' ), 'post writes stay in the content root' );
clearstatcache( true, $post_file );
$sync_posts->invoke( $loader );
mdi_state_assert( '' === $pdo->query( 'SELECT post_content FROM wp_posts WHERE ID = 41' )->fetchColumn(), 'warm sync clears content after a WordPress-to-file round trip' );
$read_post = $markdown_driver->query( 'SELECT ID, post_content FROM wp_posts WHERE ID = 41' );
mdi_state_assert( 'Written by WordPress' === ( $read_post[0]->post_content ?? null ), 'driver reads WordPress-written Markdown after a round trip' );

$dirty_options = new ReflectionProperty( $engine, 'dirty_option_names' );
$dirty_options->setValue( $engine, array( 'siteurl' => true ) );
$persist_options = new ReflectionMethod( $engine, 'persist_options' );
$persist_options->invoke( $engine );
$persist_table = new ReflectionMethod( $engine, 'persist_table' );
$persist_table->invoke( $engine, 'runtime_jobs' );
$engine->persist_schema( 'CREATE TABLE wp_runtime_jobs', 'wp_runtime_jobs', 'CREATE' );

mdi_state_assert( file_exists( $state_dir . '/_options/siteurl.json' ), 'option writes stay in the state root' );
mdi_state_assert( file_exists( $state_dir . '/_tables/runtime_jobs.json' ), 'table writes stay in the state root' );
mdi_state_assert( file_exists( $state_dir . '/_schema/runtime_jobs.sql' ), 'schema writes stay in the state root' );
mdi_state_assert( ! is_dir( $content_dir . '/_options' ) && ! is_dir( $content_dir . '/_tables' ) && ! is_dir( $content_dir . '/_schema' ), 'runtime-state files do not leak into the content root' );

$single_root = $root . '/single-root';
mkdir( $single_root, 0755, true );
$default_engine = new WP_Markdown_Write_Engine( $single_root, new WP_Markdown_Storage( $single_root ), $driver, 'wp_' );
$persist_table->invoke( $default_engine, 'runtime_jobs' );
mdi_state_assert( file_exists( $single_root . '/_tables/runtime_jobs.json' ), 'the default single-root layout is unchanged' );

mdi_state_rm_rf( $root );

if ( ! empty( $failures ) ) {
	exit( 1 );
}
