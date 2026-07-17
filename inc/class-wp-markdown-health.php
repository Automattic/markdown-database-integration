<?php
/**
 * Runtime health checks and safe db.php drop-in repair for MDI.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_Health {

	/**
	 * Diagnose the MDI runtime and drop-in state.
	 *
	 * @param array $context Optional explicit values for deterministic callers.
	 * @return array Health report.
	 */
	public static function diagnose( array $context = array() ): array {
		global $wpdb;

		$mode = $context['mode'] ?? ( defined( 'MARKDOWN_DB_MODE' ) ? MARKDOWN_DB_MODE : '' );
		$mode = is_string( $mode ) ? $mode : '';

		$sqlite_runtime = $context['sqlite_runtime'] ?? ( class_exists( 'WP_SQLite_DB' ) && $wpdb instanceof WP_SQLite_DB );
		if ( ! $sqlite_runtime ) {
			return array(
				'status'  => 'not_applicable',
				'healthy' => true,
				'mode'    => $mode,
				'message' => 'MDI drop-in health is not applicable to this non-SQLite runtime. Import/export remains available.',
			);
		}

		if ( ! in_array( $mode, array( 'primary', 'mirror' ), true ) ) {
			return array(
				'status'  => 'not_configured',
				'healthy' => true,
				'mode'    => $mode,
				'message' => 'No MDI primary or mirror mode is configured.',
			);
		}

		$dropin_loaded = $context['dropin_loaded'] ?? ( defined( 'MARKDOWN_DB_DROPIN' ) && MARKDOWN_DB_DROPIN );
		$install_fallback = $context['install_fallback'] ?? ( defined( 'MARKDOWN_DB_INSTALL_FALLBACK' ) && MARKDOWN_DB_INSTALL_FALLBACK );
		$runtime_classes = $context['runtime_classes'] ?? array(
			class_exists( 'WP_Markdown_DB' ),
			class_exists( 'WP_Markdown_Write_Engine' ),
			class_exists( 'WP_Markdown_Loader' ),
			class_exists( 'WP_Markdown_Storage' ),
		);
		$runtime_loaded = ! in_array( false, $runtime_classes, true );
		$markdown_runtime = $context['markdown_runtime'] ?? ( class_exists( 'WP_Markdown_DB' ) && $wpdb instanceof WP_Markdown_DB );

		if ( 'primary' === $mode && $dropin_loaded && $install_fallback ) {
			return array(
				'status'  => 'install_fallback',
				'healthy' => true,
				'mode'    => $mode,
				'message' => 'MDI primary install fallback is active until WordPress installation completes.',
			);
		}

		if ( $dropin_loaded && $runtime_loaded && $markdown_runtime ) {
			return array(
				'status'  => 'healthy',
				'healthy' => true,
				'mode'    => $mode,
				'message' => 'MDI drop-in and runtime classes are active.',
			);
		}

		return array(
			'status'  => 'dropin_missing_or_replaced',
			'healthy' => false,
			'mode'    => $mode,
			'message' => 'MDI ' . $mode . ' mode is configured, but the MDI db.php drop-in and runtime are not active.',
		);
	}

	/**
	 * Install or repair the MDI db.php drop-in.
	 *
	 * @param array $options Repair options.
	 * @return array Repair report.
	 */
	public static function repair_dropin( array $options = array() ): array {
		$source      = $options['source'] ?? ( defined( 'MARKDOWN_DB_PLUGIN_DIR' ) ? MARKDOWN_DB_PLUGIN_DIR . 'db.php' : '' );
		$destination = $options['destination'] ?? ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/db.php' : '' );
		$force       = ! empty( $options['force'] );

		if ( ! is_string( $source ) || ! is_readable( $source ) || ! self::is_mdi_dropin( $source ) ) {
			return self::repair_failure( 'The MDI source db.php is missing or invalid.' );
		}
		if ( ! is_string( $destination ) || '' === $destination ) {
			return self::repair_failure( 'A wp-content/db.php destination is required.' );
		}

		if ( file_exists( $destination ) && self::is_mdi_dropin( $destination ) ) {
			return array(
				'success' => true,
				'changed' => false,
				'status'  => 'already_installed',
				'message' => 'The MDI db.php drop-in is already installed. Restart PHP or WordPress only after a changed repair.',
			);
		}

		$backup = $destination . '.markdown-db-backup';
		if ( file_exists( $destination ) && ! $force ) {
			return self::repair_failure( 'Refusing to overwrite an unrelated db.php. Re-run with --force to back it up to ' . $backup . ' first.' );
		}
		if ( file_exists( $destination ) && file_exists( $backup ) ) {
			return self::repair_failure( 'Refusing to overwrite an unrelated db.php because the deterministic backup path already exists: ' . $backup );
		}
		if ( ! is_dir( dirname( $destination ) ) || ! is_writable( dirname( $destination ) ) ) {
			return self::repair_failure( 'The db.php destination directory is not writable.' );
		}

		if ( file_exists( $destination ) && ! rename( $destination, $backup ) ) {
			return self::repair_failure( 'Could not create the backup: ' . $backup );
		}
		if ( ! copy( $source, $destination ) ) {
			if ( file_exists( $backup ) ) {
				rename( $backup, $destination );
			}
			return self::repair_failure( 'Could not install the MDI db.php drop-in.' );
		}

		return array(
			'success' => true,
			'changed' => true,
			'status'  => 'installed',
			'backup'  => file_exists( $backup ) ? $backup : '',
			'message' => 'Installed the MDI db.php drop-in. Restart PHP or WordPress before checking health because db.php loads before plugins.',
		);
	}

	private static function is_mdi_dropin( string $path ): bool {
		$contents = file_get_contents( $path );
		return is_string( $contents ) && str_contains( $contents, '@studio-keep' ) && str_contains( $contents, 'MARKDOWN_DB_DROPIN' );
	}

	private static function repair_failure( string $message ): array {
		return array(
			'success' => false,
			'changed' => false,
			'message' => $message,
		);
	}
}
