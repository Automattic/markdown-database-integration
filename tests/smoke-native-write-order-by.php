<?php
/**
 * Bounded single-table UPDATE/DELETE with ORDER BY … LIMIT (issue #425).
 *
 * Action Scheduler's post store claims work with a MySQL write that ranks the
 * pending rows before LIMIT applies. mdi-native rejected ORDER BY on writes, and
 * the wp_posts path also ignored both range operators and LIMIT, so a claim could
 * never select exactly the oldest due action.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root    = sys_get_temp_dir() . '/mdi-native-write-order-by-' . bin2hex( random_bytes( 6 ) );
$state   = $root . '/state';
$content = $root . '/content';
if ( ! mkdir( $state . '/_options', 0777, true ) || ! mkdir( $state . '/_tables', 0777, true ) || ! mkdir( $content, 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the ORDER BY write fixture.' );
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $state, 'wp_', null, false, $content );
$run     = static fn( string $sql ): WP_Markdown_Query_Result => $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );

$hook   = 'wp_agent_workflow_branch_run';
$insert = static function ( string $slug, string $title, string $status, int $menu_order, string $date_gmt ) use ( $run ): int {
	$result = $run(
		"INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (0, '{$date_gmt}', '{$date_gmt}', '[]', '{$title}', '', '{$status}', 'closed', 'closed', '', '{$slug}', '', '', '{$date_gmt}', '{$date_gmt}', '', 0, '', {$menu_order}, 'scheduled-action', '', 0)"
	);
	return (int) ( $result->wpdb_state()['insert_id'] ?? 0 );
};

// Storage order deliberately differs from claim order.
$ids = array(
	'late'       => $insert( 'late', $hook, 'pending', 0, '2026-09-20 12:00:00' ),
	'oldest'     => $insert( 'oldest', $hook, 'pending', 0, '2026-09-10 12:00:00' ),
	'tie_high'   => $insert( 'tie-high', $hook, 'pending', 0, '2026-09-15 12:00:00' ),
	'tie_low'    => $insert( 'tie-low', $hook, 'pending', 0, '2026-09-15 12:00:00' ),
	'priority'   => $insert( 'priority', $hook, 'pending', 5, '2026-09-01 12:00:00' ),
	'future'     => $insert( 'future', $hook, 'pending', 0, '2030-01-01 00:00:00' ),
	'other_hook' => $insert( 'other-hook', 'some_other_hook', 'pending', 0, '2026-09-01 00:00:00' ),
	'done'       => $insert( 'done', $hook, 'publish', 0, '2026-09-01 00:00:00' ),
);

// Verbatim shape of ActionScheduler_wpPostStore::claim_actions().
$claim = static fn( string $token ): WP_Markdown_Query_Result => $run(
	"UPDATE wp_posts SET post_password = '{$token}', post_modified_gmt = '2026-09-23 19:11:39', post_modified = '2026-09-23 19:11:39' WHERE post_type = 'scheduled-action' AND post_status = 'pending' AND post_password = '' AND post_title IN ('{$hook}') AND post_date_gmt <= '2026-09-23 19:11:39' ORDER BY menu_order ASC, post_date_gmt ASC, ID ASC LIMIT 1"
);
$claimed_by = static function ( string $token ) use ( $run ): array {
	$result = $run( "SELECT ID FROM wp_posts WHERE post_type = 'scheduled-action' AND post_password = '{$token}'" );
	return array_map( static fn( object $row ): int => (int) $row->ID, $result->wpdb_state()['last_result'] ?? array() );
};

$first  = $claim( 'claim-one' );
$second = $claim( 'claim-two' );
$third  = $claim( 'claim-three' );
$fourth = $claim( 'claim-four' );
$fifth  = $claim( 'claim-five' );
$sixth  = $claim( 'claim-six' );

// Generic snapshot table: DESC, multi-key, and DELETE.
$run( 'CREATE TABLE wp_queue (id BIGINT NOT NULL AUTO_INCREMENT, priority INT NOT NULL, label VARCHAR(20) NULL, PRIMARY KEY (id))' );
foreach ( array( array( 1, 'a' ), array( 3, 'b' ), array( 2, 'c' ), array( 3, 'd' ), array( 1, 'e' ) ) as $row ) {
	$run( "INSERT INTO wp_queue (priority, label) VALUES ({$row[0]}, '{$row[1]}')" );
}
$labels = static function () use ( $run ): array {
	$result = $run( 'SELECT id, priority, label FROM wp_queue ORDER BY id ASC' );
	$out    = array();
	foreach ( $result->wpdb_state()['last_result'] ?? array() as $row ) {
		$out[ (string) $row->label ] = (int) $row->priority;
	}
	return $out;
};
$table_update = $run( 'UPDATE wp_queue SET priority = 9 WHERE priority >= 2 ORDER BY priority DESC, id DESC LIMIT 2' );
$after_update = $labels();
$table_delete = $run( 'DELETE FROM wp_queue WHERE priority < 9 ORDER BY id DESC LIMIT 1' );
$after_delete = $labels();
$bad_order    = $run( 'UPDATE wp_queue SET label = NULL ORDER BY nonexistent_column LIMIT 1' );

$checks = array(
	'the Action Scheduler claim statement parses and claims one row' => 1 === $first->return_value() && array( $ids['oldest'] ) === $claimed_by( 'claim-one' ),
	'ORDER BY post_date_gmt picks the next oldest due action'       => 1 === $second->return_value() && array( min( $ids['tie_high'], $ids['tie_low'] ) ) === $claimed_by( 'claim-two' ),
	'equal dates fall back to ID ASC'                                => 1 === $third->return_value() && array( max( $ids['tie_high'], $ids['tie_low'] ) ) === $claimed_by( 'claim-three' ),
	'menu_order ASC ranks before post_date_gmt'                      => 1 === $fourth->return_value() && array( $ids['late'] ) === $claimed_by( 'claim-four' ),
	'a higher menu_order is claimed only after lower ones'           => 1 === $fifth->return_value() && array( $ids['priority'] ) === $claimed_by( 'claim-five' ),
	'a post_date_gmt <= bound excludes future actions'               => 0 === $sixth->return_value() && array() === $claimed_by( 'claim-six' ),
	'LIMIT claims never touch other hooks or statuses'               => array() === array_intersect( array( $ids['future'], $ids['other_hook'], $ids['done'] ), array_merge( ...array_map( $claimed_by, array( 'claim-one', 'claim-two', 'claim-three', 'claim-four', 'claim-five' ) ) ) ),
	'a generic UPDATE applies DESC multi-key order before LIMIT'     => 2 === $table_update->return_value() && array( 'a' => 1, 'b' => 9, 'c' => 2, 'd' => 9, 'e' => 1 ) === $after_update,
	'a generic DELETE applies ORDER BY before LIMIT'                 => 1 === $table_delete->return_value() && array( 'a' => 1, 'b' => 9, 'c' => 2, 'd' => 9 ) === $after_delete,
	'an unorderable ORDER BY column fails instead of guessing'       => ! $bad_order->succeeded() && 'unsupported_order' === ( $bad_order->diagnostic()['reason'] ?? null ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $files as $file ) {
	$file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
}
@rmdir( $root );

exit( $failed ? 1 : 0 );
