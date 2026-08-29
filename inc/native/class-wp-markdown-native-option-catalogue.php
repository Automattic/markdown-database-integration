<?php
/** Verified persisted projection of canonical option rows. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../class-wp-markdown-file-witness.php';

final class WP_Markdown_Native_Option_Catalogue {
	private const SCHEMA = 'markdown-db-option-catalogue/v1';

	public function __construct( private readonly string $state_root ) {}

	/** @param array<int,string> $paths @return array{rows:array<int,array<string,mixed>>,signatures:array<int,string>}|null */
	public function restore( string $root, array $paths ): ?array {
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
		if ( ! is_array( $decoded ) || self::SCHEMA !== ( $decoded['schema'] ?? null ) || ! is_array( $decoded['entries'] ?? null ) || count( $paths ) !== count( $decoded['entries'] ) ) {
			return null;
		}
		$rows = array();
		$signatures = array();
		foreach ( $paths as $offset => $option_path ) {
			$entry = $decoded['entries'][ $offset ] ?? null;
			if ( ! is_array( $entry ) || basename( $option_path ) !== ( $entry['filename'] ?? null ) || ! is_array( $entry['identity'] ?? null ) || ! is_array( $entry['row'] ?? null ) || ! is_string( $entry['signature'] ?? null ) ) {
				return null;
			}
			$current = WP_Markdown_File_Witness::take( $option_path );
			if ( null === $current || $entry['identity'] !== $current->identity() ) {
				return null;
			}
			$rows[] = $entry['row'];
			$signatures[] = $entry['signature'];
		}
		return array( 'rows' => $rows, 'signatures' => $signatures );
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
				'signature' => $signatures[ $offset ],
				'row'      => $rows[ $offset ],
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
