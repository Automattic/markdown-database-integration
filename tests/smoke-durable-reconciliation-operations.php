<?php
/** Durable reconciliation operation smoke test. Usage: php tests/smoke-durable-reconciliation-operations.php */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-durable-reconciliation-operations.php';

final class MDI_Durable_Test_Adapter implements WP_Markdown_Reconciliation_Adapter {
	public array $state;
	public array $before;
	public array $after;
	public int $apply_calls = 0;
	public int $current_fence = 0;
	public bool $diverge = false;
	public bool $observation_fails = false;
	public bool $apply_throws_after_effect = false;
	public bool $partial_effect = false;

	public function __construct( array $before, array $after ) {
		$this->state  = $before;
		$this->before = $before;
		$this->after  = $after;
	}

	public function observe( array $operation ): array {
		unset( $operation );
		if ( $this->observation_fails ) {
			throw new RuntimeException( 'Injected observation failure.' );
		}
		$observed = array();
		foreach ( $this->state as $domain => $value ) {
			$observed[ $domain ] = WP_Markdown_Reconciliation_Identity::exact( $value );
		}
		return $observed;
	}

	public function fence( array $operation ): void {
		$this->current_fence = max( $this->current_fence, (int) $operation['fence'] );
	}

	public function apply( array $operation ): void {
		if ( (int) $operation['fence'] !== $this->current_fence ) {
			throw new WP_Markdown_Reconciliation_Store_Conflict( 'A newer owner fenced this mutation.' );
		}
		++$this->apply_calls;
		$this->state = $this->diverge ? array( 'file' => array( 'path' => 'other.md' ), 'wordpress' => array( 'id' => 999 ) ) : $this->after;
		if ( $this->partial_effect ) {
			$domains = array_keys( $this->state );
			$this->state[ $domains[1] ] = $this->before[ $domains[1] ];
			throw new RuntimeException( 'Injected crash between durability domains.' );
		}
		if ( $this->apply_throws_after_effect ) {
			throw new RuntimeException( 'Injected post-effect adapter failure.' );
		}
	}
}

final class MDI_Durable_Test_Crash extends RuntimeException {}

$passed = 0;
$failed = 0;

function mdi_durable_check( bool $condition, string $message ): void {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "PASS: $message\n";
		return;
	}
	++$failed;
	echo "FAIL: $message\n";
}

function mdi_durable_remove( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( scandir( $path ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$child = $path . '/' . $entry;
		is_dir( $child ) ? mdi_durable_remove( $child ) : unlink( $child );
	}
	rmdir( $path );
}

function mdi_durable_store( string $directory, array $roots = array(), int $limit = 100 ): WP_Markdown_Filesystem_Reconciliation_Operation_Store {
	if ( array() === $roots ) {
		$roots = array( MDI_DURABLE_CANONICAL_ROOT );
	}
	return new WP_Markdown_Filesystem_Reconciliation_Operation_Store( $directory, str_repeat( 'server-secret-', 4 ), $roots, $limit, 262144 );
}

function mdi_durable_plan( string $kind, array $before, array $after, int $sequence ): array {
	$identities = static function ( array $state ): array {
		$result = array();
		foreach ( $state as $domain => $value ) {
			$result[ $domain ] = WP_Markdown_Reconciliation_Identity::exact( $value );
		}
		return $result;
	};
	return array(
		'plan_id'        => 'plan-' . $sequence,
		'continuation'    => array( 'page' => 3, 'offset' => $sequence ),
		'canonical_root'  => MDI_DURABLE_CANONICAL_ROOT,
		'resource'        => array( 'type' => 'post', 'id' => '42' ),
		'kind'            => $kind,
		'direction'       => str_contains( $kind, 'canonical-to-wordpress' ) ? 'canonical_to_wordpress' : 'wordpress_to_canonical',
		'before'          => $identities( $before ),
		'after'           => $identities( $after ),
		'created_at'      => 1000 + $sequence,
	);
}

$root = tempnam( sys_get_temp_dir(), 'mdi-durable-operations-' );
if ( false === $root || ! unlink( $root ) || ! mkdir( $root, 0700 ) ) {
	throw new RuntimeException( 'Unable to create the durable reconciliation test directory.' );
}
define( 'MDI_DURABLE_CANONICAL_ROOT', $root . '/canonical-site' );
register_shutdown_function( static fn() => mdi_durable_remove( $root ) );

$kinds = array(
	'create'                           => array(
		array( 'file' => null, 'wordpress' => null ),
		array( 'file' => array( 'path' => 'post/created.md', 'hash' => 'a' ), 'wordpress' => array( 'id' => 42, 'hash' => 'a' ) ),
	),
	'update'                           => array(
		array( 'file' => array( 'path' => 'post/item.md', 'hash' => 'a' ), 'wordpress' => array( 'id' => 42, 'hash' => 'a' ) ),
		array( 'file' => array( 'path' => 'post/item.md', 'hash' => 'b' ), 'wordpress' => array( 'id' => 42, 'hash' => 'b' ) ),
	),
	'move'                             => array(
		array( 'file' => array( 'path' => 'post/old.md', 'hash' => 'a' ), 'wordpress' => array( 'id' => 42, 'slug' => 'old' ) ),
		array( 'file' => array( 'path' => 'post/new.md', 'hash' => 'a' ), 'wordpress' => array( 'id' => 42, 'slug' => 'new' ) ),
	),
	'canonical-to-wordpress-deletion' => array(
		array( 'file' => null, 'wordpress' => array( 'id' => 42, 'hash' => 'a' ) ),
		array( 'file' => null, 'wordpress' => null ),
	),
	'wordpress-to-canonical-deletion' => array(
		array( 'file' => array( 'path' => 'post/item.md', 'hash' => 'a' ), 'wordpress' => null ),
		array( 'file' => null, 'wordpress' => null ),
	),
);
$boundaries = array( 'planned', 'claimed', 'effect_applied', 'effect_observed', 'completed' );
$sequence   = 0;

foreach ( $kinds as $kind => $states ) {
	list( $before, $after ) = $states;
	foreach ( $boundaries as $boundary ) {
		$directory = $root . '/matrix-' . ++$sequence;
		$store     = mdi_durable_store( $directory );
		$service   = new WP_Markdown_Durable_Reconciliation_Operations( $store );
		$adapter   = new MDI_Durable_Test_Adapter( $before, $after );
		$record    = $service->plan( mdi_durable_plan( $kind, $before, $after, $sequence ) );
		try {
			$service->execute(
				$record['id'],
				'worker-a',
				2000,
				10,
				$adapter,
				static function ( string $at ) use ( $boundary ): void {
					if ( $at === $boundary ) {
						throw new MDI_Durable_Test_Crash( $at );
					}
				}
			);
		} catch ( MDI_Durable_Test_Crash ) {
		}

		$apply_calls = $adapter->apply_calls;
		$store       = mdi_durable_store( $directory );
		$service     = new WP_Markdown_Durable_Reconciliation_Operations( $store );
		try {
			$recovered = $service->recover( $record['id'], 'worker-b', 2011, 10, $adapter );
			$state     = $recovered['state'];
		} catch ( WP_Markdown_Reconciliation_Conflict $error ) {
			$state = $store->get( $record['id'] )['state'] ?? '';
		}
		$effect_happened = in_array( $boundary, array( 'effect_applied', 'effect_observed', 'completed' ), true );
		mdi_durable_check( $adapter->apply_calls === $apply_calls, "$kind/$boundary recovery does not replay the mutation" );
		mdi_durable_check( ( $effect_happened ? 'completed' : 'reconciliation_required' ) === $state, "$kind/$boundary recovers to the fail-closed lifecycle outcome" );
	}

	$changed_domains = array_filter( array_keys( $before ), static fn( string $domain ): bool => $before[ $domain ] !== $after[ $domain ] );
	if ( count( $changed_domains ) > 1 ) {
		$directory = $root . '/partial-' . ++$sequence;
		$store     = mdi_durable_store( $directory );
		$service   = new WP_Markdown_Durable_Reconciliation_Operations( $store );
		$adapter   = new MDI_Durable_Test_Adapter( $before, $after );
		$adapter->partial_effect = true;
		$record = $service->plan( mdi_durable_plan( $kind, $before, $after, $sequence ) );
		$partial_conflict = false;
		try {
			$service->execute( $record['id'], 'worker-a', 2100, 10, $adapter );
		} catch ( WP_Markdown_Reconciliation_Conflict ) {
			$partial_conflict = true;
		}
		$apply_calls = $adapter->apply_calls;
		$store       = mdi_durable_store( $directory );
		$service     = new WP_Markdown_Durable_Reconciliation_Operations( $store );
		$service->recover( $record['id'], 'worker-b', 2111, 10, $adapter );
		mdi_durable_check( $partial_conflict && $adapter->apply_calls === $apply_calls && 'reconciliation_required' === $store->get( $record['id'] )['state'], "$kind partial cross-domain effect fails closed without replay" );
	}
}

// Competing claims and expired-lease fencing reject stale owners deterministically.
$store   = mdi_durable_store( $root . '/claims' );
$service = new WP_Markdown_Durable_Reconciliation_Operations( $store );
$before  = array( 'file' => null, 'wordpress' => null );
$after   = array( 'file' => array( 'path' => 'post/fenced.md' ), 'wordpress' => array( 'id' => 42 ) );
$record  = $service->plan( mdi_durable_plan( 'create', $before, $after, ++$sequence ) );
$first   = $store->claim( $record['id'], $record['revision'], 'worker-a', 100, 10 );
$competing_rejected = false;
try {
	$store->claim( $record['id'], $first['revision'], 'worker-b', 105, 10 );
} catch ( WP_Markdown_Reconciliation_Store_Conflict ) {
	$competing_rejected = true;
}
$second = $store->claim( $record['id'], $first['revision'], 'worker-b', 110, 10 );
$stale_fenced = false;
try {
	$store->compare_and_set(
		$record['id'],
		array( 'revision' => $first['revision'], 'state' => 'claimed', 'owner' => 'worker-a', 'fence' => $first['fence'] ),
		array( 'state' => 'effect_observed' ),
		105
	);
} catch ( WP_Markdown_Reconciliation_Store_Conflict ) {
	$stale_fenced = true;
}
mdi_durable_check( $competing_rejected, 'an active lease prevents a competing claim' );
mdi_durable_check( $second['fence'] > $first['fence'] && $stale_fenced, 'an expired lease receives a newer fence and rejects the stale owner' );

$fenced_adapter = new MDI_Durable_Test_Adapter( $before, $after );
$fenced_adapter->fence( $first );
$fenced_adapter->fence( $second );
$stale_effect_rejected = false;
try {
	$fenced_adapter->apply( $first );
} catch ( WP_Markdown_Reconciliation_Store_Conflict ) {
	$stale_effect_rejected = true;
}
mdi_durable_check( $stale_effect_rejected && 0 === $fenced_adapter->apply_calls, 'the owning adapter rejects a stale fence at the mutation boundary' );

$expired_transition_rejected = false;
try {
	$store->compare_and_set(
		$record['id'],
		array( 'revision' => $second['revision'], 'state' => 'claimed', 'owner' => 'worker-b', 'fence' => $second['fence'] ),
		array( 'state' => 'effect_observed' ),
		120
	);
} catch ( WP_Markdown_Reconciliation_Store_Conflict ) {
	$expired_transition_rejected = true;
}
mdi_durable_check( $expired_transition_rejected, 'an expired owner cannot publish a transition before reclamation' );

// Equivalent associative values normalize to the same exact identity.
$left  = WP_Markdown_Reconciliation_Identity::exact( array( 'id' => 42, 'value' => array( 'b' => 2, 'a' => 1 ) ) );
$right = WP_Markdown_Reconciliation_Identity::exact( array( 'value' => array( 'a' => 1, 'b' => 2 ), 'id' => 42 ) );
mdi_durable_check( WP_Markdown_Reconciliation_Identity::equal( $left, $right ), 'resource observations use deterministic normalized identities' );

// Divergent post-effect state is recorded as a structured conflict without replay.
$store   = mdi_durable_store( $root . '/ambiguous' );
$service = new WP_Markdown_Durable_Reconciliation_Operations( $store );
$adapter = new MDI_Durable_Test_Adapter( $before, $after );
$adapter->diverge = true;
$record  = $service->plan( mdi_durable_plan( 'update', $before, $after, ++$sequence ) );
$structured = false;
try {
	$service->execute( $record['id'], 'worker-a', 300, 10, $adapter );
} catch ( WP_Markdown_Reconciliation_Conflict $error ) {
	$conflict   = $error->conflict();
	$structured = 'after_state_not_proven' === ( $conflict['reason'] ?? '' ) && isset( $conflict['expected'], $conflict['actual'] );
}
mdi_durable_check( 1 === $adapter->apply_calls && $structured && 'reconciliation_required' === $store->get( $record['id'] )['state'], 'ambiguous effects become a structured reconciliation_required conflict' );

// An indeterminate observation is persisted rather than left as an opaque retry loop.
$store   = mdi_durable_store( $root . '/indeterminate' );
$service = new WP_Markdown_Durable_Reconciliation_Operations( $store );
$adapter = new MDI_Durable_Test_Adapter( $before, $after );
$adapter->observation_fails = true;
$record = $service->plan( mdi_durable_plan( 'update', $before, $after, ++$sequence ) );
$indeterminate = false;
try {
	$service->execute( $record['id'], 'worker-a', 400, 10, $adapter );
} catch ( WP_Markdown_Reconciliation_Conflict $error ) {
	$indeterminate = 'observation_indeterminate' === ( $error->conflict()['reason'] ?? '' );
}
mdi_durable_check( $indeterminate && 'reconciliation_required' === $store->get( $record['id'] )['state'], 'indeterminate observations fail closed as structured conflicts' );

$store   = mdi_durable_store( $root . '/apply-exception' );
$service = new WP_Markdown_Durable_Reconciliation_Operations( $store );
$adapter = new MDI_Durable_Test_Adapter( $before, $after );
$adapter->apply_throws_after_effect = true;
$record = $service->plan( mdi_durable_plan( 'update', $before, $after, ++$sequence ) );
$completed = $service->execute( $record['id'], 'worker-a', 450, 10, $adapter );
mdi_durable_check( 1 === $adapter->apply_calls && 'completed' === $completed['state'], 'an apply exception completes only when observation still proves the exact after-state' );

// The journal is bounded, authenticated, and cannot overlap a canonical root.
$bounded_store   = mdi_durable_store( $root . '/bounded', array(), 1 );
$bounded_service = new WP_Markdown_Durable_Reconciliation_Operations( $bounded_store );
$bounded_service->plan( mdi_durable_plan( 'create', $before, $after, ++$sequence ) );
$bounded = false;
try {
	$bounded_service->plan( mdi_durable_plan( 'create', $before, $after, ++$sequence ) );
} catch ( OverflowException ) {
	$bounded = true;
}
mdi_durable_check( $bounded, 'operation storage enforces its durable record bound' );

$canonical = $root . '/canonical';
mkdir( $canonical, 0700, true );
$overlap_rejected = false;
try {
	mdi_durable_store( $canonical . '/operations', array( $canonical ) );
} catch ( InvalidArgumentException ) {
	$overlap_rejected = true;
}
mdi_durable_check( $overlap_rejected, 'operation storage is rejected inside a managed canonical root' );

$future_overlap_rejected = false;
try {
	mdi_durable_store( $root . '/future-parent', array( $root . '/future-parent/not-created-yet' ) );
} catch ( InvalidArgumentException ) {
	$future_overlap_rejected = true;
}
mdi_durable_check( $future_overlap_rejected, 'lexical overlap is rejected for a canonical root that does not yet exist' );

$unauthorized_root_rejected = false;
try {
	$root_store = mdi_durable_store( $root . '/authorized-roots', array( '/srv/canonical/other-site' ) );
	$root_store->plan( mdi_durable_plan( 'create', $before, $after, ++$sequence ) );
} catch ( InvalidArgumentException ) {
	$unauthorized_root_rejected = true;
}
mdi_durable_check( $unauthorized_root_rejected, 'operation identity must bind a canonical root authorized by its store' );

$domain_mismatch_rejected = false;
try {
	$invalid_plan          = mdi_durable_plan( 'create', $before, $after, ++$sequence );
	$invalid_plan['after'] = array( 'file' => $invalid_plan['after']['file'] );
	mdi_durable_store( $root . '/domains' )->plan( $invalid_plan );
} catch ( InvalidArgumentException ) {
	$domain_mismatch_rejected = true;
}
mdi_durable_check( $domain_mismatch_rejected, 'before and after proof must cover identical durability domains' );

$resource_shape_rejected = false;
try {
	$invalid_plan = mdi_durable_plan( 'create', $before, $after, ++$sequence );
	$invalid_plan['resource']['id'] = 42;
	mdi_durable_store( $root . '/resource-shape' )->plan( $invalid_plan );
} catch ( InvalidArgumentException ) {
	$resource_shape_rejected = true;
}
mdi_durable_check( $resource_shape_rejected, 'resource identities require backend-neutral normalized strings' );

$auth_directory = $root . '/authenticated';
$auth_store     = mdi_durable_store( $auth_directory );
$auth_service   = new WP_Markdown_Durable_Reconciliation_Operations( $auth_store );
$auth_record    = $auth_service->plan( mdi_durable_plan( 'create', $before, $after, ++$sequence ) );
$journal        = $auth_directory . '/operations.json';
$bytes          = (string) file_get_contents( $journal );
$bytes[ strlen( $bytes ) - 2 ] = 'x' === $bytes[ strlen( $bytes ) - 2 ] ? 'y' : 'x';
file_put_contents( $journal, $bytes );
$tamper_rejected = false;
try {
	$auth_store->get( $auth_record['id'] );
} catch ( Throwable ) {
	$tamper_rejected = true;
}
mdi_durable_check( $tamper_rejected, 'journal tampering fails authenticated reads closed' );

echo "Durable reconciliation checks: $passed passed, $failed failed.\n";
exit( $failed > 0 ? 1 : 0 );
