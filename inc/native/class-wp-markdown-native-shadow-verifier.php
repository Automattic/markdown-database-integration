<?php
/** Bounded comparison of authoritative wpdb reads with mdi-native. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-native-query-runtime.php';
require_once __DIR__ . '/class-wp-markdown-native-authoritative-snapshot-runtime.php';
require_once __DIR__ . '/../compatibility/class-wp-markdown-query-compatibility-comparator.php';
require_once __DIR__ . '/../class-wp-markdown-wpdb-result-snapshot.php';

final class WP_Markdown_Native_Shadow_Factory {
	public static function from_globals( object $database ): WP_Markdown_Native_Shadow_Verifier {
		$state_root = defined( 'MARKDOWN_DB_STATE_DIR' )
			? (string) MARKDOWN_DB_STATE_DIR
			: ( defined( 'MARKDOWN_DB_CONTENT_DIR' ) ? (string) MARKDOWN_DB_CONTENT_DIR : markdown_db_default_content_dir() );
		$base_prefix = (string) ( $database->base_prefix ?? ( $GLOBALS['table_prefix'] ?? ( $database->prefix ?? 'wp_' ) ) );
		$content_root = defined( 'MARKDOWN_DB_CONTENT_DIR' ) ? (string) MARKDOWN_DB_CONTENT_DIR : markdown_db_default_content_dir();
		// db.php runs before WordPress finishes resolving its multisite prefix.
		// The wrapper reads the active wpdb topology when each query is observed.
		$runtime = WP_Markdown_Native_Runtime_Factory::wordpress_runtime( $state_root, $base_prefix, $content_root );
		$maximum = defined( 'MARKDOWN_DB_NATIVE_SHADOW_MAX' ) ? (int) MARKDOWN_DB_NATIVE_SHADOW_MAX : 1000;
		$input_mode = defined( 'MARKDOWN_DB_NATIVE_SHADOW_INPUT_MODE' ) ? (string) MARKDOWN_DB_NATIVE_SHADOW_INPUT_MODE : 'canonical';
		if ( ! in_array( $input_mode, array( 'canonical', 'sql_snapshot' ), true ) ) {
			throw new InvalidArgumentException( 'The native shadow input mode must be canonical or sql_snapshot.' );
		}
		return new WP_Markdown_Native_Shadow_Verifier(
			$runtime,
			$maximum,
			array(
				'comparison' => 'sql_snapshot' === $input_mode ? 'independent_native_sql_over_authoritative_snapshots' : 'native_sql_over_canonical_state',
				'input_mode' => $input_mode,
				'runtime' => 'wordpress-deferred-topology',
				'initial_prefix' => (string) ( $database->prefix ?? '' ),
				'initial_base_prefix' => $base_prefix,
				'state_root_sha256' => hash( 'sha256', $state_root ),
				'content_root_sha256' => hash( 'sha256', $content_root ),
				'state_has_siteurl' => is_file( rtrim( $state_root, '/\\' ) . '/_options/siteurl.json' ),
			)
		);
	}
}

final class WP_Markdown_Native_Shadow_Verifier {
	private const MAX_REPRESENTATIVES = 24;
	private const MAX_REPRESENTATIVE_BYTES = 32768;

	private int $sequence = 0;
	private array $counts = array(
		'compatible'       => 0,
		'unsupported'      => 0,
		'mismatched'       => 0,
		'ignored'          => 0,
		'verifier_failures' => 0,
		'dropped'          => 0,
	);
	private array $classification_counts = array(
		'native_execution'         => 0,
		'snapshot_input_limitation' => 0,
		'row_value_or_count'       => 0,
		'column_metadata_or_types' => 0,
		'result_metadata'          => 0,
	);
	private ?array $first_blocker = null;
	private ?array $first_query_context = null;
	private ?array $last_input_state = null;
	/** @var array<string,array<string,mixed>> */
	private array $representatives = array();
	private int $representative_bytes = 0;
	private string $input_mode;
	/** @var array<string,WP_Markdown_Native_Authoritative_Snapshot_Runtime> */
	private array $pending_inputs = array();
	/** @var array<string,array{code:string,reason:string}> */
	private array $pending_input_failures = array();

	public function __construct(
		private WP_Markdown_Query_Runtime $runtime,
		private int $max_observations = 1000,
		private array $context = array()
	) {
		if ( $this->max_observations < 1 ) {
			throw new InvalidArgumentException( 'The native shadow observation bound must be positive.' );
		}
		$this->input_mode = (string) ( $this->context['input_mode'] ?? 'canonical' );
	}

	/** Capture source rows before wpdb sends the observed SELECT to MySQL. */
	public function capture_input( string $query, object $database ): void {
		if ( $this->sequence >= $this->max_observations || 'sql_snapshot' !== $this->input_mode || 1 !== preg_match( '/^\s*SELECT\b/i', $query ) ) {
			return;
		}
		$prefix = $this->query_prefix( $database );
		$key = hash( 'sha256', $query );
		try {
			$this->pending_inputs[ $key ] = WP_Markdown_Native_Authoritative_Snapshot_Runtime::capture( $database, $query, $prefix );
			unset( $this->pending_input_failures[ $key ] );
		} catch ( WP_Markdown_Native_Snapshot_Input_Exception $error ) {
			// Input capture is observational and must never interrupt wpdb's query.
			unset( $this->pending_inputs[ $key ] );
			$this->pending_input_failures[ $key ] = $error->diagnostic();
		} catch ( Throwable $error ) {
			unset( $this->pending_inputs[ $key ] );
			$this->pending_input_failures[ $key ] = array( 'code' => 'markdown_db_native_snapshot_input_unavailable', 'reason' => 'snapshot_capture_failed' );
		}
	}

	public function observe( string $query, mixed $return_value, object $database ): void {
		$key = hash( 'sha256', $query );
		$input = $this->pending_inputs[ $key ] ?? null;
		$input_failure = $this->pending_input_failures[ $key ] ?? null;
		unset( $this->pending_inputs[ $key ], $this->pending_input_failures[ $key ] );
		if ( $this->sequence >= $this->max_observations ) {
			++$this->counts['dropped'];
			return;
		}
		++$this->sequence;
		$prefix = $this->query_prefix( $database );
		$this->first_query_context ??= array(
			'prefix' => $prefix,
			'base_prefix' => (string) ( $database->base_prefix ?? '' ),
			'multisite' => ( defined( 'MULTISITE' ) && MULTISITE ) || ( function_exists( 'is_multisite' ) && is_multisite() ),
		);

		if ( 1 !== preg_match( '/^\s*SELECT\b/i', $query ) ) {
			++$this->counts['ignored'];
			return;
		}

		try {
			$runtime = $this->runtime;
			if ( 'sql_snapshot' === $this->input_mode ) {
				if ( is_array( $input_failure ) ) {
					++$this->counts['unsupported'];
					$this->retain_blocker( 'unsupported', $query, array( 'native_diagnostic' => array( 'code' => $this->safe_reason( (string) ( $input_failure['code'] ?? 'markdown_db_native_snapshot_input_unavailable' ) ), 'reason' => $this->safe_reason( (string) ( $input_failure['reason'] ?? 'unknown' ) ) ) ) );
					return;
				}
				$runtime = $input ?? WP_Markdown_Native_Authoritative_Snapshot_Runtime::capture( $database, $query, $prefix );
				$this->last_input_state = $runtime->provenance();
			}
			$native = $runtime->execute(
				new WP_Markdown_Query_Request( $query, $prefix )
			);
			if ( ! $native->succeeded() ) {
				$diagnostic = $native->diagnostic() ?? array();
				$status = 'markdown_db_native_unsupported_query' === ( $diagnostic['code'] ?? '' )
					? 'unsupported'
					: 'mismatched';
				++$this->counts[ $status ];
				$this->retain_blocker(
					$status,
					$query,
					array(
						'native_diagnostic' => array(
							'code'   => (string) ( $diagnostic['code'] ?? 'markdown_db_native_unknown_failure' ),
							'reason' => $this->safe_reason( (string) ( $diagnostic['reason'] ?? 'unknown' ) ),
						),
					)
				);
				return;
			}

			$comparison = WP_Markdown_Query_Compatibility_Comparator::compare(
				WP_Markdown_WPDB_Result_Snapshot::capture( $return_value, $database, null, true ),
				$native->corpus_result()
			);
			if ( $comparison['compatible'] ) {
				++$this->counts['compatible'];
				return;
			}

			++$this->counts['mismatched'];
			$paths = array_map(
				static fn( array $mismatch ): string => (string) $mismatch['path'],
				array_slice( $comparison['mismatches'], 0, 20 )
			);
			$this->retain_blocker(
				'mismatched',
				$query,
				array(
					'mismatch_paths'     => $paths,
					'mismatches_truncated' => count( $comparison['mismatches'] ) > count( $paths ),
				)
			);
		} catch ( Throwable $error ) {
			++$this->counts['verifier_failures'];
			$this->retain_blocker(
				'verifier_failure',
				$query,
				array( 'failure_class' => get_class( $error ), 'failure_reason' => $this->failure_reason( $error ) )
			);
		}
	}

	/** @return array<string,mixed> */
	public function report(): array {
		return array(
			'schema'           => 'mdi-native-shadow-report/v1',
			'max_observations' => $this->max_observations,
			'observed'         => $this->sequence,
			'counts'           => $this->counts,
			'classifications'   => $this->classification_counts,
			'first_blocker'    => $this->first_blocker,
			'representatives'  => array_values( $this->representatives ),
			'context'          => array_merge( $this->context, null === $this->first_query_context ? array() : array( 'first_query' => $this->first_query_context ), null === $this->last_input_state ? array() : array( 'last_input_state' => $this->last_input_state ) ),
		);
	}

	private function failure_reason( Throwable $error ): string {
		$message = $error->getMessage();
		if ( str_contains( $message, 'canonical state root' ) ) {
			return 'invalid_canonical_state_root';
		}
		if ( str_contains( $message, 'table prefix' ) ) {
			return 'invalid_table_prefix';
		}
		return 'native_verifier_exception';
	}

	private function query_prefix( object $database ): string {
		foreach ( array( $database->prefix ?? null, $database->base_prefix ?? null, $GLOBALS['table_prefix'] ?? null, 'wp_' ) as $prefix ) {
			if ( is_string( $prefix ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/D', $prefix ) ) {
				return $prefix;
			}
		}
		return 'wp_';
	}

	/** @param array<string,mixed> $details */
	private function retain_blocker( string $status, string $query, array $details ): void {
		$template = $this->query_template( $query );
		$classification = $this->classification( $status, $details );
		++$this->classification_counts[ $classification ];
		$blocker = array_merge(
			array(
				'sequence'              => $this->sequence,
				'status'                => $status,
				'query_template_sha256' => hash( 'sha256', $template ),
				'query_template'        => $template,
				'classification'         => $classification,
			),
			$details
		);
		$this->first_blocker ??= $blocker;
		$this->retain_representative( $blocker );
	}

	/** @param array<string,mixed> $blocker */
	private function retain_representative( array $blocker ): void {
		$key = $blocker['status'] . ':' . $blocker['classification'] . ':' . $blocker['query_template_sha256'];
		if ( isset( $this->representatives[ $key ] ) ) {
			$before = strlen( json_encode( $this->representatives[ $key ], JSON_THROW_ON_ERROR ) );
			++$this->representatives[ $key ]['count'];
			$after = strlen( json_encode( $this->representatives[ $key ], JSON_THROW_ON_ERROR ) );
			if ( $this->representative_bytes + $after - $before > self::MAX_REPRESENTATIVE_BYTES ) {
				--$this->representatives[ $key ]['count'];
				return;
			}
			$this->representative_bytes += $after - $before;
			return;
		}
		if ( count( $this->representatives ) >= self::MAX_REPRESENTATIVES ) {
			return;
		}
		$representative = array_merge( array( 'count' => 1 ), $blocker );
		$bytes = strlen( json_encode( $representative, JSON_THROW_ON_ERROR ) );
		if ( $bytes > self::MAX_REPRESENTATIVE_BYTES || $this->representative_bytes + $bytes > self::MAX_REPRESENTATIVE_BYTES ) {
			return;
		}
		$this->representatives[ $key ] = $representative;
		$this->representative_bytes += $bytes;
	}

	/** @param array<string,mixed> $details */
	private function classification( string $status, array $details ): string {
		if ( 'unsupported' === $status && 'markdown_db_native_snapshot_input_unavailable' === ( $details['native_diagnostic']['code'] ?? null ) ) {
			return 'snapshot_input_limitation';
		}
		if ( isset( $details['mismatch_paths'] ) ) {
			foreach ( $details['mismatch_paths'] as $path ) {
				if ( str_starts_with( (string) $path, '$.rows' ) || '$.num_rows' === $path ) {
					return 'row_value_or_count';
				}
			}
			foreach ( $details['mismatch_paths'] as $path ) {
				if ( str_starts_with( (string) $path, '$.columns' ) ) {
					return 'column_metadata_or_types';
				}
			}
			return 'result_metadata';
		}
		return 'native_execution';
	}

	private function safe_reason( string $reason ): string {
		$reason = (string) preg_replace( '/[^A-Za-z0-9_.-]/', '_', $reason );
		return '' === $reason ? 'unknown' : substr( $reason, 0, 128 );
	}

	private function query_template( string $query ): string {
		$template = '';
		$length = strlen( $query );
		for ( $index = 0; $index < $length; ++$index ) {
			$character = $query[ $index ];
			if ( "'" === $character || '"' === $character ) {
				$quote = $character;
				$template .= '?';
				for ( ++$index; $index < $length; ++$index ) {
					if ( '\\' === $query[ $index ] ) {
						++$index;
						continue;
					}
					if ( $quote === $query[ $index ] ) {
						if ( $index + 1 < $length && $quote === $query[ $index + 1 ] ) {
							++$index;
							continue;
						}
						break;
					}
				}
				continue;
			}
			if ( '/' === $character && $index + 1 < $length && '*' === $query[ $index + 1 ] ) {
				$template .= ' ';
				$end = strpos( $query, '*/', $index + 2 );
				if ( false === $end ) {
					break;
				}
				$index = $end + 1;
				continue;
			}
			if ( '#' === $character || ( '-' === $character && $index + 1 < $length && '-' === $query[ $index + 1 ] ) ) {
				$template .= ' ';
				$end = strcspn( $query, "\r\n", $index );
				$index += $end;
				continue;
			}
			if ( '0' === $character && $index + 2 < $length && ( 'x' === strtolower( $query[ $index + 1 ] ) ) && ctype_xdigit( $query[ $index + 2 ] ) ) {
				$template .= '?';
				++$index;
				while ( $index + 1 < $length && ctype_xdigit( $query[ $index + 1 ] ) ) {
					++$index;
				}
				continue;
			}
			if ( ( ctype_digit( $character ) || ( '.' === $character && $index + 1 < $length && ctype_digit( $query[ $index + 1 ] ) ) ) && ( 0 === $index || ! ctype_alnum( $query[ $index - 1 ] ) && '_' !== $query[ $index - 1 ] ) ) {
				if ( 1 === preg_match( '/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?/', substr( $query, $index ), $number ) ) {
					$template .= '?';
					$index += strlen( $number[0] ) - 1;
					continue;
				}
			}
			$template .= $character;
		}
		$template = trim( (string) preg_replace( '/\s+/', ' ', (string) $template ) );
		$template = (string) preg_replace( '/[^\x20-\x7E]/', '?', $template );
		return strlen( $template ) > 500 ? substr( $template, 0, 500 ) . '...' : $template;
	}
}
