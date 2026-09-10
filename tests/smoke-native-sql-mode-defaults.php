<?php
/** SQL-mode-sensitive implicit defaults use one logical connection across scopes. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-sql-mode-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
$checks = array();
try {
	$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
	$run = static fn( string $sql ): WP_Markdown_Query_Result => $runtime->execute( new WP_Markdown_Query_Request( $sql ) );
	$created = $run( 'CREATE TABLE wp_defaults (id bigint unsigned NOT NULL AUTO_INCREMENT, payload longtext NOT NULL, amount int NOT NULL, optional_value varchar(20) NULL, explicit_value varchar(20) NOT NULL DEFAULT \'chosen\', PRIMARY KEY (id))' );
	$inserted = $run( 'INSERT INTO wp_defaults (id) VALUES (1)' );
	$warnings = $run( 'SHOW WARNINGS' );
	$count = $run( 'SELECT @@warning_count' );
	$row = $run( 'SELECT * FROM wp_defaults WHERE id = 1' )->corpus_result()['rows'][0] ?? null;
	$checks['non-strict defaults preserve nullable and explicit defaults'] = $created->succeeded() && 1 === $inserted->return_value() && array( 'id' => '1', 'payload' => '', 'amount' => '0', 'optional_value' => null, 'explicit_value' => 'chosen' ) === $row;
	$checks['implicit defaults report bounded MySQL warnings'] = 2 === $warnings->return_value() && '1364' === $warnings->corpus_result()['rows'][0]['Code'] && '2' === $count->corpus_result()['rows'][0]['@@warning_count'];
	$set = $run( "SET SESSION sql_mode = 'strict_trans_tables,NO_ENGINE_SUBSTITUTION'" );
	$mode = $run( 'SELECT @@SESSION.sql_mode' );
	$variables = $run( "SHOW VARIABLES LIKE 'sql_mode'" );
	$checks['session and SHOW introspection agree on supported mode'] = $set->succeeded() && 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' === $mode->corpus_result()['rows'][0]['@@SESSION.sql_mode'] && 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' === $variables->corpus_result()['rows'][0]['Value'];
	$rejected = $run( 'INSERT INTO wp_defaults (id) VALUES (2)' );
	$errors = $run( 'SHOW WARNINGS' );
	$checks['strict omissions retain every missing-default error'] = 2 === $errors->return_value() && 'Error' === $errors->corpus_result()['rows'][0]['Level'] && '1364' === $errors->corpus_result()['rows'][1]['Code'];
	$checks['strict omission fails with MySQL errno and no row'] = false === $rejected->return_value() && 1364 === $rejected->diagnostic()['code'] && 0 === $run( 'SELECT * FROM wp_defaults WHERE id = 2' )->return_value();
	$invalid = $run( "SET sql_mode = 'ANSI_QUOTES'" );
	$checks['unsupported mode fails without changing existing mode'] = ! $invalid->succeeded() && 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION' === $run( 'SELECT @@sql_mode' )->corpus_result()['rows'][0]['@@sql_mode'];
	$run( "SET @@SESSION.sql_mode = ''" );
	$checks['clearing strict mode restores implicit defaults'] = 1 === $run( 'INSERT INTO wp_defaults (id) VALUES (2)' )->return_value();
	$multisite = WP_Markdown_Native_Runtime_Factory::multisite_runtime( $root );
	$multisite->execute( new WP_Markdown_Query_Request( "SET sql_mode = 'STRICT_ALL_TABLES'", 'wp_' ) );
	$site_mode = $multisite->execute( new WP_Markdown_Query_Request( 'SELECT @@sql_mode', 'wp_2_' ) );
	$checks['blog switching shares mode but separate connections do not'] = 'STRICT_ALL_TABLES' === $site_mode->corpus_result()['rows'][0]['@@sql_mode'] && '' === $run( 'SELECT @@sql_mode' )->corpus_result()['rows'][0]['@@sql_mode'];
	$multisite->close();
	$checks['logical close resets SQL session mode'] = '' === $multisite->execute( new WP_Markdown_Query_Request( 'SELECT @@sql_mode', 'wp_' ) )->corpus_result()['rows'][0]['@@sql_mode'];
} finally {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
		$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}
foreach ( $checks as $label => $passed ) { fwrite( $passed ? STDOUT : STDERR, ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n" ); }
exit( in_array( false, $checks, true ) ? 1 : 0 );
