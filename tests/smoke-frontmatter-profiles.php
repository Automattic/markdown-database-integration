<?php
/**
 * Smoke test for pluggable frontmatter profiles.
 *
 * Usage: php tests/smoke-frontmatter-profiles.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

function sanitize_key( $key ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
}

function apply_filters( $hook_name, $value, ...$args ) {
	if ( 'markdown_db_frontmatter_profile_id' === $hook_name ) {
		$post = $args[1] ?? null;
		if ( is_object( $post ) && 'page' === ( $post->post_type ?? '' ) ) {
			return 'okf';
		}
	}

	return $value;
}

require dirname( __DIR__ ) . '/inc/class-wp-markdown-frontmatter-profiles.php';
require dirname( __DIR__ ) . '/inc/class-wp-markdown-storage.php';

$failures = array();
$tmp_root = rtrim( sys_get_temp_dir(), '/' ) . '/mdi-frontmatter-profiles-' . getmypid();
if ( is_dir( $tmp_root ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $tmp_root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $tmp_root );
}
mkdir( $tmp_root, 0777, true );

$native_storage = new WP_Markdown_Storage( $tmp_root );
$native_file    = $native_storage->write_post(
	(object) array(
		'ID'                => 11,
		'post_title'        => 'Native Profile',
		'post_status'       => 'publish',
		'post_type'         => 'wiki',
		'post_author'       => 1,
		'post_date'         => '2026-06-17 00:00:00',
		'post_date_gmt'     => '2026-06-17 00:00:00',
		'post_modified'     => '2026-06-17 00:00:00',
		'post_modified_gmt' => '2026-06-17 00:00:00',
		'post_name'         => 'native-profile',
		'post_content'      => "Native body\nwith content",
	)
);

if ( ! is_string( $native_file ) || ! str_contains( file_get_contents( $native_file ), "type: wiki\n" ) ) {
	$failures[] = 'native profile did not preserve the existing WordPress-safe type field';
}

markdown_db_register_frontmatter_profile(
	'compact',
	array(
		'supports_post'         => static fn( object $post ): bool => 'wiki' === ( $post->post_type ?? '' ),
		'supports_frontmatter'  => static fn( array $frontmatter ): bool => 'compact' === ( $frontmatter['profile'] ?? '' ),
		'export_frontmatter'    => static function ( object $post ): array {
			return array(
				'profile'      => 'compact',
				'id'           => (int) $post->ID,
				'title'        => (string) $post->post_title,
				'type'         => 'domain-record',
				'wp_type'      => (string) $post->post_type,
				'slug'         => (string) $post->post_name,
				'domain_field' => 'round-trip me',
			);
		},
		'import_post_data'     => static function ( array $frontmatter, string $body ): array {
			return array(
				'ID'                => (int) ( $frontmatter['id'] ?? 0 ),
				'post_title'        => (string) ( $frontmatter['title'] ?? '' ),
				'post_status'       => 'publish',
				'post_type'         => (string) ( $frontmatter['wp_type'] ?? 'post' ),
				'post_author'       => 1,
				'post_date'         => '0000-00-00 00:00:00',
				'post_date_gmt'     => '0000-00-00 00:00:00',
				'post_modified'     => '0000-00-00 00:00:00',
				'post_modified_gmt' => '0000-00-00 00:00:00',
				'post_name'         => (string) ( $frontmatter['slug'] ?? '' ),
				'post_content'      => $body,
			);
		},
	)
);

$profile_storage = new WP_Markdown_Storage( $tmp_root );
$profile_storage->set_frontmatter_profile( 'compact' );
$profile_file = $profile_storage->write_post(
	(object) array(
		'ID'           => 12,
		'post_title'   => 'Profiled Post',
		'post_status'  => 'publish',
		'post_type'    => 'wiki',
		'post_author'  => 1,
		'post_name'    => 'profiled-post',
		'post_content' => "Profiled body\nwith content",
	)
);

$profile_raw = is_string( $profile_file ) ? file_get_contents( $profile_file ) : '';
if ( ! str_contains( $profile_raw, "profile: compact\n" ) || ! str_contains( $profile_raw, "type: domain-record\n" ) || ! str_contains( $profile_raw, "wp_type: wiki\n" ) ) {
	$failures[] = 'custom profile did not control exported frontmatter shape';
}

$posts = $profile_storage->get_all_posts( false );
$read  = null;
foreach ( $posts as $post ) {
	if ( 12 === (int) ( $post->ID ?? 0 ) ) {
		$read = $post;
		break;
	}
}

if ( null === $read ) {
	$failures[] = 'profiled post was not read back from storage';
} else {
	if ( 'wiki' !== $read->post_type ) {
		$failures[] = 'custom import profile did not map frontmatter back to WordPress post_type';
	}
	if ( "Profiled body\nwith content" !== $read->post_content ) {
		$failures[] = 'custom import profile did not preserve post_content bytes';
	}
	if ( 'round-trip me' !== ( $read->_frontmatter['domain_field'] ?? '' ) ) {
		$failures[] = 'unknown profile field was not retained on _frontmatter';
	}
}

$okf_storage = new WP_Markdown_Storage( $tmp_root );
$okf_storage->set_terms_resolver(
	static function ( int $post_id ): array {
		if ( 22 === $post_id ) {
			return array(
				(object) array(
					'taxonomy' => 'post_tag',
					'slug'     => 'alpha',
				),
				(object) array(
					'taxonomy' => 'post_tag',
					'slug'     => 'beta',
				),
			);
		}

		return array();
	}
);

$okf_parent_file = $okf_storage->write_post(
	(object) array(
		'ID'                => 21,
		'post_title'        => 'OKF Parent',
		'post_status'       => 'publish',
		'post_type'         => 'page',
		'post_author'       => 3,
		'post_date'         => '2026-06-17 12:34:56',
		'post_date_gmt'     => '2026-06-17 12:34:56',
		'post_modified'     => '2026-06-17 13:00:00',
		'post_modified_gmt' => '2026-06-17 13:00:00',
		'post_name'         => 'okf-parent',
		'post_excerpt'      => 'Parent description',
		'post_content'      => 'Parent body',
		'guid'              => 'https://example.test/okf-parent',
	)
);

$okf_child_file = $okf_storage->write_post(
	(object) array(
		'ID'                => 22,
		'post_title'        => 'OKF Child',
		'post_status'       => 'private',
		'post_type'         => 'page',
		'post_author'       => 4,
		'post_date'         => '2026-06-17 14:15:16',
		'post_date_gmt'     => '2026-06-17 14:15:16',
		'post_modified'     => '2026-06-17 15:00:00',
		'post_modified_gmt' => '2026-06-17 15:00:00',
		'post_name'         => 'okf-child',
		'post_parent'       => 21,
		'post_excerpt'      => 'Child description',
		'post_content'      => "Child body\nwith blocks",
		'guid'              => 'https://example.test/okf-child',
	)
);

$okf_raw = is_string( $okf_child_file ) ? file_get_contents( $okf_child_file ) : '';
if ( ! str_contains( $okf_raw, "type: document\n" ) || ! str_contains( $okf_raw, "wordpress:\n" ) || ! str_contains( $okf_raw, "  type: page\n" ) ) {
	$failures[] = 'OKF profile did not keep OKF type separate from wordpress.type';
}
if ( ! str_contains( $okf_raw, "tags:\n  - alpha\n  - beta\n" ) ) {
	$failures[] = 'OKF profile did not export post_tag terms as top-level tags';
}
if ( ! str_contains( $okf_raw, "timestamp: \"2026-06-17T14:15:16+00:00\"\n" ) ) {
	$failures[] = 'OKF profile did not export an ISO-like timestamp';
}

$okf_import_storage = new WP_Markdown_Storage( $tmp_root );
$okf_posts          = $okf_import_storage->get_all_posts( false );
$okf_parent         = null;
$okf_child          = null;
foreach ( $okf_posts as $post ) {
	if ( 21 === (int) ( $post->ID ?? 0 ) ) {
		$okf_parent = $post;
	}
	if ( 22 === (int) ( $post->ID ?? 0 ) ) {
		$okf_child = $post;
	}
}

if ( null === $okf_parent || null === $okf_child ) {
	$failures[] = 'OKF posts were not read back from storage';
} else {
	if ( 'okf' !== $okf_child->_frontmatter_profile ) {
		$failures[] = 'OKF-shaped frontmatter did not auto-select the OKF import profile';
	}
	if ( 'page' !== $okf_child->post_type || 'private' !== $okf_child->post_status || 'okf-child' !== $okf_child->post_name ) {
		$failures[] = 'OKF import did not reconstruct WordPress type/status/slug';
	}
	if ( 'OKF Child' !== $okf_child->post_title || 'Child description' !== $okf_child->post_excerpt ) {
		$failures[] = 'OKF import did not reconstruct title/description fields';
	}
	if ( '2026-06-17 14:15:16' !== $okf_child->post_date || '2026-06-17 14:15:16' !== $okf_child->post_date_gmt ) {
		$failures[] = 'OKF import did not reconstruct date fields from wordpress metadata';
	}
	if ( 21 !== (int) $okf_child->post_parent ) {
		$failures[] = 'OKF import did not preserve hierarchy from nested wordpress.id metadata';
	}
	if ( "Child body\nwith blocks" !== $okf_child->post_content ) {
		$failures[] = 'OKF import did not preserve post_content bytes';
	}
	if ( array( 'alpha', 'beta' ) !== ( $okf_child->_frontmatter_terms['post_tag'] ?? array() ) ) {
		$failures[] = 'OKF import did not map top-level tags back to post_tag terms';
	}
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo 'PASS: pluggable frontmatter profiles preserve native defaults and support custom mappings' . PHP_EOL;
