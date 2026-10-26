<?php
/** WordPress-shaped drop-in bootstrap coverage. */
declare( strict_types=1 );

if ( 2 !== $argc ) {
	foreach ( array( 'default-native', 'leftover-files', 'mdi-native', 'sqlite', 'mysql-content', 'mysql-full', 'unknown', 'environment-unknown', 'missing-plugin', 'missing-wpdb', 'mu-plugin' ) as $scenario ) {
		passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $scenario ), $status );
		if ( 0 !== $status ) { exit( $status ); }
	}
	exit( 0 );
}

$scenario = $argv[1];
putenv( 'MARKDOWN_DB_BACKEND' );
$root = sys_get_temp_dir() . '/mdi-dropin-' . $scenario . '-' . getmypid();
$content = $root . '/wp-content';
$plugin_parent = 'mu-plugin' === $scenario ? 'mu-plugins' : 'plugins';
$mdi = $content . '/' . $plugin_parent . '/markdown-database-integration';
mkdir( dirname( $mdi ), 0755, true );
mkdir( $content . '/db', 0755, true );
if ( 'missing-plugin' !== $scenario ) {
	symlink( dirname( __DIR__ ), $mdi );
}
register_shutdown_function( static function () use ( $root, $mdi ): void {
	if ( is_link( $mdi ) ) { unlink( $mdi ); }
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $root );
} );
copy( dirname( __DIR__ ) . '/db.php', $content . '/db.php' );
define( 'ABSPATH', $root . '/' );
define( 'WP_CONTENT_DIR', $content );
if ( in_array( $scenario, array( 'mdi-native', 'sqlite', 'mysql-content', 'mysql-full', 'unknown' ), true ) ) {
	define( 'MARKDOWN_DB_BACKEND', $scenario );
}
if ( 'environment-unknown' === $scenario ) { putenv( 'MARKDOWN_DB_BACKEND=environment-unknown' ); }
if ( 'missing-wpdb' !== $scenario ) {
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
$leftovers = array();
if ( 'leftover-files' === $scenario ) {
	mkdir( $content . '/database', 0755 );
	foreach ( array( 'database/.ht.sqlite', 'database/.ht.sqlite.php', 'markdown-index.sqlite' ) as $relative ) {
		$path = $content . '/' . $relative;
		file_put_contents( $path, "SQLite format 3\0" . str_repeat( 'non-empty retired database', 100 ) );
		chmod( $path, 0444 );
		touch( $path, 1000000000 );
		$leftovers[ $path ] = array( hash_file( 'sha256', $path ), filemtime( $path ), filesize( $path ) );
	}
	file_put_contents( $content . '/database/db-path.php', '<?php throw new RuntimeException("Retired metadata must never execute");' );
}
try {
	require $content . '/db.php';
	if ( ! in_array( $scenario, array( 'default-native', 'leftover-files', 'mdi-native', 'mu-plugin' ), true ) ) {
		throw new LogicException( 'Expected bootstrap failure.' );
	}
	if ( 'mdi-native' !== MARKDOWN_DB_BACKEND || ! $GLOBALS['wpdb'] instanceof WP_Markdown_Native_WPDB || ! MARKDOWN_DB_DROPIN ) {
		throw new LogicException( 'Native runtime was not installed.' );
	}
	foreach ( $leftovers as $path => $before ) {
		clearstatcache( true, $path );
		if ( $before !== array( hash_file( 'sha256', $path ), filemtime( $path ), filesize( $path ) ) ) {
			throw new LogicException( 'Native bootstrap changed a retired database file.' );
		}
	}
	foreach ( array( 'DB_PATH', 'FQDB', 'DB_FILE', 'DB_DIR', 'MARKDOWN_DB_INDEX_PATH', 'MARKDOWN_DB_MODE' ) as $constant ) {
		if ( defined( $constant ) ) { throw new LogicException( 'Bootstrap published retired configuration: ' . $constant ); }
	}
} catch ( WP_Markdown_Unknown_Backend $error ) {
	$diagnostic = $error->get_diagnostic();
	if ( ! in_array( $scenario, array( 'sqlite', 'mysql-content', 'mysql-full', 'unknown', 'environment-unknown' ), true ) || 'markdown_db_unknown_backend' !== $diagnostic['code'] || MARKDOWN_DB_BACKEND !== $diagnostic['backend'] || isset( $GLOBALS['wpdb'] ) ) {
		throw $error;
	}
} catch ( RuntimeException $error ) {
	if ( ! in_array( $scenario, array( 'missing-plugin', 'missing-wpdb' ), true ) || isset( $GLOBALS['wpdb'] ) || ! str_contains( $error->getMessage(), 'requires' ) ) { throw $error; }
}
echo "PASS: {$scenario}\n";
