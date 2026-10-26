<?php
/**
 * Runtime health checks and safe db.php drop-in repair for MDI.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-backend-capabilities.php';

class WP_Markdown_Health {

	/** Diagnose the native runtime and drop-in state. */
	public static function diagnose( array $context = array() ): array {
		global $wpdb;
		$backend = $context['backend_capabilities'] ?? WP_Markdown_Backend_Resolver::resolve();
		if ( ! $backend instanceof WP_Markdown_Backend_Capabilities ) {
			throw new InvalidArgumentException( 'backend_capabilities must be a WP_Markdown_Backend_Capabilities instance.' );
		}
		foreach ( $context['required_capabilities'] ?? array() as $capability ) {
			try {
				$backend->require( (string) $capability );
			} catch ( WP_Markdown_Unsupported_Backend_Capability $error ) {
				return self::with_backend( array(
					'status'     => 'unsupported_backend_capability',
					'healthy'    => false,
					'message'    => $error->getMessage(),
					'diagnostic' => $error->get_diagnostic(),
				), $backend );
			}
		}
		$dropin_loaded = $context['dropin_loaded'] ?? ( defined( 'MARKDOWN_DB_DROPIN' ) && MARKDOWN_DB_DROPIN );
		$native_runtime = $context['native_runtime'] ?? ( $wpdb instanceof WP_Markdown_Native_WPDB );
		$configured = $context['configured'] ?? ( defined( 'MARKDOWN_DB_BACKEND' ) || $dropin_loaded || $native_runtime );
		if ( ! $configured ) {
			return self::with_backend( array(
				'status'  => 'not_applicable',
				'healthy' => true,
				'message' => 'Import/export is available without an MDI database runtime.',
			), new WP_Markdown_Backend_Capabilities( 'none' ) );
		}
		if ( $dropin_loaded && $native_runtime && ( $context['dropin_outdated'] ?? self::dropin_is_outdated() ) ) {
			return self::with_backend( array(
				'status'  => 'dropin_outdated',
				'healthy' => false,
				'message' => 'wp-content/db.php differs from the MDI plugin copy and could not be refreshed. Run `wp markdown-db doctor --repair`.',
			), $backend );
		}
		if ( $dropin_loaded && $native_runtime ) {
			return self::with_backend( array(
				'status'  => 'healthy',
				'healthy' => true,
				'message' => 'MDI native drop-in and runtime are active.',
			), $backend );
		}
		return self::with_backend( array(
			'status'  => 'dropin_missing_or_replaced',
			'healthy' => false,
			'message' => 'The MDI db.php drop-in and native runtime are not active.',
		), $backend );
	}

	private static function with_backend( array $report, WP_Markdown_Backend_Capabilities $backend ): array {
		$report['backend'] = $backend->report();
		return $report;
	}

	/**
	 * Replace an installed MDI drop-in that differs from the plugin copy.
	 *
	 * Runs on every plugin load so a plugin upgrade carries its drop-in with it.
	 * An absent or unrelated db.php is left alone; doctor reports it.
	 */
	public static function refresh_outdated_dropin(): ?array {
		return self::dropin_is_outdated() ? self::repair_dropin() : null;
	}

	private static function dropin_is_outdated(): bool {
		if ( ! defined( 'MARKDOWN_DB_PLUGIN_DIR' ) || ! defined( 'WP_CONTENT_DIR' ) ) {
			return false;
		}
		$installed = @file_get_contents( WP_CONTENT_DIR . '/db.php' );
		$packaged  = @file_get_contents( MARKDOWN_DB_PLUGIN_DIR . 'db.php' );
		return is_string( $installed ) && is_string( $packaged ) && $installed !== $packaged && self::is_mdi_dropin_contents( $installed );
	}

	/** Install or repair the MDI db.php drop-in. */
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

		$destination_is_mdi = file_exists( $destination ) && self::is_mdi_dropin( $destination );
		if ( $destination_is_mdi && hash_equals( (string) hash_file( 'sha256', $source ), (string) hash_file( 'sha256', $destination ) ) ) {
			return array(
				'success' => true,
				'changed' => false,
				'status'  => 'already_installed',
				'message' => 'The MDI db.php drop-in is already installed. Restart PHP or WordPress only after a changed repair.',
			);
		}

		$backup = $destination . '.markdown-db-backup';
		if ( file_exists( $destination ) && ! $destination_is_mdi && ! $force ) {
			return self::repair_failure( 'Refusing to overwrite an unrelated db.php. Re-run with --force to back it up to ' . $backup . ' first.' );
		}
		if ( file_exists( $destination ) && ! $destination_is_mdi && file_exists( $backup ) ) {
			return self::repair_failure( 'Refusing to overwrite an unrelated db.php because the deterministic backup path already exists: ' . $backup );
		}
		if ( ! is_dir( dirname( $destination ) ) || ! is_writable( dirname( $destination ) ) ) {
			return self::repair_failure( 'The db.php destination directory is not writable.' );
		}

		$created_backup = file_exists( $destination ) && ! $destination_is_mdi;
		if ( $created_backup && ! rename( $destination, $backup ) ) {
			return self::repair_failure( 'Could not create the backup: ' . $backup );
		}

		$temporary = $destination . '.markdown-db-tmp-' . bin2hex( random_bytes( 8 ) );
		if ( ! copy( $source, $temporary ) || ! rename( $temporary, $destination ) ) {
			if ( file_exists( $temporary ) ) {
				unlink( $temporary );
			}
			if ( $created_backup && file_exists( $backup ) ) {
				rename( $backup, $destination );
			}
			return self::repair_failure( 'Could not install the MDI db.php drop-in.' );
		}

		return array(
			'success' => true,
			'changed' => true,
			'status'  => $destination_is_mdi ? 'updated' : 'installed',
			'backup'  => $created_backup && file_exists( $backup ) ? $backup : '',
			'message' => ( $destination_is_mdi ? 'Updated' : 'Installed' ) . ' the MDI db.php drop-in. Restart PHP or WordPress before checking health because db.php loads before plugins.',
		);
	}

	private static function is_mdi_dropin( string $path ): bool {
		$contents = file_get_contents( $path );
		return is_string( $contents ) && self::is_mdi_dropin_contents( $contents );
	}

	private static function is_mdi_dropin_contents( string $contents ): bool {
		return str_contains( $contents, '@studio-keep' ) && str_contains( $contents, 'MARKDOWN_DB_DROPIN' );
	}

	private static function repair_failure( string $message ): array {
		return array(
			'success' => false,
			'changed' => false,
			'message' => $message,
		);
	}
}
