<?php
/**
 * Deny direct HTTP access to canonical store roots.
 *
 * @package Markdown_Database_Integration
 */

function markdown_db_root_inside( string $root, string $boundary ): bool {
	$root = realpath( $root );
	$boundary = realpath( $boundary );
	return false !== $root && false !== $boundary && ( $root === $boundary || str_starts_with( $root, rtrim( $boundary, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) );
}

function markdown_db_guard_store_root( string $root ): bool {
	if ( ! defined( 'ABSPATH' ) || ! is_dir( $root ) || ! markdown_db_root_inside( $root, ABSPATH ) ) {
		return false;
	}
	foreach ( array( '.htaccess' => "Require all denied\n", 'index.php' => "<?php // Silence is golden.\n" ) as $name => $contents ) {
		$path = $root . '/' . $name;
		if ( file_exists( $path ) || is_link( $path ) ) {
			continue;
		}
		$handle = @fopen( $path, 'x' );
		if ( false === $handle ) {
			return false;
		}
		$written = fwrite( $handle, $contents );
		$closed = fclose( $handle );
		if ( strlen( $contents ) !== $written || ! $closed ) {
			return false;
		}
	}
	return true;
}
