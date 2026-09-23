<?php
/** Canonical option path mapping shared by readers and writers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Canonical_Option_Path {

	/** Longest sanitized stem kept before a hashed suffix disambiguates it. */
	private const STEM_LENGTH = 180;

	public static function filename( string $name ): string {
		$safe = self::safe( $name );
		if ( $safe !== $name || strlen( $name ) > self::STEM_LENGTH ) {
			return substr( $safe, 0, self::STEM_LENGTH ) . '-' . substr( md5( $name ), 0, 8 ) . '.json';
		}
		return $safe . '.json';
	}

	/**
	 * The sanitized, truncated stem a name's filename is built from.
	 *
	 * Sanitizing maps characters one to one and preserves case, so two names
	 * that differ only in letter case or trailing spaces share this stem up to
	 * case. That makes it a complete candidate filter for collated identities.
	 */
	public static function stem( string $name ): string {
		return substr( self::safe( $name ), 0, self::STEM_LENGTH );
	}

	/**
	 * Stems a canonical filename may have been built from.
	 *
	 * A filename is either `<stem>.json` or `<stem>-<8 hex>.json`. A bare stem
	 * can itself end in eight hex digits, so both readings are returned.
	 *
	 * @return array<int,string>
	 */
	public static function filename_stems( string $filename ): array {
		if ( ! str_ends_with( $filename, '.json' ) ) {
			return array();
		}
		$base  = substr( $filename, 0, -5 );
		$stems = array( $base );
		if ( 1 === preg_match( '/^(.*)-[0-9a-f]{8}$/D', $base, $match ) ) {
			$stems[] = $match[1];
		}
		return $stems;
	}

	private static function safe( string $name ): string {
		$safe = (string) preg_replace( '/[^A-Za-z0-9._\-]/', '_', $name );
		$safe = (string) preg_replace( '/_+/', '_', $safe );
		$safe = trim( $safe, '._' );
		return '' === $safe ? 'option' : $safe;
	}
}
