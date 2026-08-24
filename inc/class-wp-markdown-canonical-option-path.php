<?php
/** Canonical option path mapping shared by readers and writers. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Canonical_Option_Path {

	public static function filename( string $name ): string {
		$safe = (string) preg_replace( '/[^A-Za-z0-9._\-]/', '_', $name );
		$safe = (string) preg_replace( '/_+/', '_', $safe );
		$safe = trim( $safe, '._' );
		if ( '' === $safe ) {
			$safe = 'option';
		}
		if ( $safe !== $name || strlen( $name ) > 180 ) {
			return substr( $safe, 0, 180 ) . '-' . substr( md5( $name ), 0, 8 ) . '.json';
		}
		return $safe . '.json';
	}
}
