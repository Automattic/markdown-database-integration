<?php
/** Indeterminate canonical option reads fail closed instead of reporting every option as absent. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';
require_once __DIR__ . '/../inc/compatibility/class-wp-markdown-query-compatibility-comparator.php';

$GLOBALS['mdi_native_query_filter'] = null;
function apply_filters( string $hook, mixed $value ): mixed {
	$filter = $GLOBALS['mdi_native_query_filter'] ?? null;
	return 'query' === $hook && is_callable( $filter ) ? $filter( $value ) : $value;
}

class wpdb {
	public string $prefix = '';
	public string $options = '';
	public bool $ready = false;
	public bool $is_mysql = false;
	public int $num_queries = 0;
	public int $num_rows = 0;
	public int $rows_affected = 0;
	public int $insert_id = 0;
	public string $last_error = '';
	public ?string $last_query = null;
	public string $func_call = '';
	public array $last_result = array();
	protected array $col_info = array();
	protected bool $check_current_query = true;
	protected bool $result = false;

	public function set_prefix( string $prefix ): string {
		$this->prefix  = $prefix;
		$this->options = $prefix . 'options';
		return $prefix;
	}
	public function flush(): void {
		$this->last_result = array(); $this->col_info = array(); $this->last_query = null; $this->last_error = ''; $this->num_rows = 0; $this->rows_affected = 0;
	}
	public function add_placeholder_escape( string $value ): string { return $value; }
	public function remove_placeholder_escape( string $value ): string { return $value; }
	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) { $query = preg_replace( '/%s/', "'" . $this->_real_escape( $arg ) . "'", $query, 1 ); }
		return $query;
	}
	public function get_var( ?string $query = null, int $column = 0, int $row = 0 ): ?string {
		if ( null !== $query ) { $this->query( $query ); }
		$values = isset( $this->last_result[ $row ] ) ? array_values( get_object_vars( $this->last_result[ $row ] ) ) : array();
		return isset( $values[ $column ] ) && '' !== $values[ $column ] ? $values[ $column ] : null;
	}
	public function get_row( string $query ): ?object { $this->query( $query ); return $this->last_result[0] ?? null; }
	public function get_results( string $query ): array { $this->query( $query ); return $this->last_result; }
	public function get_col_info( string $type ): array { return array_map( static fn( object $column ): mixed => $column->{$type} ?? null, $this->col_info ); }
}
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-wpdb.php';

$GLOBALS['mdi_indeterminate_failures'] = array();
function mdi_indeterminate_assert( bool $condition, string $message ): void {
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $message . PHP_EOL;
	if ( ! $condition ) { $GLOBALS['mdi_indeterminate_failures'][] = $message; }
}
function mdi_indeterminate_remove( string $root ): void {
	foreach ( glob( $root . '/*' ) ?: array() as $path ) { is_dir( $path ) ? mdi_indeterminate_remove( $path ) : unlink( $path ); }
	rmdir( $root );
}
function mdi_indeterminate_write_salt( string $root, string $name, string $value, int $id ): void {
	file_put_contents(
		$root . '/_options/' . WP_Markdown_Canonical_Option_Path::filename( $name ),
		json_encode( array( 'option_id' => $id, 'option_name' => $name, 'option_value' => $value, 'autoload' => 'yes' ), JSON_THROW_ON_ERROR )
	);
}
function mdi_indeterminate_named_access( array $names ): WP_Markdown_Native_Table_Access {
	$schema    = WP_Markdown_Native_Runtime_Factory::options_schema();
	$predicate = new WP_Markdown_Native_Query_Predicate( 'option_name', 'IN', $names );
	return new WP_Markdown_Native_Table_Access( array( 'option_name', 'option_value' ), $predicate, $schema->natural_order(), PHP_INT_MAX );
}

$auth_key  = 'first-generation-auth-key-material-do-not-rotate';
$auth_salt = 'first-generation-auth-salt-material-do-not-rotate';
$salt_names = array( 'auth_key', 'auth_salt' );

// A healthy store serves the stored salt material and reports a genuinely
// absent option as a determinate empty set.
$healthy_root = sys_get_temp_dir() . '/mdi-native-option-indeterminate-a-' . bin2hex( random_bytes( 6 ) );
mkdir( $healthy_root . '/_options', 0777, true );
mdi_indeterminate_write_salt( $healthy_root, 'auth_key', $auth_key, 1 );
mdi_indeterminate_write_salt( $healthy_root, 'auth_salt', $auth_salt, 2 );

$healthy_provider = new WP_Markdown_Native_Option_Provider( $healthy_root, WP_Markdown_Native_Runtime_Factory::options_schema() );
$healthy_rows = $healthy_provider->read( mdi_indeterminate_named_access( $salt_names ) );
$healthy_values = array_column( is_array( $healthy_rows ) ? $healthy_rows : array(), 'option_value' );
mdi_indeterminate_assert( array( $auth_key, $auth_salt ) === $healthy_values, 'a healthy store serves the stored auth_key and auth_salt material' );

$healthy_absent = $healthy_provider->read( mdi_indeterminate_named_access( array( 'never_generated_option' ) ) );
mdi_indeterminate_assert( array() === $healthy_absent, 'a genuinely absent option still resolves cleanly as verified absent' );

$healthy_runtime = new WP_Markdown_Native_Option_Query_Runtime( $healthy_root );
$baseline = $healthy_runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name IN ('auth_key','auth_salt')" ) );
$baseline_rows = array_column( array_map( 'get_object_vars', $baseline->wpdb_state()['last_result'] ), 'option_value' );
mdi_indeterminate_assert( 2 === $baseline->return_value() && array( $auth_key, $auth_salt ) === $baseline_rows, 'a healthy option query returns the salt rows' );

$absent = $healthy_runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'never_generated_option' LIMIT 1" ) );
mdi_indeterminate_assert( 0 === $absent->return_value() && 0 === $absent->wpdb_state()['num_rows'] && null === $absent->diagnostic(), 'a genuinely absent option still returns cleanly as absent' );

// The defect site: options_root() used to translate a failed stat of the
// options directory into a bare null, and named_rows() reported that as a
// successful empty set — every option absent, as a success. An unreadable
// state root is the deterministic stand-in for a transient stat failure, and
// the provider is exercised directly because the query runtime fails even
// earlier, on its transaction lock.
$locked_root = sys_get_temp_dir() . '/mdi-native-option-indeterminate-b-' . bin2hex( random_bytes( 6 ) );
mkdir( $locked_root . '/_options', 0777, true );
mdi_indeterminate_write_salt( $locked_root, 'auth_key', $auth_key, 1 );
mdi_indeterminate_write_salt( $locked_root, 'auth_salt', $auth_salt, 2 );
$locked_provider = new WP_Markdown_Native_Option_Provider( $locked_root, WP_Markdown_Native_Runtime_Factory::options_schema() );
$locked_runtime  = new WP_Markdown_Native_Option_Query_Runtime( $locked_root );
chmod( $locked_root, 0000 );

$locked_salt_read = $locked_provider->read( mdi_indeterminate_named_access( $salt_names ) );
mdi_indeterminate_assert(
	$locked_salt_read instanceof WP_Markdown_Query_Result
		&& false === $locked_salt_read->return_value()
		&& 'markdown_db_native_unsafe_path' === ( $locked_salt_read->diagnostic()['code'] ?? null )
		&& 'indeterminate_options_directory' === ( $locked_salt_read->diagnostic()['reason'] ?? null ),
	'an unresolved options directory fails a salt lookup instead of reporting every option absent'
);

$locked_missing_read = $locked_provider->read( mdi_indeterminate_named_access( array( 'never_generated_option' ) ) );
mdi_indeterminate_assert( $locked_missing_read instanceof WP_Markdown_Query_Result && false === $locked_missing_read->return_value(), 'an unresolved options directory cannot report even a missing option as verified absent' );

$locked_all_read = $locked_provider->read( new WP_Markdown_Native_Table_Access( array( 'option_name', 'option_value' ), null, WP_Markdown_Native_Runtime_Factory::options_schema()->natural_order(), PHP_INT_MAX ) );
mdi_indeterminate_assert( $locked_all_read instanceof WP_Markdown_Query_Result && false === $locked_all_read->return_value(), 'the whole-table read fails closed against an unresolved options directory' );

// The query surface a WordPress consumer actually reads through.
$salt_read = $locked_runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name IN ('auth_key','auth_salt')" ) );
mdi_indeterminate_assert( false === $salt_read->return_value() && array() === $salt_read->wpdb_state()['last_result'] && null !== $salt_read->diagnostic(), 'an unreadable store fails the salt query rather than returning an empty set' );

$missing_read = $locked_runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'never_generated_option' LIMIT 1" ) );
mdi_indeterminate_assert( false === $missing_read->return_value() && null !== $missing_read->diagnostic(), 'an unreadable store cannot report even a missing option as verified absent' );

$alloptions_read = $locked_runtime->execute( new WP_Markdown_Query_Request( 'SELECT option_name, option_value FROM wp_options' ) );
mdi_indeterminate_assert( false === $alloptions_read->return_value() && array() === $alloptions_read->wpdb_state()['last_result'], 'the alloptions snapshot fails closed against an unreadable store' );

$alloptions_again = $locked_runtime->execute( new WP_Markdown_Query_Request( 'SELECT option_name, option_value FROM wp_options' ) );
mdi_indeterminate_assert( false === $alloptions_again->return_value(), 'fail-closed persists while the store remains unresolved' );

// The consumer surface wp_salt() reads through: a failed query is signalled
// by a false return with last_error and the structured diagnostic set, which
// is what stops the falsy-read regeneration branch from ever being reached.
$database = new WP_Markdown_Native_WPDB( $locked_runtime );
$rotated  = array();
foreach ( $salt_names as $scheme_type ) {
	$found = $database->query( "SELECT option_value FROM {$database->options} WHERE option_name = '{$scheme_type}' LIMIT 1" );
	if ( false === $found ) {
		continue;
	}
	if ( null === $database->get_var( "SELECT option_value FROM {$database->options} WHERE option_name = '{$scheme_type}' LIMIT 1" ) ) {
		$rotated[] = $scheme_type;
	}
}
mdi_indeterminate_assert(
	array() === $rotated && '' !== $database->last_error && null !== $database->last_runtime_diagnostic,
	'wp_salt-shaped regeneration never fires off an indeterminate read'
);

chmod( $locked_root, 0755 );
$auth_key_row  = (string) file_get_contents( $locked_root . '/_options/auth_key.json' );
$auth_salt_row = (string) file_get_contents( $locked_root . '/_options/auth_salt.json' );
mdi_indeterminate_assert(
	str_contains( $auth_key_row, $auth_key ) && str_contains( $auth_salt_row, $auth_salt ),
	'stored auth_key and auth_salt files survived the indeterminate window unchanged'
);

$restored = $locked_runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name IN ('auth_key','auth_salt')" ) );
$restored_rows = array_column( array_map( 'get_object_vars', $restored->wpdb_state()['last_result'] ), 'option_value' );
mdi_indeterminate_assert( 2 === $restored->return_value() && array( $auth_key, $auth_salt ) === $restored_rows, 'restoring the store serves the original salt material without a rotation' );

mdi_indeterminate_remove( $healthy_root );
mdi_indeterminate_remove( $locked_root );
exit( $GLOBALS['mdi_indeterminate_failures'] ? 1 : 0 );
