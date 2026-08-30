<?php
/** Full warm-primary boundary coverage for issue #254. */

declare( strict_types=1 );

$root = sys_get_temp_dir() . '/mdi-warm-primary-health-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root . '/content', 0755, true );
mkdir( $root . '/state', 0755, true );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MARKDOWN_DB_MODE', 'primary' );
define( 'MARKDOWN_DB_CONTENT_DIR', $root . '/content' );
define( 'MARKDOWN_DB_STATE_DIR', $root . '/state' );
define( 'MARKDOWN_DB_INDEX_PATH', $root . '/index.sqlite' );
define( 'MARKDOWN_DB_PRIMARY_BOOTSTRAP_DEADLINE_MS', 250 );
$GLOBALS['table_prefix'] = 'wp_';

final class MDI_Warm_Primary_Bail extends RuntimeException {}

class WP_SQLite_DB {
	public mixed $dbh = null;
	public string $dbname;
	public string $last_error = '';
	public bool $ready = false;
	public string $prefix = 'wp_';
	public int $num_queries = 0;
	public string $last_query = '';
	public function __construct( string $dbname ) { $this->dbname = $dbname; $this->db_connect(); }
	public function init_charset(): void {}
	public function bail( string $message, string $code ): void { throw new MDI_Warm_Primary_Bail( $code . ': ' . $message ); }
	public function set_prefix( $prefix, $set_table_names = true ) { return $this->prefix; }
	public function query( $query ) { return false; }
	public function set_sql_mode(): void {}
}

class WP_Markdown_Storage {
	public static int $canonical_reads = 0;
	public function __construct( string $content_dir, array $excluded_types = array() ) {}
	public function set_content_layout_profile( string $profile ): void {}
	public function set_post_resolver( callable $resolver ): void {}
	public function set_meta_resolver( callable $resolver ): void {}
	public function set_terms_resolver( callable $resolver ): void {}
	public function set_index_writer( callable $writer ): void {}
	public function get_excluded_types(): array { return array(); }
}

class WP_Markdown_Write_Engine {
	public static int $recoveries = 0;
	public function __construct( mixed ...$args ) {}
	public function recover_pending(): array {
		++self::$recoveries;
		usleep( 350000 );
		return array();
	}
}

final class WP_Markdown_Loader_Outcome {
	public static function retained( string $reason ): self { return new self(); }
}

class WP_Markdown_Loader {
	public static int $loads = 0;
	public static int $retentions = 0;
	public static int $owners = 0;
	public function __construct( mixed ...$args ) {}
	public function retain_previous_index( string $reason ): WP_Markdown_Loader_Outcome { ++self::$retentions; return WP_Markdown_Loader_Outcome::retained( $reason ); }
	public function load_all(): void { ++self::$loads; }
	public function sync_incremental_if_available( string $identity, ?callable $before_sync = null ): bool {
		++self::$owners;
		if ( null !== $before_sync ) { $before_sync(); }
		return true;
	}
}

final class MDI_Warm_Primary_Connection {
	private PDO $pdo;
	public function __construct() { $this->pdo = new PDO( 'sqlite::memory:' ); }
	public function get_pdo(): PDO { return $this->pdo; }
}

final class MDI_Warm_Primary_Driver {
	private MDI_Warm_Primary_Connection $connection;
	public function __construct() { $this->connection = new MDI_Warm_Primary_Connection(); }
	public function operations( callable $prefix ): object { return new stdClass(); }
	public function get_connection(): MDI_Warm_Primary_Connection { return $this->connection; }
	public function set_write_engine( WP_Markdown_Write_Engine $engine ): void {}
	public function finish_warm_bootstrap(): void {}
}

final class WP_Markdown_SQLite_Runtime_Adapter {
	public static array $attached = array();
	public static function create_runtime( string $path, ?PDO $pdo, string $database, WP_Markdown_Storage $storage, WP_Markdown_Backend_Capabilities $capabilities, bool $warm = false ): object {
		self::$attached[] = $path;
		return new MDI_Warm_Primary_Driver();
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-db.php';

$active = MARKDOWN_DB_INDEX_PATH;
$previous = $active . '.previous';
$handle = fopen( $active, 'wb' );
fwrite( $handle, 'unavailable' );
ftruncate( $handle, 2 * 1024 * 1024 * 1024 );
fclose( $handle );
file_put_contents( $previous, "SQLite format 3\0" . str_repeat( "\0", 84 ) );

$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . PHP_EOL;
	if ( ! $condition ) { $failures[] = $message; }
};

$started = hrtime( true );
$database = new WP_Markdown_DB( 'wordpress' );
$boot_ms = ( hrtime( true ) - $started ) / 1000000;
$evidence = $database->get_primary_index_evidence();

$assert( $boot_ms < MARKDOWN_DB_PRIMARY_BOOTSTRAP_DEADLINE_MS, 'ordinary warm boot stays inside the hard deadline with a 2 GiB unavailable active index' );
$assert( array( $previous ) === WP_Markdown_SQLite_Runtime_Adapter::$attached, 'warm boot opens only the last complete index generation' );
$assert( 0 === WP_Markdown_Write_Engine::$recoveries && 0 === WP_Markdown_Loader::$owners, 'warm bootstrap never enters pending recovery or synchronization ownership' );
$assert( 1 === WP_Markdown_Loader::$retentions && 0 === WP_Markdown_Loader::$loads, 'warm bootstrap serves retained rows without canonical reconstruction' );
$assert( 'markdown_db_primary_index_recovered_previous' === ( $evidence['code'] ?? null ) && 'previous' === ( $evidence['served_generation'] ?? null ) && 'invalid_sqlite_header' === ( $evidence['recovered_from']['reason'] ?? null ), 'fallback publishes typed previous-generation recovery evidence' );
$assert( 2 * 1024 * 1024 * 1024 === filesize( $active ), 'the unavailable rebuildable index is not deleted or rewritten during bootstrap' );

$owner_started = hrtime( true );
$assert( $database->synchronize_primary_index(), 'the explicit maintenance boundary acquires synchronization ownership' );
$owner_ms = ( hrtime( true ) - $owner_started ) / 1000000;
$assert( $owner_ms >= 300 && 1 === WP_Markdown_Write_Engine::$recoveries && 1 === WP_Markdown_Loader::$owners, 'slow pending recovery runs under the explicit synchronization owner only' );

unlink( $previous );
WP_Markdown_SQLite_Runtime_Adapter::$attached = array();
$failure = null;
$started = hrtime( true );
try {
	new WP_Markdown_DB( 'wordpress' );
} catch ( MDI_Warm_Primary_Bail $error ) {
	$failure = $error->getMessage();
}
$failed_ms = ( hrtime( true ) - $started ) / 1000000;
$diagnostic = $GLOBALS['markdown_db_primary_index_evidence'] ?? null;
$assert( $failed_ms < MARKDOWN_DB_PRIMARY_BOOTSTRAP_DEADLINE_MS, 'an unavailable index without a complete fallback fails within the hard deadline' );
$assert( is_string( $failure ) && str_contains( $failure, '[markdown_db_primary_index_unavailable]' ), 'failure is typed instead of reported as a generic database connection error' );
$assert( 'invalid_sqlite_header' === ( $diagnostic['reason'] ?? null ) && empty( WP_Markdown_SQLite_Runtime_Adapter::$attached ), 'typed evidence rejects the unavailable index before SQLite attachment' );
$assert( 0 === WP_Markdown_Loader::$loads && 2 * 1024 * 1024 * 1024 === filesize( $active ), 'failure neither reconstructs inline nor changes canonical authority' );

unlink( $active );
rmdir( $root . '/state' );
rmdir( $root . '/content' );
rmdir( $root );

exit( $failures ? 1 : 0 );
