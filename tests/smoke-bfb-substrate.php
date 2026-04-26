<?php
/**
 * Smoke test for the Block Format Bridge conversion substrate.
 *
 * Asserts the post-#82 contract: MDI no longer ships its own conversion
 * code and the write engine routes block-markup → markdown through
 * `bfb_convert()` exactly when the content needs converting.
 *
 * The deeper regression (Gutenberg structure preservation across the
 * blocks → markdown round-trip) is a BFB integration concern that needs
 * a live WordPress + `do_blocks()` runtime, so it lives in the
 * intelligence-chubes4 live-verify rather than here. This smoke pins the
 * substrate boundary so MDI can never silently re-grow its own converter.
 *
 * Usage: php tests/smoke-bfb-substrate.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$plugin_dir = dirname( __DIR__ );
$failures   = array();
$passes     = 0;

/** Assert helper. */
$assert = static function ( bool $cond, string $label ) use ( &$failures, &$passes ): void {
	if ( $cond ) {
		echo "✓ {$label}\n";
		$passes++;
		return;
	}
	echo "✗ {$label}\n";
	$failures[] = $label;
};

// ---------------------------------------------------------------------
// 1. The deleted converter class file is gone and no PHP file references it.
// ---------------------------------------------------------------------
$converter_path = $plugin_dir . '/inc/class-wp-markdown-converter.php';
$assert( ! file_exists( $converter_path ), 'inc/class-wp-markdown-converter.php is deleted' );

$residual = array();
$it       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$path = $file->getPathname();
	if ( str_contains( $path, '/vendor/' ) || str_contains( $path, '/node_modules/' ) || str_contains( $path, '/.git/' ) ) {
		continue;
	}
	if ( ! str_ends_with( $path, '.php' ) ) {
		continue;
	}
	if ( str_ends_with( $path, basename( __FILE__ ) ) ) {
		// This file legitimately mentions the class names in assertion strings.
		continue;
	}
	$contents = (string) file_get_contents( $path );
	if ( str_contains( $contents, 'WP_Markdown_Converter' ) ) {
		$residual[] = $path;
	}
}
$assert( empty( $residual ), 'no MDI PHP file references WP_Markdown_Converter (residual: ' . implode( ', ', $residual ) . ')' );

// ---------------------------------------------------------------------
// 2. composer.json declares chubes4/block-format-bridge and no league/* deps.
// ---------------------------------------------------------------------
$composer = json_decode( (string) file_get_contents( $plugin_dir . '/composer.json' ), true );
$assert( is_array( $composer ), 'composer.json parses as JSON' );

$require = $composer['require'] ?? array();
$assert( isset( $require['chubes4/block-format-bridge'] ), 'composer.json requires chubes4/block-format-bridge' );
$assert( ! isset( $require['league/commonmark'] ), 'composer.json no longer requires league/commonmark directly' );
$assert( ! isset( $require['league/html-to-markdown'] ), 'composer.json no longer requires league/html-to-markdown directly' );

// ---------------------------------------------------------------------
// 3. The write engine + render-time filters call bfb_convert() with the
//    correct ($from, $to) pairs.
// ---------------------------------------------------------------------
$write_engine = (string) file_get_contents( $plugin_dir . '/inc/class-wp-markdown-write-engine.php' );
$assert(
	str_contains( $write_engine, "bfb_convert( \$content, 'blocks', 'markdown' )" ),
	"write engine calls bfb_convert(\$content, 'blocks', 'markdown')"
);
$assert(
	! str_contains( $write_engine, 'WP_Markdown_Converter' ),
	'write engine no longer references WP_Markdown_Converter'
);

$plugin_main = (string) file_get_contents( $plugin_dir . '/markdown-database-integration.php' );
$assert(
	str_contains( $plugin_main, "bfb_convert( \$content, 'markdown', 'html' )" ),
	"the_content filter calls bfb_convert(\$content, 'markdown', 'html')"
);
$assert(
	str_contains( $plugin_main, "bfb_convert( \$raw, 'markdown', 'html' )" ),
	"REST edit-context filter calls bfb_convert(\$raw, 'markdown', 'html')"
);
$assert(
	str_contains( $plugin_main, "bfb_convert( \$rendered, 'markdown', 'html' )" ),
	"REST view-context filter calls bfb_convert(\$rendered, 'markdown', 'html')"
);
$assert(
	! str_contains( $plugin_main, 'WP_Markdown_Converter' ),
	'plugin entry no longer references WP_Markdown_Converter'
);

// ---------------------------------------------------------------------
// 4. db.php still loads composer's autoloader (so BFB's library.php
//    activates) and no longer require_once's the deleted converter file.
// ---------------------------------------------------------------------
$db_dropin = (string) file_get_contents( $plugin_dir . '/db.php' );
$assert(
	str_contains( $db_dropin, "require_once \$composer_autoload" ),
	'db.php still loads composer autoloader (BFB library.php registers via composer autoload.files)'
);
$assert(
	! str_contains( $db_dropin, 'class-wp-markdown-converter.php' ),
	'db.php no longer require_once the deleted converter class'
);

// ---------------------------------------------------------------------
// 4b. MDI hooks `bfb_skip_insert_conversion` so post_content stays as
//     raw markdown for markdown-managed CPTs (see issue #82 +
//     chubes4/block-format-bridge#8).
// ---------------------------------------------------------------------
$assert(
	str_contains( $plugin_main, "add_filter( 'bfb_skip_insert_conversion'" ),
	'plugin entry hooks bfb_skip_insert_conversion'
);
$assert(
	str_contains( $plugin_main, 'function markdown_db_bfb_skip_insert_conversion' ),
	'plugin entry defines markdown_db_bfb_skip_insert_conversion callback'
);
$assert(
	str_contains( $plugin_main, 'function markdown_db_is_markdown_type' ),
	'plugin entry defines markdown_db_is_markdown_type helper'
);

// Exercise the callback with synthetic args. The plugin file expects WP
// to be loaded (plugin_dir_path, add_filter, etc.); stub the bare minimum
// so the function definitions land in this scope.
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$a ) { /* noop in smoke */ }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$a ) { /* noop in smoke */ }
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
}

require_once $plugin_dir . '/markdown-database-integration.php';

$assert(
	function_exists( 'markdown_db_bfb_skip_insert_conversion' ),
	'callback function loaded from plugin entry'
);

// 4b.1: markdown into a managed CPT → veto
$assert(
	true === markdown_db_bfb_skip_insert_conversion( false, array( 'post_type' => 'wiki' ), array(), 'markdown' ),
	'callback vetoes markdown insert for managed CPT (wiki)'
);
// 4b.2: markdown into excluded type → no veto
$assert(
	false === markdown_db_bfb_skip_insert_conversion( false, array( 'post_type' => 'revision' ), array(), 'markdown' ),
	'callback does NOT veto markdown insert for excluded type (revision)'
);
// 4b.3: non-markdown format → no veto (lets html→blocks-via-h2bc fire)
$assert(
	false === markdown_db_bfb_skip_insert_conversion( false, array( 'post_type' => 'wiki' ), array(), 'html' ),
	'callback does NOT veto non-markdown source format'
);
// 4b.4: another consumer already vetoed → preserve true
$assert(
	true === markdown_db_bfb_skip_insert_conversion( true, array( 'post_type' => 'wiki' ), array(), 'asciidoc' ),
	'callback preserves an upstream true veto regardless of format/post_type'
);
// 4b.5: empty post_type → no veto
$assert(
	false === markdown_db_bfb_skip_insert_conversion( false, array(), array(), 'markdown' ),
	'callback does NOT veto when post_type is missing'
);

// ---------------------------------------------------------------------
// 5. The bridge package landed in vendor/ with the right shape.
// ---------------------------------------------------------------------
$bridge_root = $plugin_dir . '/vendor/chubes4/block-format-bridge';
$assert( is_dir( $bridge_root ), 'vendor/chubes4/block-format-bridge is installed' );
$assert( file_exists( $bridge_root . '/library.php' ), 'BFB library.php (composer autoload entry) is present' );
$assert( file_exists( $bridge_root . '/includes/api.php' ), 'BFB api.php (defines bfb_convert) is present' );
$assert(
	is_dir( $plugin_dir . '/vendor/chubes4/html-to-blocks-converter' ),
	'BFB transitively pulls in chubes4/html-to-blocks-converter (no separate plugin install required)'
);

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
echo "\n─────────────────────────────────────\n";
echo "Passed: {$passes}\n";
echo 'Failed: ' . count( $failures ) . "\n";
echo "─────────────────────────────────────\n";

if ( ! empty( $failures ) ) {
	echo "\nFailures:\n";
	foreach ( $failures as $failure ) {
		echo "  - {$failure}\n";
	}
	exit( 1 );
}
exit( 0 );
