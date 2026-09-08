<?php
/** Scalar expressions are shared by filters, groups, ordering, and HAVING. */

declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-scalar-clauses-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_dates (id int unsigned NOT NULL auto_increment, published_at datetime NOT NULL, latitude decimal(8,4) NOT NULL, label varchar(32) NOT NULL, PRIMARY KEY (id))', 'wp_' ) );
foreach ( array( array( '2024-01-15 12:00:00', '10.0', 'one' ), array( '2024-01-25 12:00:00', '20.0', 'two' ), array( '2024-02-01 12:00:00', '30.0', 'eclair' ) ) as $row ) {
	$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_dates (published_at, latitude, label) VALUES ('{$row[0]}', {$row[1]}, '{$row[2]}')", 'wp_' ) );
}
$where = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_dates WHERE DATE_ADD(published_at, INTERVAL 1 DAY) >= '2024-01-26 00:00:00' ORDER BY id", 'wp_' ) );
$subtracted = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_dates WHERE DATE_SUB(published_at, INTERVAL 1 DAY) < '2024-01-15 00:00:00' ORDER BY id", 'wp_' ) );
$difference = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_dates WHERE TIMESTAMPDIFF(DAY, published_at, published_at) = 0 ORDER BY id', 'wp_' ) );
$group = $runtime->execute( new WP_Markdown_Query_Request( "SELECT DATE_FORMAT(published_at, '%Y-%m') AS bucket, COUNT(*) AS total FROM wp_dates GROUP BY DATE_FORMAT(published_at, '%Y-%m') HAVING total > 0", 'wp_' ) );
$haversine = 'ACOS(COS(RADIANS(latitude)))';
$ordered = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id, {$haversine} AS distance FROM wp_dates HAVING {$haversine} > 0 ORDER BY {$haversine} DESC", 'wp_' ) );
$semantics = $runtime->execute( new WP_Markdown_Query_Request( "SELECT DATE_ADD('2024-01-31', INTERVAL 1 MONTH) AS month_end, DATE_SUB('2024-03-31', INTERVAL 1 MONTH) AS previous_month_end, TIMESTAMPDIFF(MONTH, '2024-01-31', '2024-02-29') AS months, CHAR_LENGTH('éclair') AS characters, LEFT('éclair', 1) AS first_letter, ACOS(2) AS invalid_domain, NULLIF(1, '1') AS coerced_null, ROUND(-1.5) AS rounded, DATE('0000-00-00 00:00:00') AS zero_date FROM wp_dates LIMIT 1", 'wp_' ) );
$checks = array(
	'WP date WHERE evaluates DATE_ADD INTERVAL after the bounded read' => array( '2', '3' ) === array_map( static fn( object $row ): string => $row->id, $where->wpdb_state()['last_result'] ),
	'DATE_SUB INTERVAL and TIMESTAMPDIFF use the shared WHERE scalar path' => array( '1' ) === array_map( static fn( object $row ): string => $row->id, $subtracted->wpdb_state()['last_result'] )
		&& array( '1', '2', '3' ) === array_map( static fn( object $row ): string => $row->id, $difference->wpdb_state()['last_result'] ),
	'date bucketing groups scalar values and retains aggregate HAVING' => array( '2024-01' => '2', '2024-02' => '1' ) === array_reduce( $group->wpdb_state()['last_result'], static function ( array $values, object $row ): array { $values[ $row->bucket ] = $row->total; return $values; }, array() ),
	'nested haversine scalar expression orders by the evaluated value' => array( '3', '2', '1' ) === array_map( static fn( object $row ): string => $row->id, $ordered->wpdb_state()['last_result'] ),
	'MariaDB month boundaries, Unicode characters, null coercion, and numeric domains retain scalar semantics' => array( 'month_end' => '2024-02-29', 'previous_month_end' => '2024-02-29', 'months' => '0', 'characters' => '6', 'first_letter' => 'é', 'invalid_domain' => null, 'coerced_null' => null, 'rounded' => '-2', 'zero_date' => '0000-00-00' ) === (array) ( $semantics->wpdb_state()['last_result'][0] ?? array() ),
);
$failed = false;
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; $failed = $failed || ! $passed; }
array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() ); array_map( 'unlink', glob( $root . '/_schema/*' ) ?: array() );
@rmdir( $root . '/_tables' ); @rmdir( $root . '/_schema' ); @rmdir( $root . '/_options' ); @rmdir( $root );
exit( $failed ? 1 : 0 );
