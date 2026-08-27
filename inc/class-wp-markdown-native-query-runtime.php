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
require_once __DIR__ . '/class-wp-markdown-native-option-mutations.php';
require_once __DIR__ . '/class-wp-markdown-native-table-mutations.php';
require_once __DIR__ . '/class-wp-markdown-native-schema-introspection.php';
require_once __DIR__ . '/class-wp-markdown-native-schema-mutations.php';
require_once __DIR__ . '/class-wp-markdown-sql-classifier.php';
require_once __DIR__ . '/class-wp-markdown-native-transactions.php';
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
					'post_type' => array( 'lookup_operators' => array( '=', 'IN' ) ),
				),
				'order_columns' => array( 'post_date' ),
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
		self::register_generated_core_snapshots( $registry, $state_root, $prefix, $base_prefix, $multisite );
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
		$transactions = new WP_Markdown_Native_Transaction_Journal( $state_root );
		// A journal surviving process termination is rolled back before the
		// runtime serves its first query, so canonical state never boots torn.
		$transactions->recover();
		$registry = self::registry( $state_root, $prefix, $base_prefix, $multisite, $content_root );
		return new WP_Markdown_Native_Query_Runtime(
			$registry,
			new WP_Markdown_Native_Query_Parser(),
			new WP_Markdown_Native_Option_Mutation_Runtime( $state_root, new WP_Markdown_Native_Option_Mutation_Parser(), $transactions ),
			new WP_Markdown_Native_Schema_Mutation_Runtime( $state_root, $registry, $transactions ),
			new WP_Markdown_Native_Table_Mutation_Runtime( $state_root, $registry, new WP_Markdown_Native_Table_Insert_Parser(), $transactions ),
			$transactions
		);
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
			$ddl = self::read_persisted_file( $root, $path );
			if ( null === $ddl ) {
				continue;
			}
			try {
				$definitions = WP_Markdown_Native_Schema_Catalog::compile( $ddl, array( $prefix ) );
				if ( array( $table ) !== array_keys( $definitions ) ) {
					continue;
				}
				$registry->register_definition( $prefix . $table, $definitions[ $table ] );
				$schema = WP_Markdown_Native_Schema_Catalog::indexed_snapshot_schema( $definitions[ $table ] );
				if ( null !== $schema ) {
					$partition_marker = rtrim( $state_root, '/\\' ) . '/_tables/' . $table . '/.mdi-partition.json';
					if ( file_exists( $partition_marker ) || is_link( $partition_marker ) ) {
						$marker = self::read_persisted_partition_marker( $state_root, $table );
						$identity = is_array( $marker ) ? (string) ( $marker['identity_column'] ?? '' ) : '';
						if ( array( $identity ) !== $schema->identity_columns() ) {
							continue;
						}
						$registry->register(
							$prefix . $table,
							$schema,
							new WP_Markdown_Native_JSON_Partition_Provider( $state_root, $schema, $table, $identity )
						);
					} else {
						self::register_json_snapshot( $registry, $state_root, $prefix . $table, $schema, $table . '.json' );
					}
				}
			} catch ( Throwable ) {
				continue;
			}
		}
	}

	/** @return array<string,mixed>|null */
	private static function read_persisted_partition_marker( string $state_root, string $table ): ?array {
		$state = realpath( $state_root );
		$tables_path = rtrim( $state_root, '/\\' ) . '/_tables';
		$tables = realpath( $tables_path );
		$table_path = false === $tables ? '' : $tables . '/' . $table;
		$directory = '' === $table_path ? false : realpath( $table_path );
		if ( false === $state
			|| false === $tables
			|| false === $directory
			|| is_link( $tables_path )
			|| is_link( $table_path )
			|| dirname( $tables ) !== $state
			|| dirname( $directory ) !== $tables
		) {
			return null;
		}
		$path = $directory . '/.mdi-partition.json';
		$contents = self::read_persisted_file( $directory, $path );
		if ( null === $contents ) {
			return null;
		}
		try {
			$marker = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return null;
		}
		return is_array( $marker )
			&& 1 === ( $marker['version'] ?? null )
			&& $table === ( $marker['table'] ?? null )
			&& is_string( $marker['identity_column'] ?? null )
			&& 1 === preg_match( '/^generation-[a-f0-9]{24}$/D', (string) ( $marker['generation'] ?? '' ) )
			? $marker
			: null;
	}

	private static function register_generated_core_snapshots(
		WP_Markdown_Native_Table_Registry $registry,
		string $state_root,
		string $prefix,
		string $base_prefix,
		bool $multisite
	): void {
		$definitions = WP_Markdown_Native_Schema_Catalog::definitions( $multisite );
		$handled = array( 'options', 'users', 'usermeta', 'posts', 'comments' );
		$network = array( 'blogs', 'blogmeta', 'registration_log', 'site', 'sitemeta', 'signups' );
		foreach ( $definitions as $table => $definition ) {
			if ( in_array( $table, $handled, true ) ) {
				continue;
			}
			$column_overlays = array();
			foreach ( $definition['indexes'] as $index ) {
				$column = $index['columns'][0]['name'] ?? '';
				$type = $definition['columns'][ $column ]['type'] ?? '';
				if ( in_array( $type, array( 'char', 'varchar' ), true ) ) {
					$ascii = static fn( array $values ): bool => self::all_ascii_strings( $values );
					$column_overlays[ $column ] = array(
						'normalizer'       => array( self::class, 'normalize_ascii_ci' ),
						'lookup_operators' => array( '=', 'IN' ),
						'lookup_validator' => $ascii,
						'filter_operators' => array( '=', 'IN' ),
						'filter_validator' => $ascii,
					);
				}
			}
			$order_columns = 'terms' === $table ? array( 'name' ) : array();
			$schema = WP_Markdown_Native_Schema_Catalog::indexed_snapshot_schema( $definition, $column_overlays, $order_columns );
			if ( null !== $schema ) {
				$table_prefix = $multisite && in_array( $table, $network, true ) ? $base_prefix : $prefix;
				self::register_json_snapshot( $registry, $state_root, $table_prefix . $table, $schema, $table . '.json' );
			}
		}
	}

	private static function read_persisted_file( string $root, string $path ): ?string {
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
