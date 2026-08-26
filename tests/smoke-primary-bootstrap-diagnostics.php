<?php
/** Operator-visible primary bootstrap failure coverage. */

declare( strict_types=1 );

$root = sys_get_temp_dir() . '/mdi-primary-bootstrap-diagnostic-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root . '/content', 0755, true );
mkdir( $root . '/state/_tables', 0755, true );
file_put_contents( $root . '/state/_tables/users.json', '[]' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', $root );
define( 'MARKDOWN_DB_MODE', 'primary' );
define( 'MARKDOWN_DB_CONTENT_DIR', $root . '/content' );
define( 'MARKDOWN_DB_STATE_DIR', $root . '/state' );
define( 'MARKDOWN_DB_INDEX_PATH', $root . '/index.sqlite' );
$GLOBALS['table_prefix'] = 'wp_';

final class MDI_Primary_Bootstrap_Bail extends RuntimeException {}

class WP_SQLite_DB {
	public mixed $dbh = null;
	public string $dbname;
	public string $last_error = '';
	public bool $ready = false;
	public string $prefix = 'wp_';
	public int $num_queries = 0;
	public string $last_query = '';

	public function __construct( string $dbname ) {
		$this->dbname = $dbname;
		$this->db_connect();
	}
	public function init_charset(): void {}
	public function bail( string $message, string $error_code ): void {
		throw new MDI_Primary_Bootstrap_Bail( $error_code . ': ' . $message );
	}
	public function set_prefix( $prefix, $set_table_names = true ) { return $this->prefix; }
	public function query( $query ) { return false; }
}

class WP_Markdown_Storage {
	public function __construct( string $content_dir, array $excluded_types = array() ) {}
	public function set_content_layout_profile( string $profile ): void {}
	public function set_post_resolver( callable $resolver ): void {}
	public function set_meta_resolver( callable $resolver ): void {}
	public function set_terms_resolver( callable $resolver ): void {}
	public function set_index_writer( callable $writer ): void {}
	public function get_excluded_types(): array { return array(); }
}

class WP_Markdown_Write_Engine {
	public function __construct( mixed ...$args ) {}
}

require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';

final class MDI_Primary_Bootstrap_Backend implements WP_Markdown_Backend_Operations {
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable { return array(); }
	public function post_rows( array $post_ids ): array { return array(); }
	public function post_status( int $post_id ): ?string { return null; }
	public function post_meta( int $post_id ): array { return array(); }
	public function post_terms( int $post_id ): array { return array(); }
	public function affected_post_ids( string $table_suffix, array $resource_ids, string $operation, array $scope = array() ): array { return array(); }
	public function options( array $names, bool $all = false ): array { return array(); }
	public function option_names(): array { return array(); }
	public function insert_id(): int { return 0; }
	public function next_post_id( int $minimum = 1 ): int { return $minimum; }
	public function upsert_file_index( int $post_id, string $path, int $mtime, int $size ): void {}
	public function delete_file_index( int $post_id ): void {}
	public function upsert_options_index( array $rows ): void {}
	public function delete_options_index( array $names ): void {}
	public function update_manifest( string $path, int $mtime, int $size ): void {}
	public function persist_schema( string $table_suffix, string $operation ): ?string { return null; }
	public function delete_schema( string $table_suffix ): void {}
	public function manifest_entries(): array { return array(); }
	public function hydrate_markdown_posts( array $posts, ?iterable $fallback_posts ): void {}
	public function hydrate_table_snapshot( string $table_suffix, callable $rows, ?array $identity = null, ?array $partition = null ): bool {
		if ( 'users' === $table_suffix ) {
			throw new RuntimeException( 'SQLite snapshot transaction failed', 0, new UnexpectedValueException( 'invalid users row shape' ) );
		}
		return false;
	}
	public function reconcile_markdown( array $files, callable $parse_file ): array { return array(); }
	public function hydrate_options( array $rows ): void {}
	public function ensure_tables( array $schemas ): void {}
	public function ensure_reconciliation_state(): void {}
	public function mutations_for_query( string $query, array $operation ): array { return array(); }
}

final class MDI_Primary_Bootstrap_Connection {
	private PDO $pdo;
	public function __construct() { $this->pdo = new PDO( 'sqlite::memory:' ); }
	public function get_pdo(): PDO { return $this->pdo; }
}

final class MDI_Primary_Bootstrap_Driver {
	private MDI_Primary_Bootstrap_Connection $connection;
	public function __construct() { $this->connection = new MDI_Primary_Bootstrap_Connection(); }
	public function operations( callable $prefix ): WP_Markdown_Backend_Operations { return new MDI_Primary_Bootstrap_Backend(); }
	public function get_connection(): MDI_Primary_Bootstrap_Connection { return $this->connection; }
	public function set_write_engine( WP_Markdown_Write_Engine $write_engine ): void {}
}

final class WP_Markdown_SQLite_Runtime_Adapter {
	public static function create_runtime( string $path, ?PDO $pdo, string $database, WP_Markdown_Storage $storage, WP_Markdown_Backend_Capabilities $capabilities ): object {
		return new MDI_Primary_Bootstrap_Driver();
	}
}

require_once __DIR__ . '/../inc/class-wp-markdown-db.php';

$visible_failure = null;
try {
	new WP_Markdown_DB( 'wordpress' );
} catch ( MDI_Primary_Bootstrap_Bail $error ) {
	$visible_failure = $error->getMessage();
}

$diagnostic = $GLOBALS['markdown_db_primary_bootstrap_diagnostic'] ?? null;
$conditions = array(
	'primary bootstrap bails with the actionable diagnostic instead of returning a generic connection failure' => is_string( $visible_failure ) && str_starts_with( $visible_failure, 'db_connect_fail: Markdown DB cold reconstruction failed. [cold_reconstruction_failed]' ),
	'the final operator boundary identifies the canonical table resource and nested root cause' => str_contains( (string) $visible_failure, '_tables/users.json' ) && str_contains( (string) $visible_failure, 'invalid users row shape' ),
	'the final operator boundary provides deterministic remediation' => str_contains( (string) $visible_failure, 'remove the disposable Markdown DB index, and retry' ),
	'structured failure evidence retains the complete causal chain' => is_array( $diagnostic ) && 'cold_reconstruction_failed' === ( $diagnostic['code'] ?? null ) && 3 === count( $diagnostic['causes'] ?? array() ) && false === ( $diagnostic['truncated'] ?? true ),
);

$failed = false;
foreach ( $conditions as $message => $condition ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . PHP_EOL;
	$failed = $failed || ! $condition;
}

@unlink( $root . '/state/_tables/users.json' );
@rmdir( $root . '/state/_tables' );
@rmdir( $root . '/state' );
@rmdir( $root . '/content' );
@rmdir( $root );

exit( $failed ? 1 : 0 );
