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
		$backend        = $context['backend_capabilities'] ?? ( $sqlite_runtime ? WP_Markdown_Backend_Resolver::resolve() : new WP_Markdown_Backend_Capabilities( 'none' ) );
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
					'mode'       => $mode,
					'message'    => $error->getMessage(),
					'diagnostic' => $error->get_diagnostic(),
				), $backend );
			}
		}
		if ( ! $sqlite_runtime ) {
			return self::with_backend( array(
				'status'  => 'not_applicable',
				'healthy' => true,
				'mode'    => $mode,
				'message' => 'MDI drop-in health is not applicable to this non-SQLite runtime. Import/export remains available.',
			), $backend );
		}

		if ( ! in_array( $mode, array( 'primary', 'mirror' ), true ) ) {
			return self::with_backend( array(
				'status'  => 'not_configured',
				'healthy' => true,
				'mode'    => $mode,
				'message' => 'No MDI primary or mirror mode is configured.',
			), $backend );
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
		$primary_index_evidence = $context['primary_index_evidence'] ?? ( $GLOBALS['markdown_db_primary_index_evidence'] ?? null );

		if ( 'primary' === $mode && $dropin_loaded && $install_fallback ) {
			return self::with_backend( array(
				'status'  => 'install_fallback',
				'healthy' => true,
				'mode'    => $mode,
				'message' => 'MDI primary install fallback is active until WordPress installation completes.',
			), $backend );
		}

		$primary_index_code = is_array( $primary_index_evidence ) ? (string) ( $primary_index_evidence['code'] ?? '' ) : '';
		$primary_index_usable = in_array( $primary_index_code, array( 'markdown_db_primary_index_ready', 'markdown_db_primary_index_recovered_previous', 'markdown_db_primary_index_cold_reconstructed' ), true );
		if ( 'primary' === $mode && str_starts_with( $primary_index_code, 'markdown_db_primary_index_' ) && ! $primary_index_usable ) {
			return self::with_backend( array(
				'status'     => 'primary_index_unavailable',
				'healthy'    => false,
				'mode'       => $mode,
				'message'    => 'The disposable primary index is unavailable; canonical files remain authoritative and require explicit index maintenance.',
				'diagnostic' => $primary_index_evidence,
			), $backend );
		}

		if ( $dropin_loaded && $runtime_loaded && $markdown_runtime ) {
			return self::with_backend( array(
				'status'  => 'markdown_db_primary_index_recovered_previous' === $primary_index_code ? 'primary_index_recovered_previous' : 'healthy',
				'healthy' => true,
				'mode'    => $mode,
				'message' => 'MDI drop-in and runtime classes are active.',
				'primary_index' => $primary_index_evidence,
			), $backend );
		}

		return self::with_backend( array(
			'status'  => 'dropin_missing_or_replaced',
			'healthy' => false,
			'mode'    => $mode,
			'message' => 'MDI ' . $mode . ' mode is configured, but the MDI db.php drop-in and runtime are not active.',
		), $backend );
	}

	/**
	 * Add the backend contract without changing existing health fields.
	 *
	 * @param array $report Health report.
	 */
	private static function with_backend( array $report, WP_Markdown_Backend_Capabilities $backend ): array {
		$report['backend'] = $backend->report();
		return $report;
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
