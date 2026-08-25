<?php
/** Native runtime composition and the option-only compatibility wrapper. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-canonical-option-path.php';
require_once __DIR__ . '/class-wp-markdown-native-query-contracts.php';
require_once __DIR__ . '/class-wp-markdown-native-query-schema.php';
require_once __DIR__ . '/class-wp-markdown-native-schema-catalog.php';
require_once __DIR__ . '/class-wp-markdown-native-sql-tokenizer.php';
require_once __DIR__ . '/class-wp-markdown-native-query-ast.php';
require_once __DIR__ . '/class-wp-markdown-native-query-parser.php';
require_once __DIR__ . '/class-wp-markdown-storage.php';
require_once __DIR__ . '/class-wp-markdown-native-table-providers.php';
require_once __DIR__ . '/class-wp-markdown-native-query-executor.php';

final class WP_Markdown_Native_Runtime_Factory {

	public static function options_schema(): WP_Markdown_Native_Table_Schema {
		return WP_Markdown_Native_Schema_Catalog::table_schema(
			'options',
			'integer',
			array(
				'columns' => array(
					'option_name' => array(
						'normalizer'      => array( self::class, 'normalize_ascii_ci' ),
						'lookup_operators' => array( '=', 'IN' ),
						'lookup_validator' => static fn( array $values ): bool => self::all_ascii_strings( $values ),
					),
					'autoload' => array(
						'lookup_operators' => array( 'IN' ),
						'lookup_validator' => static fn( array $values ): bool => ! array_diff( $values, array( 'yes', 'on', 'auto-on', 'auto' ) ),
					),
				),
			)
		);
	}

	public static function users_schema( bool $multisite = false ): WP_Markdown_Native_Table_Schema {
		$ascii_lookup = static fn( array $values ): bool => self::all_ascii_strings( $values );
		$unsigned_lookup = static fn( array $values ): bool => self::all_normalized_unsigned( $values );
		return WP_Markdown_Native_Schema_Catalog::table_schema(
			'users',
			'string',
			array(
				'columns' => array(
					'ID' => array( 'lookup_operators' => array( '=', 'IN' ), 'lookup_validator' => $unsigned_lookup ),
					'user_login' => array( 'normalizer' => array( self::class, 'normalize_ascii_ci' ), 'lookup_operators' => array( '=', 'IN' ), 'lookup_validator' => $ascii_lookup ),
					'user_nicename' => array( 'normalizer' => array( self::class, 'normalize_ascii_ci' ), 'lookup_operators' => array( '=', 'IN' ), 'lookup_validator' => $ascii_lookup ),
					'user_email' => array( 'normalizer' => array( self::class, 'normalize_ascii_ci' ), 'lookup_operators' => array( '=', 'IN' ), 'lookup_validator' => $ascii_lookup ),
				),
			),
			$multisite
		);
	}

	public static function usermeta_schema(): WP_Markdown_Native_Table_Schema {
		return WP_Markdown_Native_Schema_Catalog::table_schema(
			'usermeta',
			'string',
			array(
				'columns' => array(
					'user_id' => array(
						'lookup_operators' => array( 'IN' ),
						'lookup_validator' => static fn( array $values ): bool => self::all_normalized_unsigned( $values ),
					),
				),
			)
		);
	}

	public static function posts_schema(): WP_Markdown_Native_Table_Schema {
		return WP_Markdown_Native_Schema_Catalog::table_schema(
			'posts',
			'integer',
			array(
				'columns' => array(
					'ID' => array( 'lookup_operators' => array( '=', 'IN' ) ),
					'post_author' => array( 'lookup_operators' => array( '=', 'IN' ) ),
					'post_parent' => array( 'lookup_operators' => array( '=', 'IN' ) ),
				),
			)
		);
	}

	public static function comments_schema(): WP_Markdown_Native_Table_Schema {
		$unsigned_lookup = static fn( array $values ): bool => self::all_normalized_unsigned( $values );
		return WP_Markdown_Native_Schema_Catalog::table_schema(
			'comments',
			'string',
			array(
				'columns' => array(
					'comment_ID' => array( 'lookup_operators' => array( '=', 'IN' ), 'lookup_validator' => $unsigned_lookup ),
					'comment_post_ID' => array( 'lookup_operators' => array( '=', 'IN' ), 'lookup_validator' => $unsigned_lookup ),
					'comment_author_email' => array(
						'normalizer'       => array( self::class, 'normalize_ascii_ci' ),
						'lookup_operators' => array( '=', 'IN' ),
						'lookup_validator' => static fn( array $values ): bool => self::all_ascii_strings( $values ),
					),
					'comment_approved' => array( 'lookup_operators' => array( '=', 'IN' ) ),
					'comment_parent' => array( 'lookup_operators' => array( '=', 'IN' ), 'lookup_validator' => $unsigned_lookup ),
				),
				'order_columns' => array( 'comment_date_gmt' ),
			)
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
		self::register_persisted_plugin_tables( $registry, $state_root, $prefix, $multisite );
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

	private static function register_persisted_plugin_tables(
		WP_Markdown_Native_Table_Registry $registry,
		string $state_root,
		string $prefix,
		bool $multisite
	): void {
		$directory = rtrim( $state_root, '/\\' ) . '/_schema';
		$root = realpath( $directory );
		if ( is_link( $directory ) || false === $root || ! is_dir( $root ) ) {
			return;
		}
		$core_tables = WP_Markdown_Native_Schema_Catalog::definitions( $multisite );
		foreach ( glob( $root . '/*.sql' ) ?: array() as $path ) {
			$table = basename( $path, '.sql' );
			if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $table ) || isset( $core_tables[ $table ] ) ) {
				continue;
			}
			$ddl = self::read_persisted_schema( $root, $path );
			if ( null === $ddl ) {
				continue;
			}
			try {
				$definitions = WP_Markdown_Native_Schema_Catalog::compile( $ddl, array( $prefix ) );
				if ( array( $table ) !== array_keys( $definitions ) ) {
					continue;
				}
				$schema = WP_Markdown_Native_Schema_Catalog::indexed_snapshot_schema( $definitions[ $table ] );
				if ( null !== $schema ) {
					self::register_json_snapshot( $registry, $state_root, $prefix . $table, $schema, $table . '.json' );
				}
			} catch ( Throwable ) {
				continue;
			}
		}
	}

	private static function read_persisted_schema( string $root, string $path ): ?string {
		$real = realpath( $path );
		if ( is_link( $path ) || false === $real || ! is_file( $real ) || dirname( $real ) !== $root ) {
			return null;
		}
		$handle = @fopen( $real, 'rb' );
		if ( false === $handle ) {
			return null;
		}
		try {
			$opened = fstat( $handle );
			$current = @lstat( $real );
			if ( false === $opened
				|| false === $current
				|| $opened['dev'] !== $current['dev']
				|| $opened['ino'] !== $current['ino']
				|| 1 !== ( $opened['nlink'] ?? 1 )
				|| is_link( $real )
			) {
				return null;
			}
			$ddl = stream_get_contents( $handle );
			return is_string( $ddl ) && '' !== trim( $ddl ) ? $ddl : null;
		} finally {
			fclose( $handle );
		}
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

	private static function all_normalized_unsigned( array $values ): bool {
		foreach ( $values as $value ) {
			if ( null === self::normalize_unsigned( $value ) ) {
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
