<?php
/** Production ownership adapters and the bounded-reconciliation seam. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-durable-reconciliation-operations.php';

final class WP_Markdown_Durable_Reconciliation_Coordinator {
	private WP_Markdown_Durable_Reconciliation_Operations $operations;
	private string $owner;
	private int $lease_seconds;

	public function __construct( WP_Markdown_Reconciliation_Operation_Store $store, ?string $owner = null, int $lease_seconds = 30 ) {
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
}

/** Build the shared production coordinator outside the managed canonical roots. */
function wp_markdown_durable_reconciliation_coordinator( array $canonical_roots ): WP_Markdown_Durable_Reconciliation_Coordinator {
	$roots = array_values( array_unique( array_map( static fn( string $root ): string => rtrim( $root, '/' ), $canonical_roots ) ) );
	$site  = hash( 'sha256', implode( "\0", $roots ) );
	$base  = rtrim( sys_get_temp_dir(), '/' ) . '/markdown-database-integration-operations';
	if ( ! is_dir( $base ) && ! mkdir( $base, 0700, true ) && ! is_dir( $base ) ) {
		throw new RuntimeException( 'Unable to create the durable reconciliation runtime directory.' );
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
				$row = $this->pdo->prepare( 'SELECT fence FROM `_mdi_resource_fences` WHERE resource_key = ?' );
				$row->execute( array( $key ) );
				$current = $row->fetchColumn();
				if ( false !== $current && (int) $current >= (int) $operation['fence'] ) {
					if ( (int) $current === (int) $operation['fence'] ) {
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
		return $resource['type'] . ':' . $resource['id'];
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

/** Owns replacement/deletion of canonical paths under a durable resource lock. */
final class WP_Markdown_Filesystem_Reconciliation_Adapter implements WP_Markdown_Reconciliation_Adapter {
	private string $fence_directory;
	private $observer;
	private $mutation;

	public function __construct( string $fence_directory, callable $observer, callable $mutation ) {
		$this->fence_directory = rtrim( $fence_directory, '/' );
		$this->observer         = $observer;
		$this->mutation         = $mutation;
		if ( ! is_dir( $this->fence_directory ) && ! mkdir( $this->fence_directory, 0700, true ) && ! is_dir( $this->fence_directory ) ) {
			throw new RuntimeException( 'Unable to create the filesystem fence directory.' );
		}
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
				if ( null !== $current && (int) $current['fence'] > (int) $operation['fence'] ) {
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
		$lock = fopen( $this->fence_directory . '/' . $key . '.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new RuntimeException( 'Unable to lock the filesystem resource fence.' );
		}
		try {
			$callback( $this->fence_directory . '/' . $key . '.json' );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function read_fence( string $path ): ?array {
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
		$temp = tempnam( $this->fence_directory, '.fence-' );
		if ( false === $temp || false === file_put_contents( $temp, WP_Markdown_Reconciliation_Identity::encode( $fence ), LOCK_EX ) || ! chmod( $temp, 0600 ) || ! rename( $temp, $path ) ) {
			if ( is_string( $temp ) && is_file( $temp ) ) {
				unlink( $temp );
			}
			throw new RuntimeException( 'Unable to persist the filesystem resource fence.' );
		}
	}
}
