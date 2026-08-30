<?php
/** SQLite runtime adapter ownership and compatibility smoke test. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API', true );

class WP_SQLite_Connection {
	private PDO $pdo;
	public function __construct( PDO|array $options ) { $this->pdo = $options instanceof PDO ? $options : $options['pdo']; }
	public function get_pdo(): PDO { return $this->pdo; }
}

class WP_MySQL_On_SQLite {
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
}

class WP_Markdown_Storage {}
class WP_Markdown_Search {}

require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-driver.php';

$pdo = new PDO( 'sqlite::memory:' );
$connection = new WP_SQLite_Connection( $pdo );
$legacy = new WP_Markdown_Driver( $connection, 'wordpress', new WP_Markdown_Storage() );
$adapter = new WP_Markdown_SQLite_Runtime_Adapter( $connection, 'wordpress', new WP_Markdown_Storage() );
$detect_operation = new ReflectionMethod( WP_Markdown_SQLite_Runtime_Adapter::class, 'detect_operation' );

$passed = $legacy instanceof WP_Markdown_SQLite_Runtime_Adapter
	&& $legacy instanceof WP_Markdown_Driver
	&& $adapter->operations() instanceof WP_Markdown_SQLite_Operations
	&& null === $detect_operation->invoke( $adapter, 'TRUNCATE TABLE ownership_test' )
	&& null === $detect_operation->invoke( $adapter, 'CREATE INDEX ownership_name ON ownership_test (name)' )
	&& null === $detect_operation->invoke( $adapter, 'DELETE a, b FROM ownership_test a, ownership_test b WHERE a.id = b.id' );

$pdo->exec( 'CREATE TABLE ownership_test (id INTEGER PRIMARY KEY)' );
$adapter->beginTransaction();
$pdo->exec( 'INSERT INTO ownership_test VALUES (1)' );
$adapter->begin_canonical_transaction();
$pdo->exec( 'INSERT INTO ownership_test VALUES (2)' );
$adapter->rollback_canonical_transaction();
$passed = $passed
	&& $adapter->inTransaction()
	&& ! $adapter->canonical_transaction_active()
	&& array( '1' ) === $pdo->query( 'SELECT id FROM ownership_test ORDER BY id' )->fetchAll( PDO::FETCH_COLUMN );
$adapter->rollBack();

$adapter->begin_canonical_transaction();
$pdo->exec( 'INSERT INTO ownership_test VALUES (3)' );
$adapter->commit_canonical_transaction();
$adapter->begin_canonical_transaction();
$pdo->exec( 'INSERT INTO ownership_test VALUES (4)' );
$adapter->rollback_canonical_transaction();
$pdo->exec( 'ALTER TABLE ownership_test ADD COLUMN label TEXT' );
$hydrated_ids = $pdo->query( 'SELECT id FROM ownership_test ORDER BY id' )->fetchAll( PDO::FETCH_COLUMN );
$canonical_close_checks = array(
	'canonical scope closed'         => ! $adapter->canonical_transaction_active(),
	'schema write followed hydration' => array( '3' ) === $hydrated_ids,
);
foreach ( $canonical_close_checks as $label => $check ) {
	if ( ! $check ) {
		$details = 'schema write followed hydration' === $label ? ' Got ' . json_encode( $hydrated_ids ) . '.' : '';
		fwrite( STDERR, "FAIL: {$label}.{$details}\n" );
	}
	$passed = $passed && $check;
}

$database = tempnam( sys_get_temp_dir(), 'mdi-bounded-adapter-' );
$writer = new PDO( 'sqlite:' . $database );
$writer->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$writer->exec( 'CREATE TABLE bounded_boot (id INTEGER PRIMARY KEY)' );
$writer->exec( 'BEGIN EXCLUSIVE' );
$started = hrtime( true );
$bounded_failure = null;
try {
	WP_Markdown_SQLite_Runtime_Adapter::create_runtime( $database, null, 'wordpress', new WP_Markdown_Storage(), null, true );
} catch ( Throwable $error ) {
	$bounded_failure = $error;
}
$bounded_ms = ( hrtime( true ) - $started ) / 1000000;
$writer->rollBack();
$bounded = WP_Markdown_SQLite_Runtime_Adapter::create_runtime( $database, null, 'wordpress', new WP_Markdown_Storage(), null, true );
$query_only_during_attach = '1' === (string) $bounded->get_connection()->get_pdo()->query( 'PRAGMA query_only' )->fetchColumn();
$bounded->finish_warm_bootstrap();
$query_only_after_attach = '0' === (string) $bounded->get_connection()->get_pdo()->query( 'PRAGMA query_only' )->fetchColumn();
$passed = $passed
	&& $bounded_failure instanceof PDOException
	&& $bounded_ms < 250
	&& $query_only_during_attach
	&& $query_only_after_attach;
if ( ! ( $bounded_failure instanceof PDOException && $bounded_ms < 250 && $query_only_during_attach && $query_only_after_attach ) ) {
	fwrite( STDERR, 'FAIL: bounded warm adapter attachment. Elapsed ' . $bounded_ms . " ms.\n" );
}
$bounded = null;
$writer = null;
unlink( $database );

echo ( $passed ? 'PASS' : 'FAIL' ) . ': SQLite runtime adapter owns the implementation behind the legacy driver name.' . PHP_EOL;
exit( $passed ? 0 : 1 );
