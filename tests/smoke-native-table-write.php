<?php
/** Generic UPDATE and DELETE proof over a persisted snapshot table. */

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

$root = sys_get_temp_dir() . '/mdi-native-table-write-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the generic table write fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$created = $runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_agents (id BIGINT NOT NULL AUTO_INCREMENT, instance_key VARCHAR(60) NULL, label VARCHAR(60) NULL, PRIMARY KEY (id))',
		'wp_'
	)
);

foreach ( array(
	"INSERT INTO wp_agents (instance_key, label) VALUES (NULL, 'first')",
	"INSERT INTO wp_agents (instance_key, label) VALUES ('', 'second')",
	"INSERT INTO wp_agents (instance_key, label) VALUES ('keep', 'third')",
) as $insert ) {
	$runtime->execute( new WP_Markdown_Query_Request( $insert, 'wp_' ) );
}

/** @return array<int,array<string,mixed>> */
function table_rows( string $root ): array {
	$rows = json_decode( (string) file_get_contents( $root . '/_tables/agents.json' ), true );
	return is_array( $rows ) ? $rows : array();
}

/** @return array<int,mixed> */
function column_values( string $root, string $column ): array {
	return array_map( static fn( array $row ): mixed => $row[ $column ] ?? null, table_rows( $root ) );
}

// The corpus blocker: a disjunctive restriction over one column including NULL.
$backfill = $runtime->execute(
	new WP_Markdown_Query_Request(
		"UPDATE `wp_agents` SET instance_key = 'default' WHERE instance_key IS NULL OR instance_key = ''",
		'wp_'
	)
);
$after_backfill = column_values( $root, 'instance_key' );

$unmatched = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_agents SET label = 'none' WHERE instance_key = 'absent'", 'wp_' )
);

$deleted = $runtime->execute(
	new WP_Markdown_Query_Request( "DELETE FROM wp_agents WHERE instance_key = 'keep'", 'wp_' )
);
$after_delete = column_values( $root, 'label' );

// Serialized values carry semicolons, which must not read as a statement separator.
$serialized = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_agents (instance_key, label) VALUES ('serialized', 'a:1:{s:3:\"key\";i:42;}')",
		'wp_'
	)
);
$serialized_rows = table_rows( $root );
$semicolon_text = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_agents SET label = 'one; two' WHERE instance_key = 'serialized'", 'wp_' )
);

$unknown_column = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_agents SET missing_column = 'x' WHERE id = 1", 'wp_' )
);
$unknown_table = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_absent SET label = 'x' WHERE id = 1", 'wp_' )
);
$cross_column_or = $runtime->execute(
	new WP_Markdown_Query_Request( "UPDATE wp_agents SET label = 'x' WHERE id = 1 OR label = 'first'", 'wp_' )
);
$null_equality = $runtime->execute(
	new WP_Markdown_Query_Request( 'UPDATE wp_agents SET label = 1 WHERE instance_key = NULL', 'wp_' )
);

// A rolled back generic write must leave the snapshot untouched.
$runtime->execute( new WP_Markdown_Query_Request( 'START TRANSACTION', 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_agents SET label = 'rolled-back' WHERE id = 1", 'wp_' ) );
$runtime->execute( new WP_Markdown_Query_Request( 'ROLLBACK', 'wp_' ) );
$after_rollback = column_values( $root, 'label' );

$checks = array(
	'the fixture table is created' => 0 === $created->return_value() || true === $created->succeeded(),
	'a disjunctive NULL restriction updates every matching row' => 2 === $backfill->return_value()
		&& array( 'default', 'default', 'keep' ) === $after_backfill,
	'an unmatched restriction reports zero affected rows' => 0 === $unmatched->return_value(),
	'DELETE removes only the restricted rows' => 1 === $deleted->return_value()
		&& array( 'first', 'second' ) === $after_delete,
	'a serialized value is not read as a statement separator' => 1 === $serialized->return_value()
		&& 'a:1:{s:3:"key";i:42;}' === ( $serialized_rows[ count( $serialized_rows ) - 1 ]['label'] ?? null ),
	'a semicolon inside a literal survives an update' => 1 === $semicolon_text->return_value(),
	'an unknown assignment column fails closed' => false === $unknown_column->return_value()
		&& 'unsupported_mutation_column' === ( $unknown_column->diagnostic()['reason'] ?? null ),
	'an unregistered table fails closed' => false === $unknown_table->return_value()
		&& 'unsupported_mutation_table' === ( $unknown_table->diagnostic()['reason'] ?? null ),
	'OR across columns fails closed' => false === $cross_column_or->return_value(),
	'NULL equality fails closed' => false === $null_equality->return_value(),
	'a rolled back generic write restores the snapshot' => array( 'first', 'second', 'one; two' ) === $after_rollback,
);

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
