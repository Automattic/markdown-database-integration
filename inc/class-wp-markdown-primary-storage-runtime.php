<?php
/**
 * Filesystem-only primary storage runtime.
 *
 * Persists caller-provided disposable index rows to MDI's canonical Markdown
 * and per-option JSON files without loading the WordPress SQL runtime.
 *
 * @package Markdown_Database_Integration
 * @since 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_Primary_Storage_Runtime {

	/** @var string */
	private $content_root;

	/** @var string */
	private $state_root;

	/** @var WP_Markdown_Storage */
	private $storage;

	/**
	 * Create a filesystem-only primary runtime.
	 *
	 * Roots are created when absent, then resolved to canonical absolute paths.
	 * They deliberately are not read from WordPress constants so constrained
	 * callers control exactly which filesystem tree is authoritative.
	 *
	 * @param array{content_root:string,state_root:string} $roots Canonical storage roots.
	 * @param string[]                                     $excluded_types Post types excluded from Markdown.
	 */
	public function __construct( array $roots, array $excluded_types = array() ) {
		$this->content_root = $this->canonical_root( $roots['content_root'] ?? '', 'content_root' );
		$this->state_root   = $this->canonical_root( $roots['state_root'] ?? '', 'state_root' );
		$this->storage      = new WP_Markdown_Storage( $this->content_root, $excluded_types );
	}

	/**
	 * Flush a disposable index to canonical files.
	 *
	 * `$index` is intentionally a plain caller-owned snapshot. SQLite is never
	 * consulted: posts are post-row objects/arrays and options are option-row
	 * objects/arrays. Omitted posts or options are removed from their respective
	 * canonical stores.
	 *
	 * @param array{posts?:array<int,object|array>,options?:array<int,object|array>} $index Disposable index metadata.
	 * @return array{created:string[],changed:string[],deleted:string[]} Canonical absolute paths.
	 */
	public function flush( array $index ): array {
		$before = $this->canonical_manifest();

		$this->flush_posts( $index['posts'] ?? array() );
		$this->flush_options( $index['options'] ?? array() );

		$after = $this->canonical_manifest();
		$created = array_keys( array_diff_key( $after, $before ) );
		$deleted = array_keys( array_diff_key( $before, $after ) );
		$changed = array();
		foreach ( array_intersect_key( $after, $before ) as $path => $hash ) {
			if ( $hash !== $before[ $path ] ) {
				$changed[] = $path;
			}
		}

		sort( $created, SORT_STRING );
		sort( $changed, SORT_STRING );
		sort( $deleted, SORT_STRING );

		return array(
			'created' => $created,
			'changed' => $changed,
			'deleted' => $deleted,
		);
	}

	/**
	 * Build a fresh disposable index from the canonical files.
	 *
	 * @return array{posts:object[],options:array<int,array{option_id:int,option_name:string,option_value:string,autoload:string}>}
	 */
	public function reconstruct(): array {
		$options = array();
		foreach ( glob( $this->state_root . '/_options/*.json' ) ?: array() as $path ) {
			$option = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $option ) || '' === (string) ( $option['option_name'] ?? '' ) ) {
				continue;
			}
			$options[] = array(
				'option_id'    => (int) ( $option['option_id'] ?? 0 ),
				'option_name'  => (string) $option['option_name'],
				'option_value' => (string) ( $option['option_value'] ?? '' ),
				'autoload'     => (string) ( $option['autoload'] ?? 'yes' ),
			);
		}
		return array(
			'posts'   => $this->storage->get_all_posts(),
			'options' => $options,
		);
	}

	/** @return string */
	public function get_content_root(): string {
		return $this->content_root;
	}

	/** @return string */
	public function get_state_root(): string {
		return $this->state_root;
	}

	/**
	 * Write posts and remove canonical files no longer represented by the index.
	 *
	 * @param array<int,object|array> $posts Post rows.
	 */
	private function flush_posts( array $posts ): void {
		$ids = array();
		$by_id = array();
		foreach ( $posts as $row ) {
			$post = $this->normalize_post( (object) $row );
			$id   = (int) ( $post->ID ?? 0 );
			if ( $id <= 0 ) {
				throw new \InvalidArgumentException( 'Each primary storage post requires a positive ID.' );
			}
			$ids[ $id ]   = true;
			$by_id[ $id ] = $post;
		}

		$this->storage->set_post_resolver(
			static function ( int $id ) use ( $by_id ): ?object {
				return $by_id[ $id ] ?? null;
			}
		);

		foreach ( $by_id as $post ) {
			if ( false === $this->storage->write_post( $post ) ) {
				throw new \RuntimeException( 'Failed to write canonical Markdown post ' . $post->ID . '.' );
			}
		}

		foreach ( $this->storage->get_all_posts( true ) as $post ) {
			$id = (int) $post->ID;
			if ( $id > 0 && ! isset( $ids[ $id ] ) ) {
				$this->storage->delete_post( $id );
			}
		}
	}

	/**
	 * Write options and remove canonical option files omitted from the index.
	 *
	 * @param array<int,object|array> $options Option rows.
	 */
	private function flush_options( array $options ): void {
		$option_names = array();
		$options_dir  = $this->state_root . '/_options';
		if ( ! is_dir( $options_dir ) && ! mkdir( $options_dir, 0755, true ) && ! is_dir( $options_dir ) ) {
			throw new \RuntimeException( 'Failed to create canonical options directory.' );
		}

		foreach ( $options as $row ) {
			$option = (object) $row;
			$name   = (string) ( $option->option_name ?? '' );
			if ( '' === $name ) {
				throw new \InvalidArgumentException( 'Each primary storage option requires option_name.' );
			}
			$option_names[ $name ] = true;
			$payload = array(
				'option_id'    => (int) ( $option->option_id ?? 0 ),
				'option_name'  => $name,
				'option_value' => (string) ( $option->option_value ?? '' ),
				'autoload'     => (string) ( $option->autoload ?? 'yes' ),
			);
			$json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				throw new \RuntimeException( 'Failed to encode canonical option ' . $name . '.' );
			}
			$this->atomic_write( $options_dir . '/' . $this->option_filename( $name ), $json );
		}

		foreach ( glob( $options_dir . '/*.json' ) ?: array() as $path ) {
			$payload = json_decode( (string) file_get_contents( $path ), true );
			$name    = is_array( $payload ) ? (string) ( $payload['option_name'] ?? '' ) : '';
			if ( '' !== $name && ! isset( $option_names[ $name ] ) ) {
				@unlink( $path );
			}
		}
	}

	/** @return array<string,string> Absolute path => content hash. */
	private function canonical_manifest(): array {
		$manifest = array();
		foreach ( array( $this->content_root, $this->state_root . '/_options' ) as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && ( str_ends_with( $file->getFilename(), '.md' ) || str_ends_with( $file->getFilename(), '.json' ) ) ) {
					$manifest[ $file->getPathname() ] = (string) hash_file( 'sha256', $file->getPathname() );
				}
			}
		}
		return $manifest;
	}

	private function canonical_root( string $root, string $name ): string {
		if ( '' === $root || ! str_starts_with( $root, '/' ) ) {
			throw new \InvalidArgumentException( "{$name} must be an absolute filesystem path." );
		}
		if ( ! is_dir( $root ) && ! mkdir( $root, 0755, true ) && ! is_dir( $root ) ) {
			throw new \RuntimeException( "Failed to create {$name}." );
		}
		$canonical = realpath( $root );
		if ( false === $canonical ) {
			throw new \RuntimeException( "Failed to canonicalize {$name}." );
		}
		return rtrim( $canonical, '/' );
	}

	private function atomic_write( string $path, string $contents ): void {
		$tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex( random_bytes( 4 ) );
		if ( false === file_put_contents( $tmp, $contents, LOCK_EX ) || ! rename( $tmp, $path ) ) {
			@unlink( $tmp );
			throw new \RuntimeException( 'Failed to write canonical file ' . $path . '.' );
		}
	}

	private function normalize_post( object $post ): object {
		$post->ID                    = (int) ( $post->ID ?? 0 );
		$post->post_author           = (int) ( $post->post_author ?? 0 );
		$post->post_date             = (string) ( $post->post_date ?? '0000-00-00 00:00:00' );
		$post->post_date_gmt         = (string) ( $post->post_date_gmt ?? $post->post_date );
		$post->post_content          = (string) ( $post->post_content ?? '' );
		$post->post_title            = (string) ( $post->post_title ?? '' );
		$post->post_excerpt          = (string) ( $post->post_excerpt ?? '' );
		$post->post_status           = (string) ( $post->post_status ?? 'draft' );
		$post->comment_status        = (string) ( $post->comment_status ?? 'open' );
		$post->ping_status           = (string) ( $post->ping_status ?? 'open' );
		$post->post_password         = (string) ( $post->post_password ?? '' );
		$post->post_name             = (string) ( $post->post_name ?? '' );
		$post->to_ping               = (string) ( $post->to_ping ?? '' );
		$post->pinged                = (string) ( $post->pinged ?? '' );
		$post->post_modified         = (string) ( $post->post_modified ?? $post->post_date );
		$post->post_modified_gmt     = (string) ( $post->post_modified_gmt ?? $post->post_modified );
		$post->post_content_filtered = (string) ( $post->post_content_filtered ?? '' );
		$post->post_parent           = (int) ( $post->post_parent ?? 0 );
		$post->guid                  = (string) ( $post->guid ?? '' );
		$post->menu_order            = (int) ( $post->menu_order ?? 0 );
		$post->post_type             = (string) ( $post->post_type ?? 'post' );
		$post->post_mime_type        = (string) ( $post->post_mime_type ?? '' );
		$post->comment_count         = (int) ( $post->comment_count ?? 0 );
		return $post;
	}

	private function option_filename( string $name ): string {
		$safe = preg_replace( '/[^A-Za-z0-9._\-]/', '_', $name );
		$safe = preg_replace( '/_+/', '_', $safe );
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
