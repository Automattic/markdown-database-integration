<?php
/** Native shadow comparison, sanitization, bounds, and SQLite attachment. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-shadow-verifier.php';

class MDI_Shadow_Database {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public int $rows_affected = 0;
	public int $num_rows = 0;
	public array $last_result = array();
	protected ?array $col_info = null;
	private array $source_columns = array();

	public function result( array $rows, array $columns ): void {
		$this->last_result = array_map( static fn( array $row ): object => (object) $row, $rows );
		$this->num_rows = count( $rows );
		$this->source_columns = array_map( static fn( array $column ): object => (object) $column, $columns );
		$this->col_info = null;
	}

	public function get_col_info( string $field ): array {
		$this->col_info ??= $this->source_columns;
		return array_map( static fn( object $column ): mixed => $column->{$field} ?? null, $this->col_info );
	}

	public function col_info_is_unloaded(): bool {
		return null === $this->col_info;
	}
}

$root = sys_get_temp_dir() . '/mdi-native-shadow-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the native shadow fixture.' );
}
file_put_contents(
	$root . '/_options/siteurl.json',
	json_encode( array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://example.test', 'autoload' => 'on' ), JSON_THROW_ON_ERROR )
);
file_put_contents(
	$root . '/_tables/commentmeta.json',
	json_encode( array( array( 'meta_id' => '1', 'comment_id' => '123', 'meta_key' => 'private@example.test', 'meta_value' => 'retained' ) ), JSON_THROW_ON_ERROR )
);
file_put_contents(
	$root . '/_tables/term_relationships.json',
	json_encode( array( array( 'object_id' => '123', 'term_taxonomy_id' => '7', 'term_order' => '0' ) ), JSON_THROW_ON_ERROR )
);

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$database = new MDI_Shadow_Database();
$database->result(
	array( array( 'option_value' => 'https://example.test' ) ),
	array( array( 'name' => 'option_value', 'type' => 252 ) )
);
$verifier = new WP_Markdown_Native_Shadow_Verifier( $runtime, 5 );
$verifier->observe( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 1", 1, $database );
$verifier->observe( "UPDATE wp_options SET option_value = 'private'", 1, $database );
$database->result( array( array( 'meta_value' => 'retained' ) ), array( array( 'name' => 'meta_value', 'type' => 252 ) ) );
$commentmeta_query = "SELECT meta_value FROM wp_commentmeta WHERE meta_key = 'private@example.test' AND comment_id = 123";
$verifier->observe( $commentmeta_query, 1, $database );
$database->result( array( array( 'term_taxonomy_id' => '7' ) ), array( array( 'name' => 'term_taxonomy_id', 'type' => 8 ) ) );
$verifier->observe( 'SELECT term_taxonomy_id FROM wp_term_relationships WHERE object_id = 123', 1, $database );
$database->result( array(), array( array( 'name' => 'COUNT(*)', 'type' => 8 ) ) );
$unsupported_query = 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = 123';
$verifier->observe( $unsupported_query, 0, $database );
$verifier->observe( 'SELECT ID FROM wp_posts', 0, $database );
$report = $verifier->report();

$database->result(
	array( array( 'option_value' => 'authoritative-secret' ) ),
	array( array( 'name' => 'option_value', 'type' => 252 ) )
);
$mismatch = new WP_Markdown_Native_Shadow_Verifier( $runtime );
$mismatch->observe( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 1", 1, $database );
$mismatch_report = $mismatch->report();

final class MDI_Throwing_Runtime implements WP_Markdown_Query_Runtime {
	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		unset( $request );
		throw new RuntimeException( 'private runtime failure' );
	}
}
$failed_verifier = new WP_Markdown_Native_Shadow_Verifier( new MDI_Throwing_Runtime() );
$failed_verifier->observe( 'SELECT option_name FROM wp_options', 0, $database );
$failure_report = $failed_verifier->report();

class WP_SQLite_DB {
	public string $prefix = 'wp_';
	public string $last_query = '';
	public string $last_error = '';
	public int $insert_id = 0;
	public int $rows_affected = 0;
	public int $num_rows = 0;
	public int $num_queries = 0;
	public array $last_result = array();
	public ?string $observer_added = null;
	protected array $col_info = array();

	public function query( $query ) {
		$this->last_query = (string) $query;
		$this->last_result = array( (object) array( 'option_value' => 'https://example.test' ) );
		$this->col_info = array( (object) array( 'name' => 'option_value', 'type' => 252 ) );
		$this->num_rows = 1;
		++$this->num_queries;
		return 1;
	}

	public function get_col_info( string $field ): array {
		return array_map( static fn( object $column ): mixed => $column->{$field} ?? null, $this->col_info );
	}
}
require_once __DIR__ . '/../inc/class-wp-markdown-db.php';

final class MDI_Hostile_Shadow_Observer {
	public int $calls = 0;
	public function observe( string $query, mixed $return_value, object $database ): void {
		unset( $query, $return_value );
		++$this->calls;
		$database->prefix = 'corrupted_';
		$database->last_result = array( 'corrupted' );
		$database->observer_added = 'corrupted';
		$database->query( 'SELECT nested_observer_query' );
		throw new RuntimeException( 'private observer failure' );
	}
}

$sqlite = new WP_Markdown_DB();
$hostile = new MDI_Hostile_Shadow_Observer();
$sqlite->set_native_shadow_verifier( $hostile );
$sqlite_return = $sqlite->query( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 1" );

$checks = array(
	'supported reads compare exactly without retaining observations' => 3 === $report['counts']['compatible']
		&& 1 === $report['counts']['ignored']
		&& 1 === $report['counts']['unsupported'],
	'observation bounds drop later queries deterministically' => 5 === $report['observed']
		&& 1 === $report['counts']['dropped'],
	'first unsupported query retains a sanitized reproducible shape' => 'unsupported' === ( $report['first_blocker']['status'] ?? null )
		&& hash( 'sha256', 'SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = ?' ) === ( $report['first_blocker']['query_template_sha256'] ?? null )
		&& ! str_contains( (string) ( $report['first_blocker']['query_template'] ?? '' ), 'private@example.test' )
		&& ! str_contains( (string) ( $report['first_blocker']['query_template'] ?? '' ), '123' )
		&& str_contains( (string) ( $report['first_blocker']['query_template'] ?? '' ), 'wp_term_relationships' ),
	'column metadata inspection restores lazy wpdb state' => $database->col_info_is_unloaded(),
	'mismatches expose paths without authoritative or native values' => 'mismatched' === ( $mismatch_report['first_blocker']['status'] ?? null )
		&& in_array( '$.rows[0].option_value', $mismatch_report['first_blocker']['mismatch_paths'] ?? array(), true )
		&& ! str_contains( json_encode( $mismatch_report, JSON_THROW_ON_ERROR ), 'authoritative-secret' )
		&& ! str_contains( json_encode( $mismatch_report, JSON_THROW_ON_ERROR ), 'https://example.test' ),
	'verifier failures retain only bounded structural diagnostics' => 1 === $failure_report['counts']['verifier_failures']
		&& RuntimeException::class === ( $failure_report['first_blocker']['failure_class'] ?? null )
		&& ! str_contains( json_encode( $failure_report, JSON_THROW_ON_ERROR ), 'private runtime failure' ),
	'SQLite authoritative returns and public state survive hostile observers' => 1 === $sqlite_return
		&& 1 === $hostile->calls
		&& 'wp_' === $sqlite->prefix
		&& 1 === $sqlite->num_queries
		&& null === $sqlite->observer_added
		&& 'https://example.test' === ( $sqlite->last_result[0]->option_value ?? null ),
	'recursive observer queries are not observed twice' => 1 === $hostile->calls,
	'observer exceptions expose no private message and never escape' => 'markdown_db_native_shadow_observer_failed' === ( $GLOBALS['markdown_db_native_shadow_diagnostic']['code'] ?? null )
		&& ! isset( $GLOBALS['markdown_db_native_shadow_diagnostic']['message'] ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

@unlink( $root . '/_options/siteurl.json' );
@unlink( $root . '/_tables/commentmeta.json' );
@unlink( $root . '/_tables/term_relationships.json' );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
