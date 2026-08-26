<?php
/** Generic registry proof with a JSON users snapshot and wpdb helpers. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-query-runtime.php';

class wpdb {
	public string $prefix = '';
	public string $options = '';
	public bool $ready = false;
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
		$this->last_result = array();
		$this->col_info    = array();
		$this->last_error  = '';
		$this->num_rows    = 0;
	}

	public function add_placeholder_escape( string $value ): string {
		return $value;
	}

	public function remove_placeholder_escape( string $value ): string {
		return $value;
	}

	public function get_row( string $query ): ?object {
		$this->query( $query );
		return $this->last_result[0] ?? null;
	}

	public function get_col_info( string $type ): array {
		return array_map( static fn( object $column ): mixed => $column->{$type} ?? null, $this->col_info );
	}
}
require_once __DIR__ . '/../inc/class-wp-markdown-native-wpdb.php';

$root = sys_get_temp_dir() . '/mdi-native-generic-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the generic native fixture.' );
}

$users = array(
	array(
		'ID'                  => '2',
		'user_login'          => 'zoe',
		'user_pass'           => 'hash',
		'user_nicename'       => 'zoe',
		'user_email'          => 'zoe@example.test',
		'user_url'            => '',
		'user_registered'     => '2024-01-02 03:04:05',
		'user_activation_key' => '',
		'user_status'         => '0',
		'display_name'        => 'Zoe',
	),
	array(
		'ID'                  => '1',
		'user_login'          => 'admin',
		'user_pass'           => 'hash',
		'user_nicename'       => 'admin',
		'user_email'          => 'admin@example.test',
		'user_url'            => '',
		'user_registered'     => '0000-00-00 00:00:00',
		'user_activation_key' => '',
		'user_status'         => '0',
		'display_name'        => 'Admin',
	),
);
$users_path = $root . '/_tables/users.json';
file_put_contents( $users_path, json_encode( $users, JSON_THROW_ON_ERROR ) );

$registry = WP_Markdown_Native_Runtime_Factory::registry( $root );
$runtime = new WP_Markdown_Native_Query_Runtime( $registry );

$login = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT * FROM wp_users WHERE user_login = 'admin' LIMIT 1" )
);
$id = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT user_login, ID FROM wp_users WHERE ID = 2' )
);
$missing = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT ID FROM wp_users WHERE user_login = 'missing'" )
);
$ordered = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT ID, user_login FROM wp_users WHERE user_login IN ('zoe', 'admin', 'zoe')" )
);
$bounded_with_total = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT SQL_CALC_FOUND_ROWS ID FROM wp_users WHERE user_login IN ('zoe', 'admin') LIMIT 1" )
);
$qualified_star = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT wp_users.* FROM wp_users WHERE ID IN (1)' )
);
$grouped_bounded = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT SQL_CALC_FOUND_ROWS ID FROM wp_users WHERE 1=1 AND ((user_login IN ('zoe', 'admin'))) ORDER BY ID DESC LIMIT 1, 1" )
);
$found_rows = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT FOUND_ROWS()' ) );
$unsupported_string_order = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT user_login FROM wp_users ORDER BY user_login ASC' )
);
$case_insensitive = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT ID FROM wp_users WHERE user_login = 'ADMIN'" )
);
$unsupported_unicode = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT ID FROM wp_users WHERE user_login = 'admiñ'" )
);
$email = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT ID FROM wp_users WHERE user_email = 'zoe@example.test'" )
);
$overflow = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT ID FROM wp_users WHERE ID = 999999999999999999999999999999' )
);
$invalid_in = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT ID FROM wp_users WHERE user_login IN 'admin'" )
);
$invalid_equals = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT ID FROM wp_users WHERE user_login = ('admin', 'zoe')" )
);

$database  = new WP_Markdown_Native_WPDB( $runtime );
$wpdb_user = $database->get_row( "SELECT * FROM wp_users WHERE user_login = 'admin' LIMIT 1" );
$metadata  = $database->get_col_info( 'type' );

$reordered_user = array( 'user_login' => $users[0]['user_login'] );
foreach ( $users[0] as $column => $value ) {
	if ( 'user_login' !== $column ) {
		$reordered_user[ $column ] = $value;
	}
}
file_put_contents( $users_path, json_encode( array( $reordered_user ), JSON_THROW_ON_ERROR ) );
$reordered = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT * FROM wp_users WHERE user_login = 'zoe'" )
);

file_put_contents( $users_path, json_encode( array( $users[0], $users[0] ), JSON_THROW_ON_ERROR ) );
$malformed = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_users' ) );
$malformed_count = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT COUNT(*) FROM wp_users' ) );

$outside = dirname( $root ) . '/mdi-native-users-outside-' . bin2hex( random_bytes( 4 ) ) . '.json';
file_put_contents( $outside, json_encode( $users, JSON_THROW_ON_ERROR ) );
@unlink( $users_path );
$symlink = function_exists( 'symlink' ) && @symlink( $outside, $users_path );
$unsafe_symlink = $symlink
	? $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_users' ) )
	: null;
if ( $symlink ) {
	@unlink( $users_path );
}
$hardlink = function_exists( 'link' ) && @link( $outside, $users_path );
$unsafe_hardlink = $hardlink
	? $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_users' ) )
	: null;

@unlink( $users_path );
$absent = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_users' ) );
$too_wide = $users[0];
$too_wide['user_login'] = str_repeat( 'x', 61 );
file_put_contents( $users_path, json_encode( array( $too_wide ), JSON_THROW_ON_ERROR ) );
$invalid_width = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_users' ) );
$multisite_user = $users[1];
$multisite_user['spam'] = '0';
$multisite_user['deleted'] = '0';
$multisite_schema = WP_Markdown_Native_Runtime_Factory::users_schema( true );

$checks = array(
	'native wpdb reports conservative database capabilities without mysqli' => '0.0.0-mdi-native' === $database->db_server_info()
		&& '0.0.0' === $database->db_version(),
	'generic users login lookup returns the core-shaped row' => 'admin' === ( $login->wpdb_state()['last_result'][0]->user_login ?? null )
		&& 10 === count( get_object_vars( $login->wpdb_state()['last_result'][0] ?? (object) array() ) ),
	'indexed numeric lookup and requested projection order work' => 'zoe' === ( $id->wpdb_state()['last_result'][0]->user_login ?? null )
		&& '2' === ( $id->wpdb_state()['last_result'][0]->ID ?? null ),
	'missing users return an empty successful result' => 0 === $missing->return_value(),
	'natural order and IN set semantics are provider-neutral' => array( '1', '2' ) === array_map(
		static fn( object $row ): string => $row->ID,
		$ordered->wpdb_state()['last_result']
	),
	'SQL_CALC_FOUND_ROWS retains the unbounded matching count behind a bounded result' => 1 === $bounded_with_total->return_value()
		&& '1' === ( $bounded_with_total->wpdb_state()['last_result'][0]->ID ?? null )
		&& 2 === $runtime->last_found_rows(),
	'qualified single-table wildcards return the complete schema-shaped row' => 10 === count( get_object_vars( $qualified_star->wpdb_state()['last_result'][0] ?? (object) array() ) )
		&& 'admin' === ( $qualified_star->wpdb_state()['last_result'][0]->user_login ?? null ),
	'grouped predicates, descending order, offset LIMIT, and FOUND_ROWS execute as one stateful pair' => '1' === ( $grouped_bounded->wpdb_state()['last_result'][0]->ID ?? null )
		&& '2' === ( $found_rows->wpdb_state()['last_result'][0]->{'FOUND_ROWS()'} ?? null ),
	'ASCII case-insensitive lookups match WordPress identifiers' => '1' === ( $case_insensitive->wpdb_state()['last_result'][0]->ID ?? null ),
	'undeclared string ordering and unsupported Unicode collations fail closed' => false === $unsupported_string_order->return_value()
		&& false === $unsupported_unicode->return_value(),
	'configured email lookup and overflowing numeric literals fail correctly' => '2' === ( $email->wpdb_state()['last_result'][0]->ID ?? null )
		&& false === $overflow->return_value()
		&& array() === $overflow->wpdb_state()['last_result'],
	'invalid equals and IN operand shapes fail closed' => false === $invalid_in->return_value()
		&& false === $invalid_equals->return_value(),
	'wpdb consumes generic rows and native metadata' => 'admin' === ( $wpdb_user->user_login ?? null )
		&& array( 8, 253, 253, 253, 253, 253, 12, 253, 3, 253 ) === $metadata,
	'JSON member order is independent from schema projection order' => 'zoe' === ( $reordered->wpdb_state()['last_result'][0]->user_login ?? null )
		&& '2' === ( $reordered->wpdb_state()['last_result'][0]->ID ?? null ),
	'malformed snapshot rows fail without partial results' => false === $malformed->return_value()
		&& array() === $malformed->wpdb_state()['last_result']
		&& false === $malformed_count->return_value()
		&& array() === $malformed_count->wpdb_state()['last_result'],
	'snapshot symlink and hard-link paths fail closed' => ( ! $symlink || ( false === $unsafe_symlink->return_value()
		&& 'markdown_db_native_unsafe_path' === ( $unsafe_symlink->diagnostic()['code'] ?? null ) ) )
		&& ( ! $hardlink || ( false === $unsafe_hardlink->return_value()
		&& 'markdown_db_native_unsafe_path' === ( $unsafe_hardlink->diagnostic()['code'] ?? null ) ) ),
	'absent snapshots are empty and schema widths fail without partial rows' => 0 === $absent->return_value()
		&& false === $invalid_width->return_value()
		&& array() === $invalid_width->wpdb_state()['last_result'],
	'multisite user schemas accept required spam and deleted columns' => true === $multisite_schema->validate_row( $multisite_user ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

@unlink( $users_path );
@unlink( $outside );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
