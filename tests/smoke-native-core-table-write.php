<?php
/** Core snapshot table write proof, which WordPress authentication depends on. */

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

	public function add_placeholder_escape( string $query ): string {
		return $query;
	}

	public function remove_placeholder_escape( string $query ): string {
		return $query;
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-native-wpdb.php';

$root = sys_get_temp_dir() . '/mdi-core-write-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
file_put_contents( $root . '/_tables/usermeta.json', '[]' );

/** @return array<int,array<string,mixed>> */
$meta_rows = static function () use ( $root ): array {
	$decoded = json_decode( (string) file_get_contents( $root . '/_tables/usermeta.json' ), true );
	return is_array( $decoded ) ? $decoded : array();
};

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

// A core table carries no persisted schema file, so a write proves the
// generated core definition is accepted as authoritative provenance.
$insert = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'session_tokens', 'a:1:{s:5:\"token\";i:7;}')",
		'wp_'
	)
);
$after_insert = $meta_rows();

// WordPress locates the existing row by key before it writes, and a missing
// lookup would silently duplicate the row instead of updating it.
$lookup = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT umeta_id FROM wp_usermeta WHERE meta_key = 'session_tokens' AND user_id = 1", 'wp_' )
);

$update = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_usermeta SET meta_value = 'refreshed' WHERE meta_key = 'session_tokens' AND user_id = 1", 'wp_' )
);
$after_update = $meta_rows();

$unknown_column = $runtime->execute(
	new WP_Markdown_Query_Request( "INSERT INTO wp_usermeta (user_id, nonexistent) VALUES (1, 'x')", 'wp_' )
);

$unregistered = $runtime->execute(
	new WP_Markdown_Query_Request( "INSERT INTO wp_not_a_table (id) VALUES (1)", 'wp_' )
);

$checks = array(
	'a core snapshot table accepts an insert'         => 1 === $insert->return_value(),
	'the inserted row is persisted'                   => 1 === count( $after_insert )
		&& 'session_tokens' === ( $after_insert[0]['meta_key'] ?? null ),
	'a serialized meta value survives the write'      => 'a:1:{s:5:"token";i:7;}' === ( $after_insert[0]['meta_value'] ?? null ),
	'the identity column is assigned'                 => '' !== (string) ( $after_insert[0]['umeta_id'] ?? '' ),
	'an existing row is found by meta_key'            => 1 === ( $lookup->wpdb_state()['num_rows'] ?? 0 ),
	'a core snapshot table accepts an update'         => 1 === $update->return_value(),
	'the update replaces rather than duplicates'      => 1 === count( $after_update )
		&& 'refreshed' === ( $after_update[0]['meta_value'] ?? null ),
	'an unknown core column fails closed'             => false === $unknown_column->return_value(),
	'an unregistered table still fails closed'        => false === $unregistered->return_value(),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_options/*' ) ?: array() );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );

exit( $failed ? 1 : 0 );
