<?php
/**
 * Pluggable frontmatter profile registry.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_Frontmatter_Profiles {

	public const NATIVE_PROFILE = 'native';

	/**
	 * Registered profiles keyed by profile ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $profiles = array();

	/**
	 * Register a frontmatter profile.
	 *
	 * A profile may provide these callbacks:
	 * - supports_post( object $post, array $context ): bool
	 * - export_frontmatter( object $post, array $context ): array
	 * - import_post_data( array $frontmatter, string $body, array $context ): array|object
	 * - supports_frontmatter( array $frontmatter, string $body, array $context ): bool
	 *
	 * @param string $id      Stable profile ID.
	 * @param array  $profile Profile callbacks and metadata.
	 * @return bool Whether the profile was registered.
	 */
	public static function register( string $id, array $profile ): bool {
		$id = self::sanitize_id( $id );
		if ( '' === $id ) {
			return false;
		}

		$profile['id']         = $id;
		self::$profiles[ $id ] = $profile;
		return true;
	}

	/**
	 * Return a registered profile by ID.
	 *
	 * @param string $id Profile ID.
	 * @return array<string, mixed>|null Profile definition, or null.
	 */
	public static function get( string $id ): ?array {
		$id = self::sanitize_id( $id );
		return self::$profiles[ $id ] ?? null;
	}

	/**
	 * Resolve the profile used to export a post.
	 *
	 * @param object $post    Post row object.
	 * @param array  $context Export context.
	 * @return array<string, mixed> Profile definition.
	 */
	public static function resolve_for_export( object $post, array $context = array() ): array {
		self::ensure_native_profile();

		$explicit = self::sanitize_id( (string) ( $context['profile'] ?? '' ) );
		if ( '' !== $explicit && isset( self::$profiles[ $explicit ] ) ) {
			return self::$profiles[ $explicit ];
		}

		$selected = '';
		if ( function_exists( 'apply_filters' ) ) {
			$selected = self::sanitize_id( (string) apply_filters( 'markdown_db_frontmatter_profile_id', '', $context, $post ) );
			if ( '' !== $selected && isset( self::$profiles[ $selected ] ) ) {
				return self::$profiles[ $selected ];
			}
		}

		foreach ( self::$profiles as $id => $profile ) {
			if ( self::NATIVE_PROFILE === $id || empty( $profile['supports_post'] ) || ! is_callable( $profile['supports_post'] ) ) {
				continue;
			}
			if ( (bool) call_user_func( $profile['supports_post'], $post, $context ) ) {
				return $profile;
			}
		}

		return self::$profiles[ self::NATIVE_PROFILE ];
	}

	/**
	 * Resolve the profile used to import frontmatter.
	 *
	 * @param array  $frontmatter Decoded YAML frontmatter.
	 * @param string $body        File body.
	 * @param array  $context     Import context.
	 * @return array<string, mixed> Profile definition.
	 */
	public static function resolve_for_import( array $frontmatter, string $body, array $context = array() ): array {
		self::ensure_native_profile();

		$explicit = self::sanitize_id( (string) ( $context['profile'] ?? '' ) );
		if ( '' !== $explicit && isset( self::$profiles[ $explicit ] ) ) {
			return self::$profiles[ $explicit ];
		}

		$selected = '';
		if ( function_exists( 'apply_filters' ) ) {
			$selected = self::sanitize_id( (string) apply_filters( 'markdown_db_frontmatter_profile_id', '', $context, null ) );
			if ( '' !== $selected && isset( self::$profiles[ $selected ] ) ) {
				return self::$profiles[ $selected ];
			}
		}

		foreach ( self::$profiles as $id => $profile ) {
			if ( self::NATIVE_PROFILE === $id || empty( $profile['supports_frontmatter'] ) || ! is_callable( $profile['supports_frontmatter'] ) ) {
				continue;
			}
			if ( (bool) call_user_func( $profile['supports_frontmatter'], $frontmatter, $body, $context ) ) {
				return $profile;
			}
		}

		return self::$profiles[ self::NATIVE_PROFILE ];
	}

	/**
	 * Ensure the built-in WordPress-safe profile exists.
	 */
	private static function ensure_native_profile(): void {
		if ( isset( self::$profiles[ self::NATIVE_PROFILE ] ) ) {
			return;
		}

		self::$profiles[ self::NATIVE_PROFILE ] = array(
			'id'                 => self::NATIVE_PROFILE,
			'label'              => 'WordPress native',
			'export_frontmatter' => static fn( object $post, array $context ): array => (array) ( $context['native_frontmatter'] ?? array() ),
			'import_post_data'   => static function ( array $frontmatter, string $body ): array {
				return array(
					'ID'                    => (int) ( $frontmatter['id'] ?? 0 ),
					'post_title'            => $frontmatter['title'] ?? '',
					'post_status'           => $frontmatter['status'] ?? 'draft',
					'post_type'             => $frontmatter['type'] ?? 'post',
					'post_author'           => (int) ( $frontmatter['author'] ?? 0 ),
					'post_date'             => $frontmatter['date'] ?? '0000-00-00 00:00:00',
					'post_date_gmt'         => $frontmatter['date_gmt'] ?? ( $frontmatter['date'] ?? '0000-00-00 00:00:00' ),
					'post_modified'         => $frontmatter['modified'] ?? ( $frontmatter['date'] ?? '0000-00-00 00:00:00' ),
					'post_modified_gmt'     => $frontmatter['modified_gmt'] ?? ( $frontmatter['modified'] ?? ( $frontmatter['date'] ?? '0000-00-00 00:00:00' ) ),
					'post_name'             => $frontmatter['slug'] ?? '',
					'post_parent'           => (int) ( $frontmatter['parent'] ?? 0 ),
					'menu_order'            => (int) ( $frontmatter['menu_order'] ?? 0 ),
					'comment_status'        => $frontmatter['comment_status'] ?? 'open',
					'ping_status'           => $frontmatter['ping_status'] ?? 'open',
					'post_excerpt'          => $frontmatter['excerpt'] ?? '',
					'post_content'          => $body,
					'post_content_filtered' => '',
					'post_mime_type'        => $frontmatter['mime_type'] ?? '',
					'post_password'         => $frontmatter['password'] ?? '',
					'to_ping'               => '',
					'pinged'                => '',
					'guid'                  => $frontmatter['guid'] ?? '',
					'comment_count'         => (int) ( $frontmatter['comment_count'] ?? 0 ),
					'filter'                => 'raw',
					'_frontmatter_meta'     => isset( $frontmatter['meta'] ) && is_array( $frontmatter['meta'] ) ? $frontmatter['meta'] : array(),
					'_frontmatter_terms'    => isset( $frontmatter['terms'] ) && is_array( $frontmatter['terms'] ) ? $frontmatter['terms'] : array(),
				);
			},
		);
	}

	/**
	 * Sanitize a profile ID.
	 *
	 * @param string $id Raw ID.
	 * @return string Sanitized ID.
	 */
	private static function sanitize_id( string $id ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $id );
		}

		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $id ) ?? '' );
	}
}

if ( ! function_exists( 'markdown_db_register_frontmatter_profile' ) ) {
	/**
	 * Register a frontmatter profile.
	 *
	 * @param string $id      Stable profile ID.
	 * @param array  $profile Profile callbacks and metadata.
	 * @return bool Whether the profile was registered.
	 */
	function markdown_db_register_frontmatter_profile( string $id, array $profile ): bool {
		return WP_Markdown_Frontmatter_Profiles::register( $id, $profile );
	}
}

if ( ! function_exists( 'markdown_db_get_frontmatter_profile' ) ) {
	/**
	 * Return a registered frontmatter profile by ID.
	 *
	 * @param string $id Profile ID.
	 * @return array<string, mixed>|null Profile definition, or null.
	 */
	function markdown_db_get_frontmatter_profile( string $id ): ?array {
		return WP_Markdown_Frontmatter_Profiles::get( $id );
	}
}
