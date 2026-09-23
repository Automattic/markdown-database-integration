<?php
/**
 * Generic-table AUTO_INCREMENT never reissues a value.
 *
 * Generic tables derived the next identifier from the largest value still
 * present. Action Scheduler releases a claim by deleting its row while the
 * finished actions keep that claim_id, so each new claim reused the id and
 * re-selected every finished action ("action ignored via Async Request",
 * thousands of times, on every queue run). MySQL never reissues an
 * AUTO_INCREMENT value; neither may mdi-native.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-auto-increment-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the auto-increment fixture.' );
}
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$run     = static fn( string $sql ): WP_Markdown_Query_Result => $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
$id_of   = static fn( WP_Markdown_Query_Result $result ): int => (int) ( $result->wpdb_state()['insert_id'] ?? 0 );

$run( 'CREATE TABLE wp_claims (claim_id BIGINT NOT NULL AUTO_INCREMENT, date_created_gmt DATETIME NULL, PRIMARY KEY (claim_id))' );
$run( 'CREATE TABLE wp_actions (action_id BIGINT NOT NULL AUTO_INCREMENT, status VARCHAR(20) NOT NULL, claim_id BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (action_id))' );

// Action Scheduler's claim lifecycle: stake, run, release (delete the claim row).
$claims = array();
for ( $i = 0; $i < 3; $i++ ) {
	$run( "INSERT INTO wp_actions (status, claim_id) VALUES ('pending', 0)" );
	$claim    = $run( "INSERT INTO wp_claims (date_created_gmt) VALUES ('2026-09-23 00:00:00')" );
	$claims[] = $id_of( $claim );
	$run( 'UPDATE wp_actions SET claim_id = ' . $id_of( $claim ) . " WHERE status = 'pending' AND claim_id = 0 LIMIT 1" );
	$run( "UPDATE wp_actions SET status = 'complete' WHERE claim_id = " . $id_of( $claim ) );
	$run( 'DELETE FROM wp_claims WHERE claim_id = ' . $id_of( $claim ) );
}
$next_claim = $id_of( $run( "INSERT INTO wp_claims (date_created_gmt) VALUES ('2026-09-23 00:00:00')" ) );
$reselected = $run( "SELECT action_id FROM wp_actions WHERE claim_id = {$next_claim} AND status = 'complete'" )->return_value();

// A fresh runtime (a new request) still honours the high-water mark.
$reopened   = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$run( "DELETE FROM wp_claims WHERE claim_id = {$next_claim}" );
$after_reopen = (int) ( $reopened->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_claims (date_created_gmt) VALUES ('2026-09-23 00:00:00')", 'wp_' ) )->wpdb_state()['insert_id'] ?? 0 );

// An explicit identifier above the counter raises it.
$run( "INSERT INTO wp_claims (claim_id, date_created_gmt) VALUES (50, '2026-09-23 00:00:00')" );
$run( 'DELETE FROM wp_claims WHERE claim_id = 50' );
$after_explicit = $id_of( $run( "INSERT INTO wp_claims (date_created_gmt) VALUES ('2026-09-23 00:00:00')" ) );

// A missing or corrupt record falls back to the rows, never below them.
file_put_contents( $root . '/_tables/.auto_increment/claims.json', '{"version":1,"high_water":"broken"}' );
$after_corrupt = $id_of( $run( "INSERT INTO wp_claims (date_created_gmt) VALUES ('2026-09-23 00:00:00')" ) );

$checks = array(
	'each claim gets a new id although the previous row was deleted' => array( 1, 2, 3 ) === $claims && 4 === $next_claim,
	'a new claim never re-selects finished actions'                  => 0 === $reselected,
	'the high-water mark survives a new runtime'                     => 5 === $after_reopen,
	'an explicit higher identifier raises the counter'               => 51 === $after_explicit,
	'a corrupt record falls back to the stored rows'                 => $after_corrupt > $after_explicit,
	'the claims row set is not left with stale bookkeeping files'    => ! is_file( $root . '/_tables/claims.json.auto_increment' ) && is_dir( $root . '/_tables/.auto_increment' ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}
if ( $failed ) {
	echo 'claims=', implode( ',', $claims ), " next={$next_claim} reselected={$reselected} reopen={$after_reopen} explicit={$after_explicit} corrupt={$after_corrupt}\n";
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $files as $file ) {
	$file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
}
@rmdir( $root );

exit( $failed ? 1 : 0 );
