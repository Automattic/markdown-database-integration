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
	/** @var array<int,MDI_Snapshot_Result> */
	public array $results = array();
	public function query( string $sql ): MDI_Snapshot_Result|false {
		$result = false;
		if ( 'SHOW CREATE TABLE `wp_postmeta`' === $sql ) {
			$result = new MDI_Snapshot_Result( array( array( 'Table' => 'wp_postmeta', 'Create Table' => 'CREATE TABLE `wp_postmeta` (`meta_id` bigint(20) unsigned NOT NULL, `post_id` bigint(20) unsigned NOT NULL, PRIMARY KEY (`meta_id`))' ) ) );
		} elseif ( str_starts_with( $sql, 'SHOW CREATE TABLE' ) ) {
			$result = new MDI_Snapshot_Result( array( array( 'Table' => 'wp_posts', 'Create Table' => 'CREATE TABLE `wp_posts` (`ID` bigint(20) unsigned NOT NULL, `post_title` varchar(255) NOT NULL, PRIMARY KEY (`ID`))' ) ) );
		}
		if ( 'SELECT * FROM `wp_posts` LIMIT 10001' === $sql ) {
			$result = new MDI_Snapshot_Result( $this->rows );
		}
		if ( 'SELECT * FROM `wp_postmeta` LIMIT 10001' === $sql ) {
			$result = new MDI_Snapshot_Result( $this->meta_rows );
		}
		if ( $result instanceof MDI_Snapshot_Result ) {
			$this->results[] = $result;
		}
		return $result;
	}
}

final class MDI_Snapshot_Database {
	public string $prefix = 'wp_';
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
	public function get_col_info( string $field ): array {
		$this->col_info ??= array( (object) array( 'name' => 'ID', 'type' => 8 ), (object) array( 'name' => 'post_title', 'type' => 253 ) );
		return array_map( static fn( object $column ): mixed => $column->{$field}, $this->col_info );
	}
}

$database = new MDI_Snapshot_Database();
$database->result();
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
$tableless->capture_input( 'SELECT 1', $database );
$tableless->observe( 'SELECT 1', 1, $database );
$capture_count_at_bound = count( $database->source()->results );
$bounded->capture_input( 'SELECT ID, post_title FROM wp_posts', $database );
$bounded->observe( 'SELECT ID, post_title FROM wp_posts', 1, $database );

$checks = array(
	'authoritative snapshots compare with independently evaluated native SQL' => 2 === $second['counts']['compatible'] && 0 === $second['counts']['verifier_failures'],
	'input provenance records bounded source rows without their values' => 'authoritative_mysql_connection_pre_query' === ( $first['context']['last_input_state']['read_connection'] ?? null )
		&& 1 === ( $first['context']['last_input_state']['tables'][0]['rows'] ?? 0 )
		&& 64 === strlen( (string) ( $first['context']['last_input_state']['tables'][0]['schema_sha256'] ?? '' ) )
		&& ! str_contains( json_encode( $second, JSON_THROW_ON_ERROR ), 'Second' ),
	'mutation changes the next authoritative input view' => ( $first['context']['last_input_state']['tables'][0]['sha256'] ?? '' ) !== ( $second['context']['last_input_state']['tables'][0]['sha256'] ?? '' ),
	'raw mysqli-shaped values retain NULL while provider applies predicate, order, limit, and projection' => array( array( 'ID' => '10' ) ) === $provided,
	'typed plan traversal captures every JOIN source' => array( 'wp_posts', 'wp_postmeta' ) === array_column( $multi_table['tables'], 'table' ),
	'typed plan traversal skips a derived alias and captures its source table' => array( 'wp_posts' ) === array_column( $derived_table['tables'], 'table' ),
	'capture does no source work after the observation cap and drops the matching observation' => $capture_count_at_bound === count( $database->source()->results ) && 1 === $bounded->report()['counts']['dropped'],
	'tableless native SQL retains its parser unsupported diagnostic' => 'markdown_db_native_unsupported_query' === ( $tableless->report()['first_blocker']['native_diagnostic']['code'] ?? null ),
	'capture results are released after both schema and row reads' => array_reduce( $database->source()->results, static fn( bool $freed, MDI_Snapshot_Result $result ): bool => $freed && $result->freed, true ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	$failed += $passed ? 0 : 1;
}
exit( $failed ? 1 : 0 );
