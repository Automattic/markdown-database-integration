<?php
/** Durable, backend-neutral reconciliation operations. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface WP_Markdown_Reconciliation_Operation_Store {
	public function plan( array $operation ): array;
	public function get( string $operation_id ): ?array;
	public function claim( string $operation_id, int $revision, string $owner, int $now, int $lease_seconds ): array;
	public function compare_and_set( string $operation_id, array $expected, array $changes, int $now ): array;
	/** @return array<int,array<string,mixed>> */
	public function recoverable( int $now, int $limit ): array;
}

interface WP_Markdown_Reconciliation_Adapter {
	/** Return normalized identities for every domain named by the operation. */
	public function observe( array $operation ): array;
	/**
	 * Persist the claimed fence at the resource's owning durability boundary.
	 * A newer fence must prevent an older owner from subsequently mutating.
	 */
	public function fence( array $operation ): void;
	/** Apply once, atomically rejecting any fence that is no longer current. */
	public function apply( array $operation ): void;
}

class WP_Markdown_Reconciliation_Conflict extends RuntimeException {
	private array $conflict;

	public function __construct( array $conflict ) {
		$this->conflict = $conflict;
		parent::__construct( 'Reconciliation required for operation ' . (string) ( $conflict['operation_id'] ?? '' ) );
	}

	public function conflict(): array {
		return $this->conflict;
	}
}

class WP_Markdown_Reconciliation_Store_Conflict extends RuntimeException {}

final class WP_Markdown_Reconciliation_Identity {
	public static function exact( mixed $value ): array {
		$normalized = self::normalize( $value );
		return array(
			'algorithm' => 'sha256',
			'digest'    => hash( 'sha256', self::encode( $normalized ) ),
		);
	}

	public static function equal( mixed $left, mixed $right ): bool {
		return is_array( $left ) && is_array( $right )
			&& 'sha256' === ( $left['algorithm'] ?? null )
			&& 'sha256' === ( $right['algorithm'] ?? null )
			&& is_string( $left['digest'] ?? null )
			&& hash_equals( $left['digest'], (string) ( $right['digest'] ?? '' ) );
	}

	public static function normalize( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( self::class, 'normalize' ), $value );
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::normalize( $item );
		}
		return $value;
	}

	public static function encode( mixed $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
		return $json;
	}
}

/**
 * A local server-owned journal. A single lock protects record capacity, CAS,
 * lease fencing, and atomic publication as one consistency boundary.
 */
final class WP_Markdown_Filesystem_Reconciliation_Operation_Store implements WP_Markdown_Reconciliation_Operation_Store {
	private const VERSION = 1;
	private string $directory;
	private string $key;
	private int $max_operations;
	private int $max_bytes;
	/** @var array<string,true> */
	private array $canonical_roots = array();

	/** @param string[] $canonical_roots */
	public function __construct( string $directory, string $authentication_key, array $canonical_roots = array(), int $max_operations = 1000, int $max_bytes = 4194304 ) {
		if ( strlen( $authentication_key ) < 32 ) {
			throw new InvalidArgumentException( 'The operation-store authentication key must contain at least 32 bytes.' );
		}
		if ( $max_operations < 1 || $max_bytes < 1024 ) {
			throw new InvalidArgumentException( 'The operation-store bounds are invalid.' );
		}
		if ( array() === $canonical_roots ) {
			throw new InvalidArgumentException( 'At least one managed canonical root is required.' );
		}
		$this->directory      = $this->prepare_directory( $directory );
		$this->key            = $authentication_key;
		$this->max_operations = $max_operations;
		$this->max_bytes      = $max_bytes;
		foreach ( $canonical_roots as $root ) {
			$normalized = $this->normalized_root( $root );
			$this->assert_separate_root( $normalized );
			$this->canonical_roots[ $normalized ] = true;
		}
	}

	public function plan( array $operation ): array {
		$operation = $this->normalize_plan( $operation );
		return $this->locked(
			function ( array &$state ) use ( $operation ): array {
				$id = $operation['id'];
				if ( isset( $state['operations'][ $id ] ) ) {
					if ( $state['operations'][ $id ]['binding'] !== $operation['binding'] ) {
						throw new WP_Markdown_Reconciliation_Store_Conflict( 'Operation identity is already bound to different intent.' );
					}
					return $state['operations'][ $id ];
				}
				if ( count( $state['operations'] ) >= $this->max_operations ) {
					throw new OverflowException( 'The durable operation store reached its operation bound.' );
				}
				$state['operations'][ $id ] = $operation;
				return $operation;
			},
			true
		);
	}

	public function get( string $operation_id ): ?array {
		return $this->locked( static fn( array &$state ): ?array => $state['operations'][ $operation_id ] ?? null );
	}

	public function claim( string $operation_id, int $revision, string $owner, int $now, int $lease_seconds ): array {
		if ( '' === $owner || $lease_seconds < 1 ) {
			throw new InvalidArgumentException( 'Claims require an owner and a positive lease.' );
		}
		return $this->locked(
			function ( array &$state ) use ( $operation_id, $revision, $owner, $now, $lease_seconds ): array {
				$record = $state['operations'][ $operation_id ] ?? null;
				if ( ! is_array( $record ) || $record['revision'] !== $revision ) {
					throw new WP_Markdown_Reconciliation_Store_Conflict( 'The operation revision changed before claim.' );
				}
				$claimable = 'planned' === $record['state'] || ( in_array( $record['state'], array( 'claimed', 'effect_observed', 'ambiguous' ), true ) && (int) $record['lease_expires_at'] <= $now );
				if ( ! $claimable ) {
					throw new WP_Markdown_Reconciliation_Store_Conflict( 'The operation is not claimable.' );
				}
				$state['next_fence']       = (int) $state['next_fence'] + 1;
				$record['state']            = 'planned' === $record['state'] ? 'claimed' : $record['state'];
				$record['owner']            = $owner;
				$record['fence']            = $state['next_fence'];
				$record['lease_expires_at'] = $now + $lease_seconds;
				$record['revision']++;
				$record['updated_at']       = $now;
				$state['operations'][ $operation_id ] = $record;
				return $record;
			},
			true
		);
	}

	public function compare_and_set( string $operation_id, array $expected, array $changes, int $now ): array {
		return $this->locked(
			function ( array &$state ) use ( $operation_id, $expected, $changes, $now ): array {
				$record = $state['operations'][ $operation_id ] ?? null;
				foreach ( array( 'revision', 'state', 'owner', 'fence' ) as $field ) {
					if ( ! is_array( $record ) || ! array_key_exists( $field, $expected ) || $record[ $field ] !== $expected[ $field ] ) {
						throw new WP_Markdown_Reconciliation_Store_Conflict( 'Operation compare-and-set precondition failed.' );
					}
				}
				if ( (int) $record['lease_expires_at'] <= $now ) {
					throw new WP_Markdown_Reconciliation_Store_Conflict( 'The operation lease expired before transition.' );
				}
				$next_state = (string) ( $changes['state'] ?? $record['state'] );
				$this->assert_transition( $record['state'], $next_state );
				foreach ( array( 'state', 'evidence', 'conflict', 'continuation' ) as $field ) {
					if ( array_key_exists( $field, $changes ) ) {
						$record[ $field ] = $changes[ $field ];
					}
				}
				$record['revision']++;
				$record['updated_at'] = $now;
				if ( 'completed' === $next_state || 'reconciliation_required' === $next_state ) {
					$record['owner']            = '';
					$record['lease_expires_at'] = 0;
				}
				$state['operations'][ $operation_id ] = $record;
				return $record;
			},
			true
		);
	}

	public function recoverable( int $now, int $limit ): array {
		if ( $limit < 1 ) {
			return array();
		}
		return $this->locked(
			static function ( array &$state ) use ( $now, $limit ): array {
				$records = array_filter(
					$state['operations'],
					static fn( array $record ): bool => in_array( $record['state'], array( 'claimed', 'effect_observed', 'ambiguous' ), true ) && (int) $record['lease_expires_at'] <= $now
				);
				usort( $records, static fn( array $a, array $b ): int => array( $a['updated_at'], $a['id'] ) <=> array( $b['updated_at'], $b['id'] ) );
				return array_slice( $records, 0, $limit );
			}
		);
	}

	private function normalize_plan( array $operation ): array {
		foreach ( array( 'plan_id', 'continuation', 'canonical_root', 'resource', 'kind', 'direction', 'before', 'after' ) as $field ) {
			if ( ! array_key_exists( $field, $operation ) ) {
				throw new InvalidArgumentException( "Missing operation binding: $field" );
			}
		}
		$binding = WP_Markdown_Reconciliation_Identity::normalize(
			array_intersect_key( $operation, array_flip( array( 'plan_id', 'continuation', 'canonical_root', 'resource', 'kind', 'direction', 'before', 'after' ) ) )
		);
		$binding['canonical_root'] = $this->normalized_root( (string) $binding['canonical_root'] );
		if ( $this->canonical_roots && ! isset( $this->canonical_roots[ $binding['canonical_root'] ] ) ) {
			throw new InvalidArgumentException( 'The operation canonical root is not managed by this store.' );
		}
		if ( ! is_array( $binding['resource'] ) || ! is_string( $binding['resource']['type'] ?? null ) || ! is_string( $binding['resource']['id'] ?? null ) ) {
			throw new InvalidArgumentException( 'A normalized resource type and id are required.' );
		}
		$binding['resource']['type'] = strtolower( trim( $binding['resource']['type'] ) );
		$binding['resource']['id']   = trim( $binding['resource']['id'] );
		if ( '' === $binding['resource']['type'] || '' === $binding['resource']['id'] || ! preg_match( '/^[a-z][a-z0-9_.-]*$/', $binding['resource']['type'] ) ) {
			throw new InvalidArgumentException( 'The normalized resource type and id are invalid.' );
		}
		foreach ( array( 'plan_id', 'kind', 'direction' ) as $scalar_field ) {
			if ( ! is_string( $binding[ $scalar_field ] ) || '' === trim( $binding[ $scalar_field ] ) ) {
				throw new InvalidArgumentException( "The $scalar_field binding must be a non-empty string." );
			}
			$binding[ $scalar_field ] = trim( $binding[ $scalar_field ] );
		}
		foreach ( array( 'before', 'after' ) as $side ) {
			if ( ! is_array( $binding[ $side ] ) || array() === $binding[ $side ] ) {
				throw new InvalidArgumentException( "The $side identities must name at least one domain." );
			}
			foreach ( $binding[ $side ] as $identity ) {
				if ( ! is_array( $identity ) || 'sha256' !== ( $identity['algorithm'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $identity['digest'] ?? '' ) ) ) {
					throw new InvalidArgumentException( 'Before and after identities must be exact normalized SHA-256 identities.' );
				}
			}
		}
		if ( array_keys( $binding['before'] ) !== array_keys( $binding['after'] ) ) {
			throw new InvalidArgumentException( 'Before and after identities must name the same domains.' );
		}
		$id  = hash( 'sha256', WP_Markdown_Reconciliation_Identity::encode( $binding ) );
		$now = (int) ( $operation['created_at'] ?? time() );
		return array(
			'id'               => $id,
			'binding'          => $binding,
			'state'            => 'planned',
			'revision'         => 1,
			'owner'            => '',
			'fence'            => 0,
			'lease_expires_at' => 0,
			'evidence'         => null,
			'conflict'         => null,
			'created_at'       => $now,
			'updated_at'       => $now,
		);
	}

	private function assert_transition( string $from, string $to ): void {
		$allowed = array(
			'claimed'         => array( 'effect_observed', 'ambiguous' ),
			'effect_observed' => array( 'completed', 'ambiguous' ),
			'ambiguous'       => array( 'effect_observed', 'reconciliation_required' ),
		);
		if ( $from !== $to && ! in_array( $to, $allowed[ $from ] ?? array(), true ) ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( "Invalid operation transition: $from -> $to" );
		}
	}

	private function locked( callable $callback, bool $write = false ): mixed {
		$lock = fopen( $this->directory . '/operations.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			throw new RuntimeException( 'Unable to lock the durable operation store.' );
		}
		try {
			$state  = $this->read_state();
			$result = $callback( $state );
			if ( $write ) {
				$this->write_state( $state );
			}
			return $result;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function read_state(): array {
		$path = $this->directory . '/operations.json';
		if ( ! is_file( $path ) ) {
			return array( 'version' => self::VERSION, 'next_fence' => 0, 'operations' => array() );
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'The durable operation store is unreadable.' );
		}
		try {
			$bytes = stream_get_contents( $handle, $this->max_bytes + 1 );
		} finally {
			fclose( $handle );
		}
		if ( false === $bytes || strlen( $bytes ) > $this->max_bytes ) {
			throw new RuntimeException( 'The durable operation store is unreadable or exceeds its byte bound.' );
		}
		$envelope = json_decode( $bytes, true, 8, JSON_THROW_ON_ERROR );
		$payload  = base64_decode( (string) ( $envelope['payload'] ?? '' ), true );
		$mac      = (string) ( $envelope['mac'] ?? '' );
		if ( false === $payload || ! hash_equals( hash_hmac( 'sha256', $payload, $this->key ), $mac ) ) {
			throw new RuntimeException( 'The durable operation store failed authentication.' );
		}
		$state = json_decode( $payload, true, 64, JSON_THROW_ON_ERROR );
		if ( self::VERSION !== ( $state['version'] ?? null ) || ! is_array( $state['operations'] ?? null ) || count( $state['operations'] ) > $this->max_operations ) {
			throw new RuntimeException( 'The durable operation store has invalid or unbounded state.' );
		}
		return $state;
	}

	private function write_state( array $state ): void {
		$payload  = WP_Markdown_Reconciliation_Identity::encode( $state );
		$envelope = WP_Markdown_Reconciliation_Identity::encode( array( 'payload' => base64_encode( $payload ), 'mac' => hash_hmac( 'sha256', $payload, $this->key ) ) );
		if ( strlen( $envelope ) > $this->max_bytes ) {
			throw new OverflowException( 'The durable operation store reached its byte bound.' );
		}
		$temp = tempnam( $this->directory, '.operations-' );
		if ( false === $temp ) {
			throw new RuntimeException( 'Unable to stage the durable operation store.' );
		}
		try {
			$handle = fopen( $temp, 'c+b' );
			if ( false === $handle ) {
				throw new RuntimeException( 'Unable to open the staged durable operation store.' );
			}
			try {
				if ( ! flock( $handle, LOCK_EX ) || strlen( $envelope ) !== fwrite( $handle, $envelope ) || ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) {
					throw new RuntimeException( 'Unable to durably stage the operation store.' );
				}
			} finally {
				fclose( $handle );
			}
			if ( ! chmod( $temp, 0600 ) || ! rename( $temp, $this->directory . '/operations.json' ) ) {
				throw new RuntimeException( 'Unable to atomically publish the durable operation store.' );
			}
			$this->sync_directory();
		} finally {
			if ( is_file( $temp ) ) {
				unlink( $temp );
			}
		}
	}

	private function prepare_directory( string $directory ): string {
		if ( '' === $directory || ( '/' !== $directory[0] && ! preg_match( '/^[A-Za-z]:[\\\\\/]/', $directory ) ) ) {
			throw new InvalidArgumentException( 'The operation-store directory must be absolute.' );
		}
		$created = ! is_dir( $directory );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
			throw new RuntimeException( 'Unable to create the operation-store directory.' );
		}
		if ( ! is_dir( $directory ) ) {
			throw new RuntimeException( 'The operation-store path is not a directory.' );
		}
		if ( $created && ! chmod( $directory, 0700 ) ) {
			throw new RuntimeException( 'Unable to secure the operation-store directory.' );
		}
		clearstatcache( true, $directory );
		$permissions = fileperms( $directory );
		$owner = fileowner( $directory );
		if ( false === $permissions || 0 !== ( $permissions & 0077 ) || ! is_writable( $directory ) || ( function_exists( 'posix_geteuid' ) && $owner !== posix_geteuid() ) ) {
			throw new RuntimeException( 'The operation-store directory must be server-owned and private.' );
		}
		$real = realpath( $directory );
		if ( false === $real || is_link( $directory ) ) {
			throw new RuntimeException( 'The operation-store directory must not be a symlink.' );
		}
		return rtrim( $real, DIRECTORY_SEPARATOR );
	}

	private function assert_separate_root( string $root ): void {
		if ( $this->directory === $root || str_starts_with( $this->directory . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR ) || str_starts_with( $root . DIRECTORY_SEPARATOR, $this->directory . DIRECTORY_SEPARATOR ) ) {
			throw new InvalidArgumentException( 'The operation store must be outside managed canonical roots.' );
		}
	}

	private function normalized_root( string $root ): string {
		if ( '' === $root || ( '/' !== $root[0] && ! preg_match( '/^[A-Za-z]:[\\\\\/]/', $root ) ) ) {
			throw new InvalidArgumentException( 'Canonical roots must be absolute.' );
		}
		$real = realpath( $root );
		return false === $real ? $this->normalize_absolute_path( $root ) : rtrim( $real, DIRECTORY_SEPARATOR );
	}

	private function sync_directory(): void {
		if ( ! function_exists( 'fsync' ) ) {
			return;
		}
		$handle = @fopen( $this->directory, 'rb' );
		if ( false !== $handle ) {
			@fsync( $handle );
			fclose( $handle );
		}
	}

	private function normalize_absolute_path( string $path ): string {
		$prefix = str_starts_with( $path, DIRECTORY_SEPARATOR ) ? DIRECTORY_SEPARATOR : substr( $path, 0, 3 );
		$rest   = DIRECTORY_SEPARATOR === $prefix ? substr( $path, 1 ) : substr( $path, 3 );
		$parts  = array();
		foreach ( preg_split( '#[\\\\/]#', $rest ) ?: array() as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $part;
		}
		return rtrim( $prefix . implode( DIRECTORY_SEPARATOR, $parts ), DIRECTORY_SEPARATOR );
	}
}

final class WP_Markdown_Durable_Reconciliation_Operations {
	private WP_Markdown_Reconciliation_Operation_Store $store;

	public function __construct( WP_Markdown_Reconciliation_Operation_Store $store ) {
		$this->store = $store;
	}

	public function plan( array $operation ): array {
		return $this->store->plan( $operation );
	}

	public function execute( string $operation_id, string $owner, int $now, int $lease_seconds, WP_Markdown_Reconciliation_Adapter $adapter, ?callable $boundary = null ): array {
		$record = $this->required( $operation_id );
		if ( 'planned' !== $record['state'] ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( 'Only a planned operation may execute.' );
		}
		$this->boundary( $boundary, 'planned', $record );
		$record = $this->store->claim( $operation_id, $record['revision'], $owner, $now, $lease_seconds );
		$adapter->fence( $record );
		$this->boundary( $boundary, 'claimed', $record );
		try {
			$actual = $this->observe( $adapter, $record );
		} catch ( Throwable $error ) {
			return $this->observation_conflict( $record, $error, $now, $boundary );
		}
		if ( ! $this->matches( $record['binding']['before'], $actual ) ) {
			return $this->conflict( $record, $actual, $record['binding']['before'], 'precondition_not_proven', $now, $boundary );
		}
		try {
			$adapter->apply( $record );
		} catch ( Throwable $apply_error ) {
			try {
				$actual = $this->observe( $adapter, $record );
			} catch ( Throwable $observe_error ) {
				return $this->observation_conflict( $record, $observe_error, $now, $boundary, get_class( $apply_error ) );
			}
			if ( $this->matches( $record['binding']['after'], $actual ) ) {
				return $this->complete( $record, $actual, $now, $boundary );
			}
			return $this->conflict( $record, $actual, $record['binding']['after'], 'apply_outcome_not_proven', $now, $boundary );
		}
		$this->boundary( $boundary, 'effect_applied', $record );
		try {
			$actual = $this->observe( $adapter, $record );
		} catch ( Throwable $error ) {
			return $this->observation_conflict( $record, $error, $now, $boundary );
		}
		if ( ! $this->matches( $record['binding']['after'], $actual ) ) {
			return $this->conflict( $record, $actual, $record['binding']['after'], 'after_state_not_proven', $now, $boundary );
		}
		return $this->complete( $record, $actual, $now, $boundary );
	}

	/** Recovery observes and fences; it never calls the mutation adapter's apply method. */
	public function recover( string $operation_id, string $owner, int $now, int $lease_seconds, WP_Markdown_Reconciliation_Adapter $adapter, ?callable $boundary = null ): array {
		$record = $this->required( $operation_id );
		if ( 'completed' === $record['state'] || 'reconciliation_required' === $record['state'] ) {
			return $record;
		}
		if ( in_array( $record['state'], array( 'claimed', 'effect_observed', 'ambiguous' ), true ) && (int) $record['lease_expires_at'] > $now ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( 'The operation lease has not expired.' );
		}
		if ( in_array( $record['state'], array( 'planned', 'claimed', 'effect_observed', 'ambiguous' ), true ) ) {
			$record = $this->store->claim( $operation_id, $record['revision'], $owner, $now, $lease_seconds );
			$adapter->fence( $record );
			$this->boundary( $boundary, 'claimed', $record );
		}
		try {
			$actual = $this->observe( $adapter, $record );
		} catch ( Throwable $error ) {
			return $this->observation_conflict( $record, $error, $now, $boundary );
		}
		if ( $this->matches( $record['binding']['after'], $actual ) ) {
			return $this->complete( $record, $actual, $now, $boundary );
		}
		return $this->conflict( $record, $actual, $record['binding']['after'], 'recovery_after_state_not_proven', $now, $boundary );
	}

	private function complete( array $record, array $actual, int $now, ?callable $boundary ): array {
		if ( 'effect_observed' !== $record['state'] ) {
			$record = $this->transition( $record, 'effect_observed', array( 'actual' => $actual, 'observed_at' => $now ), null, $now );
			$this->boundary( $boundary, 'effect_observed', $record );
		}
		$record = $this->transition( $record, 'completed', array( 'actual' => $actual, 'completed_at' => $now ), null, $now );
		$this->boundary( $boundary, 'completed', $record );
		return $record;
	}

	private function conflict( array $record, array $actual, array $expected, string $reason, int $now, ?callable $boundary ): array {
		$conflict = array(
			'operation_id' => $record['id'],
			'reason'       => $reason,
			'expected'     => $expected,
			'actual'       => $actual,
			'observed_at'  => $now,
		);
		if ( 'ambiguous' !== $record['state'] ) {
			$record = $this->transition( $record, 'ambiguous', null, $conflict, $now );
			$this->boundary( $boundary, 'ambiguous', $record );
		}
		$record = $this->transition( $record, 'reconciliation_required', null, $conflict, $now );
		$this->boundary( $boundary, 'reconciliation_required', $record );
		throw new WP_Markdown_Reconciliation_Conflict( $conflict );
	}

	private function observation_conflict( array $record, Throwable $error, int $now, ?callable $boundary, ?string $apply_exception = null ): array {
		$actual = array( 'indeterminate' => array( 'exception' => get_class( $error ) ) );
		if ( null !== $apply_exception ) {
			$actual['indeterminate']['apply_exception'] = $apply_exception;
		}
		return $this->conflict(
			$record,
			$actual,
			$record['binding']['after'],
			'observation_indeterminate',
			$now,
			$boundary
		);
	}

	private function transition( array $record, string $state, ?array $evidence, ?array $conflict, int $now ): array {
		$expected = array_intersect_key( $record, array_flip( array( 'revision', 'state', 'owner', 'fence' ) ) );
		return $this->store->compare_and_set(
			$record['id'],
			$expected,
			array( 'state' => $state, 'evidence' => $evidence, 'conflict' => $conflict ),
			$now
		);
	}

	private function observe( WP_Markdown_Reconciliation_Adapter $adapter, array $record ): array {
		$actual = $adapter->observe( $record );
		return WP_Markdown_Reconciliation_Identity::normalize( $actual );
	}

	private function matches( array $expected, array $actual ): bool {
		if ( array_keys( $expected ) !== array_keys( $actual ) ) {
			return false;
		}
		foreach ( $expected as $domain => $identity ) {
			if ( ! WP_Markdown_Reconciliation_Identity::equal( $identity, $actual[ $domain ] ?? null ) ) {
				return false;
			}
		}
		return true;
	}

	private function required( string $operation_id ): array {
		$record = $this->store->get( $operation_id );
		if ( null === $record ) {
			throw new OutOfBoundsException( 'Unknown reconciliation operation.' );
		}
		return $record;
	}

	private function boundary( ?callable $callback, string $boundary, array $record ): void {
		if ( null !== $callback ) {
			$callback( $boundary, $record );
		}
	}
}
