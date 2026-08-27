<?php
/**
 * Disposable native MariaDB probe. Run in a multisite WP Codebox with mysql-full enabled:
 * wp eval-file wp-content/plugins/markdown-database-integration/tests/probe-native-mariadb-mysql-full.php
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'MARKDOWN_DB_BACKEND' ) || 'mysql-full' !== MARKDOWN_DB_BACKEND ) {
	fwrite( STDERR, "SKIP: requires a native MariaDB WordPress Codebox with MARKDOWN_DB_BACKEND=mysql-full.\n" );
	exit( 0 );
}
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

require_once dirname( __DIR__ ) . '/inc/mysql/class-wp-markdown-mysql-wpdb.php';

if ( ! $GLOBALS['wpdb'] instanceof WP_Markdown_MySQL_WPDB ) {
	throw new RuntimeException( 'mysql-full db.php bootstrap did not install the MDI wpdb boundary.' );
}

function mdi_mysql_full_probe_state( wpdb $db ): array {
	return array_intersect_key( get_object_vars( $db ), array_flip( array( 'last_error', 'rows_affected', 'insert_id', 'num_rows', 'last_query', 'last_result', 'num_queries', 'queries', 'prefix', 'base_prefix' ) ) );
}
function mdi_mysql_full_probe_compare( wpdb $stock, wpdb $delegate, string $stock_sql, string $delegate_sql, string $label ): void {
	$stock_result = $stock->query( $stock_sql );
	$delegate_result = $delegate->query( $delegate_sql );
	if ( $stock_result !== $delegate_result ) {
		throw new RuntimeException( "{$label}: return mismatch." );
	}
	$stock_state = mdi_mysql_full_probe_state( $stock );
	$delegate_state = mdi_mysql_full_probe_state( $delegate );
	foreach ( array( 'last_error', 'rows_affected', 'insert_id', 'num_rows', 'prefix', 'base_prefix' ) as $field ) {
		if ( $stock_state[ $field ] !== $delegate_state[ $field ] ) {
			throw new RuntimeException( "{$label}: {$field} mismatch." );
		}
	}
	if ( ! str_ends_with( $stock_state['last_query'], $stock_sql ) || ! str_ends_with( $delegate_state['last_query'], $delegate_sql ) || empty( $stock_state['queries'] ) || empty( $delegate_state['queries'] ) ) {
		throw new RuntimeException( "{$label}: last-query or SAVEQUERIES state mismatch." );
	}
}

$observations = array();
$stock = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$delegate = new WP_Markdown_MySQL_WPDB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST, static function ( array $observation ) use ( &$observations ): void { $observations[] = $observation; } );
$base_prefix = $GLOBALS['table_prefix'] ?? 'wp_';
$stock->set_prefix( $base_prefix );
$delegate->set_prefix( $base_prefix );
$stock->set_blog_id( 2 );
$delegate->set_blog_id( 2 );
$expected_prefix = $base_prefix . '2_';
if ( 2 !== $stock->blogid || 2 !== $delegate->blogid || $expected_prefix !== $stock->prefix || $stock->prefix !== $delegate->prefix || $stock->base_prefix !== $delegate->base_prefix ) {
	throw new RuntimeException( 'Multisite prefix state mismatch.' );
}
$suffix = substr( hash( 'sha256', getmypid() . ':' . microtime( true ) ), 0, 10 );
$stock_table = $stock->prefix . 'mdi_mysql_full_stock_' . $suffix;
$delegate_table = $delegate->prefix . 'mdi_mysql_full_delegate_' . $suffix;

try {
	$schema = ' (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `name` varchar(20) NOT NULL, KEY `name` (`name`), PRIMARY KEY (`id`)) ENGINE=InnoDB';
	mdi_mysql_full_probe_compare( $stock, $delegate, "CREATE TABLE `{$stock_table}`{$schema}", "CREATE TABLE `{$delegate_table}`{$schema}", 'CREATE TABLE' );
	mdi_mysql_full_probe_compare( $stock, $delegate, "INSERT INTO `{$stock_table}` (`name`) VALUES ('native')", "INSERT INTO `{$delegate_table}` (`name`) VALUES ('native')", 'INSERT' );
	mdi_mysql_full_probe_compare( $stock, $delegate, "UPDATE `{$stock_table}` SET `name` = 'updated' WHERE `id` = 1", "UPDATE `{$delegate_table}` SET `name` = 'updated' WHERE `id` = 1", 'UPDATE' );
	mdi_mysql_full_probe_compare( $stock, $delegate, "DROP INDEX `name` ON `{$stock_table}`", "DROP INDEX `name` ON `{$delegate_table}`", 'DROP INDEX' );
	mdi_mysql_full_probe_compare( $stock, $delegate, "TRUNCATE TABLE `{$stock_table}`", "TRUNCATE TABLE `{$delegate_table}`", 'TRUNCATE TABLE' );
	mdi_mysql_full_probe_compare( $stock, $delegate, "INSERT INTO `{$stock_table}` (`missing`) VALUES ('x')", "INSERT INTO `{$delegate_table}` (`missing`) VALUES ('x')", 'failed SQL' );
	mdi_mysql_full_probe_compare( $stock, $delegate, 'START TRANSACTION', 'START TRANSACTION', 'START TRANSACTION' );
	mdi_mysql_full_probe_compare( $stock, $delegate, "INSERT INTO `{$stock_table}` (`name`) VALUES ('rollback')", "INSERT INTO `{$delegate_table}` (`name`) VALUES ('rollback')", 'transactional INSERT' );
	mdi_mysql_full_probe_compare( $stock, $delegate, 'ROLLBACK', 'ROLLBACK', 'ROLLBACK' );
	if ( count( $observations ) !== 6 || 'CREATE' !== $observations[0]['operation'] || 'ALTER' !== $observations[3]['operation'] || 'TRUNCATE' !== $observations[4]['operation'] || ! $observations[5]['transaction']['active'] ) {
		throw new RuntimeException( 'Unexpected normalized mutation or rollback transaction observations.' );
	}
	echo "PASS: native MariaDB stock/delegating wpdb compatibility probe.\n";
} finally {
	$stock->query( "DROP TABLE IF EXISTS `{$stock_table}`" );
	$delegate->query( "DROP TABLE IF EXISTS `{$delegate_table}`" );
}
