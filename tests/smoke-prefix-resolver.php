<?php
/**
 * Smoke tests for the lazy table-prefix resolver in WP_Markdown_Write_Engine.
 *
 * The write engine accepts a callable resolver instead of a baked
 * prefix string, so it picks up the canonical $table_prefix at query
 * time even when it was unset at construct time. See GitHub issue #77
 * for the underlying boot-order bug.
 *
 * Pure-PHP tests — no WordPress, no SQLite. Exercises the
 * resolver-vs-string constructor variants and asserts each call to
 * `prefix()` returns the current value, not a captured snapshot.
 *
 * Usage: php tests/smoke-prefix-resolver.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

// The write engine references WP_Markdown_Storage and WP_SQLite_Driver in
// its constructor signature. Provide minimal stubs so we can instantiate.
if ( ! class_exists( 'WP_Markdown_Storage' ) ) {
    class WP_Markdown_Storage {
        public function __construct( ...$args ) {}
    }
}
if ( ! class_exists( 'WP_SQLite_Driver' ) ) {
    class WP_SQLite_Driver {
        public function __construct( ...$args ) {}
    }
}

require_once __DIR__ . '/../inc/class-wp-markdown-write-engine.php';

$failures = array();
$total    = 0;

function assert_smoke( string $name, bool $cond, string $detail = '' ): void {
    global $failures, $total;
    $total++;
    if ( ! $cond ) {
        $failures[] = sprintf( '✗ %s%s', $name, $detail !== '' ? ' (' . $detail . ')' : '' );
        echo '✗ ' . $name . ( $detail !== '' ? ' — ' . $detail : '' ) . PHP_EOL;
    } else {
        echo '✓ ' . $name . PHP_EOL;
    }
}

// --- helper ----------------------------------------------------------------

/**
 * Build a write engine with the given resolver (callable or string).
 * Uses a temp directory we don't actually write to — all the tests below
 * exercise prefix() reads, not file IO.
 */
function build_engine( $prefix_resolver ): WP_Markdown_Write_Engine {
    return new WP_Markdown_Write_Engine(
        sys_get_temp_dir() . '/mdi-bench-prefix-test',
        new WP_Markdown_Storage(),
        new WP_SQLite_Driver(),
        $prefix_resolver
    );
}

/**
 * Read the engine's current prefix() value via the strip_prefix() helper.
 *
 * strip_prefix() is the only public-ish accessor that exposes the result
 * of $this->prefix() to test code without going through SQLite/IO. When
 * the prefix is 'wptests_', `strip_prefix('wptests_options')` returns
 * 'options'. That's the assertion shape.
 */
function read_prefix_via_strip( WP_Markdown_Write_Engine $engine, string $with_prefix_table ): string {
    // strip_prefix is private. PHP 8.1+ allows reflection access without
    // setAccessible(true), so we just construct the method ref and invoke.
    $ref = new \ReflectionMethod( $engine, 'strip_prefix' );
    return (string) $ref->invoke( $engine, $with_prefix_table );
}

// --- tests -----------------------------------------------------------------

// 1. String fallback — pre-existing API, must keep working.
$engine = build_engine( 'wp_' );
assert_smoke(
    'string prefix: legacy "wp_" string still works',
    'options' === read_prefix_via_strip( $engine, 'wp_options' )
);

// 2. Callable that returns a static value.
$engine = build_engine( static function (): string { return 'wptests_'; } );
assert_smoke(
    'callable prefix: static "wptests_"',
    'options' === read_prefix_via_strip( $engine, 'wptests_options' )
);

// 3. Callable that mutates over time — the actual bug fix shape.
//
// The resolver returns whatever $current_prefix points at, simulating
// the production resolver that reads $table_prefix lazily. First call:
// 'wp_'. Second call (after mutation): 'wptests_'. Both queries through
// the SAME engine instance must reflect the current prefix.
$current_prefix = 'wp_';
$engine         = build_engine( static function () use ( &$current_prefix ): string { return $current_prefix; } );
assert_smoke(
    'callable prefix: initial value is "wp_"',
    'options' === read_prefix_via_strip( $engine, 'wp_options' )
);
$current_prefix = 'wptests_';
assert_smoke(
    'callable prefix: re-reads after mutation (wptests_)',
    'options' === read_prefix_via_strip( $engine, 'wptests_options' ),
    'engine must NOT cache the construct-time value'
);
$current_prefix = 'custom_';
assert_smoke(
    'callable prefix: re-reads on each call (custom_)',
    'options' === read_prefix_via_strip( $engine, 'custom_options' )
);

// 4. Resolver returning empty string — edge case. strip_prefix should
// return the input unchanged because the prefix doesn't match.
$engine = build_engine( static function (): string { return ''; } );
assert_smoke(
    'callable prefix: empty string leaves table name untouched',
    'wp_options' === read_prefix_via_strip( $engine, 'wp_options' )
);

// --- summary ---------------------------------------------------------------

echo PHP_EOL;
if ( ! empty( $failures ) ) {
    echo sprintf( "%d / %d FAILED:\n", count( $failures ), $total );
    foreach ( $failures as $f ) {
        echo '  ' . $f . PHP_EOL;
    }
    exit( 1 );
}
echo sprintf( "ALL PASSED (%d / %d)\n", $total, $total );
exit( 0 );
