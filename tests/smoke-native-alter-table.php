<?php
/** Bounded ALTER TABLE column persistence over the canonical schema catalog. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

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
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-wpdb.php';

$root = sys_get_temp_dir() . '/mdi-native-alter-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the alter table fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_agents (id BIGINT NOT NULL AUTO_INCREMENT, instance_key VARCHAR(60) NULL, label VARCHAR(60) NULL, PRIMARY KEY (id), KEY instance_key (instance_key))',
		'wp_'
	)
);
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_agents (instance_key, label) VALUES ('one', 'first')", 'wp_' ) );

function persisted_schema( string $root ): string {
	return (string) file_get_contents( $root . '/_schema/agents.sql' );
}

/** @return array<int,array<string,mixed>> */
function snapshot_rows( string $root ): array {
	$rows = json_decode( (string) file_get_contents( $root . '/_tables/agents.json' ), true );
	return is_array( $rows ) ? $rows : array();
}

// The corpus blocker.
$modified = $runtime->execute(
	new WP_Markdown_Query_Request( 'ALTER TABLE `wp_agents` MODIFY instance_key LONGTEXT NOT NULL', 'wp_' )
);
$after_modify = persisted_schema( $root );

$added = $runtime->execute(
	new WP_Markdown_Query_Request( 'ALTER TABLE wp_agents ADD COLUMN note VARCHAR(120) NULL', 'wp_' )
);
$rows_after_add = snapshot_rows( $root );

$select_after_add = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT id, note FROM wp_agents WHERE id = 1', 'wp_' )
);

$dropped = $runtime->execute(
	new WP_Markdown_Query_Request( 'ALTER TABLE wp_agents DROP COLUMN note', 'wp_' )
);
$rows_after_drop = snapshot_rows( $root );

$indexed_drop = $runtime->execute(
	new WP_Markdown_Query_Request( 'ALTER TABLE wp_agents DROP COLUMN instance_key', 'wp_' )
);
$unknown_column = $runtime->execute(
	new WP_Markdown_Query_Request( 'ALTER TABLE wp_agents MODIFY absent VARCHAR(10) NULL', 'wp_' )
);
$duplicate_add = $runtime->execute(
	new WP_Markdown_Query_Request( 'ALTER TABLE wp_agents ADD COLUMN label VARCHAR(10) NULL', 'wp_' )
);
$unknown_table = $runtime->execute(
	new WP_Markdown_Query_Request( 'ALTER TABLE wp_absent MODIFY label VARCHAR(10) NULL', 'wp_' )
);
$unsupported_action = $runtime->execute(
	new WP_Markdown_Query_Request( 'ALTER TABLE wp_agents ENGINE = InnoDB', 'wp_' )
);

$checks = array(
	'MODIFY rewrites the persisted column definition' => true === $modified->succeeded()
		&& str_contains( $after_modify, 'LONGTEXT NOT NULL' )
		&& ! str_contains( $after_modify, 'instance_key VARCHAR(60) NULL' ),
	'MODIFY preserves the remaining definition' => str_contains( $after_modify, '`label` VARCHAR(60) NULL' )
		|| str_contains( $after_modify, 'label VARCHAR(60) NULL' ),
	'ADD reconciles existing snapshot rows' => true === $added->succeeded()
		&& array_key_exists( 'note', $rows_after_add[0] ?? array() )
		&& null === $rows_after_add[0]['note'],
	'an added column is immediately selectable' => 1 === $select_after_add->return_value(),
	'DROP removes the column from persisted rows' => true === $dropped->succeeded()
		&& ! array_key_exists( 'note', $rows_after_drop[0] ?? array( 'note' => null ) ),
	'dropping an indexed column fails closed' => false === $indexed_drop->return_value(),
	'altering an unknown column fails closed' => false === $unknown_column->return_value()
		&& 'unknown_column' === ( $unknown_column->diagnostic()['reason'] ?? null ),
	'adding an existing column fails closed' => false === $duplicate_add->return_value()
		&& 'column_exists' === ( $duplicate_add->diagnostic()['reason'] ?? null ),
	'altering an unpersisted table fails closed' => false === $unknown_table->return_value()
		&& 'unknown_table' === ( $unknown_table->diagnostic()['reason'] ?? null ),
	'an unsupported table alteration fails closed' => false === $unsupported_action->return_value(),
);

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
