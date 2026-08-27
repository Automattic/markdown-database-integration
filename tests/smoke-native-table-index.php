<?php
/** Derived table index correctness across rebuild, staleness, and rollback. */

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
}
require_once __DIR__ . '/../inc/class-wp-markdown-native-wpdb.php';

$root = sys_get_temp_dir() . '/mdi-native-index-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the table index fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_items (id BIGINT NOT NULL AUTO_INCREMENT, code BIGINT NULL, label VARCHAR(60) NULL, PRIMARY KEY (id), UNIQUE KEY code (code))',
		'wp_'
	)
);

$snapshot = $root . '/_tables/items.json';
$index_path = $root . '/_tables/.index/items.json';

/** @return array<int,array<string,mixed>> */
function rows( string $snapshot ): array {
	$rows = json_decode( (string) file_get_contents( $snapshot ), true );
	return is_array( $rows ) ? $rows : array();
}

$first = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (code, label) VALUES (10, 'a')", 'wp_' ) );
$second = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (code, label) VALUES (11, 'b')", 'wp_' ) );
$third = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (code, label) VALUES (12, 'c')", 'wp_' ) );
$index_written = is_file( $index_path );
$sequential = array_map( static fn( array $row ): string => (string) $row['id'], rows( $snapshot ) );

// Appending must leave the snapshot valid and readable through the engine.
$selected = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id, label FROM wp_items WHERE id = 3', 'wp_' ) );

// A unique key recorded in the index must still be enforced.
$duplicate = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (code, label) VALUES (11, 'dup')", 'wp_' ) );

// A supplied identifier bypasses the generated-value guarantee.
$explicit_duplicate = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (id, code, label) VALUES (2, 99, 'x')", 'wp_' ) );

// A discarded index must rebuild rather than corrupt the sequence.
unlink( $index_path );
$after_discard = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (code, label) VALUES (13, 'd')", 'wp_' ) );
$rebuilt = is_file( $index_path );

// An index that no longer describes the snapshot must be ignored.
$stale = json_decode( (string) file_get_contents( $index_path ), true );
$stale['max']['id'] = 999;
$stale['fingerprint']['size'] = 1;
file_put_contents( $index_path, json_encode( $stale ) );
$after_stale = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (code, label) VALUES (14, 'e')", 'wp_' ) );

// A rolled back insert must restore the snapshot and leave the index coherent.
$before_rollback = count( rows( $snapshot ) );
$runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION', 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (code, label) VALUES (15, 'f')", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK', 'wp_' ) );
$after_rollback = count( rows( $snapshot ) );
$post_rollback_insert = $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_items (code, label) VALUES (16, 'g')", 'wp_' ) );
$post_rollback_ids = array_map( static fn( array $row ): string => (string) $row['id'], rows( $snapshot ) );

$checks = array(
	'appended inserts assign sequential identifiers' => 1 === $first->return_value()
		&& array( '1', '2', '3' ) === $sequential,
	'an index file is written alongside the snapshot' => $index_written,
	'an appended snapshot stays readable' => 1 === $selected->return_value()
		&& 'c' === ( $selected->wpdb_state()['last_result'][0]->label ?? null ),
	'a unique key recorded in the index is enforced' => false === $duplicate->return_value()
		&& 'duplicate_key' === ( $duplicate->diagnostic()['reason'] ?? null ),
	'a supplied duplicate identifier is rejected' => false === $explicit_duplicate->return_value(),
	'a discarded index rebuilds without corrupting the sequence' => 1 === $after_discard->return_value()
		&& $rebuilt
		&& '4' === (string) ( rows( $snapshot )[3]['id'] ?? null ),
	'a stale index is ignored rather than trusted' => 1 === $after_stale->return_value()
		&& '5' === (string) ( rows( $snapshot )[4]['id'] ?? null ),
	'a rolled back insert restores the snapshot' => $before_rollback === $after_rollback,
	'inserts after a rollback stay coherent' => 1 === $post_rollback_insert->return_value()
		&& array( '1', '2', '3', '4', '5', '6' ) === $post_rollback_ids,
);

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
