<?php
/** Production ownership adapters and the bounded-reconciliation seam. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-durable-reconciliation-operations.php';

final class WP_Markdown_Durable_Reconciliation_Coordinator {
	private WP_Markdown_Durable_Reconciliation_Operations $operations;
	private WP_Markdown_Reconciliation_Operation_Store $store;
	private string $owner;
	private int $lease_seconds;

	public function __construct( WP_Markdown_Reconciliation_Operation_Store $store, ?string $owner = null, int $lease_seconds = 30 ) {
		$this->store          = $store;
		$this->operations    = new WP_Markdown_Durable_Reconciliation_Operations( $store );
		$this->owner         = $owner ?? gethostname() . ':' . getmypid() . ':' . bin2hex( random_bytes( 8 ) );
		$this->lease_seconds = $lease_seconds;
	}

	/**
	 * Execute one bounded mutation. Values in before/after are normalized into
	 * exact identities; callers may pass identities directly when already known.
	 */
	public function reconcile( array $intent, WP_Markdown_Reconciliation_Adapter $adapter, ?callable $boundary = null ): array {
		foreach ( array( 'plan_id', 'continuation', 'canonical_root', 'resource', 'kind', 'direction', 'before', 'after' ) as $field ) {
			if ( ! array_key_exists( $field, $intent ) ) {
				throw new InvalidArgumentException( "Missing reconciliation intent field: $field" );
			}
		}
		foreach ( array( 'before', 'after' ) as $side ) {
			foreach ( $intent[ $side ] as $domain => $value ) {
				if ( ! is_array( $value ) || 'sha256' !== ( $value['algorithm'] ?? null ) || ! isset( $value['digest'] ) ) {
					$intent[ $side ][ $domain ] = WP_Markdown_Reconciliation_Identity::exact( $value );
				}
		}
		}
		$record = $this->operations->plan( $intent );
		if ( 'completed' === $record['state'] ) {
			return $record;
		}
		if ( 'planned' !== $record['state'] ) {
			return $this->operations->recover( $record['id'], $this->owner, time(), $this->lease_seconds, $adapter, $boundary );
		}
		return $this->operations->execute( $record['id'], $this->owner, time(), $this->lease_seconds, $adapter, $boundary );
	}

	public function recover( string $operation_id, WP_Markdown_Reconciliation_Adapter $adapter, ?callable $boundary = null ): array {
		return $this->operations->recover( $operation_id, $this->owner, time(), $this->lease_seconds, $adapter, $boundary );
	}

	public function prepare( array $intent, WP_Markdown_Reconciliation_Adapter $adapter ): array {
		foreach ( array( 'before', 'checkpoint', 'after' ) as $side ) {
			foreach ( $intent[ $side ] as $domain => $value ) {
				if ( ! is_array( $value ) || 'sha256' !== ( $value['algorithm'] ?? null ) ) { $intent[ $side ][ $domain ] = WP_Markdown_Reconciliation_Identity::exact( $value ); }
			}
		}
		$record = $this->operations->plan( $intent );
		return $this->operations->prepare( $record['id'], $this->owner, time(), $this->lease_seconds, $adapter );
	}

	public function continue_prepared( string $operation_id, WP_Markdown_Reconciliation_Adapter $adapter ): array {
		return $this->operations->continue_prepared( $operation_id, $adapter );
	}

	/** Enumerate authenticated durable IDs before callers derive any new intent. */
	public function recoverable( int $limit = 100 ): array {
		return $this->store->recoverable( time(), max( 1, min( 1000, $limit ) ) );
	}

	/** @param callable(array):?WP_Markdown_Reconciliation_Adapter $adapter_for */
	public function recover_pending( callable $adapter_for, int $limit = 100 ): array {
		$results = array();
		foreach ( $this->recoverable( $limit ) as $record ) {
			$adapter = $adapter_for( $record );
			if ( null !== $adapter ) { $results[ $record['id'] ] = $this->recover( $record['id'], $adapter ); }
		}
		return $results;
	}
}

/** Build the shared production coordinator outside the managed canonical roots. */
function wp_markdown_durable_reconciliation_coordinator( array $canonical_roots ): WP_Markdown_Durable_Reconciliation_Coordinator {
	$roots = array_values( array_unique( array_map( static fn( string $root ): string => rtrim( $root, '/' ), $canonical_roots ) ) );
	$site  = hash( 'sha256', implode( "\0", $roots ) );
	$temp_root = realpath( sys_get_temp_dir() );
	if ( false === $temp_root || ! is_dir( $temp_root ) ) {
		throw new RuntimeException( 'Unable to resolve the durable reconciliation runtime root.' );
	}
	$base = rtrim( $temp_root, DIRECTORY_SEPARATOR ) . '/markdown-database-integration-operations';
	if ( ! is_dir( $base ) && ! mkdir( $base, 0700, true ) && ! is_dir( $base ) ) {
		throw new RuntimeException( 'Unable to create the durable reconciliation runtime directory.' );
	}
	clearstatcache( true, $base );
	$base_stat = @lstat( $base );
	if (
		false === $base_stat
		|| ( $base_stat['mode'] & 0170000 ) !== 0040000
		|| is_link( $base )
		|| ! chmod( $base, 0700 )
		|| 0 !== ( fileperms( $base ) & 0077 )
		|| ( function_exists( 'posix_geteuid' ) && $base_stat['uid'] !== posix_geteuid() )
	) {
		throw new RuntimeException( 'The durable reconciliation runtime directory must be server-owned and private.' );
	}
	$key_path = $base . '/' . $site . '.key';
	if ( ! is_file( $key_path ) ) {
		$temp = tempnam( $base, '.key-' );
		if ( false === $temp || false === file_put_contents( $temp, bin2hex( random_bytes( 32 ) ), LOCK_EX ) || ! chmod( $temp, 0600 ) || ( ! @link( $temp, $key_path ) && ! is_file( $key_path ) ) ) {
			if ( is_string( $temp ) && is_file( $temp ) ) { unlink( $temp ); }
			throw new RuntimeException( 'Unable to create the durable reconciliation authentication key.' );
		}
		unlink( $temp );
	}
	$key_material = (string) file_get_contents( $key_path );
	if ( strlen( $key_material ) < 32 ) { throw new RuntimeException( 'The durable reconciliation authentication key is invalid.' ); }
	$store = new WP_Markdown_Filesystem_Reconciliation_Operation_Store( $base . '/' . $site, $key_material, $roots );
	return new WP_Markdown_Durable_Reconciliation_Coordinator( $store );
}

/** Owns a WordPress resource fence in the same database as its mutation. */
final class WP_Markdown_PDO_Reconciliation_Adapter implements WP_Markdown_Reconciliation_Adapter {
	private PDO $pdo;
	private $observer;
	private $mutation;

	public function __construct( PDO $pdo, callable $observer, callable $mutation ) {
		$this->pdo      = $pdo;
		$this->observer = $observer;
		$this->mutation = $mutation;
		$this->pdo->exec( 'CREATE TABLE IF NOT EXISTS `_mdi_resource_fences` (`resource_key` VARCHAR(191) PRIMARY KEY, `operation_id` VARCHAR(64) NOT NULL, `fence` BIGINT NOT NULL)' );
	}

	public function observe( array $operation ): array {
		return $this->identities( ( $this->observer )( $operation, $this->pdo ) );
	}

	public function fence( array $operation ): void {
		$this->transaction(
			function () use ( $operation ): void {
				$key = $this->resource_key( $operation );
				$row = $this->pdo->prepare( 'SELECT operation_id, fence FROM `_mdi_resource_fences` WHERE resource_key = ?' );
				$row->execute( array( $key ) );
				$current = $row->fetch( PDO::FETCH_ASSOC );
				if ( is_array( $current ) && (int) $current['fence'] >= (int) $operation['fence'] ) {
					if ( (int) $current['fence'] === (int) $operation['fence'] && $current['operation_id'] === $operation['id'] ) {
						return;
					}
					throw new WP_Markdown_Reconciliation_Store_Conflict( 'A newer database owner fenced this mutation.' );
				}
				$this->pdo->prepare( 'DELETE FROM `_mdi_resource_fences` WHERE resource_key = ?' )->execute( array( $key ) );
				$this->pdo->prepare( 'INSERT INTO `_mdi_resource_fences` (resource_key, operation_id, fence) VALUES (?, ?, ?)' )->execute( array( $key, $operation['id'], $operation['fence'] ) );
			}
		);
	}

	public function apply( array $operation ): void {
		$this->transaction(
			function () use ( $operation ): void {
				$statement = $this->pdo->prepare( 'SELECT operation_id, fence FROM `_mdi_resource_fences` WHERE resource_key = ?' );
				$statement->execute( array( $this->resource_key( $operation ) ) );
				$owner = $statement->fetch( PDO::FETCH_ASSOC );
				if ( ! is_array( $owner ) || $owner['operation_id'] !== $operation['id'] || (int) $owner['fence'] !== (int) $operation['fence'] ) {
					throw new WP_Markdown_Reconciliation_Store_Conflict( 'The database mutation fence is stale.' );
				}
				( $this->mutation )( $operation, $this->pdo );
			}
		);
	}

	private function resource_key( array $operation ): string {
		$resource = $operation['binding']['resource'];
		return substr( hash( 'sha256', $operation['binding']['canonical_root'] ), 0, 16 ) . ':' . $resource['type'] . ':' . $resource['id'];
	}

	private function identities( array $values ): array {
		$result = array();
		foreach ( $values as $domain => $value ) { $result[ $domain ] = WP_Markdown_Reconciliation_Identity::exact( $value ); }
		return $result;
	}

	private function transaction( callable $callback ): void {
		$owned = ! $this->pdo->inTransaction();
		if ( $owned ) {
			$this->pdo->beginTransaction();
		}
		try {
			$callback();
			if ( $owned ) {
				$this->pdo->commit();
			}
		} catch ( Throwable $error ) {
			if ( $owned && $this->pdo->inTransaction() ) {
				$this->pdo->rollBack();
			}
			throw $error;
		}
	}
}

/** Owns a normal WordPress MySQL/mysqli mutation through the active wpdb connection. */
final class WP_Markdown_WPDB_Reconciliation_Adapter implements WP_Markdown_Reconciliation_Adapter {
	private object $wpdb;
	private $observer;
	private $mutation;

	public function __construct( object $wpdb, callable $observer, callable $mutation ) {
		if ( ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			throw new InvalidArgumentException( 'A MySQL-compatible wpdb connection is required.' );
		}
		$this->wpdb     = $wpdb;
		$this->observer = $observer;
		$this->mutation = $mutation;
		$this->query( 'CREATE TABLE IF NOT EXISTS `_mdi_resource_fences` (`resource_key` VARCHAR(191) PRIMARY KEY, `operation_id` VARCHAR(64) NOT NULL, `fence` BIGINT NOT NULL)' );
	}

	public function observe( array $operation ): array {
		$result = array();
		foreach ( ( $this->observer )( $operation, $this->wpdb ) as $domain => $value ) { $result[ $domain ] = WP_Markdown_Reconciliation_Identity::exact( $value ); }
		return $result;
	}

	public function fence( array $operation ): void {
		$this->transaction( function () use ( $operation ): void {
			$key = $this->resource_key( $operation );
			$current = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT operation_id, fence FROM `_mdi_resource_fences` WHERE resource_key = %s FOR UPDATE', $key ), ARRAY_A );
			if ( is_array( $current ) && (int) $current['fence'] >= (int) $operation['fence'] ) {
				if ( (int) $current['fence'] === (int) $operation['fence'] && $current['operation_id'] === $operation['id'] ) { return; }
				throw new WP_Markdown_Reconciliation_Store_Conflict( 'A newer database owner fenced this mutation.' );
			}
			$this->query( $this->wpdb->prepare( 'DELETE FROM `_mdi_resource_fences` WHERE resource_key = %s', $key ) );
			$this->query( $this->wpdb->prepare( 'INSERT INTO `_mdi_resource_fences` (resource_key, operation_id, fence) VALUES (%s, %s, %d)', $key, $operation['id'], $operation['fence'] ) );
		} );
	}

	public function apply( array $operation ): void {
		$this->transaction( function () use ( $operation ): void {
			$key = $this->resource_key( $operation );
			$owner = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT operation_id, fence FROM `_mdi_resource_fences` WHERE resource_key = %s FOR UPDATE', $key ), ARRAY_A );
			if ( ! is_array( $owner ) || $owner['operation_id'] !== $operation['id'] || (int) $owner['fence'] !== (int) $operation['fence'] ) {
				throw new WP_Markdown_Reconciliation_Store_Conflict( 'The database mutation fence is stale.' );
			}
			( $this->mutation )( $operation, $this->wpdb );
		} );
	}

	private function resource_key( array $operation ): string {
		$binding = $operation['binding'];
		return substr( hash( 'sha256', $binding['canonical_root'] ), 0, 16 ) . ':' . $binding['resource']['type'] . ':' . $binding['resource']['id'];
	}

	private function transaction( callable $callback ): void {
		$this->query( 'START TRANSACTION' );
		try {
			$callback();
			$this->query( 'COMMIT' );
		} catch ( Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private function query( string $sql ): void {
		if ( false === $this->wpdb->query( $sql ) ) { throw new RuntimeException( 'The reconciliation database operation failed.' ); }
	}
}

/** Owns replacement/deletion of canonical paths under a durable resource lock. */
final class WP_Markdown_Filesystem_Reconciliation_Adapter implements WP_Markdown_Reconciliation_Adapter {
	private string $fence_directory;
	private $observer;
	private $mutation;

	public function __construct( string $fence_directory, callable $observer, callable $mutation ) {
		$this->fence_directory = rtrim( $fence_directory, '/' );
		$this->observer         = $observer;
		$this->mutation         = $mutation;
		if ( file_exists( $this->fence_directory ) && is_link( $this->fence_directory ) ) {
			throw new RuntimeException( 'The filesystem fence directory must not be a symlink.' );
		}
		if ( ! is_dir( $this->fence_directory ) && ! mkdir( $this->fence_directory, 0700, true ) && ! is_dir( $this->fence_directory ) ) {
			throw new RuntimeException( 'Unable to create the filesystem fence directory.' );
		}
		if ( ! chmod( $this->fence_directory, 0700 ) ) { throw new RuntimeException( 'Unable to secure the filesystem fence directory.' ); }
		$stat = lstat( $this->fence_directory );
		if ( false === $stat || 0 !== ( $stat['mode'] & 0077 ) || ( function_exists( 'posix_geteuid' ) && $stat['uid'] !== posix_geteuid() ) ) { throw new RuntimeException( 'The filesystem fence directory must be server-owned and private.' ); }
	}

	public function observe( array $operation ): array {
		$result = array();
		foreach ( ( $this->observer )( $operation ) as $domain => $value ) { $result[ $domain ] = WP_Markdown_Reconciliation_Identity::exact( $value ); }
		return $result;
	}

	public function fence( array $operation ): void {
		$this->locked(
			$operation,
			function ( string $path ) use ( $operation ): void {
				$current = $this->read_fence( $path );
				if ( null !== $current && ( (int) $current['fence'] > (int) $operation['fence'] || ( (int) $current['fence'] === (int) $operation['fence'] && $current['operation_id'] !== $operation['id'] ) ) ) {
					throw new WP_Markdown_Reconciliation_Store_Conflict( 'A newer filesystem owner fenced this mutation.' );
				}
				$this->write_fence( $path, array( 'operation_id' => $operation['id'], 'fence' => $operation['fence'] ) );
			}
		);
	}

	public function apply( array $operation ): void {
		$this->locked(
			$operation,
			function ( string $path ) use ( $operation ): void {
				$current = $this->read_fence( $path );
				if ( null === $current || $current['operation_id'] !== $operation['id'] || (int) $current['fence'] !== (int) $operation['fence'] ) {
					throw new WP_Markdown_Reconciliation_Store_Conflict( 'The filesystem mutation fence is stale.' );
				}
				( $this->mutation )( $operation );
			}
		);
	}

	private function locked( array $operation, callable $callback ): void {
		$key  = hash( 'sha256', $operation['binding']['resource']['type'] . "\0" . $operation['binding']['resource']['id'] );
		$lock_path = $this->fence_directory . '/' . $key . '.lock';
		if ( is_link( $lock_path ) ) { throw new RuntimeException( 'Filesystem fence locks must not be symlinks.' ); }
		$lock = @fopen( $lock_path, 'x+b' );
		if ( false === $lock ) { $lock = fopen( $lock_path, 'r+b' ); }
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new RuntimeException( 'Unable to lock the filesystem resource fence.' );
		}
		if ( ! chmod( $lock_path, 0600 ) ) { fclose( $lock ); throw new RuntimeException( 'Unable to secure the filesystem resource lock.' ); }
		try {
			$callback( $this->fence_directory . '/' . $key . '.json' );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function read_fence( string $path ): ?array {
		if ( is_link( $path ) ) { throw new RuntimeException( 'Filesystem fences must not be symlinks.' ); }
		if ( ! is_file( $path ) ) {
			return null;
		}
		$value = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $value ) || ! is_string( $value['operation_id'] ?? null ) || ! is_int( $value['fence'] ?? null ) ) {
			throw new RuntimeException( 'The filesystem resource fence is invalid.' );
		}
		return $value;
	}

	private function write_fence( string $path, array $fence ): void {
		if ( is_link( $path ) ) { throw new RuntimeException( 'Filesystem fences must not be symlinks.' ); }
		$temp = tempnam( $this->fence_directory, '.fence-' );
		$handle = false === $temp ? false : fopen( $temp, 'c+b' );
		$bytes = WP_Markdown_Reconciliation_Identity::encode( $fence );
		if ( false === $temp || false === $handle || strlen( $bytes ) !== fwrite( $handle, $bytes ) || ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) || ! chmod( $temp, 0600 ) ) {
			if ( is_resource( $handle ) ) { fclose( $handle ); }
			if ( is_string( $temp ) && is_file( $temp ) ) {
				unlink( $temp );
			}
			throw new RuntimeException( 'Unable to persist the filesystem resource fence.' );
		}
		fclose( $handle );
		if ( ! rename( $temp, $path ) ) { @unlink( $temp ); throw new RuntimeException( 'Unable to atomically publish the filesystem resource fence.' ); }
		if ( function_exists( 'fsync' ) ) {
			$directory = @fopen( $this->fence_directory, 'rb' );
			if ( false === $directory || ! @fsync( $directory ) ) { if ( false !== $directory ) { fclose( $directory ); } throw new RuntimeException( 'Unable to prove durable filesystem fence publication.' ); }
			fclose( $directory );
		}
	}
}
