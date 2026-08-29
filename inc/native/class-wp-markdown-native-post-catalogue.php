<?php
/** What the canonical corpus said, and which file said it. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../class-wp-markdown-file-witness.php';

/**
 * The rows the corpus produced, held against the files that produced them.
 *
 * Reading posts needs two things repeatedly: what a file said last time it
 * was read, and which file a durable identity lives in. Those were answered
 * by separate collections that were filled by different code paths and could
 * disagree, so they are one collection here, and an entry is only ever handed
 * back with the witness it was recorded under so a caller must prove the file
 * still stands before believing it.
 */
final class WP_Markdown_Native_Post_Catalogue {
	private const SCHEMA = 'markdown-db-post-catalogue/v1';

	/** @var array<string,array{witness:WP_Markdown_File_Witness,file:array<string,mixed>,post:object,row:array<string,mixed>}> */
	private array $entries = array();

	/** @var array<int,string> */
	private array $paths = array();
	private bool $loaded = false;
	private bool $scanning = false;
	/** @var array<string,true> */
	private array $seen = array();

	public function __construct(
		private readonly string $content_root,
		private readonly string $state_root
	) {}

	/**
	 * @param array<string,mixed> $file The manifest entry the row came from.
	 * @param array<string,mixed> $row
	 */
	public function remember( WP_Markdown_File_Witness $witness, array $file, object $post, array $row ): void {
		$this->load();
		$path = (string) ( $file['absolute'] ?? '' );
		if ( null === $this->relative_path( $path ) ) {
			return;
		}
		$this->entries[ $path ] = array( 'witness' => $witness, 'file' => $file, 'post' => $post, 'row' => $row );
		if ( $this->scanning ) {
			$this->seen[ $path ] = true;
		}
		$id = (int) ( $post->ID ?? 0 );
		if ( $id > 0 ) {
			$this->paths[ $id ] = $path;
		}
	}

	/**
	 * The entry recorded for a file, if it was recorded under this witness.
	 *
	 * @return array{witness:WP_Markdown_File_Witness,file:array<string,mixed>,post:object,row:array<string,mixed>}|null
	 */
	public function recorded( string $path, ?WP_Markdown_File_Witness $witness ): ?array {
		$this->load();
		$entry = $this->entries[ $path ] ?? null;
		if ( null === $entry || null === $witness || ! $entry['witness']->is( $witness ) ) {
			return null;
		}
		return $entry;
	}

	/** The file a durable identity was last found in. */
	public function file_for( int $id ): ?array {
		$this->load();
		$path = $this->paths[ $id ] ?? null;
		return null === $path ? null : ( $this->entries[ $path ]['file'] ?? null );
	}

	/** Begin a complete corpus proof that may replace the durable catalogue. */
	public function begin_scan(): void {
		$this->load();
		$this->scanning = true;
		$this->seen     = array();
	}

	/** Publish a catalogue only after every canonical post has been proven. */
	public function complete_scan(): void {
		if ( ! $this->scanning ) {
			return;
		}
		$this->scanning = false;
		foreach ( array_keys( $this->entries ) as $path ) {
			if ( ! isset( $this->seen[ $path ] ) ) {
				unset( $this->entries[ $path ] );
			}
		}
		$this->paths = array();
		foreach ( $this->entries as $path => $entry ) {
			$id = (int) ( $entry['post']->ID ?? 0 );
			if ( $id > 0 ) {
				$this->paths[ $id ] = $path;
			}
		}
		$this->seen = array();
		$this->persist();
	}

	/** A write makes every recorded statement about the corpus unproven. */
	public function forget(): void {
		$this->entries = array();
		$this->paths   = array();
		$this->loaded  = true;
		$this->scanning = false;
		$this->seen = array();
		$path = $this->catalogue_path();
		if ( null !== $path && is_file( $path ) && ! is_link( $path ) ) {
			@unlink( $path );
		}
	}

	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;
		$path = $this->catalogue_path();
		if ( null === $path || ! is_file( $path ) || is_link( $path ) ) {
			return;
		}
		$before = WP_Markdown_File_Witness::take( $path );
		$json   = null === $before ? false : @file_get_contents( $path );
		if ( false === $json || ! $before->holds() ) {
			return;
		}
		try {
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( Throwable ) {
			return;
		}
		if ( ! is_array( $decoded ) || self::SCHEMA !== ( $decoded['schema'] ?? null ) || ! is_array( $decoded['entries'] ?? null ) ) {
			return;
		}
		foreach ( $decoded['entries'] as $saved ) {
			$entry = $this->hydrate( $saved );
			if ( null === $entry ) {
				$this->entries = array();
				$this->paths   = array();
				return;
			}
			$path = $entry['file']['absolute'];
			$id   = (int) $entry['post']->ID;
			if ( isset( $this->entries[ $path ] ) || isset( $this->paths[ $id ] ) ) {
				$this->entries = array();
				$this->paths   = array();
				return;
			}
			$this->entries[ $path ] = $entry;
			$this->paths[ $id ]     = $path;
		}
	}

	/** @return array{witness:WP_Markdown_File_Witness,file:array<string,mixed>,post:object,row:array<string,mixed>}|null */
	private function hydrate( mixed $saved ): ?array {
		if ( ! is_array( $saved ) || ! is_string( $saved['path'] ?? null ) || ! is_array( $saved['identity'] ?? null ) || ! is_array( $saved['row'] ?? null ) ) {
			return null;
		}
		$relative = str_replace( '\\', '/', $saved['path'] );
		if ( '' === $relative || str_starts_with( $relative, '/' ) || in_array( '..', explode( '/', $relative ), true ) ) {
			return null;
		}
		$path = rtrim( $this->content_root, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		if ( $relative !== $this->relative_path( $path ) || ! $this->existing_path_is_safe( $path ) ) {
			return null;
		}
		$identity = array();
		foreach ( array( 'dev', 'ino', 'mode', 'size', 'mtime', 'ctime', 'nlink' ) as $name ) {
			if ( ! isset( $saved['identity'][ $name ] ) || ! is_int( $saved['identity'][ $name ] ) ) {
				return null;
			}
			$identity[ $name ] = $saved['identity'][ $name ];
		}
		$current = WP_Markdown_File_Witness::take( $path );
		if ( null === $current || $identity !== $current->identity() ) {
			return null;
		}
		$row = $saved['row'];
		if ( array_key_exists( 'post_content', $row ) || (int) ( $row['ID'] ?? 0 ) < 1 ) {
			return null;
		}
		$row['post_content'] = '';
		$post = (object) $row;
		$file = array(
			'absolute'  => $path,
			'parent_id' => isset( $saved['parent_id'] ) ? (int) $saved['parent_id'] : null,
			'mtime'     => $identity['mtime'],
			'size'      => $identity['size'],
			'witness'   => $current,
		);
		return array( 'witness' => $current, 'file' => $file, 'post' => $post, 'row' => $row );
	}

	private function persist(): void {
		$path = $this->catalogue_path( true );
		if ( null === $path ) {
			return;
		}
		$entries = array();
		foreach ( $this->entries as $entry ) {
			$relative = $this->relative_path( (string) $entry['file']['absolute'] );
			if ( null === $relative || ! $entry['witness']->holds() ) {
				return;
			}
			$row = $entry['row'];
			unset( $row['post_content'] );
			$entries[] = array(
				'path'      => $relative,
				'parent_id' => $entry['file']['parent_id'] ?? null,
				'identity'  => $entry['witness']->identity(),
				'row'       => $row,
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

	private function catalogue_path( bool $create = false ): ?string {
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
		return $real . DIRECTORY_SEPARATOR . 'posts.json';
	}

	private function relative_path( string $path ): ?string {
		$root = rtrim( $this->content_root, '/\\' ) . DIRECTORY_SEPARATOR;
		if ( ! str_starts_with( $path, $root ) ) {
			return null;
		}
		$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $root ) ) );
		return '' === $relative || in_array( '..', explode( '/', $relative ), true ) ? null : $relative;
	}

	private function existing_path_is_safe( string $path ): bool {
		$root = realpath( $this->content_root );
		$real = realpath( $path );
		if ( false === $root || false === $real || ! str_starts_with( $real, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return false;
		}
		$current  = rtrim( $this->content_root, '/\\' );
		$relative = ltrim( substr( $path, strlen( $current ) ), '/\\' );
		foreach ( preg_split( '#[\\\\/]#', $relative ) ?: array() as $segment ) {
			$current .= DIRECTORY_SEPARATOR . $segment;
			if ( is_link( $current ) ) {
				return false;
			}
		}
		return true;
	}
}
