<?php
/** Authenticated-bootstrap usermeta cache reads without a database connection. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';
require_once __DIR__ . '/../inc/compatibility/class-wp-markdown-query-compatibility-comparator.php';

class wpdb {
	public string $prefix = '';
	public string $options = '';
	public string $users = '';
	public string $usermeta = '';
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
		$this->prefix   = $prefix;
		$this->options  = $prefix . 'options';
		$this->users    = $prefix . 'users';
		$this->usermeta = $prefix . 'usermeta';
		return $prefix;
	}

	public function flush(): void {
		$this->last_result = array();
		$this->col_info    = array();
		$this->last_error  = '';
		$this->num_rows    = 0;
	}

	public function add_placeholder_escape( string $value ): string { return $value; }
	public function remove_placeholder_escape( string $value ): string { return $value; }

	public function get_results( string $query, string $output = 'OBJECT' ): array {
		$this->query( $query );
		return ARRAY_A === $output
			? array_map( static fn( object $row ): array => get_object_vars( $row ), $this->last_result )
			: $this->last_result;
	}

	public function get_col_info( string $type ): array {
		return array_map( static fn( object $column ): mixed => $column->{$type} ?? null, $this->col_info );
	}
}
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-wpdb.php';

$root = sys_get_temp_dir() . '/mdi-native-usermeta-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the native usermeta fixture.' );
}

$rows = array(
	array( 'umeta_id' => '10', 'user_id' => '1', 'meta_key' => 'wp_capabilities', 'meta_value' => 'caps' ),
	array( 'umeta_id' => '2', 'user_id' => '1', 'meta_key' => 'session_tokens', 'meta_value' => 'token-old' ),
	array( 'umeta_id' => '11', 'user_id' => '2', 'meta_key' => 'locale', 'meta_value' => null ),
	array( 'umeta_id' => '3', 'user_id' => '1', 'meta_key' => 'session_tokens', 'meta_value' => 'token-new' ),
	array( 'umeta_id' => '12', 'user_id' => '2', 'meta_key' => null, 'meta_value' => 'orphan' ),
);
$path = $root . '/_tables/usermeta.json';
file_put_contents( $path, json_encode( $rows, JSON_THROW_ON_ERROR ) );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$query = 'SELECT user_id, meta_key, meta_value FROM wp_usermeta WHERE user_id IN (1,2,1) ORDER BY umeta_id ASC';
$result = $runtime->execute( new WP_Markdown_Query_Request( $query ) );
$actual = array(
	'schema'       => 'mdi-query-compatibility-corpus/v1',
	'observations' => array(
		array(
			'schema_version' => 1,
			'sequence'       => 1,
			'scenario'       => 'core.usermeta.update-meta-cache',
			'query'          => $query,
			'result'         => $result->corpus_result(),
			'transaction'    => array( 'before' => array(), 'after' => array() ),
		),
	),
);
$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/query-corpus/mdi-native-usermeta-v1.json' ), true, 512, JSON_THROW_ON_ERROR );
$comparison = WP_Markdown_Query_Compatibility_Comparator::compare( $fixture, $actual );

$database = new WP_Markdown_Native_WPDB( $runtime );
$cached_rows = $database->get_results( $query, ARRAY_A );
$metadata = $database->get_col_info( 'type' );
$missing = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT user_id, meta_key, meta_value FROM wp_usermeta WHERE user_id IN (99) ORDER BY umeta_id ASC' )
);
// WordPress reads and writes user meta with an equality predicate, so this
// resolves rather than failing closed.
$equality_predicate = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT meta_value FROM wp_usermeta WHERE user_id = 1 ORDER BY umeta_id ASC' )
);
$meta_key_predicate = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT meta_value FROM wp_usermeta WHERE meta_key = 'session_tokens' AND user_id = 1 ORDER BY umeta_id ASC" )
);
$unsupported_order = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT meta_value FROM wp_usermeta WHERE user_id IN (1) ORDER BY meta_key ASC' )
);

$multisite = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_2_', 'wp_', true );
$global_usermeta = $multisite->execute(
	new WP_Markdown_Query_Request( 'SELECT meta_key FROM wp_usermeta WHERE user_id IN (1) ORDER BY umeta_id ASC', 'wp_2_' )
);
$site_usermeta = $multisite->execute(
	new WP_Markdown_Query_Request( 'SELECT meta_key FROM wp_2_usermeta WHERE user_id IN (1) ORDER BY umeta_id ASC', 'wp_2_' )
);
$site_options = $multisite->execute(
	new WP_Markdown_Query_Request( 'SELECT option_name FROM wp_2_options', 'wp_2_' )
);

$invalid = $rows[0];
$invalid['meta_key'] = str_repeat( 'x', 256 );
file_put_contents( $path, json_encode( array( $invalid ), JSON_THROW_ON_ERROR ) );
$malformed_width = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT meta_key FROM wp_usermeta WHERE user_id IN (1)' ) );
file_put_contents( $path, json_encode( array( $rows[0], $rows[0] ), JSON_THROW_ON_ERROR ) );
$duplicate_identity = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT meta_key FROM wp_usermeta WHERE user_id IN (1)' ) );

$real_state_root = getenv( 'MDI_NATIVE_REAL_STATE_ROOT' );
$real_snapshot_verified = true;
if ( is_string( $real_state_root ) && '' !== $real_state_root ) {
	$real_runtime = WP_Markdown_Native_Runtime_Factory::runtime( $real_state_root );
	$real_result = $real_runtime->execute(
		new WP_Markdown_Query_Request( 'SELECT user_id, meta_key, meta_value FROM wp_usermeta WHERE user_id IN (1) ORDER BY umeta_id ASC' )
	);
	$real_rows = $real_result->wpdb_state()['last_result'];
	$real_snapshot_verified = $real_result->succeeded()
		&& 0 < count( $real_rows )
		&& array_reduce( $real_rows, static fn( bool $valid, object $row ): bool => $valid && '1' === $row->user_id, true );
}

$checks = array(
	'committed MariaDB corpus matches the native cache query' => $comparison['compatible'],
	'core cache query uses numeric umeta order and SQL IN set semantics' => array( 'token-old', 'token-new', 'caps', null, 'orphan' ) === array_column( $cached_rows, 'meta_value' ),
	'duplicate meta keys and nullable values survive wpdb ARRAY_A results' => array( 'session_tokens', 'session_tokens' ) === array_column( array_slice( $cached_rows, 0, 2 ), 'meta_key' )
		&& null === $cached_rows[3]['meta_value']
		&& null === $cached_rows[4]['meta_key'],
	'wpdb exposes MySQL field metadata without a connection' => array( 8, 253, 252 ) === $metadata,
	'missing users produce an empty successful cache result' => 0 === $missing->return_value(),
	'an equality predicate resolves user meta' => 3 === $equality_predicate->return_value(),
	'a meta_key predicate locates the existing rows' => 2 === $meta_key_predicate->return_value(),
	'string ordering still fails closed' => false === $unsupported_order->return_value(),
	'multisite composition keeps global identity tables on base_prefix' => 3 === $global_usermeta->return_value()
		&& false === $site_usermeta->return_value()
		&& 0 === $site_options->return_value(),
	'schema violations and duplicate natural identities fail without partial rows' => false === $malformed_width->return_value()
		&& false === $duplicate_identity->return_value()
		&& array() === $duplicate_identity->wpdb_state()['last_result'],
);

if ( is_string( $real_state_root ) && '' !== $real_state_root ) {
	$checks['configured installed canonical usermeta snapshot executes successfully'] = $real_snapshot_verified;
} else {
	echo 'SKIP: installed canonical usermeta snapshot root is not configured' . PHP_EOL;
}

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

@unlink( $path );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
