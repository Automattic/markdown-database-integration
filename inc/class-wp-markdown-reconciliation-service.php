<?php
/** Backend-neutral three-way content reconciliation. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-reconciliation-adapters.php';

/**
 * Supplies stable pages of normalized content state and owns mutations.
 *
 * enumerate() must return:
 * - source_identity: a non-empty identity for the complete source state.
 * - snapshots: at most $limit snapshot arrays, in any order.
 * - continuation: an opaque string for the next page, or null.
 *
 * A snapshot contains resource_id, canonical_path, expected_canonical_path,
 * canonical, wordpress, and baseline. canonical and wordpress are normalized
 * values or null. baseline is null or an array containing canonical_root,
 * canonical_path, and identity. Exact identity fields may be supplied as
 * canonical_identity and wordpress_identity; otherwise they are calculated.
 * For moves or adapters with additional durability domains, durable_before and
 * durable_after may contain exact identity maps.
 *
 * adapter_for() receives the durable operation record (or intent before it is
 * recorded) and the private plan entry when one is available. Recovery passes
 * null for the entry, so production adapters must be able to recover ownership
 * from the operation binding alone.
 */
interface WP_Markdown_Reconciliation_Content_Adapter {
	public function enumerate( array $scope, ?string $continuation, int $limit ): array;

	public function adapter_for( array $operation, ?array $plan_entry = null ): WP_Markdown_Reconciliation_Adapter;
}

/**
 * Plans and applies deterministic, bounded, three-way reconciliation pages.
 *
 * This service is deliberately stateless. Durable operation state remains in
 * WP_Markdown_Durable_Reconciliation_Coordinator (#190); plan and continuation
 * identities bind callers to the source snapshot and validated options.
 */
final class WP_Markdown_Reconciliation_Service {
	private const SCHEMA_VERSION = 1;
	private const CATEGORIES = array(
		'created',
		'updated_from_file',
		'written_from_wordpress',
		'deleted_from_file',
		'deleted_from_wordpress',
		'moved',
		'unchanged',
		'conflicts',
	);

	private WP_Markdown_Durable_Reconciliation_Coordinator $coordinator;
	private WP_Markdown_Reconciliation_Content_Adapter $adapter;

	public function __construct( WP_Markdown_Durable_Reconciliation_Coordinator $coordinator, WP_Markdown_Reconciliation_Content_Adapter $adapter ) {
		$this->coordinator = $coordinator;
		$this->adapter     = $adapter;
	}

	/** Build one deterministic page without mutating either content domain. */
	public function plan( array $request ): array {
		return $this->run( $request, false );
	}

	/** Recover prior operations, then apply one deterministic bounded page. */
	public function apply( array $request ): array {
		return $this->run( $request, true );
	}

	/** Convenience entry point for transports exposing an explicit mode. */
	public function reconcile( array $request, string $mode = 'plan' ): array {
		if ( ! in_array( $mode, array( 'plan', 'apply' ), true ) ) {
			throw new InvalidArgumentException( 'Reconciliation mode must be plan or apply.' );
		}
		return 'apply' === $mode ? $this->apply( $request ) : $this->plan( $request );
	}

	private function run( array $request, bool $apply ): array {
		$options      = $this->options( $request );
		$continuation = $this->continuation( $request['continuation'] ?? null );
		$requested_plan_id = $request['plan_id'] ?? null;
		$requested_source  = $request['source_identity'] ?? null;
		foreach ( array( 'plan_id' => $requested_plan_id, 'source_identity' => $requested_source ) as $name => $identity ) {
			if ( null !== $identity && ( ! is_string( $identity ) || ! preg_match( '/^[a-f0-9]{64}$/', $identity ) ) ) {
				throw new InvalidArgumentException( "$name must be a SHA-256 identity." );
			}
		}
		if ( $apply && ( ! is_string( $requested_plan_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $requested_plan_id ) || ! is_string( $requested_source ) || ! preg_match( '/^[a-f0-9]{64}$/', $requested_source ) ) ) {
			throw new InvalidArgumentException( 'Apply mode requires SHA-256 plan_id and source_identity values.' );
		}
		$page         = $this->adapter->enumerate( $this->scope( $options ), $continuation['cursor'] ?? null, $options['batch_size'] );
		$this->assert_page( $page, $options['batch_size'] );
		$live_source_identity = trim( $page['source_identity'] );
		$source_identity = null === $continuation ? $live_source_identity : (string) $requested_source;
		$plan_id         = hash( 'sha256', WP_Markdown_Reconciliation_Identity::encode( array( 'schema_version' => self::SCHEMA_VERSION, 'source_identity' => $source_identity, 'options' => $options ) ) );
		if ( ! $apply && is_string( $requested_plan_id ) && ! hash_equals( $plan_id, $requested_plan_id ) ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( 'The supplied plan_id is stale.' );
		}
		if ( ! $apply && is_string( $requested_source ) && ! hash_equals( $source_identity, $requested_source ) ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( 'The supplied source_identity is stale.' );
		}

		if ( null !== $continuation ) {
			$expected = $this->continuation_identity( $plan_id, $source_identity, $continuation['cursor'] );
			if ( ! hash_equals( $expected, $continuation['identity'] ) ) {
				throw new InvalidArgumentException( 'The continuation identity does not match this plan and source.' );
			}
		}
		if ( $apply ) {
			if ( ! hash_equals( $plan_id, $requested_plan_id ) ) {
				throw new WP_Markdown_Reconciliation_Store_Conflict( 'The supplied plan_id is missing or stale.' );
			}
			if ( null === $continuation && ! hash_equals( $live_source_identity, $requested_source ) ) {
				throw new WP_Markdown_Reconciliation_Store_Conflict( 'The supplied source_identity is missing or stale.' );
			}
		}

		$result            = $this->empty_result( $plan_id, $source_identity, $options );
		$blocked_resources = array();
		if ( $apply ) {
			$this->recover_original_operations( $plan_id, $source_identity, $result, $blocked_resources );
		}

		$snapshots = array();
		foreach ( $page['snapshots'] as $snapshot ) {
			$normalized = $this->snapshot( $snapshot, $options );
			$key        = $normalized['resource_id'];
			if ( isset( $snapshots[ $key ] ) ) {
				throw new InvalidArgumentException( 'A reconciliation page contains duplicate resource_id values.' );
			}
			$snapshots[ $key ] = $normalized;
		}
		ksort( $snapshots, SORT_STRING );

		foreach ( $snapshots as $snapshot ) {
			$entry    = $this->classify( $snapshot, $options );
			$category = $entry['category'];
			if ( $apply && isset( $blocked_resources[ $snapshot['resource_id'] ] ) ) {
				continue;
			}
			if ( $apply && $this->is_action( $category ) ) {
				try {
					$record = $this->apply_entry( $entry, $snapshot, $plan_id, $source_identity, $continuation['cursor'] ?? null, $options );
					if ( 'reconciliation_required' === ( $record['state'] ?? null ) ) {
						$result['operation_ids'][] = $record['id'];
						$entry = $this->conflict_entry( $snapshot, 'durable_conflict', $record['id'], 'reconciliation_required' );
						$category = 'conflicts';
					} else {
					$entry['operation_id']    = $record['id'];
					$entry['operation_state'] = $record['state'];
					$result['operation_ids'][] = $record['id'];
					}
				} catch ( WP_Markdown_Reconciliation_Conflict $error ) {
					$operation_id = $error->conflict()['operation_id'] ?? null;
					if ( is_string( $operation_id ) ) { $result['operation_ids'][] = $operation_id; }
					$entry = $this->conflict_entry( $snapshot, 'durable_conflict', $operation_id, 'reconciliation_required' );
					$category = 'conflicts';
				} catch ( WP_Markdown_Reconciliation_Store_Conflict $error ) {
					$entry = $this->conflict_entry( $snapshot, 'durable_store_conflict', null, 'reconciliation_required' );
					$category = 'conflicts';
				}
			}
			$result['categories'][ $category ][] = $this->public_entry( $entry );
		}

		foreach ( self::CATEGORIES as $category ) {
			usort( $result['categories'][ $category ], array( $this, 'compare_entries' ) );
			$result['counts'][ $category ] = count( $result['categories'][ $category ] );
		}
		$result['operation_ids'] = array_values( array_unique( $result['operation_ids'] ) );
		sort( $result['operation_ids'], SORT_STRING );
		$result['continuation'] = null === $page['continuation'] ? null : array(
			'cursor'   => $page['continuation'],
			'identity' => $this->continuation_identity( $plan_id, $source_identity, $page['continuation'] ),
		);
		return $result;
	}

	private function options( array $request ): array {
		$direction = $request['direction'] ?? 'bidirectional';
		if ( ! in_array( $direction, array( 'bidirectional', 'canonical_to_wordpress', 'wordpress_to_canonical' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid reconciliation direction.' );
		}
		$root = $this->canonical_root( $request['canonical_root'] ?? null );
		$scope = $request['managed_scope'] ?? null;
		if ( ! is_array( $scope ) || array() === $scope ) {
			throw new InvalidArgumentException( 'managed_scope must be a non-empty array of strings.' );
		}
		foreach ( $scope as $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				throw new InvalidArgumentException( 'managed_scope must contain only non-empty strings.' );
			}
		}
		$scope = array_values( array_unique( array_map( 'trim', $scope ) ) );
		sort( $scope, SORT_STRING );
		$deletion_policy = $request['deletion_policy'] ?? 'none';
		if ( ! in_array( $deletion_policy, array( 'none', 'managed' ), true ) ) {
			throw new InvalidArgumentException( 'deletion_policy must be none or managed.' );
		}
		$conflict_policy = $request['conflict_policy'] ?? 'none';
		if ( ! in_array( $conflict_policy, array( 'none', 'prefer_canonical', 'prefer_wordpress' ), true ) ) {
			throw new InvalidArgumentException( 'Unsafe conflict policy.' );
		}
		$batch_size = $request['batch_size'] ?? 100;
		if ( ! is_int( $batch_size ) || $batch_size < 1 || $batch_size > 1000 ) {
			throw new InvalidArgumentException( 'batch_size must be an integer from 1 through 1000.' );
		}
		return array(
			'direction'       => $direction,
			'canonical_root'  => $root,
			'managed_scope'   => $scope,
			'deletion_policy' => $deletion_policy,
			'conflict_policy' => $conflict_policy,
			'batch_size'      => $batch_size,
			'layout_profile'  => is_string( $request['layout_profile'] ?? null ) ? $request['layout_profile'] : '',
		);
	}

	private function scope( array $options ): array {
		return array( 'canonical_root' => $options['canonical_root'], 'managed_scope' => $options['managed_scope'], 'layout_profile' => $options['layout_profile'] );
	}

	private function canonical_root( mixed $root ): string {
		if ( ! is_string( $root ) || '' === trim( $root ) || ! $this->is_absolute_path( $root ) ) {
			throw new InvalidArgumentException( 'canonical_root must be an absolute path.' );
		}
		$root = str_replace( '\\', '/', trim( $root ) );
		$parts = array();
		foreach ( explode( '/', $root ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $part;
		}
		$prefix = preg_match( '/^[A-Za-z]:/', $root ) ? substr( $root, 0, 2 ) . '/' : '/';
		if ( preg_match( '/^[A-Za-z]:/', $root ) ) {
			array_shift( $parts );
		}
		$normalized = rtrim( $prefix . implode( '/', $parts ), '/' );
		return '' === $normalized ? '/' : $normalized;
	}

	private function is_absolute_path( string $path ): bool {
		return str_starts_with( $path, '/' ) || 1 === preg_match( '/^[A-Za-z]:[\\\\\/]/', $path );
	}

	private function continuation( mixed $value ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || ! is_string( $value['cursor'] ?? null ) || '' === $value['cursor'] || ! is_string( $value['identity'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $value['identity'] ) ) {
			throw new InvalidArgumentException( 'continuation must contain a cursor and SHA-256 identity.' );
		}
		return array( 'cursor' => $value['cursor'], 'identity' => $value['identity'] );
	}

	private function assert_page( array $page, int $limit ): void {
		if ( ! is_string( $page['source_identity'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', trim( $page['source_identity'] ) ) || ! is_array( $page['snapshots'] ?? null ) || count( $page['snapshots'] ) > $limit ) {
			throw new UnexpectedValueException( 'The content adapter returned an invalid or unbounded page.' );
		}
		if ( null !== ( $page['continuation'] ?? null ) && ( ! is_string( $page['continuation'] ) || '' === $page['continuation'] ) ) {
			throw new UnexpectedValueException( 'The content adapter returned an invalid continuation.' );
		}
	}

	private function snapshot( mixed $snapshot, array $options ): array {
		if ( ! is_array( $snapshot ) || ! is_string( $snapshot['resource_id'] ?? null ) || '' === trim( $snapshot['resource_id'] ) ) {
			throw new UnexpectedValueException( 'Every snapshot requires a non-empty resource_id.' );
		}
		foreach ( array( 'canonical_path', 'expected_canonical_path' ) as $field ) {
			if ( ! array_key_exists( $field, $snapshot ) || ( null !== $snapshot[ $field ] && ! is_string( $snapshot[ $field ] ) ) ) {
				throw new UnexpectedValueException( "Snapshot $field must be a string or null." );
			}
		}
		foreach ( array( 'canonical', 'wordpress' ) as $domain ) {
			if ( ! array_key_exists( $domain, $snapshot ) ) {
				throw new UnexpectedValueException( "Snapshot $domain is required." );
			}
			$identity = $snapshot[ $domain . '_identity' ] ?? ( null === $snapshot[ $domain ] ? null : WP_Markdown_Reconciliation_Identity::exact( $snapshot[ $domain ] ) );
			if ( null !== $identity ) {
				$this->assert_identity( $identity );
				if ( null === $snapshot[ $domain ] || ! WP_Markdown_Reconciliation_Identity::equal( $identity, WP_Markdown_Reconciliation_Identity::exact( $snapshot[ $domain ] ) ) ) {
					throw new UnexpectedValueException( "Snapshot $domain identity is not exact for its normalized value." );
				}
			}
			$snapshot[ $domain . '_identity' ] = $identity;
		}
		$baseline = $snapshot['baseline'] ?? null;
		if ( null !== $baseline ) {
			if ( ! is_array( $baseline ) || ! is_string( $baseline['canonical_root'] ?? null ) || ! array_key_exists( 'canonical_path', $baseline ) || ( null !== $baseline['canonical_path'] && ! is_string( $baseline['canonical_path'] ) ) ) {
				throw new UnexpectedValueException( 'Snapshot baseline is invalid.' );
			}
			$baseline['canonical_root'] = $this->canonical_root( $baseline['canonical_root'] );
			if ( null !== ( $baseline['identity'] ?? null ) ) {
				$this->assert_identity( $baseline['identity'] );
			} else {
				$baseline['identity'] = null;
			}
			if ( isset( $baseline['resource_id'] ) && ( ! is_string( $baseline['resource_id'] ) || '' === $baseline['resource_id'] ) ) {
				throw new UnexpectedValueException( 'Snapshot baseline resource_id is invalid.' );
			}
			if ( isset( $baseline['resource_type'] ) && ( ! is_string( $baseline['resource_type'] ) || ! preg_match( '/^[a-z][a-z0-9_.-]*$/', $baseline['resource_type'] ) ) ) {
				throw new UnexpectedValueException( 'Snapshot baseline resource_type is invalid.' );
			}
		}
		$snapshot['resource_id']   = trim( $snapshot['resource_id'] );
		$snapshot['resource_type'] = is_string( $snapshot['resource_type'] ?? null ) && preg_match( '/^[a-z][a-z0-9_.-]*$/', $snapshot['resource_type'] ) ? $snapshot['resource_type'] : 'content';
		$snapshot['baseline']      = $baseline;
		$snapshot['baseline_identity'] = $baseline['identity'] ?? null;
		$snapshot['move_direction'] = $snapshot['move_direction'] ?? 'wordpress_to_canonical';
		if ( ! in_array( $snapshot['move_direction'], array( 'canonical_to_wordpress', 'wordpress_to_canonical' ), true ) ) {
			throw new UnexpectedValueException( 'Snapshot move_direction is invalid.' );
		}
		foreach ( array( 'durable_before', 'durable_after' ) as $map ) {
			if ( isset( $snapshot[ $map ] ) ) {
				$this->assert_identity_map( $snapshot[ $map ] );
			}
		}
		if ( isset( $snapshot['durable_before'], $snapshot['durable_after'] ) && array_keys( $snapshot['durable_before'] ) !== array_keys( $snapshot['durable_after'] ) ) {
			throw new UnexpectedValueException( 'Durable before and after maps must name identical domains.' );
		}
		if ( $this->is_move( $snapshot ) ) {
			if ( ! isset( $snapshot['durable_before'], $snapshot['durable_after'] ) || $this->identity_maps_equal( $snapshot['durable_before'], $snapshot['durable_after'] ) ) {
				throw new UnexpectedValueException( 'Move snapshots require distinct durable before and after identity maps that cover path state.' );
			}
		}
		return $snapshot;
	}

	private function classify( array $snapshot, array $options ): array {
		$c = $snapshot['canonical_identity'];
		$w = $snapshot['wordpress_identity'];
		$b = $snapshot['baseline_identity'];
		$category = 'conflicts';
		$reason   = 'divergent_without_baseline';

		if ( $this->same_nullable_identity( $c, $w ) ) {
			$category = $this->is_move( $snapshot ) ? 'moved' : 'unchanged';
			$reason   = null;
		} elseif ( null === $b ) {
			if ( null !== $c && null === $w ) {
				$category = 'created';
				$reason   = null;
			} elseif ( null === $c && null !== $w ) {
				$category = 'written_from_wordpress';
				$reason   = null;
			}
		} else {
			$canonical_changed = ! $this->same_nullable_identity( $c, $b );
			$wordpress_changed = ! $this->same_nullable_identity( $w, $b );
			if ( $canonical_changed && ! $wordpress_changed ) {
				$category = null === $c ? 'deleted_from_file' : 'updated_from_file';
				$reason   = null;
			} elseif ( $wordpress_changed && ! $canonical_changed ) {
				$category = null === $w ? 'deleted_from_wordpress' : 'written_from_wordpress';
				$reason   = null;
			} else {
				$reason = 'both_sides_changed';
			}
		}

		if ( 'conflicts' !== $category && 'unchanged' !== $category && ! $this->direction_allows( $category, $snapshot, $options['direction'] ) ) {
			$category = 'conflicts';
			$reason   = 'direction_disallows_change';
		}
		if ( in_array( $category, array( 'deleted_from_file', 'deleted_from_wordpress' ), true ) && ( 'managed' !== $options['deletion_policy'] || ! $this->baseline_proves_management( $snapshot, $options['canonical_root'] ) ) ) {
			$category = 'conflicts';
			$reason   = 'deletion_not_proven';
		}
		if ( 'conflicts' === $category && 'none' !== $options['conflict_policy'] ) {
			$preferred = 'prefer_canonical' === $options['conflict_policy'] ? 'updated_from_file' : 'written_from_wordpress';
			if ( null !== ( 'prefer_canonical' === $options['conflict_policy'] ? $c : $w ) && $this->direction_allows( $preferred, $snapshot, $options['direction'] ) ) {
				$category = $preferred;
				$reason   = null;
			}
		}

		$entry = $this->base_entry( $snapshot );
		$entry['category'] = $category;
		if ( null !== $reason ) {
			$entry['reason'] = $reason;
		}
		return $entry;
	}

	private function direction_allows( string $category, array $snapshot, string $direction ): bool {
		if ( 'bidirectional' === $direction ) {
			return true;
		}
		$required = match ( $category ) {
			'created', 'updated_from_file', 'deleted_from_file' => 'canonical_to_wordpress',
			'written_from_wordpress', 'deleted_from_wordpress' => 'wordpress_to_canonical',
			'moved' => $snapshot['move_direction'],
			default => $direction,
		};
		return $required === $direction;
	}

	private function baseline_proves_management( array $snapshot, string $canonical_root ): bool {
		$baseline = $snapshot['baseline'];
		$target_path = $snapshot['canonical_path'] ?? $snapshot['expected_canonical_path'];
		return is_array( $baseline )
			&& null !== $baseline['identity']
			&& $baseline['canonical_root'] === $canonical_root
			&& is_string( $baseline['canonical_path'] )
			&& '' !== $baseline['canonical_path']
			&& $baseline['canonical_path'] === $target_path
			&& ( ! isset( $baseline['resource_id'] ) || $baseline['resource_id'] === $snapshot['resource_id'] )
			&& ( ! isset( $baseline['resource_type'] ) || $baseline['resource_type'] === $snapshot['resource_type'] );
	}

	private function apply_entry( array $entry, array $snapshot, string $plan_id, string $source_identity, ?string $cursor, array $options ): array {
		$category  = $entry['category'];
		$direction = in_array( $category, array( 'created', 'updated_from_file', 'deleted_from_file' ), true ) ? 'canonical_to_wordpress' : ( 'moved' === $category ? $snapshot['move_direction'] : 'wordpress_to_canonical' );
		$before    = $snapshot['durable_before'] ?? array(
			'canonical' => $this->durable_identity( $snapshot['canonical_identity'] ),
			'wordpress' => $this->durable_identity( $snapshot['wordpress_identity'] ),
		);
		$target = match ( $category ) {
			'created', 'updated_from_file' => $snapshot['canonical_identity'],
			'written_from_wordpress' => $snapshot['wordpress_identity'],
			'deleted_from_file', 'deleted_from_wordpress' => null,
			'moved' => $snapshot['canonical_identity'],
		};
		$after = $snapshot['durable_after'] ?? array( 'canonical' => $this->durable_identity( $target ), 'wordpress' => $this->durable_identity( $target ) );
		$intent = array(
			'plan_id'       => $plan_id,
			'continuation'   => array(
				'service_schema'  => self::SCHEMA_VERSION,
				'source_identity' => $source_identity,
				'cursor'          => $cursor,
				'resource_id'     => $snapshot['resource_id'],
				'canonical_path'  => $snapshot['canonical_path'],
				'expected_canonical_path' => $snapshot['expected_canonical_path'],
				'layout_profile'   => $options['layout_profile'],
			),
			'canonical_root' => $options['canonical_root'],
			'resource'       => array( 'type' => $snapshot['resource_type'], 'id' => $snapshot['resource_id'] ),
			'kind'           => $category,
			'direction'      => $direction,
			'before'         => $before,
			'after'          => $after,
		);
		$private_entry = $entry;
		$private_entry['snapshot'] = $snapshot;
		$adapter = $this->adapter->adapter_for( $intent, $private_entry );
		return $this->coordinator->reconcile( $intent, $adapter );
	}

	private function recover_original_operations( string $plan_id, string $source_identity, array &$result, array &$blocked_resources ): void {
		foreach ( $this->coordinator->recoverable( 1000 ) as $record ) {
			$binding = $record['binding'] ?? array();
			if ( $plan_id !== ( $binding['plan_id'] ?? null ) || $source_identity !== ( $binding['continuation']['source_identity'] ?? null ) ) {
				continue;
			}
			$resource_id = (string) ( $binding['resource']['id'] ?? '' );
			try {
				$durable_adapter = $this->adapter->adapter_for( $record, null );
				$recovered = 'planned' === ( $record['state'] ?? null )
					? $this->coordinator->reconcile( $binding, $durable_adapter )
					: $this->coordinator->recover( $record['id'], $durable_adapter );
				$result['operation_ids'][] = $recovered['id'];
			} catch ( WP_Markdown_Reconciliation_Conflict $error ) {
				$blocked_resources[ $resource_id ] = true;
				$operation_id = $error->conflict()['operation_id'] ?? $record['id'];
				$result['operation_ids'][] = $operation_id;
				$result['categories']['conflicts'][] = $this->record_conflict_entry( $record, 'durable_conflict', $operation_id );
			} catch ( WP_Markdown_Reconciliation_Store_Conflict $error ) {
				$blocked_resources[ $resource_id ] = true;
				$result['operation_ids'][] = $record['id'];
				$result['categories']['conflicts'][] = $this->record_conflict_entry( $record, 'durable_store_conflict', $record['id'] ?? null );
			}
		}
	}

	private function base_entry( array $snapshot ): array {
		return array(
			'canonical_path'      => $snapshot['canonical_path'],
			'expected_canonical_path' => $snapshot['expected_canonical_path'],
			'resource_id'         => $snapshot['resource_id'],
			'canonical_identity'  => $snapshot['canonical_identity'],
			'wordpress_identity'  => $snapshot['wordpress_identity'],
			'baseline_identity'   => $snapshot['baseline_identity'],
		);
	}

	private function conflict_entry( array $snapshot, string $reason, ?string $operation_id = null, ?string $state = null ): array {
		$entry = $this->base_entry( $snapshot );
		$entry['category'] = 'conflicts';
		$entry['reason'] = $reason;
		if ( null !== $operation_id ) {
			$entry['operation_id'] = $operation_id;
		}
		if ( null !== $state ) {
			$entry['operation_state'] = $state;
		}
		return $entry;
	}

	private function record_conflict_entry( array $record, string $reason, ?string $operation_id ): array {
		$binding = $record['binding'] ?? array();
		$before  = $binding['before'] ?? array();
		return array(
			'canonical_path'      => $binding['continuation']['canonical_path'] ?? null,
			'expected_canonical_path' => null,
			'resource_id'         => (string) ( $binding['resource']['id'] ?? '' ),
			'canonical_identity'  => $this->nullable_durable_identity( $before['canonical'] ?? null ),
			'wordpress_identity'  => $this->nullable_durable_identity( $before['wordpress'] ?? null ),
			'baseline_identity'   => null,
			'category'            => 'conflicts',
			'reason'              => $reason,
			'operation_id'        => $operation_id,
			'operation_state'     => 'reconciliation_required',
		);
	}

	private function public_entry( array $entry ): array {
		unset( $entry['category'], $entry['snapshot'] );
		return $entry;
	}

	private function empty_result( string $plan_id, string $source_identity, array $options ): array {
		$categories = array_fill_keys( self::CATEGORIES, array() );
		return array(
			'schema_version'  => self::SCHEMA_VERSION,
			'plan_id'         => $plan_id,
			'source_identity' => $source_identity,
			'options'         => $options,
			'categories'      => $categories,
			'counts'          => array_fill_keys( self::CATEGORIES, 0 ),
			'operation_ids'   => array(),
			'continuation'    => null,
		);
	}

	private function continuation_identity( string $plan_id, string $source_identity, string $cursor ): string {
		return hash( 'sha256', WP_Markdown_Reconciliation_Identity::encode( array( $plan_id, $source_identity, $cursor ) ) );
	}

	private function is_action( string $category ): bool {
		return ! in_array( $category, array( 'unchanged', 'conflicts' ), true );
	}

	private function is_move( array $snapshot ): bool {
		return null !== $snapshot['canonical_identity'] && null !== $snapshot['wordpress_identity'] && null !== $snapshot['canonical_path'] && null !== $snapshot['expected_canonical_path'] && $snapshot['canonical_path'] !== $snapshot['expected_canonical_path'];
	}

	private function same_nullable_identity( ?array $left, ?array $right ): bool {
		return null === $left || null === $right ? $left === $right : WP_Markdown_Reconciliation_Identity::equal( $left, $right );
	}

	private function durable_identity( ?array $identity ): array {
		return null === $identity ? WP_Markdown_Reconciliation_Identity::exact( null ) : $identity;
	}

	private function nullable_durable_identity( mixed $identity ): ?array {
		if ( ! is_array( $identity ) ) {
			return null;
		}
		$null = WP_Markdown_Reconciliation_Identity::exact( null );
		return WP_Markdown_Reconciliation_Identity::equal( $identity, $null ) ? null : $identity;
	}

	private function assert_identity( mixed $identity ): void {
		if ( ! is_array( $identity ) || 'sha256' !== ( $identity['algorithm'] ?? null ) || ! is_string( $identity['digest'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', $identity['digest'] ) ) {
			throw new UnexpectedValueException( 'An exact SHA-256 identity is required.' );
		}
	}

	private function assert_identity_map( mixed $map ): void {
		if ( ! is_array( $map ) || array() === $map ) {
			throw new UnexpectedValueException( 'A durable identity map must name at least one domain.' );
		}
		foreach ( $map as $domain => $identity ) {
			if ( ! is_string( $domain ) || '' === $domain ) {
				throw new UnexpectedValueException( 'Durability domain names must be non-empty strings.' );
			}
			$this->assert_identity( $identity );
		}
	}

	private function identity_maps_equal( array $left, array $right ): bool {
		if ( array_keys( $left ) !== array_keys( $right ) ) {
			return false;
		}
		foreach ( $left as $domain => $identity ) {
			if ( ! WP_Markdown_Reconciliation_Identity::equal( $identity, $right[ $domain ] ) ) {
				return false;
			}
		}
		return true;
	}

	private function compare_entries( array $left, array $right ): int {
		return array( (string) ( $left['canonical_path'] ?? '' ), (string) $left['resource_id'] ) <=> array( (string) ( $right['canonical_path'] ?? '' ), (string) $right['resource_id'] );
	}
}
