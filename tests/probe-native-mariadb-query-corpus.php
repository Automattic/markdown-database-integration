<?php
/** Emit a sanitized native MariaDB query compatibility corpus to stdout. */

if ( ! defined( 'ABSPATH' ) || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof wpdb || ! extension_loaded( 'mysqli' ) ) {
	fwrite( STDERR, "SKIP: requires WordPress on native MySQL/MariaDB.\n" );
	exit( 0 );
}
require_once dirname( __DIR__ ) . '/inc/compatibility/class-wp-markdown-query-compatibility-corpus.php';

$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$db->set_prefix( (string) ( $GLOBALS['table_prefix'] ?? 'wp_' ) );
$suffix = substr( hash( 'sha256', getmypid() . ':' . microtime( true ) ), 0, 12 );
$table = $db->prefix . 'mdi_query_corpus_' . $suffix;
$replacements = array( 'probe_table' => $table, 'table_prefix' => $db->prefix, 'database' => DB_NAME );
$site_url = function_exists( 'home_url' ) ? home_url() : '';
if ( is_string( $site_url ) && '' !== $site_url ) { $replacements['site_url'] = $site_url; }
$normalizer = new WP_Markdown_Query_Compatibility_Normalizer( $replacements );
$recorder = new WP_Markdown_Query_Compatibility_Recorder( $normalizer );
$active = false;
$transaction = static function () use ( &$active ): array { return array( 'active' => $active ); };
$capture = static function ( string $scenario, string $query, callable $assertion ) use ( $recorder, $db, $transaction, &$active ): mixed {
	$result = $recorder->capture( $scenario, $query, static function () use ( $db, $query, &$active ): mixed {
		$result = $db->query( $query );
		if ( 'START TRANSACTION' === $query ) { $active = true; }
		if ( in_array( $query, array( 'COMMIT', 'ROLLBACK' ), true ) ) { $active = false; }
		return $result;
	}, $db, $transaction );
	if ( ! $assertion( $result, $db ) ) { throw new RuntimeException( 'Native query corpus scenario failed: ' . $scenario ); }
	return $result;
};

try {
	$capture( 'core.schema.create', "CREATE TABLE `{$table}` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `kind` varchar(32) NOT NULL, `value` longtext NOT NULL, PRIMARY KEY (`id`), KEY `kind` (`kind`)) ENGINE=InnoDB", static fn( mixed $result ): bool => true === $result );
	$capture( 'core.schema.inspect', "SHOW COLUMNS FROM `{$table}`", static fn( mixed $result, wpdb $database ): bool => is_int( $result ) && $result >= 3 && $database->num_rows >= 3 );
	$capture( 'core.posts.insert-shape', "INSERT INTO `{$table}` (`kind`,`value`) VALUES ('post','canonical')", static fn( mixed $result, wpdb $database ): bool => 1 === $result && 1 === (int) $database->insert_id );
	$capture( 'core.options.select-shape', "SELECT `id`,`kind`,`value` FROM `{$table}` WHERE `kind`='post' ORDER BY `id`", static fn( mixed $result, wpdb $database ): bool => 1 === $result && 1 === $database->num_rows );
	$capture( 'core.metadata.update-shape', "UPDATE `{$table}` SET `value`='updated' WHERE `id`=1", static fn( mixed $result, wpdb $database ): bool => 1 === $result && 1 === $database->rows_affected );
	$capture( 'core.transaction.begin', 'START TRANSACTION', static fn( mixed $result ): bool => true === $result );
	$capture( 'core.comments.delete-shape', "DELETE FROM `{$table}` WHERE `id`=1", static fn( mixed $result, wpdb $database ): bool => 1 === $result && 1 === $database->rows_affected );
	$capture( 'core.transaction.rollback', 'ROLLBACK', static fn( mixed $result ): bool => true === $result );
	$capture( 'core.transaction.rollback-proof', "SELECT `id` FROM `{$table}` WHERE `id`=1", static fn( mixed $result, wpdb $database ): bool => 1 === $result && 1 === $database->num_rows );
	$previous_suppression = $db->suppress_errors( true );
	try { $capture( 'core.failure.invalid-column', "INSERT INTO `{$table}` (`missing`) VALUES ('x')", static fn( mixed $result, wpdb $database ): bool => false === $result && '' !== $database->last_error ); }
	finally { $db->suppress_errors( $previous_suppression ); }
	echo $recorder->json();
} finally {
	$db->query( "DROP TABLE IF EXISTS `{$table}`" );
}
