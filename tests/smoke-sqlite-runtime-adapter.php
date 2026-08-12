<?php
/** SQLite runtime adapter ownership and compatibility smoke test. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class WP_SQLite_Connection {
	public function __construct( private PDO $pdo ) {}
	public function get_pdo(): PDO { return $this->pdo; }
}

class WP_MySQL_On_SQLite {
	private WP_SQLite_Connection $connection;
	public function __construct( string $dsn, ?string $username = null, ?string $password = null, array $options = array() ) {
		unset( $dsn, $username, $password );
		$this->connection = new WP_SQLite_Connection( $options['pdo'] );
	}
	public function get_connection(): WP_SQLite_Connection { return $this->connection; }
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

echo ( $passed ? 'PASS' : 'FAIL' ) . ': SQLite runtime adapter owns the implementation behind the legacy driver name.' . PHP_EOL;
exit( $passed ? 0 : 1 );
