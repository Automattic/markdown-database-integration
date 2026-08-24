<?php
/** Native runtime composition and the option-only compatibility wrapper. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-canonical-option-path.php';
require_once __DIR__ . '/class-wp-markdown-native-query-contracts.php';
require_once __DIR__ . '/class-wp-markdown-native-query-schema.php';
require_once __DIR__ . '/class-wp-markdown-native-query-parser.php';
require_once __DIR__ . '/class-wp-markdown-native-table-providers.php';
require_once __DIR__ . '/class-wp-markdown-native-query-executor.php';

final class WP_Markdown_Native_Runtime_Factory {

	public static function options_schema(): WP_Markdown_Native_Table_Schema {
		return new WP_Markdown_Native_Table_Schema(
			array(
				'option_id'    => array(
					'type'     => 8,
					'nullable' => false,
					'validate' => static fn( mixed $value ): bool => is_int( $value ) && $value >= 0,
				),
				'option_name'  => array(
					'type'            => 253,
					'nullable'        => false,
					'validate'        => static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= 191,
					'normalize'       => array( self::class, 'normalize_ascii_ci' ),
					'lookup_validate' => static fn( array $values ): bool => self::all_ascii_strings( $values ),
				),
				'option_value' => array(
					'type'     => 252,
					'nullable' => false,
					'validate' => 'is_string',
				),
				'autoload'     => array(
					'type'             => 253,
					'nullable'         => false,
					'validate'         => static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= 20,
					'lookup_operators' => array( 'IN' ),
					'lookup_validate'  => static fn( array $values ): bool => ! array_diff(
						$values,
						array( 'yes', 'on', 'auto-on', 'auto' )
					),
				),
			),
			'option_id',
			array( 'option_name', 'autoload' )
		);
	}

	public static function users_schema( bool $multisite = false ): WP_Markdown_Native_Table_Schema {
		$width = static fn( int $maximum ): callable => static fn( mixed $value ): bool => is_string( $value )
			&& strlen( $value ) <= $maximum;
		$unsigned = static fn( mixed $value ): bool => is_string( $value )
			&& 1 === preg_match( '/^[1-9][0-9]*$/D', $value );
		$nonnegative = static fn( mixed $value ): bool => is_string( $value )
			&& 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value );
		$signed = static fn( mixed $value ): bool => is_string( $value )
			&& 1 === preg_match( '/^-?(?:0|[1-9][0-9]*)$/D', $value );

		$columns = array(
			'ID'                  => array(
				'type'            => 8,
				'nullable'        => false,
				'validate'        => $unsigned,
				'normalize'       => array( self::class, 'normalize_unsigned' ),
				'lookup_validate' => static fn( array $values ): bool => ! in_array(
					null,
					array_map( array( self::class, 'normalize_unsigned' ), $values ),
					true
				),
			),
			'user_login'          => self::lookup_string_column( 60, true ),
			'user_pass'           => self::string_column( 255 ),
			'user_nicename'       => self::lookup_string_column( 50, true ),
			'user_email'          => self::lookup_string_column( 100, true ),
			'user_url'            => self::string_column( 100 ),
			'user_registered'     => array(
				'type'     => 12,
				'nullable' => false,
				'validate' => $width( 19 ),
			),
			'user_activation_key' => self::string_column( 255 ),
			'user_status'         => array(
				'type'      => 3,
				'nullable'  => false,
				'validate'  => $signed,
				'normalize' => array( self::class, 'normalize_signed' ),
			),
			'display_name'        => self::string_column( 250 ),
		);
		if ( $multisite ) {
			$columns['spam'] = array(
				'type'      => 1,
				'nullable'  => false,
				'validate'  => $nonnegative,
				'normalize' => array( self::class, 'normalize_unsigned' ),
			);
			$columns['deleted'] = array(
				'type'      => 1,
				'nullable'  => false,
				'validate'  => $nonnegative,
				'normalize' => array( self::class, 'normalize_unsigned' ),
			);
		}

		return new WP_Markdown_Native_Table_Schema(
			$columns,
			'ID',
			array( 'ID', 'user_login', 'user_nicename', 'user_email' )
		);
	}

	public static function usermeta_schema(): WP_Markdown_Native_Table_Schema {
		$unsigned = static fn( mixed $value ): bool => is_string( $value )
			&& 1 === preg_match( '/^[1-9][0-9]*$/D', $value );
		$nonnegative = static fn( mixed $value ): bool => is_string( $value )
			&& 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value );
		$lookup = array(
			'normalize'       => array( self::class, 'normalize_unsigned' ),
			'lookup_validate' => static fn( array $values ): bool => ! in_array(
				null,
				array_map( array( self::class, 'normalize_unsigned' ), $values ),
				true
			),
		);

		return new WP_Markdown_Native_Table_Schema(
			array(
				'umeta_id'  => array(
					'type'      => 8,
					'nullable'  => false,
					'validate'  => $unsigned,
					'normalize' => array( self::class, 'normalize_unsigned' ),
				),
				'user_id'    => array_merge(
					array(
						'type'             => 8,
						'nullable'         => false,
						'validate'         => $nonnegative,
						'lookup_operators' => array( 'IN' ),
					),
					$lookup
				),
				'meta_key'   => array(
					'type'     => 253,
					'nullable' => true,
					'validate' => static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= 255,
				),
				'meta_value' => array(
					'type'     => 252,
					'nullable' => true,
					'validate' => 'is_string',
				),
			),
			'umeta_id',
			array( 'user_id' )
		);
	}

	public static function registry(
		string $state_root,
		string $prefix = 'wp_',
		?string $base_prefix = null,
		bool $multisite = false
	): WP_Markdown_Native_Table_Registry {
		$base_prefix = $base_prefix ?? $prefix;
		$registry = new WP_Markdown_Native_Table_Registry();
		$options  = self::options_schema();
		$registry->register(
			$prefix . 'options',
			$options,
			new WP_Markdown_Native_Option_Provider( $state_root, $options )
		);
		self::register_json_snapshot(
			$registry,
			$state_root,
			$base_prefix . 'users',
			self::users_schema( $multisite ),
			'users.json'
		);
		self::register_json_snapshot(
			$registry,
			$state_root,
			$base_prefix . 'usermeta',
			self::usermeta_schema(),
			'usermeta.json'
		);
		return $registry;
	}

	public static function runtime(
		string $state_root,
		string $prefix = 'wp_',
		?string $base_prefix = null,
		bool $multisite = false
	): WP_Markdown_Native_Query_Runtime {
		return new WP_Markdown_Native_Query_Runtime( self::registry( $state_root, $prefix, $base_prefix, $multisite ) );
	}

	public static function register_json_snapshot(
		WP_Markdown_Native_Table_Registry $registry,
		string $state_root,
		string $table,
		WP_Markdown_Native_Table_Schema $schema,
		string $filename
	): void {
		$registry->register(
			$table,
			$schema,
			new WP_Markdown_Native_JSON_Snapshot_Provider( $state_root, $schema, $filename )
		);
	}

	public static function normalize_unsigned( mixed $value ): ?string {
		if ( is_int( $value ) && $value >= 0 ) {
			return (string) $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]+$/D', $value ) ) {
			return null;
		}
		$value = ltrim( $value, '0' );
		return '' === $value ? '0' : $value;
	}

	public static function normalize_signed( mixed $value ): ?string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^-?[0-9]+$/D', $value ) ) {
			return null;
		}
		$negative = str_starts_with( $value, '-' );
		$value    = ltrim( $negative ? substr( $value, 1 ) : $value, '0' );
		$value    = '' === $value ? '0' : $value;
		return $negative && '0' !== $value ? '-' . $value : $value;
	}

	public static function normalize_ascii_ci( mixed $value ): ?string {
		if ( ! is_string( $value ) || 1 === preg_match( '/[^\x00-\x7F]/', $value ) ) {
			return null;
		}
		return strtolower( $value );
	}

	/** @return array{type:int,nullable:bool,validate:callable} */
	private static function string_column( int $maximum ): array {
		return array(
			'type'     => 253,
			'nullable' => false,
			'validate' => static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= $maximum,
		);
	}

	/** @return array{type:int,nullable:bool,validate:callable,lookup_validate:callable} */
	private static function lookup_string_column( int $maximum, bool $ascii_case_insensitive = false ): array {
		$lookup = array(
			'lookup_validate' => static fn( array $values ): bool => $ascii_case_insensitive
				? self::all_ascii_strings( $values )
				: self::all_strings( $values ),
		);
		if ( $ascii_case_insensitive ) {
			$lookup['normalize'] = array( self::class, 'normalize_ascii_ci' );
		}
		return array_merge(
			self::string_column( $maximum ),
			$lookup
		);
	}

	private static function all_strings( array $values ): bool {
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				return false;
			}
		}
		return true;
	}

	private static function all_ascii_strings( array $values ): bool {
		foreach ( $values as $value ) {
			if ( null === self::normalize_ascii_ci( $value ) ) {
				return false;
			}
		}
		return true;
	}
}

final class WP_Markdown_Native_Option_Query_Runtime implements WP_Markdown_Query_Runtime {

	private WP_Markdown_Native_Query_Runtime $runtime;

	public function __construct(
		string $state_root,
		private string $table_prefix = 'wp_'
	) {
		$this->runtime = WP_Markdown_Native_Runtime_Factory::runtime( $state_root, $table_prefix );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		if ( $this->table_prefix !== $request->table_prefix() ) {
			return WP_Markdown_Query_Result::failure(
				array(
					'code'    => 'markdown_db_native_unsupported_query',
					'reason'  => 'unsupported_table_prefix',
					'message' => 'The native option runtime is bound to a different canonical state root.',
				)
			);
		}
		return $this->runtime->execute( $request );
	}
}
