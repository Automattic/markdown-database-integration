<?php
/**
 * Smoke test proving MDI behaves like a normal SQL database driver: it does
 * not decide which post meta rows it stores. Every meta key — including
 * underscore-prefixed "private" keys such as Data Machine's
 * `_datamachine_post_flow_id` provenance stamp — must round-trip through a
 * full write-then-read cycle exactly as written.
 *
 * The only keys ever excluded from serialized frontmatter are MDI's own
 * bookkeeping meta (source path/identity/hash and reconciliation baseline),
 * because those describe the file's relationship to canonical storage, not
 * the post itself.
 *
 * Usage: php tests/smoke-private-post-meta-roundtrip.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['mdi_roundtrip_filters']            = array();
$GLOBALS['mdi_roundtrip_deprecated_hooks']   = array();
$GLOBALS['mdi_roundtrip_post_meta']          = array();

function add_filter( string $tag, callable $callback ): void {
	$GLOBALS['mdi_roundtrip_filters'][ $tag ][] = $callback;
}

function has_filter( string $tag, $callback = false ): bool {
	unset( $callback );
	return ! empty( $GLOBALS['mdi_roundtrip_filters'][ $tag ] );
}

function apply_filters( string $tag, $value, ...$args ) {
	foreach ( $GLOBALS['mdi_roundtrip_filters'][ $tag ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function _deprecated_hook( string $hook, string $version, string $replacement = '', string $message = '' ): void {
	$GLOBALS['mdi_roundtrip_deprecated_hooks'][] = compact( 'hook', 'version', 'replacement', 'message' );
}

function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
	$meta = $GLOBALS['mdi_roundtrip_post_meta'][ $post_id ] ?? array();
	if ( '' === $key ) {
		return $meta;
	}
	$values = $meta[ $key ] ?? array();
	return $single ? ( $values[0] ?? '' ) : $values;
}

function get_post( int $post_id ): ?object {
	unset( $post_id );
	return null;
}

function maybe_unserialize( mixed $value ): mixed {
	if ( ! is_string( $value ) || ! preg_match( '/^(?:a|O|s|i|b|d|N):/', $value ) ) {
		return $value;
	}
	$result = @unserialize( $value, array( 'allowed_classes' => false ) );
	return false === $result && 'b:0;' !== $value ? $value : $result;
}

require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/class-wp-markdown-wordpress-reconciliation-adapter.php';

$passed = 0;
$failed = 0;

function mdi_roundtrip_check( bool $condition, string $message ): void {
	global $passed, $failed;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		++$passed;
		return;
	}
	echo "FAIL: {$message}\n";
	++$failed;
}

function mdi_roundtrip_rm( string $path ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		@unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( scandir( $path ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		mdi_roundtrip_rm( $path . '/' . $entry );
	}
	@rmdir( $path );
}

$root = rtrim( sys_get_temp_dir(), '/' ) . '/mdi-private-meta-roundtrip-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0777, true );
register_shutdown_function( static fn() => mdi_roundtrip_rm( $root ) );

// MDI's own bookkeeping meta — must never leak into frontmatter, because it
// describes the file's relationship to canonical storage, not the post.
$bookkeeping_meta = array(
	'_markdown_source_path'             => 'wiki/should-not-appear.md',
	'_markdown_source_identity'         => 'wiki/should-not-appear-identity.md',
	'_markdown_source_hash'             => 'deadbeefcafef00d',
	'_markdown_reconciliation_baseline' => 'opaque-baseline-blob',
);

// Every one of these is a real underscore-prefixed key that the old
// hardcoded allowlist silently dropped unless it was `_thumbnail_id` or
// `_wp_page_template`. Data Machine's flow-provenance stamp is the exact
// key the bug report names.
$private_meta = array(
	'_datamachine_post_flow_id'     => 'flow-42',
	'_datamachine_post_handler'     => 'wiki-importer',
	'_intelligence_wiki_auto_stub'  => 'true',
	'_thumbnail_id'                 => '99', // Previously allowlisted — still must work.
	'_wp_page_template'             => 'templates/full-width.php', // Previously allowlisted — still must work.
	'_some_third_party_plugin_meta' => 'never-allowlisted-before-either',
);

$public_meta = array( 'seo_description' => 'A normal, always-visible meta value.' );

echo "Test 1: WP_Markdown_Storage::write_post() with meta supplied directly on the post object\n";

$direct_storage = new WP_Markdown_Storage( $root );
$direct_post    = (object) array_merge(
	array(
		'ID'                => 30,
		'post_title'        => 'Direct Provenance Post',
		'post_status'       => 'publish',
		'post_type'         => 'wiki',
		'post_author'       => 1,
		'post_date'         => '2026-07-01 00:00:00',
		'post_date_gmt'     => '2026-07-01 00:00:00',
		'post_modified'     => '2026-07-01 00:00:00',
		'post_modified_gmt' => '2026-07-01 00:00:00',
		'post_name'         => 'direct-provenance-post',
		'post_content'      => 'Body content.',
		'_frontmatter_meta' => array_merge( $bookkeeping_meta, $private_meta, $public_meta ),
	)
);
$direct_file = $direct_storage->write_post( $direct_post );
mdi_roundtrip_check( is_string( $direct_file ) && is_file( $direct_file ), 'write_post() writes the markdown file' );

$direct_raw = is_string( $direct_file ) ? (string) file_get_contents( $direct_file ) : '';
foreach ( $private_meta as $key => $value ) {
	mdi_roundtrip_check(
		str_contains( $direct_raw, "    {$key}: " . WP_Markdown_Yaml::yaml_scalar( $value ) . "\n" ),
		"underscore-prefixed private meta \"{$key}\" is written into frontmatter"
	);
}
mdi_roundtrip_check(
	str_contains( $direct_raw, "    seo_description: A normal, always-visible meta value.\n" ),
	'ordinary public meta is written into frontmatter'
);
foreach ( $bookkeeping_meta as $key => $value ) {
	mdi_roundtrip_check(
		! str_contains( $direct_raw, (string) $key ) && ! str_contains( $direct_raw, (string) $value ),
		"MDI bookkeeping meta \"{$key}\" is excluded from frontmatter"
	);
}

// Read back with a *new* storage instance so this is a genuine file
// round trip, not an in-memory echo of what was just written.
$direct_read_storage = new WP_Markdown_Storage( $root );
$direct_posts         = $direct_read_storage->get_all_posts( false );
$direct_read          = null;
foreach ( $direct_posts as $candidate ) {
	if ( 30 === (int) ( $candidate->ID ?? 0 ) ) {
		$direct_read = $candidate;
		break;
	}
}
mdi_roundtrip_check( null !== $direct_read, 'post written via write_post() is read back by get_all_posts()' );
if ( null !== $direct_read ) {
	foreach ( $private_meta as $key => $value ) {
		mdi_roundtrip_check(
			( $direct_read->_frontmatter_meta[ $key ] ?? null ) === $value,
			"underscore-prefixed private meta \"{$key}\" survives a full write-then-read round trip"
		);
	}
	mdi_roundtrip_check(
		( $direct_read->_frontmatter_meta['seo_description'] ?? null ) === $public_meta['seo_description'],
		'ordinary public meta survives a full write-then-read round trip'
	);
	foreach ( array_keys( $bookkeeping_meta ) as $key ) {
		mdi_roundtrip_check(
			! array_key_exists( $key, $direct_read->_frontmatter_meta ),
			"MDI bookkeeping meta \"{$key}\" is not resurrected on read"
		);
	}
}

echo "\nTest 2: WP_Markdown_Storage with a raw meta_resolver, as wired by the real wpdb driver\n";

// This mirrors how inc/class-wp-markdown-db.php wires the meta resolver:
// a flat SELECT of every wp_postmeta row for the post, bookkeeping included,
// exactly as a real database driver would return them.
$resolver_meta = array_merge( $bookkeeping_meta, $private_meta, $public_meta );
$resolver_rows = array();
foreach ( $resolver_meta as $key => $value ) {
	$resolver_rows[] = (object) array( 'meta_key' => (string) $key, 'meta_value' => (string) $value );
}

$resolver_storage = new WP_Markdown_Storage( $root );
$resolver_storage->set_meta_resolver(
	static function ( int $post_id ) use ( $resolver_rows ) {
		return 31 === $post_id ? $resolver_rows : array();
	}
);
$resolver_post = (object) array(
	'ID'                => 31,
	'post_title'        => 'Resolver Provenance Post',
	'post_status'       => 'publish',
	'post_type'         => 'wiki',
	'post_author'       => 1,
	'post_date'         => '2026-07-01 00:00:00',
	'post_date_gmt'     => '2026-07-01 00:00:00',
	'post_modified'     => '2026-07-01 00:00:00',
	'post_modified_gmt' => '2026-07-01 00:00:00',
	'post_name'         => 'resolver-provenance-post',
	'post_content'      => 'Body content.',
);
$resolver_file = $resolver_storage->write_post( $resolver_post );
$resolver_raw  = is_string( $resolver_file ) ? (string) file_get_contents( $resolver_file ) : '';
mdi_roundtrip_check(
	str_contains( $resolver_raw, "    _datamachine_post_flow_id: flow-42\n" ),
	'meta_resolver-sourced private meta (Data Machine provenance) is written into frontmatter, just like any other database driver would store it'
);
foreach ( array_keys( $bookkeeping_meta ) as $key ) {
	mdi_roundtrip_check(
		! str_contains( $resolver_raw, (string) $key ),
		"meta_resolver-sourced MDI bookkeeping meta \"{$key}\" is excluded from frontmatter"
	);
}

$resolver_read_storage = new WP_Markdown_Storage( $root );
$resolver_posts        = $resolver_read_storage->get_all_posts( false );
$resolver_read         = null;
foreach ( $resolver_posts as $candidate ) {
	if ( 31 === (int) ( $candidate->ID ?? 0 ) ) {
		$resolver_read = $candidate;
		break;
	}
}
mdi_roundtrip_check(
	null !== $resolver_read && ( $resolver_read->_frontmatter_meta['_datamachine_post_flow_id'] ?? null ) === 'flow-42',
	'Data Machine provenance meta sourced from a raw meta_resolver survives a full write-then-read round trip, so Intelligence wiki list --agent/--pipeline filters can match it'
);

echo "\nTest 3: the markdown_db_internal_meta_allowlist filter no longer restricts serialization, but firing it triggers a deprecation notice\n";

add_filter(
	'markdown_db_internal_meta_allowlist',
	static function ( array $allowlist ): array {
		// A legacy 3rd-party callback trying to narrow the allowlist. It
		// must have zero effect now — the allowlist concept is gone.
		return array( '_thumbnail_id' );
	}
);

$deprecated_storage = new WP_Markdown_Storage( $root );
$deprecated_post    = (object) array(
	'ID'                => 32,
	'post_title'        => 'Deprecated Filter Post',
	'post_status'       => 'publish',
	'post_type'         => 'wiki',
	'post_author'       => 1,
	'post_date'         => '2026-07-01 00:00:00',
	'post_date_gmt'     => '2026-07-01 00:00:00',
	'post_modified'     => '2026-07-01 00:00:00',
	'post_modified_gmt' => '2026-07-01 00:00:00',
	'post_name'         => 'deprecated-filter-post',
	'post_content'      => 'Body content.',
	'_frontmatter_meta' => array( '_datamachine_post_flow_id' => 'flow-77' ),
);
$deprecated_file = $deprecated_storage->write_post( $deprecated_post );
$deprecated_raw  = is_string( $deprecated_file ) ? (string) file_get_contents( $deprecated_file ) : '';
mdi_roundtrip_check(
	str_contains( $deprecated_raw, "    _datamachine_post_flow_id: flow-77\n" ),
	'a registered markdown_db_internal_meta_allowlist callback no longer removes unlisted private meta from frontmatter'
);
mdi_roundtrip_check(
	1 === count( array_filter( $GLOBALS['mdi_roundtrip_deprecated_hooks'], static fn( array $call ): bool => 'markdown_db_internal_meta_allowlist' === $call['hook'] ) ),
	'a registered markdown_db_internal_meta_allowlist callback fires a deprecation notice via _deprecated_hook()'
);

echo "\nTest 4: WP_Markdown_WordPress_Reconciliation_Adapter::wordpress_meta() also round-trips private meta and excludes bookkeeping meta\n";

$GLOBALS['mdi_roundtrip_post_meta'][42] = array(
	'_datamachine_post_flow_id'         => array( 'flow-99' ),
	'_markdown_source_path'             => array( 'wiki/bookkeeping.md' ),
	'_markdown_source_identity'         => array( 'wiki/bookkeeping-identity.md' ),
	'_markdown_source_hash'             => array( 'abc123def456' ),
	'_markdown_reconciliation_baseline' => array( 'baseline-blob' ),
);

$adapter                = new WP_Markdown_WordPress_Reconciliation_Adapter();
$wordpress_meta_method  = new ReflectionMethod( $adapter, 'wordpress_meta' );
$adapter_meta           = $wordpress_meta_method->invoke( $adapter, 42 );
mdi_roundtrip_check(
	( $adapter_meta['_datamachine_post_flow_id'][0] ?? null ) === 'flow-99',
	'reconciliation adapter wordpress_meta() preserves Data Machine provenance meta'
);
mdi_roundtrip_check(
	! array_key_exists( '_markdown_source_path', $adapter_meta )
	&& ! array_key_exists( '_markdown_source_identity', $adapter_meta )
	&& ! array_key_exists( '_markdown_source_hash', $adapter_meta )
	&& ! array_key_exists( '_markdown_reconciliation_baseline', $adapter_meta ),
	'reconciliation adapter wordpress_meta() still excludes MDI bookkeeping meta'
);

if ( $failed > 0 ) {
	echo "\nFAILURES: {$failed}\n";
	exit( 1 );
}

echo "\nAll tests passed ({$passed} assertions).\n";
