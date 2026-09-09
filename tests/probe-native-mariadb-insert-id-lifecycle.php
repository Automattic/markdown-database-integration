<?php
/** Verify stock wpdb insert_id lifecycle semantics on disposable MySQL. */

if ( ! defined( 'ABSPATH' ) || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof wpdb ) {
	fwrite( STDERR, "SKIP: requires WordPress with MySQL/MariaDB.\n" );
	exit( 0 );
}

$database = $GLOBALS['wpdb'];
$suffix = substr( hash( 'sha256', getmypid() . ':' . microtime( true ) ), 0, 12 );
$table = $database->prefix . 'mdi_insert_id_lifecycle_' . $suffix;
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

try {
	$assert( true === $database->query( "CREATE TABLE `{$table}` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `value` varchar(20) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB" ), 'CREATE TABLE failed.' );
	$assert( 1 === $database->query( "INSERT INTO `{$table}` (`value`) VALUES ('first')" ), 'INSERT failed.' );
	$insert_id = (int) $database->insert_id;
	$assert( $insert_id > 0, 'INSERT did not set insert_id.' );
	$assert( 1 === $database->query( "SELECT `id` FROM `{$table}` WHERE `id` = {$insert_id}" ) && $insert_id === (int) $database->insert_id, 'SELECT changed insert_id.' );
	$assert( 1 === $database->query( "UPDATE `{$table}` SET `value` = 'updated' WHERE `id` = {$insert_id}" ) && $insert_id === (int) $database->insert_id, 'UPDATE changed insert_id.' );
	$assert( 1 === $database->query( "DELETE FROM `{$table}` WHERE `id` = {$insert_id}" ) && $insert_id === (int) $database->insert_id, 'DELETE changed insert_id.' );
	$assert( true === $database->query( 'START TRANSACTION' ) && $insert_id === (int) $database->insert_id, 'START TRANSACTION changed insert_id.' );
	$assert( true === $database->query( 'COMMIT' ) && $insert_id === (int) $database->insert_id, 'COMMIT changed insert_id.' );
	$previous_suppression = $database->suppress_errors( true );
	try {
		$assert( false === $database->query( "INSERT INTO `{$table}` (`missing`) VALUES ('failed')" ) && 0 === (int) $database->insert_id, 'Failed INSERT did not reset insert_id.' );
	} finally {
		$database->suppress_errors( $previous_suppression );
	}
	$assert( 1 === $database->query( "REPLACE INTO `{$table}` (`value`) VALUES ('replacement')" ) && (int) $database->insert_id > 0, 'REPLACE did not set insert_id.' );
	echo "PASS: stock MySQL wpdb insert_id lifecycle.\n";
} finally {
	$database->query( "DROP TABLE IF EXISTS `{$table}`" );
}
