<?php
/** Native wpdb lifecycle methods must never require a mysqli connection. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'wordpress_tests' );

class wpdb {
	public string $prefix = '';
	public string $base_prefix = '';
	public bool $ready = false;
	public int $num_queries = 0;
	public int $num_rows = 0;
	public int $rows_affected = 0;
	public int $insert_id = 0;
	public string $last_error = '';
	public ?string $last_query = null;
	public ?bool $is_mysql = null;
	public string $func_call = '';
	public array $last_result = array();
	protected array $col_info = array();
	protected bool $check_current_query = true;
	protected bool $result = false;

	public function set_prefix( string $prefix ): string {
		$this->prefix = $prefix;
		$this->base_prefix = $prefix;
		return $prefix;
	}

	public function flush(): void {
		$this->last_result = array();
		$this->col_info = array();
		$this->last_error = '';
	}

	public function add_placeholder_escape( string $value ): string { return $value; }
	public function remove_placeholder_escape( string $value ): string { return $value; }
}

require_once __DIR__ . '/../inc/native/class-wp-markdown-native-wpdb.php';

$root = sys_get_temp_dir() . '/mdi-native-wpdb-lifecycle-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$database = new WP_Markdown_Native_WPDB( WP_Markdown_Native_Runtime_Factory::runtime( $root ) );

$selection = $database->select( DB_NAME );
$closed = $database->close();
$reconnected = $database->db_connect();
$invalid_selection = $database->select( '' );
$invalid_errno = $database->last_errno;
$invalid_error = $database->last_error;
$reconnected_after_error = $database->check_connection( false );

$checks = array(
	'database selection succeeds without mysqli, keeps wpdb return semantics, and preserves the canonical prefix' => null === $selection && 'wp_' === $database->prefix,
	'native wpdb advertises the MySQL dialect without creating a mysqli connection' => true === $database->is_mysql,
	'logical close and reconnect report wpdb lifecycle state' => true === $closed && true === $reconnected && true === $database->ready,
	'invalid selection exposes a normal database error state' => false === $invalid_selection && 1049 === $invalid_errno && 'Unknown database' === $invalid_error,
	'connection checks restore the ready state without a reconnect loop' => true === $reconnected_after_error && true === $database->ready && 0 === $database->last_errno,
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
