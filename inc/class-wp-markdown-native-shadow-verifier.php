<?php
/** Bounded comparison of authoritative wpdb reads with mdi-native. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-native-query-runtime.php';
require_once __DIR__ . '/class-wp-markdown-query-compatibility-comparator.php';

final class WP_Markdown_Native_Shadow_Verifier {
	private int $sequence = 0;
	private array $counts = array(
		'compatible'       => 0,
		'unsupported'      => 0,
		'mismatched'       => 0,
		'ignored'          => 0,
		'verifier_failures' => 0,
		'dropped'          => 0,
	);
	private ?array $first_blocker = null;

	public function __construct(
		private WP_Markdown_Query_Runtime $runtime,
		private int $max_observations = 1000
	) {
		if ( $this->max_observations < 1 ) {
			throw new InvalidArgumentException( 'The native shadow observation bound must be positive.' );
		}
	}

	public function observe( string $query, mixed $return_value, object $database ): void {
		if ( $this->sequence >= $this->max_observations ) {
			++$this->counts['dropped'];
			return;
		}
		++$this->sequence;

		if ( 1 !== preg_match( '/^\s*SELECT\b/i', $query ) ) {
			++$this->counts['ignored'];
			return;
		}

		try {
			$native = $this->runtime->execute(
				new WP_Markdown_Query_Request( $query, (string) ( $database->prefix ?? 'wp_' ) )
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
							'reason' => (string) ( $diagnostic['reason'] ?? 'unknown' ),
						),
					)
				);
				return;
			}

			$comparison = WP_Markdown_Query_Compatibility_Comparator::compare(
				$this->authoritative_result( $return_value, $database ),
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
				array( 'failure_class' => get_class( $error ) )
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
			'first_blocker'    => $this->first_blocker,
		);
	}

	/** @return array<string,mixed> */
	private function authoritative_result( mixed $return_value, object $database ): array {
		if ( ! is_int( $return_value ) && ! is_bool( $return_value ) ) {
			throw new RuntimeException( 'Authoritative query results must use wpdb scalar returns.' );
		}
		$rows = array();
		foreach ( $database->last_result ?? array() as $row ) {
			if ( ! is_array( $row ) && ! is_object( $row ) ) {
				throw new RuntimeException( 'Authoritative query rows must be arrays or objects.' );
			}
			$rows[] = (array) $row;
		}

		return array(
			'return' => array(
				'type'  => is_bool( $return_value ) ? 'boolean' : 'integer',
				'value' => $return_value,
			),
			'rows'          => $rows,
			'columns'       => $this->columns( $database ),
			'last_error'    => (string) ( $database->last_error ?? '' ),
			'error_code'    => isset( $database->last_errno ) ? $database->last_errno : 0,
			'insert_id'     => (int) ( $database->insert_id ?? 0 ),
			'rows_affected' => (int) ( $database->rows_affected ?? 0 ),
			'num_rows'      => (int) ( $database->num_rows ?? 0 ),
			'exception'     => null,
		);
	}

	/** @return array<int,array{name:string,type:string|null}> */
	private function columns( object $database ): array {
		if ( ! method_exists( $database, 'get_col_info' ) ) {
			return array();
		}
		try {
			$property = new ReflectionProperty( $database, 'col_info' );
			$before = $property->getValue( $database );
		} catch ( ReflectionException $error ) {
			return array();
		}

		try {
			$names = (array) $database->get_col_info( 'name' );
			$types = (array) $database->get_col_info( 'type' );
			$columns = array();
			foreach ( $names as $index => $name ) {
				$columns[] = array(
					'name' => (string) $name,
					'type' => isset( $types[ $index ] ) ? (string) $types[ $index ] : null,
				);
			}
			return $columns;
		} finally {
			$property->setValue( $database, $before );
		}
	}

	/** @param array<string,mixed> $details */
	private function retain_blocker( string $status, string $query, array $details ): void {
		if ( null !== $this->first_blocker ) {
			return;
		}
		$template = $this->query_template( $query );
		$this->first_blocker = array_merge(
			array(
				'sequence'              => $this->sequence,
				'status'                => $status,
				'query_template_sha256' => hash( 'sha256', $template ),
				'query_template'        => $template,
			),
			$details
		);
	}

	private function query_template( string $query ): string {
		$template = preg_replace( '/\/\*.*?\*\/|--[^\r\n]*|#[^\r\n]*/s', ' ', $query );
		$template = preg_replace( '/\b0x[0-9A-Fa-f]+\b/i', '?', (string) $template );
		$template = preg_replace( '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', '?', (string) $template );
		$template = preg_replace( '/(?<![A-Za-z0-9_])[0-9]+(?![A-Za-z0-9_])/', '?', (string) $template );
		$template = trim( (string) preg_replace( '/\s+/', ' ', (string) $template ) );
		$template = (string) preg_replace( '/[^\x20-\x7E]/', '?', $template );
		return strlen( $template ) > 500 ? substr( $template, 0, 500 ) . '...' : $template;
	}
}
