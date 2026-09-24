<?php
/** Verified persisted projection of canonical option rows. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../class-wp-markdown-file-witness.php';

final class WP_Markdown_Native_Option_Catalogue {
	private const SCHEMA = 'markdown-db-option-catalogue/v2';
	private const HOT_AUTOLOAD = array( 'yes', 'on', 'auto-on', 'auto' );

	public function __construct( private readonly string $state_root ) {}

	/**
	 * Restore catalogued rows for the given paths.
	 *
	 * Entries are matched by filename. A file whose lstat identity still equals
	 * the catalogued identity is answered from the catalogue; a new or changed
	 * file is marked stale so the caller reads just that file. Returns null only
	 * when the catalogue itself is missing or unusable.
	 *
	 * @param array<int,string> $paths
	 * @return array{rows:array<int,array<string,mixed>|null>,autoloads:array<int,?string>,signatures:array<int,?string>,stale:array<int,true>}|null
	 */
	public function restore( string $root, array $paths ): ?array {
		unset( $root );
		$path = $this->path();
		if ( null === $path || ! is_file( $path ) || is_link( $path ) ) {
			return null;
		}
		$witness = WP_Markdown_File_Witness::take( $path );
		$json = null === $witness ? false : @file_get_contents( $path );
		if ( false === $json || ! $witness->holds() ) {
			return null;
		}
		try {
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( Throwable ) {
			return null;
		}
		if ( ! is_array( $decoded ) || self::SCHEMA !== ( $decoded['schema'] ?? null ) || ! is_array( $decoded['entries'] ?? null ) ) {
			return null;
		}
		$by_filename = array();
		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['filename'] ?? null ) ) {
				$by_filename[ $entry['filename'] ] = $entry;
			}
		}
		$rows = array();
		$autoloads = array();
		$signatures = array();
		$stale = array();
		foreach ( $paths as $offset => $option_path ) {
			$entry = $by_filename[ basename( $option_path ) ] ?? null;
			$current = WP_Markdown_File_Witness::take( $option_path );
			if ( ! is_array( $entry )
				|| ! is_array( $entry['identity'] ?? null )
				|| ! is_string( $entry['autoload'] ?? null )
				|| ( null !== ( $entry['row'] ?? null ) && ! is_array( $entry['row'] ) )
				|| ! is_string( $entry['signature'] ?? null )
				|| null === $current
				|| $entry['identity'] !== $current->identity()
			) {
				$rows[] = null;
				$autoloads[] = null;
				$signatures[] = null;
				$stale[ $offset ] = true;
				continue;
			}
			$rows[] = $entry['row'];
			$autoloads[] = $entry['autoload'];
			$signatures[] = $entry['signature'];
		}
		return array( 'rows' => $rows, 'autoloads' => $autoloads, 'signatures' => $signatures, 'stale' => $stale );
	}

	/** @param array<int,string> $paths @param array<int,array<string,mixed>> $rows @param array<int,string> $signatures */
	public function persist( array $paths, array $rows, array $signatures ): void {
		if ( count( $paths ) !== count( $rows ) || count( $paths ) !== count( $signatures ) ) {
			return;
		}
		$path = $this->path( true );
		if ( null === $path ) {
			return;
		}
		$entries = array();
		foreach ( $paths as $offset => $option_path ) {
			$witness = WP_Markdown_File_Witness::take( $option_path );
			if ( null === $witness ) {
				return;
			}
			$entries[] = array(
				'filename' => basename( $option_path ),
				'identity' => $witness->identity(),
				'autoload' => (string) ( $rows[ $offset ]['autoload'] ?? '' ),
				'signature' => $signatures[ $offset ],
				'row'      => in_array( $rows[ $offset ]['autoload'] ?? null, self::HOT_AUTOLOAD, true ) ? $rows[ $offset ] : null,
			);
		}
		try {
			$json = json_encode( array( 'schema' => self::SCHEMA, 'entries' => $entries ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
			$temp = $path . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 8 ) );
		} catch ( Throwable ) {
			return;
		}
		$handle = @fopen( $temp, 'x+b' );
		if ( false === $handle ) {
			return;
		}
		$remaining = $json;
		$ok = true;
		while ( '' !== $remaining ) {
			$written = fwrite( $handle, $remaining );
			if ( false === $written || 0 === $written ) {
				$ok = false;
				break;
			}
			$remaining = substr( $remaining, $written );
		}
		$ok = $ok && fflush( $handle );
		fclose( $handle );
		if ( ! $ok || ! @chmod( $temp, 0600 ) || ! @rename( $temp, $path ) ) {
			@unlink( $temp );
		}
	}

	private function path( bool $create = false ): ?string {
		$root = realpath( $this->state_root );
		if ( false === $root || is_link( $this->state_root ) ) {
			return null;
		}
		$directory = $root . DIRECTORY_SEPARATOR . '_indexes';
		if ( ! is_dir( $directory ) && ( ! $create || ! @mkdir( $directory, 0700 ) ) ) {
			return null;
		}
		$real = realpath( $directory );
		if ( false === $real || is_link( $directory ) || dirname( $real ) !== $root ) {
			return null;
		}
		return $real . DIRECTORY_SEPARATOR . 'options.json';
	}
}
