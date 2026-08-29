<?php
/** Direct canonical option query behavior without a database connection. */
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

$root = sys_get_temp_dir() . '/mdi-native-option-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the native option fixture.' );
}

$write_option = static function ( string $name, mixed $row ) use ( $root ): void {
	file_put_contents( $root . '/_options/' . WP_Markdown_Canonical_Option_Path::filename( $name ), is_string( $row ) ? $row : json_encode( $row, JSON_THROW_ON_ERROR ) );
};
$write_option( 'siteurl', array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://example.test', 'autoload' => 'on' ) );
$escaped_name = "tab\tback\\slash";
$write_option( $escaped_name, array( 'option_id' => 2, 'option_name' => $escaped_name, 'option_value' => 'escaped', 'autoload' => 'off' ) );
$write_option( 'blogname', array( 'option_id' => 3, 'option_name' => 'blogname', 'option_value' => 'Native Site', 'autoload' => 'auto-on' ) );
$write_option( 'automatic', array( 'option_id' => 4, 'option_name' => 'automatic', 'option_value' => 'automatic', 'autoload' => 'auto' ) );
$write_option( 'legacy', array( 'option_id' => 5, 'option_name' => 'legacy', 'option_value' => 'legacy', 'autoload' => 'yes' ) );
$write_option( 'disabled', array( 'option_id' => 6, 'option_name' => 'disabled', 'option_value' => 'disabled', 'autoload' => 'off' ) );

$runtime = new WP_Markdown_Native_Option_Query_Runtime( $root );
$query = "SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 1";
$result = $runtime->execute( new WP_Markdown_Query_Request( $query ) );
$autoload_query = "SELECT option_name, option_value FROM wp_options WHERE autoload IN ( 'yes', 'on', 'auto-on', 'auto' )";
$autoload_result = $runtime->execute( new WP_Markdown_Query_Request( $autoload_query ) );
$alloptions_query = 'SELECT option_name, option_value FROM wp_options';
$alloptions_result = $runtime->execute( new WP_Markdown_Query_Request( $alloptions_query ) );
$prime_query = "SELECT option_name, option_value FROM wp_options WHERE option_name IN ('legacy','missing','siteurl','legacy')";
$prime_result = $runtime->execute( new WP_Markdown_Query_Request( $prime_query ) );
$actual = array(
	'schema'       => 'mdi-query-compatibility-corpus/v1',
	'observations' => array(
		array(
			'schema_version' => 1,
			'sequence'       => 1,
			'scenario'       => 'core.options.get-option',
			'query'          => $query,
			'result'         => $result->corpus_result(),
			'transaction'    => array( 'before' => array(), 'after' => array() ),
		),
		array(
			'schema_version' => 1,
			'sequence'       => 2,
			'scenario'       => 'core.options.load-alloptions',
			'query'          => $autoload_query,
			'result'         => $autoload_result->corpus_result(),
			'transaction'    => array( 'before' => array(), 'after' => array() ),
		),
		array(
			'schema_version' => 1,
			'sequence'       => 3,
			'scenario'       => 'core.options.load-alloptions-fallback',
			'query'          => $alloptions_query,
			'result'         => $alloptions_result->corpus_result(),
			'transaction'    => array( 'before' => array(), 'after' => array() ),
		),
		array(
			'schema_version' => 1,
			'sequence'       => 4,
			'scenario'       => 'core.options.prime-caches',
			'query'          => $prime_query,
			'result'         => $prime_result->corpus_result(),
			'transaction'    => array( 'before' => array(), 'after' => array() ),
		),
	),
);
$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/query-corpus/mdi-native-options-v1.json' ), true, 512, JSON_THROW_ON_ERROR );
$comparison = WP_Markdown_Query_Compatibility_Comparator::compare( $fixture, $actual );
$catalogue_path = $root . '/_indexes/options.json';
$persisted_catalogue = json_decode( (string) file_get_contents( $catalogue_path ), true, 512, JSON_THROW_ON_ERROR );
$persisted_entries = array_column( $persisted_catalogue['entries'], null, 'filename' );
$catalogued_runtime = new WP_Markdown_Native_Option_Query_Runtime( $root );
$catalogued_rows = $catalogued_runtime->execute( new WP_Markdown_Query_Request( $alloptions_query ) )->wpdb_state()['last_result'];

// A same-size, same-mtime atomic replacement must invalidate persisted rows.
$disabled_path = $root . '/_options/disabled.json';
$disabled_stat = stat( $disabled_path );
$disabled_raw = (string) file_get_contents( $disabled_path );
$replacement_raw = str_replace( '"option_value":"disabled"', '"option_value":"altered!"', $disabled_raw );
$replacement_path = $disabled_path . '.replacement';
file_put_contents( $replacement_path, $replacement_raw );
touch( $replacement_path, (int) $disabled_stat['mtime'] );
rename( $replacement_path, $disabled_path );
$replaced_runtime = new WP_Markdown_Native_Option_Query_Runtime( $root );
$replaced_rows = $replaced_runtime->execute( new WP_Markdown_Query_Request( $alloptions_query ) )->wpdb_state()['last_result'];
$replaced_values = array_column( array_map( 'get_object_vars', $replaced_rows ), 'option_value', 'option_name' );

file_put_contents( $catalogue_path, '{' );
$corrupt_catalogue_runtime = new WP_Markdown_Native_Option_Query_Runtime( $root );
$corrupt_catalogue_result = $corrupt_catalogue_runtime->execute( new WP_Markdown_Query_Request( $alloptions_query ) );

$state = $result->wpdb_state();
$projection = $runtime->execute( new WP_Markdown_Query_Request( "SELECT `option_name`, option_id, autoload FROM `wp_options` WHERE `option_name` = 'siteurl'" ) );
$projection_state = $projection->wpdb_state();
$missing = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'missing' LIMIT 1" ) );
$zero_limit = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 0" ) );
$unsupported = array(
	$runtime->execute( new WP_Markdown_Query_Request( "SELECT unsupported_column FROM wp_options WHERE option_name = 'siteurl'" ) ),
	$runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_posts WHERE option_name = 'siteurl'" ) ),
	$runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 0" ) ),
	$runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'site\\qurl'" ) ),
);
$custom_prefix_runtime = new WP_Markdown_Native_Option_Query_Runtime( $root, 'wp_2_' );
$custom_prefix = $custom_prefix_runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_2_options WHERE option_name = 'siteurl'", 'wp_2_' ) );
$like_option = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name LIKE 'site%'" ) );
$escaped = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'tab\\tback\\\\slash'" ) );
$case_insensitive_option = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'SITEURL'" ) );
$autoload_subset = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_name FROM wp_options WHERE autoload IN ('on', 'yes') LIMIT 1" ) );
$alloptions_limited = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT option_name FROM wp_options LIMIT 2' ) );
$conjunctive = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE autoload = 'on' AND option_name = 'siteurl' LIMIT 1" ) );
$conjunctive_missing = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE autoload = 'off' AND option_name = 'siteurl' LIMIT 1" ) );
$autoload_state = $autoload_result->wpdb_state();
$autoload_unsupported = array(
	$runtime->execute( new WP_Markdown_Query_Request( "SELECT option_name FROM wp_options WHERE autoload IN ('off')" ) ),
	$runtime->execute( new WP_Markdown_Query_Request( "SELECT option_name FROM wp_options WHERE autoload = 'on'" ) ),
);
$autoload_duplicates = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_name FROM wp_options WHERE autoload IN ('on', 'on')" ) );
$database = new WP_Markdown_Native_WPDB( $runtime );
$insert_cron = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('cron', 'first', 'on') ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`), `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)" ) );
$insert_cron_state = $insert_cron->wpdb_state();
$read_cron = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_id, option_value, autoload FROM wp_options WHERE option_name = 'cron' LIMIT 1" ) );
$update_cron = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_options (option_value, autoload, option_name) VALUES ('second', 'off', 'cron') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload), option_name = VALUES(option_name)" ) );
$noop_cron = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('cron', 'second', 'off') ON DUPLICATE KEY UPDATE option_name = VALUES(option_name), option_value = VALUES(option_value), autoload = VALUES(autoload)" ) );
$read_updated_cron = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_id, option_value, autoload FROM wp_options WHERE option_name = 'cron' LIMIT 1" ) );
$direct_update_cron = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE `wp_options` SET `option_value` = 'third', `autoload` = 'auto-off' WHERE `option_name` = 'cron'" ) );
$noop_direct_update_cron = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_options SET option_value = 'third', autoload = 'auto-off' WHERE option_name = 'cron'" ) );
$read_direct_updated_cron = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_id, option_value, autoload FROM wp_options WHERE option_name = 'cron' LIMIT 1" ) );
$reopened_runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_' );
$read_persisted_cron = $reopened_runtime->execute( new WP_Markdown_Query_Request( "SELECT option_id, option_value, autoload FROM wp_options WHERE option_name = 'cron' LIMIT 1" ) );
$missing_direct_update = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_options SET option_value = 'missing' WHERE option_name = 'not_present'" ) );
$delete_cron = $runtime->execute( new WP_Markdown_Query_Request( "DELETE FROM `wp_options` WHERE `option_name` = 'cron'" ) );
$delete_missing = $runtime->execute( new WP_Markdown_Query_Request( "DELETE FROM wp_options WHERE option_name = 'not_present'" ) );
$unsupported_upsert = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('invalid', 'value', 'on') ON DUPLICATE KEY UPDATE option_name = VALUES(option_name), option_value = VALUES(option_value)" ) );
$cron_path = $root . '/_options/cron.json';
$cron_temp_files = glob( $cron_path . '.tmp-*' ) ?: array();
$wpdb_upsert = $database->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('wpdb_native', 'value', 'on') ON DUPLICATE KEY UPDATE option_name = VALUES(option_name), option_value = VALUES(option_value), autoload = VALUES(autoload)" );
$wpdb_upsert_state = array( 'rows_affected' => $database->rows_affected, 'insert_id' => $database->insert_id );
unlink( $root . '/_options/wpdb_native.json' );
$plain_insert = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('installing_lock', 123456, 'no')" ) );
$plain_insert_state = $plain_insert->wpdb_state();
$read_plain_insert = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_id, option_value, autoload FROM wp_options WHERE option_name = 'installing_lock' LIMIT 1" ) );
$duplicate_plain_insert = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('INSTALLING_LOCK', 654321, 'no')" ) );
unlink( $root . '/_options/installing_lock.json' );
$prepared_query = $database->prepare( "SELECT option_value FROM {$database->options} WHERE option_name = %s LIMIT 1", 'siteurl' );
$wpdb_row = $database->get_row( $prepared_query );
$wpdb_var = $database->get_var( $prepared_query );
$wpdb_results = $database->get_results( "SELECT option_name, option_value FROM {$database->options} WHERE option_name = 'siteurl'" );
$wpdb_columns = $database->get_col_info( 'name' );
$wpdb_alloptions = $database->get_results( $autoload_query );
$wpdb_alloptions_fallback = $database->get_results( $alloptions_query );
$wpdb_primed = $database->get_results( $prime_query );
$wpdb_unsupported = $database->query( 'SELECT COUNT(option_id) FROM wp_options' );
$wpdb_unsupported_diagnostic = $database->last_runtime_diagnostic;
$GLOBALS['mdi_native_query_filter'] = static fn( string $sql ): string => str_replace( "'siteurl'", "'missing'", $sql );
$wpdb_filtered = $database->query( $prepared_query );
$GLOBALS['mdi_native_query_filter'] = null;

$write_option( 'spaced option', array( 'option_id' => 7, 'option_name' => 'spaced option', 'option_value' => 'spaced', 'autoload' => 'off' ) );
$case_insensitive_hashed_option = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'SPACED OPTION'" ) );
$write_option( 'other option', '{' );
$missing_hashed_option = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'missing option'" ) );
$write_option( 'broken', '{' );
$malformed = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'broken'" ) );
$malformed_bulk = $runtime->execute( new WP_Markdown_Query_Request( $autoload_query ) );
$exact_list_ignores_unrelated_malformed = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_name FROM wp_options WHERE option_name IN ('siteurl','missing')" ) );
$write_option( 'invalid-row', array( 'option_id' => -1, 'option_name' => 'invalid-row', 'option_value' => 'invalid', 'autoload' => str_repeat( 'x', 21 ) ) );
$invalid_row = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'invalid-row'" ) );
$write_option( 'SPACED OPTION', array( 'option_id' => 8, 'option_name' => 'SPACED OPTION', 'option_value' => 'duplicate', 'autoload' => 'off' ) );
$duplicate_hashed_option = $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'Spaced Option'" ) );
$outside = dirname( $root ) . '/mdi-native-outside-' . bin2hex( random_bytes( 4 ) ) . '.json';
file_put_contents( $outside, json_encode( array( 'option_id' => 7, 'option_name' => 'hardlinked', 'option_value' => 'unsafe', 'autoload' => 'on' ), JSON_THROW_ON_ERROR ) );
$symlink_supported = function_exists( 'symlink' ) && @symlink( $outside, $root . '/_options/linked.json' );
$unsafe = $symlink_supported ? $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'linked'" ) ) : null;
$hardlink_supported = function_exists( 'link' ) && @link( $outside, $root . '/_options/hardlinked.json' );
$unsafe_hardlink = $hardlink_supported ? $runtime->execute( new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'hardlinked'" ) ) : null;

$checks = array(
	'committed corpus matches native result' => $comparison['compatible'],
	'the persisted catalogue retains full rows only for hot autoload options' => is_array( $persisted_entries['siteurl.json']['row'] ?? null )
		&& null === ( $persisted_entries['disabled.json']['row'] ?? null )
		&& 'off' === ( $persisted_entries['disabled.json']['autoload'] ?? null ),
	'a cold runtime restores the verified option catalogue' => 6 === count( $catalogued_rows ),
	'same-size same-mtime option replacement invalidates the catalogue' => strlen( $disabled_raw ) === strlen( $replacement_raw )
		&& 'altered!' === ( $replaced_values['disabled'] ?? null ),
	'a corrupt option catalogue falls back to canonical files' => 6 === $corrupt_catalogue_result->return_value(),
	'wpdb state preserves row and native metadata shape' => 1 === $result->return_value() && 1 === $state['num_rows'] && 'https://example.test' === ( $state['last_result'][0]->option_value ?? null ) && 'option_value' === ( $state['col_info'][0]->name ?? null ) && 252 === ( $state['col_info'][0]->type ?? null ),
	'projection order and MySQL string scalars are preserved' => array( 'option_name', 'option_id', 'autoload' ) === array_map( static fn( object $column ): string => $column->name, $projection_state['col_info'] ) && '1' === ( $projection_state['last_result'][0]->option_id ?? null ),
	'missing options succeed with an empty result' => 0 === $missing->return_value() && 0 === $missing->wpdb_state()['num_rows'],
	'limit zero succeeds with an empty result' => 0 === $zero_limit->return_value() && 0 === $zero_limit->wpdb_state()['num_rows'],
	'configured table prefixes are exact' => 1 === $custom_prefix->return_value(),
	'supported MySQL literal escapes resolve exact option names' => 'escaped' === ( $escaped->wpdb_state()['last_result'][0]->option_value ?? null ),
	'ASCII option identities use case-insensitive WordPress collation' => 'https://example.test' === ( $case_insensitive_option->wpdb_state()['last_result'][0]->option_value ?? null ),
	'hashed ASCII option identities use case-insensitive WordPress collation' => 'spaced' === ( $case_insensitive_hashed_option->wpdb_state()['last_result'][0]->option_value ?? null ),
	'missing hashed ASCII option identities succeed with an empty result' => 0 === $missing_hashed_option->return_value(),
	'bulk autoload rows preserve option-id and projection order' => array( 'siteurl', 'blogname', 'automatic', 'legacy' ) === array_map( static fn( object $row ): string => $row->option_name, $autoload_state['last_result'] ) && array( 'option_name', 'option_value' ) === array_map( static fn( object $column ): string => $column->name, $autoload_state['col_info'] ),
	'autoload subsets and limits remain bounded' => 1 === $autoload_subset->return_value() && 'siteurl' === ( $autoload_subset->wpdb_state()['last_result'][0]->option_name ?? null ),
	'duplicate allowed autoload values retain SQL set semantics' => 1 === $autoload_duplicates->return_value() && 'siteurl' === ( $autoload_duplicates->wpdb_state()['last_result'][0]->option_name ?? null ),
	'alloptions fallback remains bounded and ordered' => 2 === $alloptions_limited->return_value() && array( 'siteurl', $escaped_name ) === array_map( static fn( object $row ): string => $row->option_name, $alloptions_limited->wpdb_state()['last_result'] ),
	'indexed option names push down while non-indexed conjunctions filter in PHP' => 'https://example.test' === ( $conjunctive->wpdb_state()['last_result'][0]->option_value ?? null )
		&& 0 === $conjunctive_missing->return_value()
		&& array( 'option_value' ) === array_map( static fn( object $column ): string => $column->name, $conjunctive->wpdb_state()['col_info'] ),
	'option-name lists deduplicate, omit missing rows, and preserve option-id order' => array( 'siteurl', 'legacy' ) === array_map( static fn( object $row ): string => $row->option_name, $prime_result->wpdb_state()['last_result'] ),
	'unsupported autoload predicates and values fail closed' => array_reduce( $autoload_unsupported, static fn( bool $valid, WP_Markdown_Query_Result $candidate ): bool => $valid && false === $candidate->return_value() && 'markdown_db_native_unsupported_query' === ( $candidate->diagnostic()['code'] ?? null ), true ),
	'wpdb helpers consume native result state without a connection' => 'https://example.test' === ( $wpdb_row->option_value ?? null ) && 'https://example.test' === $wpdb_var && 'siteurl' === ( $wpdb_results[0]->option_name ?? null ) && array( 'option_name', 'option_value' ) === $wpdb_columns,
	'canonical option upserts atomically insert, update, and retain identities' => 1 === $insert_cron->return_value()
		&& 1 === $insert_cron_state['rows_affected']
		&& 7 === $insert_cron_state['insert_id']
		&& '7' === ( $read_cron->wpdb_state()['last_result'][0]->option_id ?? null )
		&& 'first' === ( $read_cron->wpdb_state()['last_result'][0]->option_value ?? null )
		&& 2 === $update_cron->return_value()
		&& 0 === $noop_cron->return_value()
		&& '7' === ( $read_updated_cron->wpdb_state()['last_result'][0]->option_id ?? null )
		&& 'second' === ( $read_updated_cron->wpdb_state()['last_result'][0]->option_value ?? null )
		&& 'off' === ( $read_updated_cron->wpdb_state()['last_result'][0]->autoload ?? null )
		&& array() === $cron_temp_files,
	'canonical option updates mutate exact existing identities only' => 1 === $direct_update_cron->return_value()
		&& 0 === $noop_direct_update_cron->return_value()
		&& 0 === $missing_direct_update->return_value()
		&& '7' === ( $read_direct_updated_cron->wpdb_state()['last_result'][0]->option_id ?? null )
		&& 'third' === ( $read_direct_updated_cron->wpdb_state()['last_result'][0]->option_value ?? null )
		&& 'auto-off' === ( $read_direct_updated_cron->wpdb_state()['last_result'][0]->autoload ?? null )
		&& 'third' === ( $read_persisted_cron->wpdb_state()['last_result'][0]->option_value ?? null ),
	'exact option deletes remove canonical rows and preserve missing-row semantics' => 1 === $delete_cron->return_value()
		&& 1 === $delete_cron->wpdb_state()['rows_affected']
		&& 0 === $delete_missing->return_value()
		&& ! file_exists( $cron_path ),
	'option mutations fail closed unless duplicate assignments preserve the complete row' => false === $unsupported_upsert->return_value()
		&& 'unsupported_option_upsert' === ( $unsupported_upsert->diagnostic()['reason'] ?? null )
		&& ! file_exists( $root . '/_options/invalid.json' ),
	'plain canonical option inserts preserve integer values and reject duplicate collated identities' => 1 === $plain_insert->return_value()
		&& 1 === $plain_insert_state['rows_affected']
		&& 7 === $plain_insert_state['insert_id']
		&& '123456' === ( $read_plain_insert->wpdb_state()['last_result'][0]->option_value ?? null )
		&& 'no' === ( $read_plain_insert->wpdb_state()['last_result'][0]->autoload ?? null )
		&& false === $duplicate_plain_insert->return_value()
		&& 'duplicate_key' === ( $duplicate_plain_insert->diagnostic()['reason'] ?? null ),
	'wpdb exposes native mutation affected rows and insert identity' => 1 === $wpdb_upsert
		&& 1 === $wpdb_upsert_state['rows_affected']
		&& 7 === $wpdb_upsert_state['insert_id'],
	'wpdb get_results consumes the core alloptions query' => 4 === count( $wpdb_alloptions ) && 'legacy' === ( $wpdb_alloptions[3]->option_name ?? null ),
	'wpdb get_results consumes the core alloptions fallback' => 6 === count( $wpdb_alloptions_fallback ) && 'disabled' === ( $wpdb_alloptions_fallback[5]->option_name ?? null ),
	'wpdb get_results consumes option cache priming queries' => 2 === count( $wpdb_primed ) && 'siteurl' === ( $wpdb_primed[0]->option_name ?? null ),
	'wpdb facade exposes structured unsupported-query failures' => false === $wpdb_unsupported && 'markdown_db_native_unsupported_query' === ( $wpdb_unsupported_diagnostic['code'] ?? null ),
	'wpdb query filters run before execution and last_query capture' => 0 === $wpdb_filtered && str_contains( (string) $database->last_query, "'missing'" ),
	'option_name LIKE scans ASCII prefixes' => 'https://example.test' === ( $like_option->wpdb_state()['last_result'][0]->option_value ?? null ),
	'unsupported queries fail without partial results' => array_reduce( $unsupported, static fn( bool $valid, WP_Markdown_Query_Result $candidate ): bool => $valid && false === $candidate->return_value() && array() === $candidate->wpdb_state()['last_result'] && 'markdown_db_native_unsupported_query' === ( $candidate->diagnostic()['code'] ?? null ), true ),
	'malformed canonical options fail exact and bulk reads explicitly' => false === $malformed->return_value() && false === $malformed_bulk->return_value() && 'markdown_db_native_malformed_option' === ( $malformed_bulk->diagnostic()['code'] ?? null ),
	'exact option lists do not scan unrelated malformed rows' => 1 === $exact_list_ignores_unrelated_malformed->return_value(),
	'canonical rows enforce wp_options schema constraints' => false === $invalid_row->return_value() && 'markdown_db_native_malformed_option' === ( $invalid_row->diagnostic()['code'] ?? null ),
	'duplicate hashed collated identities fail closed' => false === $duplicate_hashed_option->return_value() && 'duplicate_collated_identity' === ( $duplicate_hashed_option->diagnostic()['reason'] ?? null ),
	'canonical path symlinks fail closed' => ! $symlink_supported || ( false === $unsafe->return_value() && 'markdown_db_native_unsafe_path' === ( $unsafe->diagnostic()['code'] ?? null ) ),
	'canonical path hard links fail closed' => ! $hardlink_supported || ( false === $unsafe_hardlink->return_value() && 'markdown_db_native_unsafe_path' === ( $unsafe_hardlink->diagnostic()['code'] ?? null ) ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

@unlink( $root . '/_options/siteurl.json' );
@unlink( $root . '/_options/' . WP_Markdown_Canonical_Option_Path::filename( $escaped_name ) );
foreach ( array( 'blogname', 'automatic', 'legacy', 'disabled', 'spaced option', 'SPACED OPTION', 'other option' ) as $option_name ) {
	@unlink( $root . '/_options/' . WP_Markdown_Canonical_Option_Path::filename( $option_name ) );
}
@unlink( $root . '/_options/broken.json' );
@unlink( $root . '/_options/invalid-row.json' );
if ( $symlink_supported ) {
	@unlink( $root . '/_options/linked.json' );
}
if ( $hardlink_supported ) {
	@unlink( $root . '/_options/hardlinked.json' );
}
@unlink( $outside );
@unlink( $catalogue_path );
@rmdir( $root . '/_indexes' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
