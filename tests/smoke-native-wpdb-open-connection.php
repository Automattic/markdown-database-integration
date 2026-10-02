<?php
/** Native wpdb opens independent logical connections that contend for advisory locks like MySQL connections. */

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

$root = sys_get_temp_dir() . '/mdi-native-open-connection-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the open-connection probe fixture.' );
}

/** @return int|string|null */
function open_connection_scalar( WP_Markdown_Native_WPDB $db, string $sql ): int|string|null {
	$db->query( $sql );
	return isset( $db->last_result[0] ) ? current( get_object_vars( $db->last_result[0] ) ) : null;
}

$factory = static fn(): WP_Markdown_Query_Runtime => WP_Markdown_Native_Runtime_Factory::runtime( $root );
$primary = new WP_Markdown_Native_WPDB( $factory(), 'wp_', $factory );
$second  = $primary->open_connection();
$third   = $second->open_connection();

$owner_acquire     = open_connection_scalar( $primary, "SELECT GET_LOCK('venue-lock', 0)" );
$second_contended  = open_connection_scalar( $second, "SELECT GET_LOCK('venue-lock', 0)" );
$second_other_lock = open_connection_scalar( $second, "SELECT GET_LOCK('other-lock', 0)" );
$third_contended   = open_connection_scalar( $third, "SELECT GET_LOCK('other-lock', 0)" );
$owner_release     = open_connection_scalar( $primary, "SELECT RELEASE_LOCK('venue-lock')" );
$second_acquire    = open_connection_scalar( $second, "SELECT GET_LOCK('venue-lock', 0)" );
$second->close();
$after_close       = open_connection_scalar( $third, "SELECT GET_LOCK('other-lock', 0)" );

$without_factory = new WP_Markdown_Native_WPDB( $factory(), 'wp_' );
try {
	$without_factory->open_connection();
	$fails_closed = false;
} catch ( LogicException ) {
	$fails_closed = true;
}

$assertions = array(
	'open_connection returns a distinct native wpdb' => $second instanceof WP_Markdown_Native_WPDB && $second !== $primary && $third !== $second,
	'a held lock excludes the opened connection' => '1' === $owner_acquire && '0' === $second_contended,
	'the opened connection owns its own locks' => '1' === $second_other_lock && '0' === $third_contended,
	'release on the owner transfers the lock' => '1' === $owner_release && '1' === $second_acquire,
	'closing an opened connection releases its locks' => '1' === $after_close,
	'the opened connection keeps the table prefix' => 'wp_' === $second->prefix,
	'without a factory open_connection fails closed' => $fails_closed,
);
$passed = ! in_array( false, $assertions, true );
fwrite( $passed ? STDOUT : STDERR, json_encode( array( 'assertions' => $assertions, 'passed' => $passed ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n" );
exit( $passed ? 0 : 1 );
