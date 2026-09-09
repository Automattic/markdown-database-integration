<?php
/** Bounded comparison of authoritative wpdb reads with mdi-native. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-native-query-runtime.php';
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
		return new WP_Markdown_Native_Shadow_Verifier(
			$runtime,
			$maximum,
			array(
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
	private ?array $first_query_context = null;

	public function __construct(
		private WP_Markdown_Query_Runtime $runtime,
		private int $max_observations = 1000,
		private array $context = array()
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
			$native = $this->runtime->execute(
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
							'reason' => (string) ( $diagnostic['reason'] ?? 'unknown' ),
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
			'first_blocker'    => $this->first_blocker,
			'context'          => array_merge( $this->context, null === $this->first_query_context ? array() : array( 'first_query' => $this->first_query_context ) ),
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
