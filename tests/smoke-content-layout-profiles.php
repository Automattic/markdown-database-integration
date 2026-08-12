<?php
/**
 * Smoke test for pluggable content-layout profiles.
 *
 * Usage: php tests/smoke-content-layout-profiles.php
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

function sanitize_key( $key ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
}

function apply_filters( $hook_name, $value, ...$args ) {
	return $value;
}

require dirname( __DIR__ ) . '/inc/class-wp-markdown-frontmatter-profiles.php';
require dirname( __DIR__ ) . '/inc/class-wp-markdown-content-layout-profiles.php';
require dirname( __DIR__ ) . '/inc/class-wp-markdown-storage.php';

$root = rtrim( sys_get_temp_dir(), '/' ) . '/mdi-content-layout-profiles-' . getmypid();
mkdir( $root, 0777, true );
$failures = array();

markdown_db_register_content_layout_profile(
	'flat-pages-fixture',
	array(
		'label' => 'Flat pages fixture',
		'extensions' => array( 'md' ),
		'hierarchy' => 'frontmatter',
		'enumerate' => static function ( string $content_dir ): array {
			return array_filter( scandir( $content_dir ) ?: array(), static fn( string $path ): bool => str_ends_with( $path, '.md' ) );
		},
		'map_source' => static function ( string $path, array $frontmatter ): array {
			return array(
				'post_type' => 'page',
				'post_name' => basename( $path, '.md' ),
				'post_parent' => (int) ( $frontmatter['parent'] ?? 0 ),
				'source_identity' => $path,
			);
		},
		'path_for_post' => static function ( object $post ): string {
			return (string) $post->post_name . '.md';
		},
	)
);

if ( markdown_db_register_content_layout_profile( 'incomplete', array( 'enumerate' => static fn(): array => array() ) ) ) {
	$failures[] = 'incomplete non-default profile was registered';
}
try {
	WP_Markdown_Content_Layout_Profiles::resolve( 'unknown-layout' );
	$failures[] = 'explicit unknown profile did not fail closed';
} catch ( InvalidArgumentException $exception ) {
}

file_put_contents( $root . '/foo.md', "---\nid: 1\ntitle: Foo\nstatus: publish\n---\n\nFoo body\n" );
$storage = new WP_Markdown_Storage( $root );
$storage->set_content_layout_profile( 'flat-pages-fixture' );
$posts = $storage->get_all_posts();
$foo = $posts[0] ?? null;
if ( ! is_object( $foo ) || 'page' !== $foo->post_type || 'foo' !== $foo->post_name || 'foo.md' !== ( $foo->_source_identity ?? '' ) ) {
	$failures[] = 'fixture profile did not map content/foo.md to page /foo with its stable source identity';
}

$written = $storage->write_post( (object) array( 'ID' => 1, 'post_type' => 'page', 'post_name' => 'foo', 'post_status' => 'publish', 'post_title' => 'Foo', 'post_content' => 'Updated body' ) );
if ( $root . '/foo.md' !== $written ) {
	$failures[] = 'fixture profile did not export page /foo to content/foo.md';
}

$duplicate = $storage->write_post( (object) array( 'ID' => 2, 'post_type' => 'page', 'post_name' => 'foo', 'post_status' => 'publish', 'post_title' => 'Duplicate', 'post_content' => 'Duplicate' ) );
if ( false !== $duplicate ) {
	$failures[] = 'duplicate route identity was not rejected';
}

$indexed = array();
$storage->set_index_writer( static function ( int $id, string $path ) use ( &$indexed ): void { $indexed[ $id ] = $path; } );
$moved = $storage->write_post( (object) array( 'ID' => 1, 'post_type' => 'page', 'post_name' => 'moved', 'post_status' => 'publish', 'post_title' => 'Moved', 'post_content' => 'Moved' ) );
if ( $root . '/moved.md' !== $moved || file_exists( $root . '/foo.md' ) || 'moved.md' !== ( $indexed[1] ?? '' ) ) {
	$failures[] = 'profile write did not atomically move the prior canonical path and update the index';
}

$blocked = new WP_Markdown_Storage( $root );
$blocked->set_content_layout_profile( 'flat-pages-fixture' );
$blocked->set_file_mutation_observer(
	static function ( string $path ): void {
		if ( str_ends_with( $path, '/moved.md' ) ) {
			unlink( $path );
			mkdir( $path );
		}
	}
);
$failed_move = $blocked->write_post( (object) array( 'ID' => 1, 'post_type' => 'page', 'post_name' => 'blocked', 'post_status' => 'publish', 'post_title' => 'Blocked', 'post_content' => 'Blocked' ) );
if ( false !== $failed_move || file_exists( $root . '/blocked.md' ) ) {
	$failures[] = 'failed old-route removal committed a second canonical file';
}
rmdir( $root . '/moved.md' );

file_put_contents( $root . '/alias.md', "---\nid: 5\n---\n\nalias\n" );
file_put_contents( $root . '/second.md', "---\nid: 6\n---\n\nsecond\n" );
markdown_db_register_content_layout_profile(
	'identity-fixture',
	array(
		'enumerate' => static fn(): array => array( './alias.md', 'alias.md', 'second.md' ),
		'map_source' => static fn( string $path ): array => array( 'post_type' => 'page', 'post_name' => basename( $path, '.md' ), 'source_identity' => 'shared' ),
		'path_for_post' => static fn( object $post ): string => $post->post_name . '.md',
	)
);
$identity_storage = new WP_Markdown_Storage( $root );
$identity_storage->set_content_layout_profile( 'identity-fixture' );
if ( 1 !== count( $identity_storage->get_all_posts() ) ) {
	$failures[] = 'source aliases or duplicate stable identities were not deterministically deduplicated';
}

if ( function_exists( 'symlink' ) ) {
	$outside = $root . '-outside';
	mkdir( $outside, 0777, true );
	file_put_contents( $outside . '/escape.md', "---\nid: 4\n---\n\nescape\n" );
	symlink( $outside . '/escape.md', $root . '/linked.md' );
	markdown_db_register_content_layout_profile(
		'symlink-fixture',
		array(
			'enumerate' => static fn(): array => array( 'linked.md' ),
			'map_source' => static fn(): array => array( 'post_type' => 'page', 'post_name' => 'linked' ),
			'path_for_post' => static fn(): string => 'linked.md',
		)
	);
	$symlink_storage = new WP_Markdown_Storage( $root );
	$symlink_storage->set_content_layout_profile( 'symlink-fixture' );
	if ( array() !== $symlink_storage->get_all_posts() || false !== $symlink_storage->write_post( (object) array( 'ID' => 4, 'post_name' => 'linked' ) ) ) {
		$failures[] = 'symlinked profile path escaped containment';
	}
}

markdown_db_register_content_layout_profile(
	'unsafe-fixture',
	array(
		'enumerate' => static fn(): array => array( '../escape.md', '_tables/state.md' ),
		'map_source' => static fn(): array => array( 'post_type' => 'page', 'post_name' => 'unsafe' ),
		'path_for_post' => static fn(): string => '../escape.md',
	)
);
$unsafe = new WP_Markdown_Storage( $root );
$unsafe->set_content_layout_profile( 'unsafe-fixture' );
if ( array() !== $unsafe->get_all_posts() || false !== $unsafe->write_post( (object) array( 'ID' => 3, 'post_name' => 'unsafe' ) ) ) {
	$failures[] = 'profile paths escaped the content root or claimed state paths';
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo 'PASS: content layout profiles round-trip flat files and enforce path containment' . PHP_EOL;
