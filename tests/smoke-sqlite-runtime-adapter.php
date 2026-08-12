<?php
/** SQLite runtime adapter ownership and compatibility smoke test. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MARKDOWN_DB_SQLITE_LEGACY_RESULT_API', true );

class WP_SQLite_Connection {
	public function __construct( private PDO $pdo ) {}
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

$passed = $legacy instanceof WP_Markdown_SQLite_Runtime_Adapter
	&& $legacy instanceof WP_Markdown_Driver
	&& $adapter->operations() instanceof WP_Markdown_SQLite_Operations;

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

echo ( $passed ? 'PASS' : 'FAIL' ) . ': SQLite runtime adapter owns the implementation behind the legacy driver name.' . PHP_EOL;
exit( $passed ? 0 : 1 );
