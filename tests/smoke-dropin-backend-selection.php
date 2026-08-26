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
	foreach ( array( $sqlite . '/wp-includes/database', $sqlite . '/wp-includes/sqlite', $mdi . '/inc/generated', $content . '/markdown' ) as $directory ) {
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
	foreach ( array( 'class-wp-markdown-canonical-option-path.php', 'class-wp-markdown-native-query-contracts.php', 'class-wp-markdown-native-query-schema.php', 'class-wp-markdown-native-schema-catalog.php', 'class-wp-markdown-native-sql-tokenizer.php', 'class-wp-markdown-native-query-ast.php', 'class-wp-markdown-native-query-parser.php', 'class-wp-markdown-native-table-providers.php', 'class-wp-markdown-native-option-mutations.php', 'class-wp-markdown-native-table-mutations.php', 'class-wp-markdown-native-schema-introspection.php', 'class-wp-markdown-native-schema-mutations.php', 'class-wp-markdown-native-query-executor.php', 'class-wp-markdown-native-query-runtime.php', 'class-wp-markdown-native-wpdb.php', 'class-wp-markdown-query-compatibility-comparator.php', 'class-wp-markdown-wpdb-result-snapshot.php', 'class-wp-markdown-native-shadow-verifier.php' ) as $file ) {
		copy( dirname( __DIR__ ) . '/inc/' . $file, $mdi . '/inc/' . $file );
	}
	copy( dirname( __DIR__ ) . '/inc/generated/wp-core-schema-catalog.php', $mdi . '/inc/generated/wp-core-schema-catalog.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-mysql-outbox.php', $mdi . '/inc/class-wp-markdown-mysql-outbox.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-mutation-impact.php', $mdi . '/inc/class-wp-markdown-mutation-impact.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-mysql-impact-adapter.php', $mdi . '/inc/class-wp-markdown-mysql-impact-adapter.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-mysql-semantic-drain.php', $mdi . '/inc/class-wp-markdown-mysql-semantic-drain.php' );
	$mysql_wpdb = $mdi . '/inc/class-wp-markdown-mysql-wpdb.php';
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-mysql-wpdb.php', $mysql_wpdb );
	if ( 'mysql-full-incompatible' === $scenario ) {
		file_put_contents( $mysql_wpdb, str_replace( 'BOOTSTRAP_ABI = 2', 'BOOTSTRAP_ABI = 3', (string) file_get_contents( $mysql_wpdb ) ) );
	}
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-durable-reconciliation-operations.php', $mdi . '/inc/class-wp-markdown-durable-reconciliation-operations.php' );
	copy( dirname( __DIR__ ) . '/inc/class-wp-markdown-reconciliation-adapters.php', $mdi . '/inc/class-wp-markdown-reconciliation-adapters.php' );
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
	if ( 'unknown' === $scenario ) {
		define( 'MARKDOWN_DB_BACKEND', 'unknown' );
	} elseif ( 'incomplete' === $scenario ) {
		define( 'MARKDOWN_DB_BACKEND', 'incomplete' );
		$GLOBALS['markdown_db_backend_declarations'] = array( 'incomplete' => array() );
	} elseif ( 'mdi-native' === $scenario ) {
		define( 'MARKDOWN_DB_BACKEND', 'mdi-native' );
		define( 'MARKDOWN_DB_STATE_DIR', $content . '/markdown' );
		define( 'MARKDOWN_DB_CONTENT_DIR', $content . '/markdown' );
		class wpdb {
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
	if ( in_array( $scenario, array( 'mysql-full', 'mysql-full-shadow', 'mysql-full-no-sink', 'mysql-full-incompatible', 'mysql-full-outbox-incompatible', 'mysql-full-prior-outbox' ), true ) ) {
		define( 'MARKDOWN_DB_BACKEND', 'mysql-full' );
		if ( 'mysql-full-shadow' === $scenario ) { define( 'MARKDOWN_DB_NATIVE_SHADOW', true ); }
		$GLOBALS['mdi_dropin_scenario'] = $scenario;
		class MDI_Dropin_Result { private array $rows; public function __construct( array $rows ) { $this->rows = $rows; } public function fetch_assoc(): ?array { return array_shift( $this->rows ); } public function free(): void {} }
		class MDI_Dropin_Statement {
			public int $affected_rows = 0;
			public string $error = '';
			private mixed $result = true;
			public function __construct( private MDI_Dropin_Connection $connection, private string $query ) {}
			public function bind_param( string $types, mixed &...$values ): bool { unset( $types, $values ); return true; }
			public function execute(): bool { $this->result = $this->connection->query( $this->query ); return false !== $this->result; }
			public function get_result(): mixed { return $this->result; }
			public function close(): void {}
		}
		class MDI_Dropin_Connection { public int $affected_rows = 0; public string $error = ''; public bool $upgraded = false; public function prepare( string $query ): MDI_Dropin_Statement { return new MDI_Dropin_Statement( $this, $query ); } public function query( string $query ): mixed {
			if ( str_starts_with( $query, 'ALTER TABLE' ) && str_contains( $query, 'ADD COLUMN `semantic_envelope`' ) ) { $this->upgraded = true; return true; }
			if ( str_starts_with( $query, 'SHOW COLUMNS' ) ) {
				$types = array( 'id' => 'bigint unsigned', 'event_id' => 'char(36)', 'schema_version' => 'smallint unsigned', 'state' => 'varchar(16)', 'database_name' => 'varchar(191)', 'blog_id' => 'bigint unsigned', 'table_prefix' => 'varchar(191)', 'base_prefix' => 'varchar(191)', 'event_kind' => 'varchar(16)', 'operation_name' => 'varchar(32)', 'payload' => 'longtext', 'payload_sha256' => 'char(64)', 'created_at' => 'datetime', 'available_at' => 'datetime', 'leased_until' => 'datetime', 'lease_token' => 'varchar(64)', 'worker_token' => 'varchar(64)', 'acknowledged_at' => 'datetime', 'acknowledgement_token' => 'varchar(64)', 'attempts' => 'int unsigned', 'reclaims' => 'int unsigned', 'failures' => 'int unsigned', 'last_error' => 'text', 'last_error_at' => 'datetime', 'semantic_envelope' => 'longtext' );
				if ( 'mysql-full-outbox-incompatible' === ( $GLOBALS['mdi_dropin_scenario'] ?? '' ) ) { unset( $types['last_error_at'] ); }
				if ( 'mysql-full-prior-outbox' === ( $GLOBALS['mdi_dropin_scenario'] ?? '' ) && ! $this->upgraded ) { unset( $types['semantic_envelope'] ); }
				$nullable = array( 'leased_until', 'lease_token', 'worker_token', 'acknowledged_at', 'acknowledgement_token', 'last_error', 'last_error_at', 'semantic_envelope' );
				$defaults = array( 'state' => 'pending', 'attempts' => '0', 'reclaims' => '0', 'failures' => '0' );
				$rows = array();
				foreach ( $types as $field => $type ) { $rows[] = array( 'Field' => $field, 'Type' => $type, 'Null' => in_array( $field, $nullable, true ) ? 'YES' : 'NO', 'Default' => $defaults[ $field ] ?? null, 'Extra' => 'id' === $field ? 'auto_increment' : '' ); }
				return new MDI_Dropin_Result( $rows );
			}
			if ( str_starts_with( $query, 'SHOW INDEX' ) ) { $rows = array(); foreach ( array( 'PRIMARY' => array( 'id' ), 'mdi_event' => array( 'event_id' ), 'mdi_claim' => array( 'state', 'available_at', 'id' ), 'mdi_lease_reclaim' => array( 'state', 'leased_until', 'id' ), 'mdi_scope' => array( 'database_name', 'blog_id', 'table_prefix', 'id' ), 'mdi_payload' => array( 'payload_sha256' ) ) as $key => $columns ) { foreach ( $columns as $offset => $column ) { $rows[] = array( 'Key_name' => $key, 'Seq_in_index' => $offset + 1, 'Column_name' => $column, 'Non_unique' => in_array( $key, array( 'PRIMARY', 'mdi_event' ), true ) ? 0 : 1, 'Sub_part' => null, 'Index_type' => 'BTREE' ); } } return new MDI_Dropin_Result( $rows ); }
			if ( str_starts_with( $query, 'SELECT `ENGINE`' ) ) { return new MDI_Dropin_Result( array( array( 'Engine' => 'InnoDB' ) ) ); }
			return true;
		} }
		class wpdb { public string $prefix = 'wp_'; public string $base_prefix = 'wp_'; protected object $dbh; public function __construct( $user, $password, $name, $host ) { $this->dbh = new MDI_Dropin_Connection(); } public function query( $query ) { return true; } }
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
		if ( in_array( $scenario, array( 'mysql-full', 'mysql-full-shadow', 'mysql-full-no-sink', 'mysql-full-prior-outbox' ), true ) && $GLOBALS['wpdb'] instanceof WP_Markdown_MySQL_WPDB && ( $GLOBALS['markdown_db_mysql_outbox'] ?? null ) instanceof WP_Markdown_MySQL_Outbox ) {
			if ( 'mysql-full-prior-outbox' === $scenario && ! $GLOBALS['wpdb']->markdown_db_mysql_connection()->upgraded ) { throw new RuntimeException( 'Prior outbox schema was not upgraded.' ); }
			if ( 'mysql-full-shadow' === $scenario && ! ( $GLOBALS['markdown_db_native_shadow_verifier'] ?? null ) instanceof WP_Markdown_Native_Shadow_Verifier ) { throw new RuntimeException( 'Native shadow verifier was not attached.' ); }
			echo "PASS: mysql-full installs the MDI-owned wpdb boundary.\n";
			exit( 0 );
		}
		if ( 'mdi-native' === $scenario && $GLOBALS['wpdb'] instanceof WP_Markdown_Native_WPDB && defined( 'MARKDOWN_DB_DROPIN' ) ) {
			echo "PASS: mdi-native installs the filesystem-backed wpdb boundary.\n";
			exit( 0 );
		}
		if ( in_array( $scenario, array( 'mysql-full-incompatible', 'mysql-full-outbox-incompatible' ), true ) && $GLOBALS['wpdb'] instanceof wpdb && str_starts_with( (string) ( $GLOBALS['markdown_db_mysql_full_diagnostic']['code'] ?? '' ), 'markdown_db_mysql_full_' ) ) {
			echo "PASS: {$scenario} explicitly falls back to stock wpdb.\n";
			exit( 0 );
		}
		if ( 'sqlite' !== $scenario || ! isset( $GLOBALS['wpdb'] ) ) {
			throw new RuntimeException( 'Expected backend bootstrap failure.' );
		}
		echo "PASS: SQLite remains the default drop-in backend.\n";
	} catch ( WP_Markdown_Unknown_Backend|WP_Markdown_Unsupported_Backend_Capability $error ) {
		$diagnostic = $error->get_diagnostic();
		if ( 'unknown' === $scenario && 'markdown_db_unknown_backend' === $diagnostic['code'] && 'unknown' === $diagnostic['backend'] ) {
			echo "PASS: unknown backend fails closed with a structured diagnostic.\n";
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
foreach ( array( 'sqlite', 'mdi-native', 'mysql-full', 'mysql-full-shadow', 'mysql-full-no-sink', 'mysql-full-prior-outbox', 'mysql-full-incompatible', 'mysql-full-outbox-incompatible', 'unknown', 'incomplete' ) as $scenario ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario ), $status );
	if ( 0 !== $status ) {
		++$failed;
	}
}
exit( $failed ? 1 : 0 );
