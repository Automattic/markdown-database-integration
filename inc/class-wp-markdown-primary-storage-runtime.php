<?php
/**
 * Constrained primary runtime.
 *
 * Boots the same primary-mode storage, driver, loader, and write engine around
 * a caller-owned SQLite connection. SQLite remains a disposable index; the
 * canonical Markdown and JSON roots remain the durable state.
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

	/** @var WP_Markdown_Driver */
	private $driver;

	/** @var WP_Markdown_Write_Engine */
	private $write_engine;

	/** @var WP_Markdown_Loader */
	private $loader;

	/** @var array{files:array<string,string>,hash:string} */
	private $identity;

	/**
	 * Bootstrap primary-mode machinery around a caller-provided SQLite cache.
	 *
	 * The supplied connection owns the cache lifecycle. Pass false for
	 * `$cold_boot` only when that cache was previously hydrated from the supplied
	 * identity. Warm boot requires that identity; cold reconstruction does not.
	 *
	 * @param array{content_root:string,state_root:string} $roots Canonical storage roots.
	 * @param WP_SQLite_Connection                          $connection Disposable SQLite cache connection.
	 * @param string                                        $database SQLite database name.
	 * @param array{files:array<string,string>,hash:string}|null $identity Identity represented by a warm cache.
	 * @param bool                                          $cold_boot Whether to reconstruct the cache from files.
	 * @param string[]                                      $excluded_types Post types excluded from Markdown.
	 * @param callable|string                               $prefix Table prefix or resolver.
	 */
	public static function bootstrap(
		array $roots,
		WP_SQLite_Connection $connection,
		string $database,
		?array $identity = null,
		bool $cold_boot = true,
		array $excluded_types = array(),
		$prefix = 'wp_'
	): self {
		if ( ! class_exists( 'WP_Markdown_Driver' ) || ! class_exists( 'WP_Markdown_Write_Engine' ) || ! class_exists( 'WP_Markdown_Loader' ) ) {
			throw new \LogicException( 'Load the MDI primary driver, write engine, and loader before bootstrapping the storage runtime.' );
		}

		$runtime = new self( $roots );
		$storage = new WP_Markdown_Storage( $runtime->content_root, $excluded_types );
		$runtime->driver = new WP_Markdown_Driver( $connection, $database, $storage );
		$runtime->write_engine = new WP_Markdown_Write_Engine(
			$runtime->content_root,
			$storage,
			$runtime->driver,
			$prefix,
			$runtime->state_root
		);
		$runtime->driver->set_write_engine( $runtime->write_engine );
		$runtime->configure_storage_resolvers( $storage, $prefix );
		$runtime->loader = new WP_Markdown_Loader(
			$runtime->content_root,
			$runtime->driver,
			$storage,
			$prefix,
			$runtime->state_root
		);

		$current_identity = $runtime->canonical_identity();
		if ( ! $cold_boot ) {
			if ( null === $identity ) {
				throw new \RuntimeException( 'A canonical identity is required for a warm SQLite cache.' );
			}
			if ( $identity['hash'] !== $current_identity['hash'] ) {
				throw new \RuntimeException( 'The supplied SQLite cache identity does not match the canonical files.' );
			}
		}
		if ( $cold_boot ) {
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
	 * This is for one-time imports where SQLite is the input and canonical files
	 * are the output. It deliberately does not load or synchronize files.
	 */
	public static function bootstrap_existing_cache(
		array $roots,
		WP_SQLite_Connection $connection,
		string $database,
		array $excluded_types = array(),
		$prefix = 'wp_'
	): self {
		if ( ! class_exists( 'WP_Markdown_Driver' ) || ! class_exists( 'WP_Markdown_Write_Engine' ) || ! class_exists( 'WP_Markdown_Loader' ) ) {
			throw new \LogicException( 'Load the MDI primary driver, write engine, and loader before attaching the storage runtime.' );
		}

		$runtime = new self( $roots );
		$storage = new WP_Markdown_Storage( $runtime->content_root, $excluded_types );
		$runtime->driver = new WP_Markdown_Driver( $connection, $database, $storage );
		$runtime->write_engine = new WP_Markdown_Write_Engine( $runtime->content_root, $storage, $runtime->driver, $prefix, $runtime->state_root );
		$runtime->driver->set_write_engine( $runtime->write_engine );
		$runtime->configure_storage_resolvers( $storage, $prefix );
		$runtime->loader = new WP_Markdown_Loader( $runtime->content_root, $runtime->driver, $storage, $prefix, $runtime->state_root );
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
	public function get_driver(): WP_Markdown_Driver {
		return $this->driver;
	}

	/**
	 * Flush pending normal driver writes without waiting for PHP shutdown.
	 *
	 * @return array{created:string[],changed:string[],deleted:string[]} Sorted paths relative to their canonical root.
	 */
	public function flush(): array {
		$before = $this->canonical_identity()['files'];
		$this->write_engine->flush_dirty( true );
		$after = $this->canonical_identity()['files'];
		$this->identity = array(
			'files' => $after,
			'hash'  => hash( 'sha256', json_encode( $after, JSON_UNESCAPED_SLASHES ) ),
		);

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
		return array( 'created' => $created, 'changed' => $changed, 'deleted' => $deleted );
	}

	/** @return array{files:array<string,string>,hash:string} Canonical identity represented by this cache. */
	public function get_identity(): array {
		return $this->identity;
	}

	/** @return WP_Markdown_Loader */
	public function get_loader(): WP_Markdown_Loader {
		return $this->loader;
	}

	private function configure_storage_resolvers( WP_Markdown_Storage $storage, $prefix ): void {
		$prefix_resolver = is_callable( $prefix ) ? $prefix : static function () use ( $prefix ): string { return (string) $prefix; };
		$driver = $this->driver;
		$storage->set_post_resolver( static function ( int $post_id ) use ( $driver, $prefix_resolver ): ?object {
			$rows = $driver->query( 'SELECT post_name, post_parent, post_type FROM `' . $prefix_resolver() . 'posts` WHERE ID = ' . $post_id );
			return is_array( $rows ) && ! empty( $rows ) ? $rows[0] : null;
		} );
		$storage->set_meta_resolver( static function ( int $post_id ) use ( $driver, $prefix_resolver ): array {
			$rows = $driver->query( 'SELECT meta_key, meta_value FROM `' . $prefix_resolver() . 'postmeta` WHERE post_id = ' . $post_id );
			return is_array( $rows ) ? $rows : array();
		} );
		$storage->set_terms_resolver( static function ( int $post_id ) use ( $driver, $prefix_resolver ): array {
			$prefix = $prefix_resolver();
			$rows = $driver->query( "SELECT tt.taxonomy, t.slug FROM `{$prefix}term_relationships` tr JOIN `{$prefix}term_taxonomy` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id JOIN `{$prefix}terms` t ON tt.term_id = t.term_id WHERE tr.object_id = {$post_id}" );
			return is_array( $rows ) ? $rows : array();
		} );
		$storage->set_index_writer( static function ( int $post_id, string $path, int $mtime, int $size ) use ( $driver ): void {
			$driver->update_file_index( $post_id, $path, $mtime, $size );
		} );
	}

	/** @return array{files:array<string,string>,hash:string} */
	private function canonical_identity(): array {
		$files = array();
		foreach ( array( $this->content_root => '', $this->state_root . '/_options' => '_options/' ) as $root => $prefix ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && ( str_ends_with( $file->getFilename(), '.md' ) || str_ends_with( $file->getFilename(), '.json' ) ) ) {
					$files[ $prefix . substr( $file->getPathname(), strlen( $root ) + 1 ) ] = (string) hash_file( 'sha256', $file->getPathname() );
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
