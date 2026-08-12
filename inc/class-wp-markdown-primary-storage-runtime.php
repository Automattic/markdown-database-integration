<?php
/**
 * Constrained primary runtime.
 *
 * Boots the same primary-mode storage, driver, loader, and write engine around
 * a caller-owned disposable index. The index remains rebuildable; the
 * canonical Markdown and JSON roots remain the durable state.
 *
 * @package Markdown_Database_Integration
 * @since 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-backend-capabilities.php';
require_once __DIR__ . '/class-wp-markdown-backend-adapter.php';

class WP_Markdown_Primary_Storage_Runtime {

	/** @var string */
	private $content_root;

	/** @var string */
	private $state_root;

	/** @var object */
	private $driver;

	/** @var WP_Markdown_Write_Engine */
	private $write_engine;

	/** @var WP_Markdown_Loader */
	private $loader;

	/** @var array{files:array<string,string>,hash:string} */
	private $identity;

	/**
	 * Bootstrap primary-mode machinery around a caller-provided cache.
	 *
	 * The supplied connection owns the cache lifecycle. Pass false for
	 * `$cold_boot` only when that cache was previously hydrated from the supplied
	 * identity. Warm boot requires that identity; cold reconstruction does not.
	 *
	 * @param array{content_root:string,state_root:string} $roots Canonical storage roots.
	 * @param object                                        $connection Disposable cache connection.
	 * @param string                                        $database Cache database name.
	 * @param array{files:array<string,string>,hash:string}|null $identity Identity represented by a warm cache.
	 * @param bool                                          $cold_boot Whether to reconstruct the cache from files.
	 * @param string[]                                      $excluded_types Post types excluded from Markdown.
	 * @param callable|string                               $prefix Table prefix or resolver.
	 */
	public static function bootstrap(
		array $roots,
		$connection,
		string $database,
		?array $identity = null,
		bool $cold_boot = true,
		array $excluded_types = array(),
		$prefix = 'wp_',
		?WP_Markdown_Backend_Capabilities $backend_capabilities = null
	): self {
		$storage = new WP_Markdown_Storage( rtrim( $roots['content_root'], '/' ), $excluded_types );
		[ $operations, $driver ] = wp_markdown_runtime_adapter( $connection, $database, $storage, $prefix, $backend_capabilities );
		return self::bootstrap_with_operations( $roots, $operations, $driver, $identity, $cold_boot, $excluded_types, $prefix, $backend_capabilities );
	}

	/** Backend-neutral primary runtime bootstrap. */
	public static function bootstrap_with_operations( array $roots, WP_Markdown_Backend_Operations $operations, $runtime_driver, ?array $identity = null, bool $cold_boot = true, array $excluded_types = array(), $prefix = 'wp_', ?WP_Markdown_Backend_Capabilities $backend_capabilities = null ): self {
		$backend_capabilities = WP_Markdown_Backend_Resolver::resolve( $backend_capabilities );
		$backend_capabilities->require( 'disposable_index_operation' );
		if ( ! class_exists( 'WP_Markdown_Write_Engine' ) || ! class_exists( 'WP_Markdown_Loader' ) ) {
			throw new \LogicException( 'Load the MDI primary driver, write engine, and loader before bootstrapping the storage runtime.' );
		}

		$runtime = new self( $roots );
		$storage = new WP_Markdown_Storage( $runtime->content_root, $excluded_types );
		$storage->set_content_layout_profile( defined( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE' ) ? MARKDOWN_DB_CONTENT_LAYOUT_PROFILE : '' );
		$runtime->driver = $runtime_driver;
		$runtime->write_engine = new WP_Markdown_Write_Engine(
			$runtime->content_root,
			$storage,
			$operations,
			$prefix,
			$runtime->state_root
		);
		if ( method_exists( $runtime->driver, 'set_write_engine' ) ) { $runtime->driver->set_write_engine( $runtime->write_engine ); }
		$runtime->configure_storage_resolvers( $storage, $operations );
		$runtime->loader = new WP_Markdown_Loader(
			$runtime->content_root,
			$operations,
			$storage,
			$prefix,
			$runtime->state_root
		);

		$current_identity = $runtime->canonical_identity();
		if ( ! $cold_boot ) {
			if ( null === $identity ) {
				throw new \RuntimeException( wp_markdown_runtime_identity_error( false ) );
			}
			if ( $identity['hash'] !== $current_identity['hash'] ) {
				throw new \RuntimeException( wp_markdown_runtime_identity_error( true ) );
			}
		}
		if ( $cold_boot ) {
			$backend_capabilities->require( 'cold_reconstruction' );
			$runtime->loader->load_all();
		} else {
			$runtime->loader->sync_incremental();
		}
		$runtime->identity = $runtime->canonical_identity();

		return $runtime;
	}

	/**
	 * Attach primary write machinery to an already-populated caller-owned cache.
	 *
	 * This is for one-time imports where the backend is the input and canonical files
	 * are the output. It deliberately does not load or synchronize files.
	 */
	public static function bootstrap_existing_cache(
		array $roots,
		$connection,
		string $database,
		array $excluded_types = array(),
		$prefix = 'wp_',
		?WP_Markdown_Backend_Capabilities $backend_capabilities = null
	): self {
		$storage = new WP_Markdown_Storage( rtrim( $roots['content_root'], '/' ), $excluded_types );
		[ $operations, $driver ] = wp_markdown_runtime_adapter( $connection, $database, $storage, $prefix, $backend_capabilities );
		return self::bootstrap_existing_cache_with_operations( $roots, $operations, $driver, $excluded_types, $prefix, $backend_capabilities );
	}

	/** Attach neutral persistence to an existing caller-owned cache. */
	public static function bootstrap_existing_cache_with_operations( array $roots, WP_Markdown_Backend_Operations $operations, $runtime_driver, array $excluded_types = array(), $prefix = 'wp_', ?WP_Markdown_Backend_Capabilities $backend_capabilities = null ): self {
		$backend_capabilities = WP_Markdown_Backend_Resolver::resolve( $backend_capabilities );
		$backend_capabilities->require( 'disposable_index_operation' );
		if ( ! class_exists( 'WP_Markdown_Write_Engine' ) || ! class_exists( 'WP_Markdown_Loader' ) ) {
			throw new \LogicException( 'Load the MDI primary driver, write engine, and loader before attaching the storage runtime.' );
		}

		$runtime = new self( $roots );
		$storage = new WP_Markdown_Storage( $runtime->content_root, $excluded_types );
		$storage->set_content_layout_profile( defined( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE' ) ? MARKDOWN_DB_CONTENT_LAYOUT_PROFILE : '' );
		$runtime->driver = $runtime_driver;
		$runtime->write_engine = new WP_Markdown_Write_Engine( $runtime->content_root, $storage, $operations, $prefix, $runtime->state_root );
		if ( method_exists( $runtime->driver, 'set_write_engine' ) ) { $runtime->driver->set_write_engine( $runtime->write_engine ); }
		$runtime->configure_storage_resolvers( $storage, $operations );
		$runtime->loader = new WP_Markdown_Loader( $runtime->content_root, $operations, $storage, $prefix, $runtime->state_root );
		$runtime->loader->prepare_existing_cache();
		$runtime->identity = $runtime->canonical_identity();

		return $runtime;
	}

	/** @param array{content_root:string,state_root:string} $roots */
	private function __construct( array $roots ) {
		$this->content_root = $this->canonical_root( $roots['content_root'] ?? '', 'content_root' );
		$this->state_root   = $this->canonical_root( $roots['state_root'] ?? '', 'state_root' );
		$this->identity     = array( 'files' => array(), 'hash' => '' );
	}

	/**
	 * Return the public driver for normal WordPress post and option mutations.
	 * Queries made through this driver use the ordinary MDI write interception.
	 */
	public function get_driver() {
		return $this->driver;
	}

	/**
	 * Flush pending normal driver writes without waiting for PHP shutdown.
	 *
	 * @return array{created:string[],changed:string[],deleted:string[]} Sorted paths relative to their canonical root.
	 */
	public function flush(): array {
		$changes         = $this->driver->flush_canonical_writes();
		$this->identity  = $this->canonical_identity();
		return $changes;
	}

	/** @return array{files:array<string,string>,hash:string} Canonical identity represented by this cache. */
	public function get_identity(): array {
		return $this->identity;
	}

	/** @return WP_Markdown_Loader */
	public function get_loader(): WP_Markdown_Loader {
		return $this->loader;
	}

	private function configure_storage_resolvers( WP_Markdown_Storage $storage, WP_Markdown_Backend_Operations $operations ): void {
		$storage->set_post_resolver( static function ( int $post_id ) use ( $operations ): ?object {
			$rows = $operations->post_rows( array( $post_id ) );
			return empty( $rows ) ? null : (object) $rows[0];
		} );
		$storage->set_meta_resolver( static function ( int $post_id ) use ( $operations ): array {
			return $operations->post_meta( $post_id );
		} );
		$storage->set_terms_resolver( static function ( int $post_id ) use ( $operations ): array {
			return $operations->post_terms( $post_id );
		} );
		$storage->set_index_writer( static function ( int $post_id, string $path, int $mtime, int $size ) use ( $operations ): void {
			$operations->upsert_file_index( $post_id, $path, $mtime, $size );
		} );
	}

	/**
	 * Build a metadata identity without reading canonical file contents.
	 *
	 * Explicit flushes use the write engine's per-path content hashes. This
	 * identity instead lets bootstrap reject ordinary external replacements
	 * without turning every read-only bootstrap into a corpus-wide hash pass.
	 *
	 * @return array{files:array<string,string>,hash:string}
	 */
	private function canonical_identity(): array {
		$files = array();
		foreach ( array( $this->content_root => '', $this->state_root . '/_options' => '_options/' ) as $root => $prefix ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && ( str_ends_with( $file->getFilename(), '.md' ) || str_ends_with( $file->getFilename(), '.json' ) ) ) {
					$files[ $prefix . substr( $file->getPathname(), strlen( $root ) + 1 ) ] = $file->getMTime() . ':' . $file->getSize() . ':' . $file->getInode() . ':' . $file->getCTime();
				}
			}
		}
		ksort( $files, SORT_STRING );
		return array( 'files' => $files, 'hash' => hash( 'sha256', json_encode( $files, JSON_UNESCAPED_SLASHES ) ) );
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
}
