<?php
/**
 * WordPress-shaped db.php bootstrap coverage for backend selection.
 *
 * Usage: php tests/smoke-dropin-backend-selection.php
 */

declare( strict_types=1 );

if ( 2 === $argc ) {
	$scenario = $argv[1];
	$root     = sys_get_temp_dir() . '/mdi-dropin-backend-' . $scenario . '-' . getmypid();
	$content  = $root . '/wp-content';
	$sqlite   = $content . '/mu-plugins/sqlite-database-integration';
	$mdi      = $content . '/plugins/markdown-database-integration';
	foreach ( array( $sqlite . '/wp-includes/database', $sqlite . '/wp-includes/sqlite', $mdi . '/inc/native/generated', $content . '/markdown' ) as $directory ) {
		mkdir( $directory, 0755, true );
	}
	register_shutdown_function( static function () use ( $root ): void {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $root );
	} );

	file_put_contents( $sqlite . '/wp-includes/database/version.php', "<?php\n" );
	file_put_contents( $sqlite . '/constants.php', "<?php\n" );
	file_put_contents( $sqlite . '/wp-includes/database/load.php', "<?php\nclass WP_MySQL_On_SQLite extends PDO {}\nclass WP_SQLite_Connection {}\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/db.php', "<?php\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/class-wp-sqlite-db.php', "<?php\nclass WP_SQLite_DB {}\n" );
	file_put_contents( $sqlite . '/wp-includes/sqlite/install-functions.php', "<?php\n" );
	foreach ( array( 'class-wp-markdown-frontmatter-profiles.php', 'class-wp-markdown-content-layout-profiles.php', 'class-wp-markdown-storage.php', 'class-wp-markdown-search.php', 'class-wp-markdown-write-engine.php', 'class-wp-markdown-loader.php' ) as $file ) {
		file_put_contents( $mdi . '/inc/' . $file, "<?php\n" );
	}
	file_put_contents( $mdi . '/inc/class-wp-markdown-storage.php', "<?php\nclass WP_Markdown_Storage { public function __construct( string \$root ) {} }\n" );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-backend-capabilities.php', $mdi . '/inc/class-wp-markdown-backend-capabilities.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-sql-classifier.php', $mdi . '/inc/class-wp-markdown-sql-classifier.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-query-observer-boundary.php', $mdi . '/inc/class-wp-markdown-query-observer-boundary.php' );
	foreach ( array(
		'class-wp-markdown-canonical-option-path.php',
		'class-wp-markdown-table-durability-policy.php',
		'class-wp-markdown-file-witness.php',
		'native/class-wp-markdown-native-post-catalogue.php',
		'native/class-wp-markdown-native-option-catalogue.php',
		'native/class-wp-markdown-native-query-contracts.php',
		'native/class-wp-markdown-native-query-schema.php',
		'native/class-wp-markdown-native-schema-catalog.php',
		'native/class-wp-markdown-native-sql-tokenizer.php',
		'native/class-wp-markdown-native-query-ast.php',
		'native/class-wp-markdown-native-query-parser.php',
		'native/class-wp-markdown-native-scalar-evaluator.php',
		'native/class-wp-markdown-native-sql-session.php',
		'native/class-wp-markdown-native-json-row-stream.php',
		'native/class-wp-markdown-native-table-providers.php',
		'native/class-wp-markdown-native-option-mutations.php',
		'native/class-wp-markdown-native-table-index.php',
		'native/class-wp-markdown-native-table-statements.php',
		'native/class-wp-markdown-native-table-insert-parser.php',
		'native/class-wp-markdown-native-table-mutations.php',
		'native/class-wp-markdown-native-post-mutations.php',
		'native/class-wp-markdown-native-schema-introspection.php',
		'native/class-wp-markdown-native-schema-mutations.php',
		'native/class-wp-markdown-native-transactions.php',
		'native/class-wp-markdown-native-temporary-tables.php',
		'native/class-wp-markdown-native-advisory-locks.php',
		'native/class-wp-markdown-native-query-executor.php',
		'native/class-wp-markdown-native-query-runtime.php',
		'native/class-wp-markdown-native-wpdb.php',
		'native/class-wp-markdown-native-authoritative-snapshot-runtime.php',
		'compatibility/class-wp-markdown-query-compatibility-comparator.php',
		'class-wp-markdown-operation-profile.php',
		'class-wp-markdown-wpdb-result-snapshot.php',
		'native/class-wp-markdown-native-shadow-verifier.php',
	) as $relative ) {
		$destination = $mdi . '/inc/' . $relative;
		$directory   = dirname( $destination );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			throw new RuntimeException( 'Failed to create the drop-in fixture directory.' );
		}
		copy( dirname( __DIR__ ) . '/inc/' . $relative, $destination );
	}
	copy( dirname( __DIR__ ) . '/inc/native/generated/wp-core-schema-catalog.php', $mdi . '/inc/native/generated/wp-core-schema-catalog.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-durable-reconciliation-operations.php', $mdi . '/inc/class-wp-markdown-durable-reconciliation-operations.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-reconciliation-adapters.php', $mdi . '/inc/class-wp-markdown-reconciliation-adapters.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-primary-index-health.php', $mdi . '/inc/class-wp-markdown-primary-index-health.php' );
	file_put_contents( $mdi . '/inc/class-wp-markdown-driver.php', "<?php\n" );
	file_put_contents( $mdi . '/inc/class-wp-markdown-db.php', "<?php\nclass WP_Markdown_DB { public function __construct( string \$database ) {} }\n" );
	file_put_contents( $mdi . '/markdown-database-integration.php', "<?php\n" );
	copy( dirname( __DIR__ ) . '/db.php', $content . '/db.php' );

	define( 'ABSPATH', $root . '/' );
	define( 'WP_CONTENT_DIR', $content );
	define( 'DB_NAME', 'wordpress' );
	define( 'DB_USER', 'user' );
	define( 'DB_PASSWORD', 'password' );
	define( 'DB_HOST', 'host' );
	define( 'MARKDOWN_DB_VERSION', 'test' );
	if ( in_array( $scenario, array( 'primary-cold-index', 'primary-warm-index' ), true ) ) {
		define( 'MARKDOWN_DB_BACKEND', 'sqlite' );
		define( 'MARKDOWN_DB_MODE', 'primary' );
		mkdir( $content . '/markdown/_options', 0755 );
		file_put_contents( $content . '/markdown/_options/siteurl.json', '"https://example.test"' );
		if ( 'primary-warm-index' === $scenario ) {
			file_put_contents( $content . '/markdown-index.sqlite', 'existing primary index' );
		}
		file_put_contents( $sqlite . '/wp-includes/sqlite/class-wp-sqlite-storage.php', '<?php class WP_SQLite_Storage { public static function with_explicit_path( string $path ) { throw new RuntimeException( "Storage initialization must not touch the primary index." ); } }' );
	}
	$expected_backend = 'mdi-native';
	$database_dir = $content . '/database';
	if ( in_array( $scenario, array( 'default-db-dir', 'default-fqdbdir' ), true ) ) {
		$database_dir = $root . '/custom';
		define( 'default-db-dir' === $scenario ? 'DB_DIR' : 'FQDBDIR', $database_dir );
	}
	if ( 'default-native' !== $scenario ) {
		mkdir( $database_dir, 0755, true );
	}
	if ( in_array( $scenario, array( 'default-legacy-sqlite', 'default-db-dir', 'default-fqdbdir', 'default-missing-db-path', 'default-missing-fqdb', 'default-missing-db-file', 'default-interrupted-migration', 'mdi-native' ), true ) ) {
		file_put_contents( $database_dir . '/.ht.sqlite', "SQLite format 3\0" . str_repeat( "\0", 16 ) );
		$expected_backend = 'sqlite';
	}
	if ( 'default-legacy-php' === $scenario ) {
		file_put_contents( $database_dir . '/.ht.sqlite.php', "SQLite format 3\0" . str_repeat( "\0", 16 ) );
		$expected_backend = 'sqlite';
	}
	if ( in_array( $scenario, array( 'default-randomized', 'default-missing-recorded', 'default-interrupted-migration' ), true ) ) {
		$recorded = $database_dir . '/.ht.secret/.ht.sqlite';
		file_put_contents( $database_dir . '/db-path.php', '<?php return ' . var_export( $recorded, true ) . ';' );
		$expected_backend = 'sqlite';
		if ( 'default-randomized' === $scenario ) {
			mkdir( dirname( $recorded ), 0755 );
			file_put_contents( $recorded, 'managed database' );
		}
	}
	if ( 'default-empty-ht' === $scenario || 'default-nonsqlite-ht' === $scenario ) {
		file_put_contents( $database_dir . '/.ht.sqlite', 'default-empty-ht' === $scenario ? '' : 'not sqlite' );
	}
	if ( 'default-unread-metadata' === $scenario ) {
		file_put_contents( $database_dir . '/db-path.php', '<?php throw new RuntimeException( "Detection read metadata." );' );
		$expected_backend = 'sqlite';
	}
	if ( in_array( $scenario, array( 'default-db-path', 'default-conflicting-paths', 'default-fqdb', 'default-db-file', 'default-empty-fqdb' ), true ) ) {
		$constant = match ( $scenario ) {
			'default-fqdb', 'default-empty-fqdb' => 'FQDB',
			'default-db-file' => 'DB_FILE',
			default => 'DB_PATH',
		};
		define( $constant, 'DB_FILE' === $constant ? 'custom.sqlite' : $database_dir . '/custom.sqlite' );
		file_put_contents( $database_dir . '/custom.sqlite', 'default-empty-fqdb' === $scenario ? '' : "SQLite format 3\0" . str_repeat( "\0", 16 ) );
		$expected_backend = 'default-empty-fqdb' === $scenario ? 'mdi-native' : 'sqlite';
		if ( 'default-conflicting-paths' === $scenario ) {
			define( 'FQDB', $database_dir . '/missing.sqlite' );
		}
	}
	if ( in_array( $scenario, array( 'default-missing-db-path', 'default-missing-fqdb', 'default-missing-db-file' ), true ) ) {
		$constant = match ( $scenario ) {
			'default-missing-fqdb' => 'FQDB',
			'default-missing-db-file' => 'DB_FILE',
			default => 'DB_PATH',
		};
		define( $constant, 'DB_FILE' === $constant ? 'missing.sqlite' : $database_dir . '/missing.sqlite' );
		if ( 'DB_PATH' === $constant ) {
			define( 'FQDB', $database_dir . '/.ht.sqlite' );
		}
		$expected_backend = 'mdi-native';
	}
	$snapshot = static function () use ( $database_dir ): array {
		$files = array( $database_dir => is_dir( $database_dir ) );
		if ( is_dir( $database_dir ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $database_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $file ) {
				$files[ $file->getPathname() ] = $file->isDir() ? 'directory' : hash_file( 'sha256', $file->getPathname() );
			}
		}
		return $files;
	};
	$storage_before = $snapshot();
	if ( 'explicit-empty-sqlite' === $scenario ) {
		define( 'MARKDOWN_DB_BACKEND', 'sqlite' );
		file_put_contents( $database_dir . '/.ht.sqlite', '' );
		$storage_before = $snapshot();
	} elseif ( in_array( $scenario, array( 'unknown', 'retired-content', 'retired-full' ), true ) ) {
		define( 'MARKDOWN_DB_BACKEND', match ( $scenario ) {
			'retired-content' => 'mysql-content',
			'retired-full' => 'mysql-full',
			default => 'unknown',
		} );
	} elseif ( 'incomplete' === $scenario ) {
		define( 'MARKDOWN_DB_BACKEND', 'incomplete' );
		$GLOBALS['markdown_db_backend_declarations'] = array( 'incomplete' => array() );
	} elseif ( 'sqlite' === $scenario ) {
		define( 'MARKDOWN_DB_BACKEND', 'sqlite' );
	} elseif ( 'mdi-native' === $scenario || str_starts_with( $scenario, 'default-' ) ) {
		// The default scenarios leave MARKDOWN_DB_BACKEND undefined on purpose.
		if ( 'mdi-native' === $scenario ) {
			define( 'MARKDOWN_DB_BACKEND', 'mdi-native' );
		}
		define( 'MARKDOWN_DB_STATE_DIR', $content . '/markdown' );
		define( 'MARKDOWN_DB_CONTENT_DIR', $content . '/markdown' );
		class wpdb {
			public bool $is_mysql = false;
			public string $prefix = '';
			public string $base_prefix = '';
			public array $last_result = array();
			public array $col_info = array();
			public string $last_error = '';
			public int $insert_id = 0;
			public int $rows_affected = 0;
			public int $num_rows = 0;
			public int $num_queries = 0;
			public bool $ready = false;
			public bool $check_current_query = true;
			public mixed $result = null;
			public string $func_call = '';
			public string $last_query = '';
			public function set_prefix( string $prefix ): void { $this->prefix = $prefix; $this->base_prefix = $prefix; }
			public function flush(): void {}
			public function remove_placeholder_escape( string $query ): string { return $query; }
			public function add_placeholder_escape( string $value ): string { return $value; }
		}
	}

	try {
		// Match require_wp_db(): instantiate stock wpdb when db.php leaves no global.
		$bootstrap = static function () use ( $content ): void {
			global $wpdb;
			require $content . '/db.php';
			if ( ! isset( $wpdb ) ) {
				$GLOBALS['wpdb'] = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			}
		};
		$bootstrap();
		if ( in_array( $scenario, array( 'primary-cold-index', 'primary-warm-index' ), true ) ) {
			if ( ! $GLOBALS['wpdb'] instanceof WP_Markdown_DB || DB_PATH !== $content . '/markdown-index.sqlite' || defined( 'MARKDOWN_DB_INSTALL_FALLBACK' ) ) {
				throw new RuntimeException( 'An installed primary site did not use its index.' );
			}
			if ( 'primary-cold-index' === $scenario ? file_exists( DB_PATH ) : 'existing primary index' !== file_get_contents( DB_PATH ) ) {
				throw new RuntimeException( 'The drop-in created or changed the primary index.' );
			}
			echo "PASS: {$scenario} leaves index initialization to primary mode.\n";
			exit( 0 );
		}
		if ( 'mdi-native' === $scenario && $GLOBALS['wpdb'] instanceof WP_Markdown_Native_WPDB && defined( 'MARKDOWN_DB_DROPIN' ) ) {
			echo "PASS: mdi-native installs the filesystem-backed wpdb boundary.\n";
			exit( 0 );
		}
		if ( $storage_before !== $snapshot() ) {
			throw new RuntimeException( 'Backend detection changed SQLite storage.' );
		}
		if ( str_starts_with( $scenario, 'default-' ) ) {
			if ( $expected_backend !== MARKDOWN_DB_BACKEND || ( 'mdi-native' === $expected_backend ) !== ( $GLOBALS['wpdb'] instanceof WP_Markdown_Native_WPDB ) ) {
				throw new RuntimeException( 'Unexpected backend for ' . $scenario . ': ' . MARKDOWN_DB_BACKEND );
			}
			echo "PASS: {$scenario} selects {$expected_backend} without changing storage.\n";
			exit( 0 );
		}
		if ( ! in_array( $scenario, array( 'sqlite', 'explicit-empty-sqlite' ), true ) || ! isset( $GLOBALS['wpdb'] ) ) {
			throw new RuntimeException( 'Expected backend bootstrap failure.' );
		}
		echo "PASS: an explicitly configured SQLite backend keeps the SQLite drop-in path.\n";
	} catch ( WP_Markdown_Unknown_Backend|WP_Markdown_Unsupported_Backend_Capability $error ) {
		$diagnostic = $error->get_diagnostic();
		if ( in_array( $scenario, array( 'unknown', 'retired-content', 'retired-full' ), true ) && 'markdown_db_unknown_backend' === $diagnostic['code'] && MARKDOWN_DB_BACKEND === $diagnostic['backend'] && ! isset( $GLOBALS['wpdb'] ) ) {
			echo "PASS: {$scenario} backend fails closed with a structured diagnostic.\n";
			exit( 0 );
		}
		if ( 'incomplete' === $scenario && 'markdown_db_unsupported_backend_capability' === $diagnostic['code'] && 'incomplete' === $diagnostic['backend'] ) {
			echo "PASS: incomplete backend fails closed with a structured diagnostic.\n";
			exit( 0 );
		}
		throw $error;
	}
	exit( 0 );
}

$failed = 0;
foreach ( array( 'sqlite', 'explicit-empty-sqlite', 'default-empty-ht', 'default-nonsqlite-ht', 'default-empty-fqdb', 'primary-cold-index', 'primary-warm-index', 'default-native', 'default-legacy-sqlite', 'default-legacy-php', 'default-db-dir', 'default-fqdbdir', 'default-randomized', 'default-missing-recorded', 'default-interrupted-migration', 'default-unread-metadata', 'default-db-path', 'default-fqdb', 'default-db-file', 'default-conflicting-paths', 'default-missing-db-path', 'default-missing-fqdb', 'default-missing-db-file', 'mdi-native', 'retired-content', 'retired-full', 'unknown', 'incomplete' ) as $scenario ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario ), $status );
	if ( 0 !== $status ) {
		++$failed;
	}
}
exit( $failed ? 1 : 0 );
