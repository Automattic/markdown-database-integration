<?php
/** Native runtime composition and the option-only compatibility wrapper. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-canonical-option-path.php';
require_once __DIR__ . '/class-wp-markdown-native-query-contracts.php';
require_once __DIR__ . '/class-wp-markdown-native-query-schema.php';
require_once __DIR__ . '/class-wp-markdown-native-sql-tokenizer.php';
require_once __DIR__ . '/class-wp-markdown-native-query-ast.php';
require_once __DIR__ . '/class-wp-markdown-native-query-parser.php';
require_once __DIR__ . '/class-wp-markdown-storage.php';
require_once __DIR__ . '/class-wp-markdown-native-table-providers.php';
require_once __DIR__ . '/class-wp-markdown-native-query-executor.php';

final class WP_Markdown_Native_Runtime_Factory {

	public static function options_schema(): WP_Markdown_Native_Table_Schema {
		return new WP_Markdown_Native_Table_Schema(
			array(
				'option_id'    => new WP_Markdown_Native_Column(
					8,
					false,
					static fn( mixed $value ): bool => is_int( $value ) && $value >= 0
				),
				'option_name'  => new WP_Markdown_Native_Column(
					253,
					false,
					static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= 191,
					array( self::class, 'normalize_ascii_ci' ),
					array( '=', 'IN' ),
					static fn( array $values ): bool => self::all_ascii_strings( $values )
				),
				'option_value' => new WP_Markdown_Native_Column(
					252,
					false,
					'is_string'
				),
				'autoload'     => new WP_Markdown_Native_Column(
					253,
					false,
					static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= 20,
					null,
					array( 'IN' ),
					static fn( array $values ): bool => ! array_diff(
						$values,
						array( 'yes', 'on', 'auto-on', 'auto' )
					)
				),
			),
			'option_id'
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
			'ID'                  => new WP_Markdown_Native_Column(
				8,
				false,
				$unsigned,
				array( self::class, 'normalize_unsigned' ),
				array( '=', 'IN' ),
				static fn( array $values ): bool => ! in_array(
					null,
					array_map( array( self::class, 'normalize_unsigned' ), $values ),
					true
				)
			),
			'user_login'          => self::lookup_string_column( 60, true ),
			'user_pass'           => self::string_column( 255 ),
			'user_nicename'       => self::lookup_string_column( 50, true ),
			'user_email'          => self::lookup_string_column( 100, true ),
			'user_url'            => self::string_column( 100 ),
			'user_registered'     => new WP_Markdown_Native_Column( 12, false, $width( 19 ) ),
			'user_activation_key' => self::string_column( 255 ),
			'user_status'         => new WP_Markdown_Native_Column( 3, false, $signed, array( self::class, 'normalize_signed' ) ),
			'display_name'        => self::string_column( 250 ),
		);
		if ( $multisite ) {
			$columns['spam'] = new WP_Markdown_Native_Column( 1, false, $nonnegative, array( self::class, 'normalize_unsigned' ) );
			$columns['deleted'] = new WP_Markdown_Native_Column( 1, false, $nonnegative, array( self::class, 'normalize_unsigned' ) );
		}

		return new WP_Markdown_Native_Table_Schema(
			$columns,
			'ID'
		);
	}

	public static function usermeta_schema(): WP_Markdown_Native_Table_Schema {
		$unsigned = static fn( mixed $value ): bool => is_string( $value )
			&& 1 === preg_match( '/^[1-9][0-9]*$/D', $value );
		$nonnegative = static fn( mixed $value ): bool => is_string( $value )
			&& 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value );
		return new WP_Markdown_Native_Table_Schema(
			array(
				'umeta_id'  => new WP_Markdown_Native_Column( 8, false, $unsigned, array( self::class, 'normalize_unsigned' ) ),
				'user_id'    => new WP_Markdown_Native_Column(
					8,
					false,
					$nonnegative,
					array( self::class, 'normalize_unsigned' ),
					array( 'IN' ),
					static fn( array $values ): bool => ! in_array(
						null,
						array_map( array( self::class, 'normalize_unsigned' ), $values ),
						true
					)
				),
				'meta_key'   => new WP_Markdown_Native_Column( 253, true, static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= 255 ),
				'meta_value' => new WP_Markdown_Native_Column( 252, true, 'is_string' ),
			),
			'umeta_id'
		);
	}

	public static function posts_schema(): WP_Markdown_Native_Table_Schema {
		$unsigned = static fn( mixed $value ): bool => is_int( $value ) && $value >= 0;
		$string   = static fn( int $maximum ): callable => static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= $maximum;
		return new WP_Markdown_Native_Table_Schema(
			array(
				'ID'                    => new WP_Markdown_Native_Column( 8, false, $unsigned, null, array( '=', 'IN' ) ),
				'post_author'           => new WP_Markdown_Native_Column( 8, false, $unsigned, null, array( '=', 'IN' ) ),
				'post_date'             => new WP_Markdown_Native_Column( 12, false, $string( 19 ) ),
				'post_date_gmt'         => new WP_Markdown_Native_Column( 12, false, $string( 19 ) ),
				'post_content'          => new WP_Markdown_Native_Column( 252, false, 'is_string' ),
				'post_title'            => new WP_Markdown_Native_Column( 252, false, 'is_string' ),
				'post_excerpt'          => new WP_Markdown_Native_Column( 252, false, 'is_string' ),
				'post_status'           => new WP_Markdown_Native_Column( 253, false, $string( 20 ) ),
				'comment_status'        => new WP_Markdown_Native_Column( 253, false, $string( 20 ) ),
				'ping_status'           => new WP_Markdown_Native_Column( 253, false, $string( 20 ) ),
				'post_password'         => new WP_Markdown_Native_Column( 253, false, $string( 255 ) ),
				'post_name'             => new WP_Markdown_Native_Column( 253, false, $string( 200 ) ),
				'to_ping'               => new WP_Markdown_Native_Column( 252, false, 'is_string' ),
				'pinged'                => new WP_Markdown_Native_Column( 252, false, 'is_string' ),
				'post_modified'         => new WP_Markdown_Native_Column( 12, false, $string( 19 ) ),
				'post_modified_gmt'     => new WP_Markdown_Native_Column( 12, false, $string( 19 ) ),
				'post_content_filtered' => new WP_Markdown_Native_Column( 252, false, 'is_string' ),
				'post_parent'           => new WP_Markdown_Native_Column( 8, false, $unsigned, null, array( '=', 'IN' ) ),
				'guid'                  => new WP_Markdown_Native_Column( 253, false, $string( 255 ) ),
				'menu_order'            => new WP_Markdown_Native_Column( 3, false, 'is_int' ),
				'post_type'             => new WP_Markdown_Native_Column( 253, false, $string( 20 ) ),
				'post_mime_type'        => new WP_Markdown_Native_Column( 253, false, $string( 100 ) ),
				'comment_count'         => new WP_Markdown_Native_Column( 8, false, $unsigned ),
			),
			'ID'
		);
	}

	public static function comments_schema(): WP_Markdown_Native_Table_Schema {
		$positive = static fn( mixed $value ): bool => is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value );
		$nonnegative = static fn( mixed $value ): bool => is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value );
		$signed = static fn( mixed $value ): bool => is_string( $value ) && 1 === preg_match( '/^-?(?:0|[1-9][0-9]*)$/D', $value );
		$string = static fn( int $maximum ): callable => static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= $maximum;
		$unsigned_lookup = static fn( array $values ): bool => ! in_array(
			null,
			array_map( array( self::class, 'normalize_unsigned' ), $values ),
			true
		);
		return new WP_Markdown_Native_Table_Schema(
			array(
				'comment_ID'           => new WP_Markdown_Native_Column( 8, false, $positive, array( self::class, 'normalize_unsigned' ), array( '=', 'IN' ), $unsigned_lookup ),
				'comment_post_ID'      => new WP_Markdown_Native_Column( 8, false, $nonnegative, array( self::class, 'normalize_unsigned' ), array( '=', 'IN' ), $unsigned_lookup ),
				'comment_author'       => new WP_Markdown_Native_Column( 252, false, $string( 255 ) ),
				'comment_author_email' => new WP_Markdown_Native_Column(
					253,
					false,
					$string( 100 ),
					array( self::class, 'normalize_ascii_ci' ),
					array( '=', 'IN' ),
					static fn( array $values ): bool => self::all_ascii_strings( $values )
				),
				'comment_author_url'   => new WP_Markdown_Native_Column( 253, false, $string( 200 ) ),
				'comment_author_IP'    => new WP_Markdown_Native_Column( 253, false, $string( 100 ) ),
				'comment_date'         => new WP_Markdown_Native_Column( 12, false, $string( 19 ) ),
				'comment_date_gmt'     => new WP_Markdown_Native_Column( 12, false, $string( 19 ) ),
				'comment_content'      => new WP_Markdown_Native_Column( 252, false, 'is_string' ),
				'comment_karma'        => new WP_Markdown_Native_Column( 3, false, $signed, array( self::class, 'normalize_signed' ) ),
				'comment_approved'     => new WP_Markdown_Native_Column( 253, false, $string( 20 ), null, array( '=', 'IN' ) ),
				'comment_agent'        => new WP_Markdown_Native_Column( 253, false, $string( 255 ) ),
				'comment_type'         => new WP_Markdown_Native_Column( 253, false, $string( 20 ) ),
				'comment_parent'       => new WP_Markdown_Native_Column( 8, false, $nonnegative, array( self::class, 'normalize_unsigned' ), array( '=', 'IN' ), $unsigned_lookup ),
				'user_id'              => new WP_Markdown_Native_Column( 8, false, $nonnegative, array( self::class, 'normalize_unsigned' ) ),
			),
			'comment_ID',
			array( 'comment_date_gmt' )
		);
	}

	public static function registry(
		string $state_root,
		string $prefix = 'wp_',
		?string $base_prefix = null,
		bool $multisite = false,
		?string $content_root = null
	): WP_Markdown_Native_Table_Registry {
		$base_prefix = $base_prefix ?? $prefix;
		$content_root = $content_root ?? $state_root;
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
		$posts = self::posts_schema();
		$registry->register(
			$prefix . 'posts',
			$posts,
			new WP_Markdown_Native_Post_Provider( $content_root, $posts )
		);
		self::register_json_snapshot(
			$registry,
			$state_root,
			$prefix . 'comments',
			self::comments_schema(),
			'comments.json'
		);
		return $registry;
	}

	public static function runtime(
		string $state_root,
		string $prefix = 'wp_',
		?string $base_prefix = null,
		bool $multisite = false,
		?string $content_root = null
	): WP_Markdown_Native_Query_Runtime {
		return new WP_Markdown_Native_Query_Runtime( self::registry( $state_root, $prefix, $base_prefix, $multisite, $content_root ) );
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

	private static function string_column( int $maximum ): WP_Markdown_Native_Column {
		return new WP_Markdown_Native_Column(
			253,
			false,
			static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= $maximum
		);
	}

	private static function lookup_string_column( int $maximum, bool $ascii_case_insensitive = false ): WP_Markdown_Native_Column {
		return new WP_Markdown_Native_Column(
			253,
			false,
			static fn( mixed $value ): bool => is_string( $value ) && strlen( $value ) <= $maximum,
			$ascii_case_insensitive ? array( self::class, 'normalize_ascii_ci' ) : null,
			array( '=', 'IN' ),
			static fn( array $values ): bool => $ascii_case_insensitive
				? self::all_ascii_strings( $values )
				: self::all_strings( $values )
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
