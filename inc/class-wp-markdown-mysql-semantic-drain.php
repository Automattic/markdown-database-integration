<?php
/** Bounded, ordered durable outbox drain to an event-envelope consumer. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_MySQL_Semantic_Drain {
	private array $failures = array();

	public function __construct( private WP_Markdown_MySQL_Outbox $outbox, private WP_Markdown_MySQL_Impact_Adapter $adapter ) {}

	/**
	 * The consumer must accept one complete envelope atomically or return false.
	 * Retried events retain the same event_id and persisted envelope bytes.
	 */
	public function drain( string $worker_token, callable $consumer, int $limit = 100, int $lease_seconds = 60 ): array {
		$result = array( 'claimed' => 0, 'acknowledged' => 0, 'failed' => 0, 'intents' => 0, 'fenced' => 0 );
		for ( $processed = 0; $processed < max( 1, $limit ); ++$processed ) {
			$records = $this->outbox->claim( $worker_token, 1, $lease_seconds );
			if ( ! $records ) {
				break;
			}
			$record = $records[0];
			++$result['claimed'];
			$stage = 'planning';
			try {
				$envelope = $record['semantic_envelope'] ?? null;
				if ( ! is_array( $envelope ) ) {
					$envelope = array(
						'event_id' => (string) $record['event_id'],
						'outbox_id' => (int) $record['id'],
						'payload_sha256' => (string) ( $record['payload_sha256'] ?? '' ),
						'intents' => $this->adapter->intents( $record ),
					);
					if ( ! $this->outbox->cache_semantic_envelope( (int) $record['id'], $worker_token, (string) $record['lease_token'], $envelope ) ) {
						++$result['fenced'];
						throw new RuntimeException( 'Outbox lease was fenced before envelope persistence.' );
					}
				}
				$stage = 'consumer';
				if ( false === $consumer( $envelope ) ) { throw new RuntimeException( 'Semantic event consumer rejected the envelope.' ); }
				$result['intents'] += count( $envelope['intents'] );
				if ( ! $this->outbox->acknowledge( (int) $record['id'], $worker_token, (string) $record['lease_token'] ) ) {
					++$result['fenced'];
					throw new RuntimeException( 'Outbox lease acknowledgement was fenced.' );
				}
				++$result['acknowledged'];
			} catch ( Throwable $error ) {
				++$result['failed'];
				$failure = array( 'event_id' => (string) ( $record['event_id'] ?? '' ), 'stage' => $stage, 'message' => substr( $error->getMessage(), 0, 1024 ) );
				$this->failures[] = $failure;
				$this->failures = array_slice( $this->failures, -10 );
				if ( ! $this->outbox->fail( (int) $record['id'], $worker_token, (string) $record['lease_token'], (string) json_encode( $failure, JSON_UNESCAPED_SLASHES ) ) ) { ++$result['fenced']; }
				break;
			}
		}
		return $result;
	}

	public function diagnostics(): array { $planner = $this->adapter->planner_diagnostics(); $outbox = $this->outbox->diagnostics(); return array( 'ready' => $this->outbox->is_ready() && ! empty( $planner['ready'] ), 'planner_ready' => ! empty( $planner['ready'] ), 'planner' => $planner, 'planning_failures' => $this->failures, 'durable_planning_failure' => $outbox['planning_failure_sample'] ?? null, 'consumer_contract' => 'atomic_event_envelope', 'publication' => 'not implemented' ); }
}
