<?php
/**
 * One-time frontmatter migration to MDI's portable canonical shape.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_Frontmatter_Migration {

	public const OPTION = 'markdown_db_frontmatter_portable_migrated';

	/**
	 * Run the migration once for the configured content directory.
	 */
	public static function maybe_run(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( get_option( self::OPTION ) ) {
			return;
		}

		$excluded_types = defined( 'MARKDOWN_DB_EXCLUDED_TYPES' ) ? array_filter( array_map( 'trim', explode( ',', MARKDOWN_DB_EXCLUDED_TYPES ) ) ) : array();
		$content_dir    = defined( 'MARKDOWN_DB_CONTENT_DIR' ) ? MARKDOWN_DB_CONTENT_DIR : '';
		if ( '' === $content_dir || ! is_dir( $content_dir ) ) {
			update_option( self::OPTION, gmdate( 'c' ), false );
			return;
		}

		$result = self::migrate_content_dir( $content_dir, $excluded_types );
		if ( empty( $result['errors'] ) ) {
			update_option( self::OPTION, gmdate( 'c' ), false );
		}
	}

	/**
	 * Convert native-frontmatter files in a content directory to the current portable profile.
	 *
	 * @param string   $content_dir    Markdown content directory.
	 * @param string[] $excluded_types Post types excluded from markdown storage.
	 * @return array{scanned:int,migrated:int,skipped:int,errors:array<int,string>}
	 */
	public static function migrate_content_dir( string $content_dir, array $excluded_types = array() ): array {
		$reader = new WP_Markdown_Storage( $content_dir, $excluded_types );
		$posts  = $reader->get_all_posts( false );

		$result = array(
			'scanned'  => count( $posts ),
			'migrated' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		$posts_by_id = array();
		foreach ( $posts as $post ) {
			$id = (int) ( $post->ID ?? 0 );
			if ( $id > 0 ) {
				$posts_by_id[ $id ] = $post;
			}
		}

		$writer = new WP_Markdown_Storage( $content_dir, $excluded_types );
		$writer->set_post_resolver(
			static function ( int $post_id ) use ( $posts_by_id ): ?object {
				return $posts_by_id[ $post_id ] ?? null;
			}
		);
		$writer->set_meta_resolver(
			static function ( int $post_id ) use ( $posts_by_id ): array {
				$post = $posts_by_id[ $post_id ] ?? null;
				$meta = is_object( $post ) && is_array( $post->_frontmatter_meta ?? null ) ? $post->_frontmatter_meta : array();
				$rows = array();
				foreach ( $meta as $key => $value ) {
					foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
						$rows[] = (object) array(
							'meta_key'   => (string) $key,
							'meta_value' => (string) $item,
						);
					}
				}
				return $rows;
			}
		);
		$writer->set_terms_resolver(
			static function ( int $post_id ) use ( $posts_by_id ): array {
				$post  = $posts_by_id[ $post_id ] ?? null;
				$terms = is_object( $post ) && is_array( $post->_frontmatter_terms ?? null ) ? $post->_frontmatter_terms : array();
				$rows  = array();
				foreach ( $terms as $taxonomy => $slugs ) {
					foreach ( is_array( $slugs ) ? $slugs : array( $slugs ) as $slug ) {
						$rows[] = (object) array(
							'taxonomy' => (string) $taxonomy,
							'slug'     => (string) $slug,
						);
					}
				}
				return $rows;
			}
		);

		foreach ( $posts as $post ) {
			if ( WP_Markdown_Frontmatter_Profiles::NATIVE_PROFILE !== (string) ( $post->_frontmatter_profile ?? '' ) ) {
				++$result['skipped'];
				continue;
			}

			$written = $writer->write_post( $post );
			if ( false === $written ) {
				$result['errors'][] = 'Failed to migrate post ' . (int) ( $post->ID ?? 0 );
				continue;
			}
			++$result['migrated'];
		}

		return $result;
	}
}
