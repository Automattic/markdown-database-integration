<?php
/** SQL snapshot shadow mode independently evaluates authoritative source rows. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-shadow-verifier.php';

final class MDI_Snapshot_Result {
	private int $offset = 0;
	public bool $freed = false;
	/** @param array<int,array<string,mixed>> $rows */
	public function __construct( private array $rows ) {}
	public function fetch_assoc(): ?array { return $this->rows[ $this->offset++ ] ?? null; }
	public function free(): void { $this->freed = true; }
}

final class MDI_Snapshot_Connection {
	/** @var array<int,array<string,mixed>> */
	public array $rows = array( array( 'ID' => '1', 'post_title' => 'First' ) );
	/** @var array<int,array<string,mixed>> */
	public array $meta_rows = array( array( 'meta_id' => '1', 'post_id' => '1' ) );
	/** @var array<int,array<string,mixed>> */
	public array $global_rows = array( array( 'meta_id' => '1', 'site_id' => '1', 'meta_key' => 'site_name', 'meta_value' => 'Example' ) );
	/** @var array<int,array<string,mixed>> */
	public array $plugin_rows = array( array( 'id' => '1', 'name' => 'Agent' ) );
	/** @var array<int,MDI_Snapshot_Result> */
	public array $results = array();
	public function query( string $sql ): MDI_Snapshot_Result|false {
		$result = false;
		if ( 'SHOW CREATE TABLE `wp_postmeta`' === $sql ) {
			$result = new MDI_Snapshot_Result( array( array( 'Table' => 'wp_postmeta', 'Create Table' => 'CREATE TABLE `wp_postmeta` (`meta_id` bigint(20) unsigned NOT NULL, `post_id` bigint(20) unsigned NOT NULL, PRIMARY KEY (`meta_id`))' ) ) );
		} elseif ( 'SHOW CREATE TABLE `wp_sitemeta`' === $sql || 'SHOW CREATE TABLE `wp_usermeta`' === $sql ) {
			$table = str_contains( $sql, 'sitemeta' ) ? 'wp_sitemeta' : 'wp_usermeta';
			$result = new MDI_Snapshot_Result( array( array( 'Table' => $table, 'Create Table' => 'CREATE TABLE `' . $table . '` (`meta_id` bigint(20) unsigned NOT NULL, `site_id` bigint(20) unsigned NOT NULL, `meta_key` varchar(255) NOT NULL, `meta_value` longtext NOT NULL, PRIMARY KEY (`meta_id`))' ) ) );
		} elseif ( 'SHOW CREATE TABLE `agents`' === $sql ) {
			$result = new MDI_Snapshot_Result( array( array( 'Table' => 'agents', 'Create Table' => 'CREATE TABLE `agents` (`id` bigint(20) unsigned NOT NULL, `name` varchar(255) NOT NULL, PRIMARY KEY (`id`))' ) ) );
		} elseif ( 'SHOW CREATE TABLE `wp_2_options`' === $sql ) {
			return false;
		} elseif ( str_starts_with( $sql, 'SHOW CREATE TABLE' ) ) {
			$result = new MDI_Snapshot_Result( array( array( 'Table' => 'wp_posts', 'Create Table' => 'CREATE TABLE `wp_posts` (`ID` bigint(20) unsigned NOT NULL, `post_title` varchar(255) NOT NULL, PRIMARY KEY (`ID`))' ) ) );
		}
		if ( 'SELECT * FROM `wp_posts` LIMIT 10001' === $sql ) {
			$result = new MDI_Snapshot_Result( $this->rows );
		}
		if ( 'SELECT * FROM `wp_postmeta` LIMIT 10001' === $sql ) {
			$result = new MDI_Snapshot_Result( $this->meta_rows );
		}
		if ( 'SELECT * FROM `wp_sitemeta` LIMIT 10001' === $sql || 'SELECT * FROM `wp_usermeta` LIMIT 10001' === $sql ) {
			$result = new MDI_Snapshot_Result( $this->global_rows );
		}
		if ( 'SELECT * FROM `agents` LIMIT 10001' === $sql ) {
			$result = new MDI_Snapshot_Result( $this->plugin_rows );
		}
		if ( $result instanceof MDI_Snapshot_Result ) {
			$this->results[] = $result;
		}
		return $result;
	}
}

final class MDI_Snapshot_Database {
	public string $prefix = 'wp_';
	public string $base_prefix = 'wp_';
	public array $last_result = array();
	public int $num_rows = 0;
	public string $last_error = '';
	public int $insert_id = 0;
	public int $rows_affected = 0;
	protected ?array $col_info = null;
	private MDI_Snapshot_Connection $connection;
	public function __construct() { $this->connection = new MDI_Snapshot_Connection(); }
	public function markdown_db_mysql_connection(): object { return $this->connection; }
	public function source(): MDI_Snapshot_Connection { return $this->connection; }
	public function result(): void {
		$this->last_result = array_map( static fn( array $row ): object => (object) $row, $this->connection->rows );
		$this->num_rows = count( $this->last_result );
	}
	/** @param array<int,array<string,mixed>> $rows @param array<int,array{name:string,type:int}> $columns */
	public function result_rows( array $rows, array $columns ): void {
		$this->last_result = array_map( static fn( array $row ): object => (object) $row, $rows );
		$this->num_rows = count( $this->last_result );
		$this->col_info = array_map( static fn( array $column ): object => (object) $column, $columns );
	}
	public function get_col_info( string $field ): array {
		$this->col_info ??= array( (object) array( 'name' => 'ID', 'type' => 8 ), (object) array( 'name' => 'post_title', 'type' => 253 ) );
		return array_map( static fn( object $column ): mixed => $column->{$field}, $this->col_info );
	}
}

$database = new MDI_Snapshot_Database();
$database->result();
$database->insert_id = 73;
$verifier = new WP_Markdown_Native_Shadow_Verifier(
	WP_Markdown_Native_Runtime_Factory::runtime( sys_get_temp_dir() ),
	10,
	array( 'input_mode' => 'sql_snapshot' )
);
$verifier->capture_input( 'SELECT ID, post_title FROM wp_posts', $database );
$verifier->observe( 'SELECT ID, post_title FROM wp_posts', 1, $database );
$first = $verifier->report();
$database->source()->rows = array( array( 'ID' => '2', 'post_title' => 'Second' ) );
$database->result();
$verifier->capture_input( 'SELECT ID, post_title FROM wp_posts', $database );
$verifier->observe( 'SELECT ID, post_title FROM wp_posts', 1, $database );
$second = $verifier->report();

$schema = new WP_Markdown_Native_Table_Schema(
	array(
		'ID' => new WP_Markdown_Native_Column( 8, false ),
		'post_title' => new WP_Markdown_Native_Column( 253, true ),
	),
	'ID',
	array( 'ID', 'post_title' )
);
$provider = new WP_Markdown_Native_Authoritative_Snapshot_Provider(
	array(
		array( 'ID' => '10', 'post_title' => null ),
		array( 'ID' => '2', 'post_title' => 'Second' ),
		array( 'ID' => '3', 'post_title' => 'Third' ),
	),
	$schema
);
$provided = $provider->read( new WP_Markdown_Native_Table_Access( array( 'ID' ), new WP_Markdown_Native_Query_Predicate( 'ID', '>', array( '2' ) ), 'ID', 1, true ) );
$multi_table = WP_Markdown_Native_Authoritative_Snapshot_Runtime::capture(
	$database,
	'SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON p.ID = m.post_id',
	'wp_'
)->provenance();
$derived_table = WP_Markdown_Native_Authoritative_Snapshot_Runtime::capture(
	$database,
	'SELECT derived.ID FROM (SELECT ID FROM wp_posts) AS derived',
	'wp_'
)->provenance();
$database->prefix = 'wp_2_';
$global_table = WP_Markdown_Native_Authoritative_Snapshot_Runtime::capture(
	$database,
	'SELECT meta_key, meta_value FROM wp_sitemeta WHERE site_id = 1',
	$database->prefix
)->provenance();
$user_meta_table = WP_Markdown_Native_Authoritative_Snapshot_Runtime::capture(
	$database,
	'SELECT meta_key, meta_value FROM wp_usermeta WHERE site_id = 1',
	$database->prefix
)->provenance();
$plugin_table = WP_Markdown_Native_Authoritative_Snapshot_Runtime::capture(
	$database,
	'SELECT id, name FROM agents WHERE id = 1',
	$database->prefix
)->provenance();
$database->prefix = 'wp_';
$missing_schema_reason = null;
try {
	WP_Markdown_Native_Authoritative_Snapshot_Runtime::capture( $database, 'SELECT option_value FROM wp_2_options WHERE option_name = \'siteurl\'', 'wp_2_' );
} catch ( WP_Markdown_Native_Snapshot_Input_Exception $error ) {
	$missing_schema_reason = $error->diagnostic()['reason'];
}
$reordered = new WP_Markdown_Native_Shadow_Verifier(
	WP_Markdown_Native_Runtime_Factory::runtime( sys_get_temp_dir() ),
	1,
	array( 'input_mode' => 'sql_snapshot' )
);
$database->result_rows(
	array( array( 'post_title' => 'Second', 'ID' => '2' ) ),
	array( array( 'name' => 'post_title', 'type' => 253 ), array( 'name' => 'ID', 'type' => 8 ) )
);
$reordered->capture_input( 'SELECT post_title, ID FROM wp_posts', $database );
$reordered->observe( 'SELECT post_title, ID FROM wp_posts', 1, $database );
$binary_rows = array( array( 'ID' => '3', 'post_title' => "\xFF\x00binary" ) );
$database->source()->rows = $binary_rows;
$binary = WP_Markdown_Native_Authoritative_Snapshot_Runtime::capture( $database, 'SELECT ID, post_title FROM wp_posts', 'wp_' )->provenance();
$database->result_rows(
	array( array( 'DATABASE()' => '' ) ),
	array( array( 'name' => 'DATABASE()', 'type' => 253 ) )
);
$database_fast_path = new WP_Markdown_Native_Shadow_Verifier(
	WP_Markdown_Native_Runtime_Factory::runtime( sys_get_temp_dir() ),
	1,
	array( 'input_mode' => 'sql_snapshot' )
);
$database_fast_path->capture_input( 'SELECT DATABASE()', $database );
$database_fast_path->observe( 'SELECT DATABASE()', 1, $database );
$bounded = new WP_Markdown_Native_Shadow_Verifier(
	WP_Markdown_Native_Runtime_Factory::runtime( sys_get_temp_dir() ),
	1,
	array( 'input_mode' => 'sql_snapshot' )
);
$bounded->capture_input( 'SELECT ID, post_title FROM wp_posts', $database );
$bounded->observe( 'SELECT ID, post_title FROM wp_posts', 1, $database );
$tableless = new WP_Markdown_Native_Shadow_Verifier(
	WP_Markdown_Native_Runtime_Factory::runtime( sys_get_temp_dir() ),
	1,
	array( 'input_mode' => 'sql_snapshot' )
);
$database->result_rows( array( array( 'one' => '1' ) ), array( array( 'name' => 'one', 'type' => 3 ) ) );
$tableless->capture_input( 'SELECT 1 AS one', $database );
$tableless->observe( 'SELECT 1 AS one', 1, $database );
$capture_count_at_bound = count( $database->source()->results );
$bounded->capture_input( 'SELECT ID, post_title FROM wp_posts', $database );
$bounded->observe( 'SELECT ID, post_title FROM wp_posts', 1, $database );

$checks = array(
	'authoritative snapshots compare with independently evaluated native SQL' => 2 === $second['counts']['compatible'] && 0 === $second['counts']['verifier_failures'],
	'input provenance records bounded source rows without their values' => 'authoritative_mysql_connection_pre_query' === ( $first['context']['last_input_state']['read_connection'] ?? null )
		&& 1 === ( $first['context']['last_input_state']['tables'][0]['rows'] ?? 0 )
		&& 64 === strlen( (string) ( $first['context']['last_input_state']['tables'][0]['schema_sha256'] ?? '' ) )
		&& 'pre_query_wpdb_insert_id' === ( $first['context']['last_input_state']['facade_state']['native_insert_id'] ?? null )
		&& 64 === strlen( (string) ( $first['context']['last_input_state']['facade_state']['insert_id_sha256'] ?? '' ) )
		&& ! isset( $first['context']['last_input_state']['facade_state']['insert_id'] )
		&& ! str_contains( json_encode( $second, JSON_THROW_ON_ERROR ), 'Second' ),
	'mutation changes the next authoritative input view' => ( $first['context']['last_input_state']['tables'][0]['sha256'] ?? '' ) !== ( $second['context']['last_input_state']['tables'][0]['sha256'] ?? '' ),
	'raw mysqli-shaped values retain NULL while provider applies predicate, order, limit, and projection' => array( array( 'ID' => '10' ) ) === $provided,
	'provider emits multi-column projections in requested order through the native executor' => 1 === $reordered->report()['counts']['compatible'],
	'binary source rows are bounded and hashed without JSON encoding or report disclosure' => 1 === ( $binary['tables'][0]['rows'] ?? 0 )
		&& 64 === strlen( (string) ( $binary['tables'][0]['sha256'] ?? '' ) )
		&& ! str_contains( json_encode( $binary, JSON_THROW_ON_ERROR ), 'binary' ),
	'DATABASE() uses the stateless native runtime path without a snapshot oracle' => 1 === $database_fast_path->report()['counts']['compatible']
		&& 'native_runtime_fast_path' === ( $database_fast_path->report()['context']['last_input_state']['read_connection'] ?? null )
		&& array() === ( $database_fast_path->report()['context']['last_input_state']['tables'] ?? null ),
	'typed plan traversal captures every JOIN source' => array( 'wp_posts', 'wp_postmeta' ) === array_column( $multi_table['tables'], 'table' ),
	'typed plan traversal skips a derived alias and captures its source table' => array( 'wp_posts' ) === array_column( $derived_table['tables'], 'table' ),
	'global tables use the base prefix when the active blog prefix differs' => array( 'wp_sitemeta' ) === array_column( $global_table['tables'], 'table' )
		&& array( 'wp_usermeta' ) === array_column( $user_meta_table['tables'], 'table' ),
	'validated non-WordPress-prefixed plugin tables compile by exact captured identity' => array( 'agents' ) === array_column( $plugin_table['tables'], 'table' ),
	'absent blog-2 schemas remain explicit snapshot input limitations' => 'source_schema_unavailable' === $missing_schema_reason,
	'capture does no source work after the observation cap and drops the matching observation' => $capture_count_at_bound === count( $database->source()->results ) && 1 === $bounded->report()['counts']['dropped'],
	'tableless scalar SQL is independently compared through the stateless runtime path' => 1 === $tableless->report()['counts']['compatible']
		&& 'native_runtime_fast_path' === ( $tableless->report()['context']['last_input_state']['read_connection'] ?? null )
		&& array() === ( $tableless->report()['context']['last_input_state']['tables'] ?? null ),
	'capture results are released after both schema and row reads' => array_reduce( $database->source()->results, static fn( bool $freed, MDI_Snapshot_Result $result ): bool => $freed && $result->freed, true ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	$failed += $passed ? 0 : 1;
}
exit( $failed ? 1 : 0 );
