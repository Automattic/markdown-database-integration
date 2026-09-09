<?php
/** SQL snapshot shadow mode independently evaluates authoritative source rows. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-shadow-verifier.php';

final class MDI_Snapshot_Result {
	private int $offset = 0;
	/** @param array<int,array<string,mixed>> $rows */
	public function __construct( private array $rows ) {}
	public function fetch_assoc(): ?array { return $this->rows[ $this->offset++ ] ?? null; }
}

final class MDI_Snapshot_Connection {
	/** @var array<int,array<string,mixed>> */
	public array $rows = array( array( 'ID' => '1', 'post_title' => 'First' ) );
	public function query( string $sql ): MDI_Snapshot_Result|false {
		if ( str_starts_with( $sql, 'SHOW CREATE TABLE' ) ) {
			return new MDI_Snapshot_Result( array( array( 'Table' => 'wp_posts', 'Create Table' => 'CREATE TABLE `wp_posts` (`ID` bigint(20) unsigned NOT NULL, `post_title` varchar(255) NOT NULL, PRIMARY KEY (`ID`))' ) ) );
		}
		if ( 'SELECT * FROM `wp_posts`' === $sql ) {
			return new MDI_Snapshot_Result( $this->rows );
		}
		return false;
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

$checks = array(
	'authoritative snapshots compare with independently evaluated native SQL' => 2 === $second['counts']['compatible'] && 0 === $second['counts']['verifier_failures'],
	'input provenance records bounded source rows without their values' => 'authoritative_mysql_connection_pre_query' === ( $first['context']['last_input_state']['read_connection'] ?? null )
		&& 1 === ( $first['context']['last_input_state']['tables'][0]['rows'] ?? 0 )
		&& ! str_contains( json_encode( $second, JSON_THROW_ON_ERROR ), 'Second' ),
	'mutation changes the next authoritative input view' => ( $first['context']['last_input_state']['tables'][0]['sha256'] ?? '' ) !== ( $second['context']['last_input_state']['tables'][0]['sha256'] ?? '' ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	$failed += $passed ? 0 : 1;
}
exit( $failed ? 1 : 0 );
