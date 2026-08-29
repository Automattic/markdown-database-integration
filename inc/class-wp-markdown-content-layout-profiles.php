<?php
/**
 * Pluggable content-layout profile registry.
 *
 * Layout profiles own source-file discovery and routes. Frontmatter profiles
 * only own the contents of those files.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_Content_Layout_Profiles {
	public const DEFAULT_PROFILE = 'post-type-hierarchy';

	/** @var array<string,array<string,mixed>> */
	private static array $profiles = array();

	/** Register a layout profile. */
	public static function register( string $id, array $profile ): bool {
		$id = self::sanitize_id( $id );
		if ( '' === $id ) {
			return false;
		}
		if ( self::DEFAULT_PROFILE !== $id && ( empty( $profile['enumerate'] ) || ! is_callable( $profile['enumerate'] ) || empty( $profile['map_source'] ) || ! is_callable( $profile['map_source'] ) || empty( $profile['path_for_post'] ) || ! is_callable( $profile['path_for_post'] ) ) ) {
			return false;
		}
		$profile['id'] = $id;
		self::$profiles[ $id ] = $profile;
		return true;
	}

	/** @return array<string,mixed>|null */
	public static function get( string $id ): ?array {
		self::ensure_default();
		return self::$profiles[ self::sanitize_id( $id ) ] ?? null;
	}

	/** @return array<string,mixed> @throws \InvalidArgumentException */
	public static function resolve( string $id = '', array $context = array() ): array {
		self::ensure_default();
		$id = self::sanitize_id( $id );
		if ( '' !== $id ) {
			if ( isset( self::$profiles[ $id ] ) ) {
				return self::$profiles[ $id ];
			}
			throw new \InvalidArgumentException( sprintf( 'Unknown content-layout profile "%s".', $id ) );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$id = self::sanitize_id( (string) apply_filters( 'markdown_db_content_layout_profile_id', '', $context ) );
			if ( '' !== $id ) {
				if ( isset( self::$profiles[ $id ] ) ) {
					return self::$profiles[ $id ];
				}
				throw new \InvalidArgumentException( sprintf( 'Unknown content-layout profile "%s".', $id ) );
			}
		}
		return self::$profiles[ self::DEFAULT_PROFILE ];
	}

	private static function ensure_default(): void {
		if ( isset( self::$profiles[ self::DEFAULT_PROFILE ] ) ) {
			return;
		}
		self::$profiles[ self::DEFAULT_PROFILE ] = array(
			'id' => self::DEFAULT_PROFILE,
			'label' => 'Post type hierarchy',
			'extensions' => array( 'md' ),
			'hierarchy' => 'directory-index',
		);
	}

	private static function sanitize_id( string $id ): string {
		return function_exists( 'sanitize_key' ) ? sanitize_key( $id ) : strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $id ) ?? '' );
	}
}

if ( ! function_exists( 'markdown_db_register_content_layout_profile' ) ) {
	function markdown_db_register_content_layout_profile( string $id, array $profile ): bool {
		return WP_Markdown_Content_Layout_Profiles::register( $id, $profile );
	}
}

if ( ! function_exists( 'markdown_db_get_content_layout_profile' ) ) {
	function markdown_db_get_content_layout_profile( string $id ): ?array {
		return WP_Markdown_Content_Layout_Profiles::get( $id );
	}
}
