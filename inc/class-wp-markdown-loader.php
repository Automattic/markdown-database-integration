<?php
/** Backend-neutral filesystem and reconciliation coordinator. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-wp-markdown-backend-operations.php';
require_once __DIR__ . '/class-wp-markdown-backend-adapter.php';
require_once __DIR__ . '/class-wp-markdown-reconciliation-adapters.php';

class WP_Markdown_Loader {
	private const CORE_TABLE_SUFFIXES = array( 'users', 'usermeta', 'terms', 'term_taxonomy', 'termmeta', 'postmeta', 'term_relationships', 'comments', 'commentmeta', 'links' );
	private const SNAPSHOT_TABLE_SUFFIXES = array( 'posts', 'users', 'usermeta', 'terms', 'term_taxonomy', 'termmeta', 'postmeta', 'term_relationships', 'comments', 'commentmeta', 'links' );
	private $content_dir;
	private $state_dir;
	private $operations;
	private $prefix_resolver;
	private $storage;
	private $timings = array();
	private $stats = array();
	private $id_cursor;
	private $pending_id_writes = array();
	private $reconciliation;

	public function __construct( string $content_dir, $operations, WP_Markdown_Storage $storage, $prefix = 'wp_', ?string $state_dir = null, ?WP_Markdown_Durable_Reconciliation_Coordinator $reconciliation = null ) {
		$this->content_dir = rtrim( $content_dir, '/' );
		$this->state_dir = rtrim( $state_dir ?? $content_dir, '/' );
		$this->storage = $storage;
		$this->prefix_resolver = is_callable( $prefix ) ? $prefix : static function () use ( $prefix ): string { return (string) $prefix; };
		if ( ! $operations instanceof WP_Markdown_Backend_Operations ) {
			$operations = wp_markdown_backend_operations_from_legacy( $operations, $prefix );
		}
		$this->operations = $operations;
		$this->reconciliation = $reconciliation;
		if ( null !== $reconciliation && method_exists( $this->operations, 'set_reconciliation_coordinator' ) ) {
			$this->operations->set_reconciliation_coordinator( $reconciliation, $this->content_dir );
		}
	}

	public function load_all(): void {
		$start = microtime( true );
		$this->stats = array( 'boot_mode' => 'cold' );
		try {
			$this->operations->ensure_reconciliation_state();
			$this->recover_pending_operations();
			$this->operations->ensure_tables( $this->schema_files() );
			$this->operations->hydrate_options( $this->option_rows() );
			foreach ( self::CORE_TABLE_SUFFIXES as $table ) { $this->hydrate_table( $table ); }
			$this->operations->hydrate_markdown_posts( $this->markdown_posts(), $this->json_rows( 'posts' ) );
			$this->flush_pending_id_writes();
			$this->hydrate_plugins();
		} catch ( \Throwable $e ) { error_log( 'Markdown DB Loader error: ' . $e->getMessage() ); }
		$this->timings['total'] = microtime( true ) - $start;
	}

	public function sync_incremental(): void {
		$start = microtime( true );
		$this->stats = array( 'boot_mode' => 'warm' );
		try {
			$this->operations->ensure_reconciliation_state();
			$this->recover_pending_operations();
			$this->operations->hydrate_options( $this->option_rows() );
			foreach ( self::SNAPSHOT_TABLE_SUFFIXES as $table ) { $this->hydrate_table( $table, true ); }
			$this->hydrate_plugins( true );
			$files = iterator_to_array( $this->storage->get_markdown_file_manifest_iterator(), true );
			$result = $this->operations->reconcile_markdown( $files, fn( string $path, $parent = null ) => $this->prepare_markdown_post( $this->storage->read_file( $path, true, $parent ) ) );
			$this->flush_pending_id_writes();
			$this->stats = array_merge( $this->stats, $result );
		} catch ( \Throwable $e ) {
			$this->stats['sync_status'] = 'retained_previous_index';
			$this->stats['sync_error']  = $this->is_contention_error( $e ) ? 'canonical_store_busy' : 'canonical_sync_failed';
			error_log( 'Markdown DB sync error: ' . $e->getMessage() );
			return;
		}
		$this->stats['sync_status'] = 'complete';
		$this->timings['total'] = microtime( true ) - $start;
	}
	private function is_contention_error( \Throwable $error ): bool {
		do {
			if ( preg_match( '/(?:database|table|schema) is locked|database is busy/i', $error->getMessage() ) ) {
				return true;
			}
			$error = $error->getPrevious();
		} while ( null !== $error );
		return false;
	}

	public function prepare_existing_cache(): void { $this->operations->ensure_reconciliation_state(); }
	private function recover_pending_operations(): void {
		if ( null === $this->reconciliation ) { return; }
		$this->reconciliation->recover_pending(
			function ( array $record ): ?WP_Markdown_Reconciliation_Adapter {
				$binding = $record['binding'];
				if ( 'canonical_to_wordpress' !== $binding['direction'] || 'post' !== $binding['resource']['type'] ) { return null; }
				$id = (int) $binding['resource']['id'];
				$path = (string) ( $binding['continuation']['path'] ?? '' );
				$absolute = '' === $path ? '' : $this->content_dir . '/' . $path;
				$observer = fn(): array => array( 'canonical' => is_file( $absolute ) ? array( 'path' => $absolute, 'hash' => hash_file( 'sha256', $absolute ) ) : null, 'wordpress' => $this->loader_post_receipt( $id ) );
				return new WP_Markdown_PDO_Reconciliation_Adapter( $this->operations_pdo(), $observer, static function (): void { throw new RuntimeException( 'Loader recovery does not replay an unproven database mutation.' ); } );
			},
			100
		);
	}
	private function operations_pdo(): PDO {
		$property = new ReflectionProperty( $this->operations, 'driver' );
		$driver = $property->getValue( $this->operations );
		return $driver->get_connection()->get_pdo();
	}
	private function loader_post_receipt( int $id ): ?array {
		$rows = $this->operations->post_rows( array( $id ) );
		return empty( $rows ) ? null : (array) $rows[0];
	}
	public function get_timings(): array { return $this->timings; }
	public function get_stats(): array { return $this->stats; }
	// Legacy reflection hook delegates to the neutral hydration path.
	private function load_table_from_json( string $table_suffix, ?callable $before_hydration = null ): void { $this->hydrate_table( $table_suffix, null !== $before_hydration, $before_hydration ); }
	private function load_options(): void { $this->operations->hydrate_options( $this->option_rows() ); }
	private function create_json_manifest_table(): void { $this->operations->ensure_reconciliation_state(); }
	private function save_json_manifest(): void { foreach ( glob( $this->state_dir . '/_tables/*.json' ) ?: array() as $file ) { $this->operations->update_manifest( '_tables/' . basename( $file ), (int) filemtime( $file ), (int) filesize( $file ) ); } }
	private function load_posts(): void { $this->operations->hydrate_markdown_posts( $this->markdown_posts(), $this->json_rows( 'posts' ) ); $this->flush_pending_id_writes(); }
	private function sync_markdown_posts(): void { $this->operations->reconcile_markdown( iterator_to_array( $this->storage->get_markdown_file_manifest_iterator(), true ), fn( string $path, $parent = null ) => $this->prepare_markdown_post( $this->storage->read_file( $path, true, $parent ) ) ); $this->flush_pending_id_writes(); }
	private function sync_json_tables(): void { foreach ( self::SNAPSHOT_TABLE_SUFFIXES as $table ) { $this->hydrate_table( $table, true ); } $this->hydrate_plugins( true ); }
	private function load_plugin_tables(): void { $this->hydrate_plugins(); }

	private function hydrate_table( string $table, bool $incremental = false, ?callable $before_hydration = null ): void {
		$lock = $this->partition_lock( $table );
		try {
			$partition_marker = $this->partition_marker( $table );
			if ( null !== $partition_marker ) {
				$this->operations->hydrate_table_snapshot( $table, fn(): iterable => $this->partition_rows( $table, $partition_marker ), null, $incremental ? null : array( 'preserve_existing' => true ) );
				return;
			}
			$path = '_tables/' . $table . '.json';
			$identity = $this->file_identity( $path );
			if ( null === $identity ) { return; }
			$partition = null;
			if ( in_array( $table, array( 'posts', 'postmeta', 'term_relationships' ), true ) ) {
				$partition = array( 'kind' => $table, 'post_types' => $this->storage->get_excluded_types() );
			}
			if ( null !== $before_hydration ) { $partition = array( 'before_hydration' => $before_hydration ); }
			if ( ! $incremental && null === $partition ) { $partition = array( 'preserve_existing' => true ); }
			$this->operations->hydrate_table_snapshot( $table, fn(): iterable => $this->json_rows( $table ), $identity, $partition );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}
	private function hydrate_plugins( bool $incremental = false ): void {
		$tables = array_map( static fn( $file ): string => basename( $file, '.json' ), glob( $this->state_dir . '/_tables/*.json' ) ?: array() );
		foreach ( glob( $this->state_dir . '/_tables/*/.mdi-partition.json' ) ?: array() as $marker ) { $tables[] = basename( dirname( $marker ) ); }
		foreach ( array_unique( $tables ) as $table ) {
			if ( ! in_array( $table, self::CORE_TABLE_SUFFIXES, true ) && 'posts' !== $table ) { $this->operations->ensure_tables( array( $table => (string) @file_get_contents( $this->state_dir . '/_schema/' . $table . '.sql' ) ) ); $this->hydrate_table( $table, $incremental ); }
		}
	}
	private function partition_marker( string $table ): ?array { $data = json_decode( (string) @file_get_contents( $this->state_dir . '/_tables/' . $table . '/.mdi-partition.json' ), true ); return is_array( $data ) && 1 === ( $data['version'] ?? null ) && $table === ( $data['table'] ?? null ) && is_string( $data['identity_column'] ?? null ) && preg_match( '/^generation-[a-f0-9]{24}$/', (string) ( $data['generation'] ?? '' ) ) ? $data : null; }
	private function partition_rows( string $table, array $marker ): iterable {
		$directory = $this->state_dir . '/_tables/' . $table . '/' . $marker['generation'];
		foreach ( glob( $directory . '/*.json' ) ?: array() as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $data ) || 1 !== ( $data['_mdi_partition']['version'] ?? null ) || $marker['identity_column'] !== ( $data['_mdi_partition']['identity_column'] ?? null ) || ! is_array( $data['row'] ?? null ) ) { throw new \RuntimeException( 'Markdown DB: Invalid table partition row.' ); }
			yield $data['row'];
		}
	}
	/** @return resource */
	private function partition_lock( string $table ) {
		$directory = sys_get_temp_dir() . '/markdown-database-integration-locks';
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) { throw new \RuntimeException( 'Markdown DB: Failed to create lock directory.' ); }
		$lock = fopen( $directory . '/partition-' . hash( 'sha256', $this->state_dir . "\0" . $table ) . '.lock', 'c+' );
		if ( false === $lock || ! flock( $lock, LOCK_SH ) ) { throw new \RuntimeException( 'Markdown DB: Failed to lock table partition.' ); }
		return $lock;
	}
	/** @return iterable<array<string,mixed>> */
	private function json_rows( string $table ): ?iterable {
		$file = $this->state_dir . '/_tables/' . $table . '.json';
		if ( ! is_file( $file ) ) { return null; }
		if ( 0 === (int) filesize( $file ) ) { return array(); }
		return \JsonMachine\Items::fromFile( $file, array( 'decoder' => new \JsonMachine\JsonDecoder\ExtJsonDecoder( true ) ) );
	}
	private function prefix(): string { return ( $this->prefix_resolver )(); } // Legacy reflection hook.
	private function markdown_posts(): array {
		$posts = iterator_to_array( $this->storage->get_all_posts_iterator( true ), false );
		$minimum_id = $this->fallback_post_id_floor();
		foreach ( $posts as $post ) { $minimum_id = max( $minimum_id, (int) ( $post->ID ?? 0 ) + 1 ); }
		$this->id_cursor = $this->operations->next_post_id( $minimum_id );
		foreach ( $posts as $index => $post ) {
			if ( empty( $post->_source_identity ) && ! empty( $post->_source_file ) && str_starts_with( $post->_source_file, $this->content_dir . '/' ) ) {
				$post->_source_identity = substr( $post->_source_file, strlen( $this->content_dir ) + 1 );
			}
			$posts[ $index ] = $this->prepare_markdown_post( $post );
		}
		return array_values( array_filter( $posts ) );
	}
	private function prepare_markdown_post( ?object $post ): ?object {
		if ( null === $post || (int) ( $post->ID ?? 0 ) > 0 ) { return $post; }
		$source = (string) ( $post->_source_file ?? '' );
		if ( '' === $source || ! is_file( $source ) ) { return null; }
		if ( null === $this->id_cursor ) { $this->id_cursor = $this->operations->next_post_id( $this->fallback_post_id_floor() ); }
		$post->ID = $this->id_cursor++;
		$this->pending_id_writes[ $source ] = (int) $post->ID;
		return $post;
	}
	private function fallback_post_id_floor(): int {
		$max = 0;
		foreach ( $this->json_rows( 'posts' ) ?? array() as $row ) { $max = max( $max, (int) ( (array) $row )['ID'] ); }
		return $max + 1;
	}
	private function flush_pending_id_writes(): void {
		foreach ( $this->pending_id_writes as $path => $id ) {
			if ( ! $this->storage->inject_id_into_frontmatter( $path, $id ) ) { error_log( "Markdown DB: Failed to persist assigned ID {$id} back to {$path}" ); }
		}
		$this->pending_id_writes = array();
	}
	private function option_rows(): array { $rows = array(); foreach ( glob( $this->state_dir . '/_options/*.json' ) ?: array() as $file ) { $row = json_decode( (string) file_get_contents( $file ), true ); if ( is_array( $row ) && isset( $row['option_name'] ) ) { $rows[] = $row; } } return $rows; }
	private function schema_files(): array { $schemas = array(); foreach ( glob( $this->state_dir . '/_schema/*.sql' ) ?: array() as $file ) { $schemas[ basename( $file, '.sql' ) ] = (string) file_get_contents( $file ); } return $schemas; }
	private function file_identity( string $path ): ?array { $file = $this->state_dir . '/' . $path; clearstatcache( true, $file ); return is_file( $file ) ? array( 'mtime' => (int) filemtime( $file ), 'size' => (int) filesize( $file ), 'path' => $file ) : null; }
}
