<?php
/**
 * Regression coverage for SQLite SQL mode bootstrap compatibility.
 *
 * Usage: php tests/smoke-sql-mode-bootstrap.php
 */

declare( strict_types=1 );

$legacy = in_array( '--legacy', $argv, true );
if ( $legacy ) {
	define( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API', true );
}

$root = sys_get_temp_dir() . '/mdi-sql-mode-bootstrap-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', $root );
define( 'FQDB', $root . '/index.sqlite' );
define( 'MARKDOWN_DB_CONTENT_DIR', $root . '/content' );
define( 'MARKDOWN_DB_STATE_DIR', $root . '/state' );
define( 'MARKDOWN_DB_MODE', 'mirror' );

class WP_SQLite_DB {
	public mixed $dbh = null;
	public string $dbname;
	public string $last_error = '';
	public bool $ready = false;
	public bool $zero_dates_allowed = false;
	public string $prefix = 'wp_';
	public int $sql_mode_calls = 0;

	public function __construct( string $dbname ) {
		$this->dbname = $dbname;
		$this->db_connect();
	}

	public function init_charset(): void {}
	public function bail( string $message, string $error_code ): void {
		unset( $error_code );
		throw new RuntimeException( $message );
	}
	public function set_prefix( $prefix, $set_table_names = true ) {
		unset( $set_table_names );
		$old          = $this->prefix;
		$this->prefix = $prefix;
		return $old;
	}
	public function set_sql_mode( array $modes = array() ): void {
		unset( $modes );
		++$this->sql_mode_calls;
		$this->zero_dates_allowed = true;
	}
}

class WP_SQLite_Connection {
	private PDO $pdo;

	public function __construct( array $options ) {
		$this->pdo = $options['pdo'] ?? new PDO( 'sqlite:' . $options['path'] );
	}

	public function get_pdo(): PDO {
		return $this->pdo;
	}
}

class WP_Markdown_Storage {
	public function __construct( string $content_dir, array $excluded_types = array() ) {
		unset( $content_dir, $excluded_types );
	}
	public function set_post_resolver( callable $resolver ): void { unset( $resolver ); }
	public function set_meta_resolver( callable $resolver ): void { unset( $resolver ); }
	public function set_terms_resolver( callable $resolver ): void { unset( $resolver ); }
	public function set_index_writer( callable $writer ): void { unset( $writer ); }
}

class WP_Markdown_Driver {
	private WP_SQLite_Connection $connection;

	public function __construct( WP_SQLite_Connection $connection, string $dbname, WP_Markdown_Storage $storage ) {
		unset( $dbname, $storage );
		$this->connection = $connection;
	}

	public function get_connection(): WP_SQLite_Connection {
		return $this->connection;
	}

	public function set_write_engine( WP_Markdown_Write_Engine $write_engine ): void {
		unset( $write_engine );
	}

	public function query( string $sql ): array {
		unset( $sql );
		return array();
	}

	public function update_file_index( int $post_id, string $relative_path, int $mtime, int $size ): void {
		unset( $post_id, $relative_path, $mtime, $size );
	}
}

class WP_Markdown_Write_Engine {
	public function __construct( mixed ...$args ) { unset( $args ); }
}

class WP_Markdown_Loader {}

require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-db.php';

$database = new WP_Markdown_DB( 'wordpress' );
$failed   = 0;

function mdi_sql_mode_assert( bool $condition, string $label ): void {
	global $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $condition ) {
		++$failed;
	}
}

mdi_sql_mode_assert( true === $database->ready, 'SQLite database boot completes' );
if ( $legacy ) {
	mdi_sql_mode_assert( 0 === $database->sql_mode_calls, 'legacy result API skips incompatible SQL mode discovery' );
} else {
	mdi_sql_mode_assert( 1 === $database->sql_mode_calls, 'canonical result API normalizes WordPress SQL modes' );
	mdi_sql_mode_assert( $database->zero_dates_allowed, 'canonical bootstrap allows WordPress zero-date sentinels' );
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --legacy';
	passthru( $command, $legacy_status );
	mdi_sql_mode_assert( 0 === $legacy_status, 'legacy result API remains bootable' );
}

unset( $database );
@unlink( FQDB );
@rmdir( MARKDOWN_DB_CONTENT_DIR );
@rmdir( MARKDOWN_DB_STATE_DIR );
@rmdir( $root );

exit( $failed ? 1 : 0 );
