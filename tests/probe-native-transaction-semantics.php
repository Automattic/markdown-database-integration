<?php
/** Probe how the native runtime answers MySQL transaction control statements. */

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

$root = sys_get_temp_dir() . '/mdi-native-transactions-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the transaction probe fixture.' );
}

file_put_contents(
	$root . '/_options/probe_option.json',
	json_encode(
		array(
			'option_id'    => 1,
			'option_name'  => 'probe_option',
			'option_value' => 'committed',
			'autoload'     => 'on',
		),
		JSON_THROW_ON_ERROR
	)
);

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

/** @return array<string,mixed> */
function probe_statement( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): array {
	try {
		$result = $runtime->execute( new WP_Markdown_Query_Request( $sql ) );
		return array(
			'sql'          => $sql,
			'outcome'      => 'returned',
			'return_value' => $result->return_value(),
			'diagnostic'   => $result->diagnostic(),
			'last_error'   => $result->wpdb_state()['last_error'] ?? null,
		);
	} catch ( Throwable $error ) {
		return array(
			'sql'       => $sql,
			'outcome'   => 'threw',
			'exception' => get_class( $error ),
			'message'   => $error->getMessage(),
		);
	}
}

$statements = array(
	'START TRANSACTION',
	'BEGIN',
	'SAVEPOINT probe_point',
	'ROLLBACK TO SAVEPOINT probe_point',
	'RELEASE SAVEPOINT probe_point',
	'COMMIT',
	'ROLLBACK',
	'SET autocommit = 0',
);

$observations = array();
foreach ( $statements as $statement ) {
	$observations[] = probe_statement( $runtime, $statement );
}

// A rolled-back mutation must not remain visible in canonical state.
$mutation = array(
	'begin'    => probe_statement( $runtime, 'START TRANSACTION' ),
	'mutate'   => probe_statement(
		$runtime,
		"UPDATE wp_options SET option_value = 'rolled-back' WHERE option_name = 'probe_option'"
	),
	'rollback' => probe_statement( $runtime, 'ROLLBACK' ),
);

$after_rollback = probe_statement(
	$runtime,
	"SELECT option_value FROM wp_options WHERE option_name = 'probe_option'"
);

$canonical_after_rollback = json_decode( (string) file_get_contents( $root . '/_options/probe_option.json' ), true );

$report = array(
	'schema'                   => 'mdi-native-transaction-probe/v1',
	'control_statements'       => $observations,
	'rollback_sequence'        => $mutation,
	'select_after_rollback'    => $after_rollback,
	'canonical_after_rollback' => $canonical_after_rollback['option_value'] ?? null,
	'durability_preserved'     => 'committed' === ( $canonical_after_rollback['option_value'] ?? null ),
);

// A failed ROLLBACK must not leave a mutation published in canonical state.
$passed = true === $report['durability_preserved'];
$report['passed'] = $passed;

fwrite(
	$passed ? STDOUT : STDERR,
	json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n"
);
exit( $passed ? 0 : 1 );
