<?php
/** Foreground post writes retry transient SQLite contention without replaying other failures. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API', true );

class WP_SQLite_Connection {
	public function __construct( private PDO $pdo ) {}
	public function get_pdo(): PDO { return $this->pdo; }
}

class WP_MySQL_On_SQLite {
	public static string $failure = '';
	public static int $update_attempts = 0;
	private WP_SQLite_Connection $connection;
	private bool $in_transaction = false;

	public function __construct( string $dsn, ?string $username = null, ?string $password = null, array $options = array() ) {
		unset( $dsn, $username, $password );
		$this->connection = new WP_SQLite_Connection( $options['pdo'] );
	}

	public function get_connection(): WP_SQLite_Connection { return $this->connection; }
	public function beginTransaction(): bool { $this->connection->get_pdo()->beginTransaction(); $this->in_transaction = true; return true; }
	public function commit(): bool { $this->connection->get_pdo()->commit(); $this->in_transaction = false; return true; }
	public function rollBack(): bool { $this->connection->get_pdo()->rollBack(); $this->in_transaction = false; return true; }
	public function inTransaction(): bool { return $this->in_transaction; }
	public function get_insert_id(): int { return 0; }

	public function query( string $query, $fetch_mode = PDO::FETCH_OBJ, ...$fetch_mode_args ) {
		unset( $fetch_mode, $fetch_mode_args );
		if ( str_starts_with( $query, 'UPDATE wp_posts' ) ) {
			++self::$update_attempts;
			if ( 'locked' === self::$failure && 1 === self::$update_attempts ) {
				throw new PDOException( 'SQLSTATE[HY000]: General error: 5 database is locked' );
			}
			if ( 'invalid' === self::$failure ) {
				throw new PDOException( 'SQLSTATE[HY000]: General error: 1 invalid update' );
			}
		}
		return $this->connection->get_pdo()->query( $query );
	}
}

class WP_Markdown_Storage {}

class WP_Markdown_Search {
	public function __construct( mixed ...$args ) { unset( $args ); }
	public function maybe_rewrite_query( string $query ): ?string { unset( $query ); return null; }
}

class WP_Markdown_Write_Engine {
	public int $prepared = 0;
	public int $continued = 0;

	public function recover_pending(): array { return array(); }
	public function wordpress_post_identities( array $post_ids ): array { return array_fill_keys( $post_ids, array( 'before' => true ) ); }
	public function prepare_post_commit( int $post_id, mixed $before ): array { unset( $before ); ++$this->prepared; return array( 'post_id' => $post_id ); }
	public function continue_post_commit( array $operation ): array { unset( $operation ); ++$this->continued; return array(); }
	public function persist_mutation( array $mutation ): void { unset( $mutation ); }
}

require_once dirname( __DIR__ ) . '/inc/sqlite/class-wp-markdown-sqlite-runtime-adapter.php';

function mdi_contention_adapter( WP_Markdown_Write_Engine $engine ): array {
	$pdo = new PDO( 'sqlite::memory:' );
	$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	$pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_content TEXT)' );
	$pdo->exec( "INSERT INTO wp_posts (ID, post_content) VALUES (1, 'before')" );
	$adapter = new WP_Markdown_SQLite_Runtime_Adapter( new WP_SQLite_Connection( $pdo ), 'wordpress', new WP_Markdown_Storage() );
	$adapter->set_write_engine( $engine );
	return array( $adapter, $pdo );
}

$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) { echo 'PASS: ' . $message . PHP_EOL; return; }
	$failures[] = $message;
	echo 'FAIL: ' . $message . PHP_EOL;
};

WP_MySQL_On_SQLite::$failure = 'locked';
WP_MySQL_On_SQLite::$update_attempts = 0;
$engine = new WP_Markdown_Write_Engine();
[ $adapter, $pdo ] = mdi_contention_adapter( $engine );
$adapter->query( "UPDATE wp_posts SET post_content='after' WHERE ID=1" );
$assert( 'after' === $pdo->query( 'SELECT post_content FROM wp_posts WHERE ID=1' )->fetchColumn(), 'post update succeeds after transient SQLite contention' );
$assert( 2 === WP_MySQL_On_SQLite::$update_attempts, 'only the failed foreground transaction is retried' );
$assert( 1 === $engine->prepared && 1 === $engine->continued, 'canonical continuation runs exactly once after the successful transaction' );

WP_MySQL_On_SQLite::$failure = 'invalid';
WP_MySQL_On_SQLite::$update_attempts = 0;
$engine = new WP_Markdown_Write_Engine();
[ $adapter ] = mdi_contention_adapter( $engine );
$error = null;
try {
	$adapter->query( "UPDATE wp_posts SET post_content='after' WHERE ID=1" );
} catch ( Throwable $thrown ) {
	$error = $thrown;
}
$assert( $error instanceof PDOException && str_contains( $error->getMessage(), 'invalid update' ), 'non-contention failures still surface' );
$assert( 1 === WP_MySQL_On_SQLite::$update_attempts, 'non-contention failures are not replayed' );
$assert( 0 === $engine->prepared && 0 === $engine->continued, 'failed transactions do not prepare canonical continuation' );

exit( empty( $failures ) ? 0 : 1 );
