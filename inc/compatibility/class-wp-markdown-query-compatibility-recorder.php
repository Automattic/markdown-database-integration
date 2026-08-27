<?php
/** wpdb observation recorder for backend compatibility work. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../class-wp-markdown-wpdb-result-snapshot.php';

final class WP_Markdown_Query_Compatibility_Recorder {
	public const SCHEMA = 'mdi-query-compatibility-corpus/v1';
	private int $sequence = 0;
	/** @var array<int,array<string,mixed>> */
	private array $observations = array();
	/** @var array<int,array{scenario:string,class:string,message:string}> */
	private array $capture_failures = array();

	public function __construct( private WP_Markdown_Query_Compatibility_Normalizer $normalizer ) {}

	/**
	 * Observe one query without changing its return value or caller-visible wpdb state.
	 *
	 * @param callable():mixed      $execute
	 * @param callable():array|null $transaction_state
	 */
	public function capture( string $scenario, string $query, callable $execute, object $database, ?callable $transaction_state = null ): mixed {
		if ( '' === trim( $scenario ) || '' === trim( $query ) ) {
			throw new InvalidArgumentException( 'Query corpus observations require a scenario and SQL.' );
		}
		$before = array();
		$capture_ready = true;
		try {
			$before = null === $transaction_state ? array() : $transaction_state();
		} catch ( Throwable $capture_error ) {
			$this->record_capture_failure( $scenario, $capture_error );
			$capture_ready = false;
		}

		$exception = null;
		try {
			$result = $execute();
		} catch ( Throwable $error ) {
			$result = null;
			$exception = $error;
		}

		try {
			if ( ! $capture_ready ) {
				throw new RuntimeException( 'Query compatibility capture failed before execution.' );
			}
			$after = null === $transaction_state ? array() : $transaction_state();
			$this->observations[] = $this->normalizer->value(
				array(
					'schema_version' => 1,
					'sequence' => ++$this->sequence,
					'scenario' => $scenario,
					'query' => trim( $query ),
					'result' => WP_Markdown_WPDB_Result_Snapshot::capture( $result, $database, $exception ),
					'transaction' => array(
						'before' => $before,
						'after' => $after,
					),
				)
			);
		} catch ( Throwable $capture_error ) {
			if ( $capture_ready ) {
				$this->record_capture_failure( $scenario, $capture_error );
			}
		}

		if ( null !== $exception ) {
			throw $exception;
		}
		return $result;
	}

	/** @return array{schema:string,observations:array<int,array<string,mixed>>} */
	public function document(): array {
		if ( $this->capture_failures ) {
			throw new RuntimeException( 'Query compatibility corpus contains capture failures.' );
		}
		return array(
			'schema' => self::SCHEMA,
			'observations' => $this->observations,
		);
	}

	/** @return array<int,array{scenario:string,class:string,message:string}> */
	public function capture_failures(): array {
		return $this->capture_failures;
	}

	public function json(): string {
		return json_encode( $this->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
	}

	private function record_capture_failure( string $scenario, Throwable $error ): void {
		$this->capture_failures[] = array(
			'scenario' => $scenario,
			'class' => get_class( $error ),
			'message' => $this->normalizer->string( $error->getMessage() ),
		);
	}

}
