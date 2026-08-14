<?php
/**
 * Backend capability contract.
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_Unsupported_Backend_Capability extends LogicException {

	/**
	 * @var array{code:string,backend:string,capability:string,message:string}
	 */
	private array $diagnostic;

	/**
	 * @param array{code:string,backend:string,capability:string,message:string} $diagnostic Structured failure details.
	 */
	public function __construct( array $diagnostic ) {
		$this->diagnostic = $diagnostic;
		parent::__construct( $diagnostic['message'] );
	}

	/**
	 * @return array{code:string,backend:string,capability:string,message:string}
	 */
	public function get_diagnostic(): array {
		return $this->diagnostic;
	}
}

class WP_Markdown_Unknown_Backend extends LogicException {

	/** @var array{code:string,backend:string,message:string} */
	private array $diagnostic;

	/** @param array{code:string,backend:string,message:string} $diagnostic Structured failure details. */
	public function __construct( array $diagnostic ) {
		$this->diagnostic = $diagnostic;
		parent::__construct( $diagnostic['message'] );
	}

	/** @return array{code:string,backend:string,message:string} */
	public function get_diagnostic(): array {
		return $this->diagnostic;
	}
}

class WP_Markdown_Backend_Capabilities {

	private const KNOWN = array(
		'content_mutation_capture',
		'table_mutation_capture',
		'schema_persistence',
		'cold_reconstruction',
		'disposable_index_operation',
		'lazy_post_content_resolution',
		'explicit_flush',
		'changed_path_receipts',
	);

	private string $backend;

	/** @var array<string, bool> */
	private array $capabilities;

	/**
	 * @param array<string, bool> $capabilities Capability declarations. Omitted known capabilities fail closed.
	 */
	public function __construct( string $backend, array $capabilities = array() ) {
		if ( '' === $backend ) {
			throw new InvalidArgumentException( 'A backend identifier is required.' );
		}

		$this->backend      = $backend;
		$this->capabilities = array_fill_keys( self::KNOWN, false );
		foreach ( $capabilities as $capability => $supported ) {
			if ( ! in_array( $capability, self::KNOWN, true ) ) {
				throw new InvalidArgumentException( 'Unknown backend capability: ' . $capability );
			}
			$this->capabilities[ $capability ] = (bool) $supported;
		}
	}

	/**
	 * SQLite is the shipped reference backend. Keep this declaration complete
	 * while future backends are extracted behind this contract.
	 */
	public static function sqlite(): self {
		return new self( 'sqlite', array_fill_keys( self::KNOWN, true ) );
	}

	/** Content-primary MySQL deliberately excludes full-table interception. */
	public static function mysql_content(): self {
		return new self( 'mysql-content', array(
			'content_mutation_capture' => true,
			'cold_reconstruction'      => true,
			'explicit_flush'           => true,
			'changed_path_receipts'    => true,
		) );
	}

	public function get_backend(): string {
		return $this->backend;
	}

	public function supports( string $capability ): bool {
		return $this->capabilities[ $capability ] ?? false;
	}

	/**
	 * Require a capability before executing a backend-specific operation.
	 * Unknown and undeclared capabilities are unsupported by default.
	 */
	public function require( string $capability ): void {
		if ( $this->supports( $capability ) ) {
			return;
		}

		$diagnostic = array(
			'code'       => 'markdown_db_unsupported_backend_capability',
			'backend'    => $this->backend,
			'capability' => $capability,
			'message'    => sprintf( 'The %s backend does not support %s.', $this->backend, $capability ),
		);
		throw new WP_Markdown_Unsupported_Backend_Capability( $diagnostic );
	}

	/**
	 * @return array{id:string,capabilities:array<string, bool>}
	 */
	public function report(): array {
		return array(
			'id'           => $this->backend,
			'capabilities' => $this->capabilities,
		);
	}
}

/**
 * Resolves the backend declaration used by runtime and diagnostic boundaries.
 */
class WP_Markdown_Backend_Resolver {

	/** @var array<string, WP_Markdown_Backend_Capabilities> */
	private static array $declarations = array();

	private static ?string $active_backend = null;

	/** Register a backend declaration before the drop-in creates wpdb. */
	public static function register( WP_Markdown_Backend_Capabilities $backend ): void {
		self::$declarations[ $backend->get_backend() ] = $backend;
	}

	/**
	 * Activate the configured backend declaration for this bootstrap.
	 *
	 * wp-config.php may set MARKDOWN_DB_BACKEND and
	 * $GLOBALS['markdown_db_backend_declarations'][ $id ] to either a
	 * WP_Markdown_Backend_Capabilities instance or a capability boolean array.
	 */
	public static function configure_from_globals(): WP_Markdown_Backend_Capabilities {
		foreach ( $GLOBALS['markdown_db_backend_declarations'] ?? array() as $id => $declaration ) {
			if ( $declaration instanceof WP_Markdown_Backend_Capabilities ) {
				self::register( $declaration );
			} elseif ( is_string( $id ) && is_array( $declaration ) ) {
				self::register( new WP_Markdown_Backend_Capabilities( $id, $declaration ) );
			} else {
				throw new InvalidArgumentException( 'Backend declarations must be capability instances or capability arrays keyed by backend identifier.' );
			}
		}

		$id = defined( 'MARKDOWN_DB_BACKEND' ) ? (string) MARKDOWN_DB_BACKEND : 'sqlite';
		if ( 'sqlite' === $id && ! isset( self::$declarations['sqlite'] ) ) {
			self::register( WP_Markdown_Backend_Capabilities::sqlite() );
		}
		if ( 'mysql-content' === $id && ! isset( self::$declarations['mysql-content'] ) ) {
			self::register( WP_Markdown_Backend_Capabilities::mysql_content() );
		}
		if ( ! isset( self::$declarations[ $id ] ) ) {
			throw new WP_Markdown_Unknown_Backend( array(
				'code'    => 'markdown_db_unknown_backend',
				'backend' => $id,
				'message' => sprintf( 'Markdown Database Integration has no declaration for the %s backend.', $id ),
			) );
		}

		self::$active_backend = $id;
		return self::$declarations[ $id ];
	}

	/** Fail bootstrap before wpdb exists when its configured backend is incomplete. */
	public static function require_runtime_capabilities( WP_Markdown_Backend_Capabilities $backend, string $mode ): void {
		$required = array(
			'content_mutation_capture',
			'table_mutation_capture',
			'schema_persistence',
			'lazy_post_content_resolution',
			'explicit_flush',
			'changed_path_receipts',
		);
		if ( 'primary' === $mode ) {
			$required[] = 'disposable_index_operation';
			$required[] = 'cold_reconstruction';
		}
		foreach ( $required as $capability ) {
			$backend->require( $capability );
		}
	}

	/**
	 * SQLite is the only shipped runtime backend. A supplied declaration is used
	 * by tests and future backend adapters without coupling feature code to one.
	 */
	public static function resolve( ?WP_Markdown_Backend_Capabilities $backend = null ): WP_Markdown_Backend_Capabilities {
		if ( null !== $backend ) {
			return $backend;
		}
		if ( null !== self::$active_backend ) {
			return self::$declarations[ self::$active_backend ];
		}
		return WP_Markdown_Backend_Capabilities::sqlite();
	}
}
