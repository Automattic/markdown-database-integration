<?php
/**
 * Smoke coverage for the MDI+BFB markdown storage round-trip.
 *
 * This is a pure-PHP harness around the MDI-owned boundaries: REST write
 * normalization, the write engine, filesystem storage, and render/read edge
 * conversion. It does not boot WordPress's REST controller; instead it calls
 * the production callbacks with small stubs and uses a real temp markdown
 * directory so disk persistence is exercised.
 *
 * Usage: php tests/smoke-bfb-storage-roundtrip.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
}

// ---------------------------------------------------------------------------
// Minimal WordPress/BFB stubs.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ): string {
		return dirname( (string) $file ) . '/';
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ): void {
		unset( $args );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ): void {
		unset( $args );
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( ...$args ): void {
		unset( $args );
	}
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array() ): array {
		unset( $args );
		return array( 'wiki' => 'wiki', 'post' => 'post' );
	}
}

if ( ! function_exists( 'bfb_get_adapter' ) ) {
	function bfb_get_adapter( string $format ) {
		return in_array( $format, array( 'html', 'markdown' ), true ) ? new stdClass() : null;
	}
}

require_once __DIR__ . '/../vendor/chubes4/block-format-bridge/includes/normalization.php';

$bfb_calls = array();

if ( ! function_exists( 'bfb_convert' ) ) {
	function bfb_convert( string $content, string $from, string $to ): string {
		global $bfb_calls, $blocks_fixture, $blocks_as_markdown;
		$bfb_calls[] = array( $content, $from, $to );

		if ( 'blocks' === $from && 'markdown' === $to && $content === $blocks_fixture ) {
			return $blocks_as_markdown;
		}

		if ( 'markdown' === $from && 'html' === $to ) {
			return '<h1>Round Trip</h1><p>Intro with <strong>bold</strong>.</p><ul><li>Parent<ul><li>Child item</li></ul></li><li>Second</li></ul><blockquote><p>Quote with continuation</p></blockquote><p>Final paragraph.</p>';
		}

		return $content;
	}
}

if ( ! class_exists( 'WP_SQLite_Driver' ) ) {
	class WP_SQLite_Driver {}
}

require_once __DIR__ . '/../markdown-database-integration.php';
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/class-wp-markdown-write-engine.php';

// ---------------------------------------------------------------------------
// Test harness.
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function assert_eq( $actual, $expected, string $label ): void {
	global $passed, $failed;
	if ( $actual === $expected ) {
		echo '✓ ' . $label . PHP_EOL;
		$passed++;
		return;
	}

	echo '✗ ' . $label . PHP_EOL;
	echo '  expected: ' . var_export( $expected, true ) . PHP_EOL;
	echo '  actual:   ' . var_export( $actual, true ) . PHP_EOL;
	$failed++;
}

function assert_true( bool $cond, string $label, string $detail = '' ): void {
	global $passed, $failed;
	if ( $cond ) {
		echo '✓ ' . $label . PHP_EOL;
		$passed++;
		return;
	}

	echo '✗ ' . $label . ( '' !== $detail ? ' — ' . $detail : '' ) . PHP_EOL;
	$failed++;
}

function rm_rf( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			rm_rf( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}

function markdown_body_from_file( string $path ): string {
	$raw = (string) file_get_contents( $path );
	if ( 1 !== preg_match( '/^---\n.+?\n---\n\n?(.*)$/s', $raw, $m ) ) {
		return '';
	}
	return rtrim( $m[1] );
}

class MDI_BFB_Roundtrip_Driver extends WP_SQLite_Driver {
	/** @var array<int, object> */
	public array $posts = array();
	private int $insert_id = 0;

	public function set_next_insert_id( int $id ): void {
		$this->insert_id = $id;
	}

	public function get_insert_id(): int {
		return $this->insert_id;
	}

	public function query( string $sql ): array {
		if ( 1 === preg_match( '/WHERE\s+ID\s*=\s*(\d+)/i', $sql, $m ) ) {
			$id = (int) $m[1];
			return isset( $this->posts[ $id ] ) ? array( $this->posts[ $id ] ) : array();
		}
		return array();
	}
}

function make_post( int $id, string $slug, string $content ): object {
	return (object) array(
		'ID'                    => $id,
		'post_author'           => 1,
		'post_date'             => '2026-04-28 00:00:00',
		'post_date_gmt'         => '2026-04-28 00:00:00',
		'post_content'          => $content,
		'post_title'            => 'Round Trip',
		'post_excerpt'          => '',
		'post_status'           => 'publish',
		'comment_status'        => 'open',
		'ping_status'           => 'open',
		'post_password'         => '',
		'post_name'             => $slug,
		'to_ping'               => '',
		'pinged'                => '',
		'post_modified'         => '2026-04-28 00:00:00',
		'post_modified_gmt'     => '2026-04-28 00:00:00',
		'post_content_filtered' => '',
		'post_parent'           => 0,
		'guid'                  => '',
		'menu_order'            => 0,
		'post_type'             => 'wiki',
		'post_mime_type'        => '',
		'comment_count'         => 0,
	);
}

function persist_post( WP_Markdown_Write_Engine $engine, MDI_BFB_Roundtrip_Driver $driver, int $id ): void {
	$driver->set_next_insert_id( $id );
	$engine->persist_write( 'INSERT INTO `wp_posts` (`ID`) VALUES (' . $id . ')', 'wp_posts', 'INSERT' );
	$engine->flush_dirty();
}

class MDI_BFB_Response {
	private array $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function get_data(): array {
		return $this->data;
	}

	public function set_data( array $data ): void {
		$this->data = $data;
	}
}

class MDI_BFB_Request {
	private array $params;

	public function __construct( array $params ) {
		$this->params = $params;
	}

	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}
}

// ---------------------------------------------------------------------------
// Fixtures.
// ---------------------------------------------------------------------------

$raw_markdown = "# Round Trip\n\nIntro with **bold**.\n\n- Parent\n  - Child item\n- Second\n\n> Quote with continuation\n\nFinal paragraph.";

$blocks_as_markdown = "## Round Trip\n\n- Parent\n  - Child item\n- Second\n\n> Quote with continuation\n\nFinal paragraph.";

$blocks_fixture = '<!-- wp:heading {"level":2} --><h2>Round Trip</h2><!-- /wp:heading -->'
	. '<!-- wp:list --><ul><li>Parent<ul><li>Child item</li></ul></li><li>Second</li></ul><!-- /wp:list -->'
	. '<!-- wp:quote --><blockquote><p>Quote with continuation</p></blockquote><!-- /wp:quote -->'
	. '<!-- wp:paragraph --><p>Final paragraph.</p><!-- /wp:paragraph -->';

$malformed_blocks = '<!-- wp:quote --><blockquote><p>Still open</p>';

$tmp_root = sys_get_temp_dir() . '/mdi-bfb-storage-roundtrip-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp_root, 0755, true );

$storage = new WP_Markdown_Storage( $tmp_root, array() );
$driver  = new MDI_BFB_Roundtrip_Driver();
$engine  = new WP_Markdown_Write_Engine( $tmp_root, $storage, $driver, 'wp_' );

// ---------------------------------------------------------------------------
// 1. Raw markdown writes stay raw in storage.
// ---------------------------------------------------------------------------

$driver->posts[101] = make_post( 101, 'raw-markdown', $raw_markdown );
persist_post( $engine, $driver, 101 );

$raw_path = $tmp_root . '/wiki/raw-markdown.md';
assert_true( file_exists( $raw_path ), 'raw markdown write creates a .md file' );
assert_eq( markdown_body_from_file( $raw_path ), $raw_markdown, '.md body stores raw markdown unchanged' );
assert_eq( $driver->posts[101]->post_content, $raw_markdown, 'post_content remains raw markdown for markdown-managed CPT write' );
assert_eq( $storage->read_post( 101 )->post_content ?? null, $raw_markdown, 'storage read returns the same raw markdown as the .md file' );

// ---------------------------------------------------------------------------
// 2. Block-editor REST writes convert to markdown before write-engine storage.
// ---------------------------------------------------------------------------

$prepared = make_post( 202, 'block-editor', $blocks_fixture );
$prepared = markdown_db_rest_pre_insert_filter( $prepared, new MDI_BFB_Request( array( 'type' => 'wiki' ) ) );

assert_true( ! is_wp_error( $prepared ), 'valid block-editor write passes REST pre-insert validation' );
assert_eq( $prepared->post_content, $blocks_as_markdown, 'REST pre-insert converts serialized blocks to markdown' );
assert_eq( end( $bfb_calls ), array( $blocks_fixture, 'blocks', 'markdown' ), 'REST pre-insert routes block markup through BFB blocks→markdown' );

$driver->posts[202] = $prepared;
persist_post( $engine, $driver, 202 );

$blocks_path = $tmp_root . '/wiki/block-editor.md';
assert_true( file_exists( $blocks_path ), 'block-editor write creates a .md file' );
assert_eq( markdown_body_from_file( $blocks_path ), $blocks_as_markdown, '.md body stores converted markdown, not serialized blocks' );
assert_eq( $driver->posts[202]->post_content, markdown_body_from_file( $blocks_path ), 'post_content and .md body agree after block-editor write' );
assert_true( ! str_contains( markdown_body_from_file( $blocks_path ), '<!-- wp:' ), 'serialized block comments do not leak into storage' );
assert_eq( $storage->read_post( 202 )->post_content ?? null, $blocks_as_markdown, 'storage read returns converted markdown for block-editor write' );

// ---------------------------------------------------------------------------
// 3. Render/read edges convert markdown to HTML through BFB.
// ---------------------------------------------------------------------------

$rendered = markdown_db_the_content_filter( $raw_markdown );
assert_true( str_contains( $rendered, '<h1>Round Trip</h1>' ), 'the_content renders markdown heading to HTML' );
assert_true( str_contains( $rendered, '<blockquote>' ), 'the_content renders blockquote to HTML' );
assert_eq( end( $bfb_calls ), array( $raw_markdown, 'markdown', 'html' ), 'the_content routes markdown through BFB markdown→HTML' );

$response = new MDI_BFB_Response(
	array(
		'content' => array(
			'raw'      => $raw_markdown,
			'rendered' => $raw_markdown,
		),
	)
);
$response = markdown_db_rest_prepare_filter( $response, make_post( 101, 'raw-markdown', $raw_markdown ), new MDI_BFB_Request( array( 'context' => 'edit' ) ) );
$data     = $response->get_data();
assert_true( str_contains( $data['content']['raw'], '<ul>' ), 'REST edit context returns editor-safe HTML' );
assert_eq( $driver->posts[101]->post_content, $raw_markdown, 'REST prepare does not mutate stored post_content' );

// ---------------------------------------------------------------------------
// 4. Malformed block input returns a structured error and leaves storage alone.
// ---------------------------------------------------------------------------

$before_bad_write = (string) file_get_contents( $blocks_path );
$bad_prepared     = make_post( 202, 'block-editor', $malformed_blocks );
$bad_result       = markdown_db_rest_pre_insert_filter( $bad_prepared, new MDI_BFB_Request( array( 'type' => 'wiki' ) ) );

assert_true( is_wp_error( $bad_result ), 'malformed block-editor write returns WP_Error' );
assert_eq( $bad_result->get_error_code(), 'bfb_blocks_unclosed_comment', 'malformed block-editor write returns structured BFB error code' );
assert_eq( (string) file_get_contents( $blocks_path ), $before_bad_write, 'malformed block-editor write does not corrupt the existing .md file' );
assert_eq( $driver->posts[202]->post_content, $blocks_as_markdown, 'malformed block-editor write does not corrupt stored post_content' );

rm_rf( $tmp_root );

if ( $failed > 0 ) {
	echo PHP_EOL . "Failed: {$failed}" . PHP_EOL;
	exit( 1 );
}

echo PHP_EOL . "All {$passed} assertions passed." . PHP_EOL;
