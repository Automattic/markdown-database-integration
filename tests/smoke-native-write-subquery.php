<?php
/** Native UPDATE and DELETE membership writes over typed SELECT plans. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_write_subquery_remove_tree( string $root ): void {
	if ( ! is_dir( $root ) ) {
		return;
	}
	$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}

$root = sys_get_temp_dir() . '/mdi-write-subquery-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0755, true );
mkdir( $root . '/_tables', 0755, true );
file_put_contents( $root . '/_tables/postmeta.json', '[{"meta_id":1,"post_id":1,"meta_key":"matched","meta_value":"one"},{"meta_id":2,"post_id":2,"meta_key":"matched","meta_value":"two"},{"meta_id":3,"post_id":3,"meta_key":"unrelated","meta_value":"three"},{"meta_id":4,"post_id":4,"meta_key":null,"meta_value":null}]' );
file_put_contents( $root . '/_tables/termmeta.json', '[{"meta_id":1,"term_id":1,"meta_key":"matched","meta_value":null},{"meta_id":2,"term_id":2,"meta_key":"01","meta_value":null},{"meta_id":3,"term_id":3,"meta_key":"1","meta_value":null},{"meta_id":4,"term_id":4,"meta_key":null,"meta_value":null}]' );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$insert_post = static function ( int $id, string $type ) use ( $runtime ): WP_Markdown_Query_Result {
	return $runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES ({$id}, 1, '2026-09-07 00:00:00', '2026-09-07 00:00:00', '', 'Post {$id}', '', 'publish', 'open', 'open', '', 'post-{$id}', '', '', '2026-09-07 00:00:00', '2026-09-07 00:00:00', '', 0, '', 0, '{$type}', '', 0)" ) );
};
$posts = array( $insert_post( 1, 'post' ), $insert_post( 2, 'page' ), $insert_post( 3, 'post' ), $insert_post( 4, 'page' ) );
$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES (5, 5, '01', 'five'), (6, 6, '1', 'six')" ) );
$update = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_postmeta SET meta_value = 'page' WHERE post_id IN (SELECT ID FROM wp_posts WHERE post_type = 'page' AND ID = 2)" ) );
$after_update = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$string_members = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_postmeta SET meta_value = 'member' WHERE meta_key IN (SELECT meta_key FROM wp_termmeta)" ) );
$after_string_members = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$grouped = $runtime->execute( new WP_Markdown_Query_Request( "DELETE FROM wp_postmeta WHERE post_id IN (SELECT ID FROM wp_posts WHERE post_type = 'post') OR post_id = 2" ) );
$after_grouped = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$delete = $runtime->execute( new WP_Markdown_Query_Request( "DELETE FROM wp_postmeta WHERE post_id IN (SELECT ID FROM wp_posts WHERE post_type = 'post')" ) );
$after_delete = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$self_target = $runtime->execute( new WP_Markdown_Query_Request( 'DELETE FROM wp_postmeta WHERE post_id IN (SELECT post_id FROM wp_postmeta)' ) );
$after_self_target = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$union_target = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_postmeta SET meta_value = 'union-bypass' WHERE meta_id IN (SELECT ID FROM wp_posts UNION SELECT meta_id FROM wp_postmeta)" ) );
$after_union_target = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$join_target = $runtime->execute( new WP_Markdown_Query_Request( 'DELETE FROM wp_postmeta WHERE meta_id IN (SELECT p.ID FROM wp_posts p JOIN wp_postmeta m ON p.ID = m.post_id)' ) );
$after_join_target = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$null_member = $runtime->execute( new WP_Markdown_Query_Request( 'DELETE FROM wp_postmeta WHERE meta_key IN (SELECT meta_key FROM wp_termmeta)' ) );
$after_null_member = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$empty = $runtime->execute( new WP_Markdown_Query_Request( "UPDATE wp_postmeta SET meta_value = 'changed' WHERE post_id IN (SELECT ID FROM wp_posts WHERE post_type = 'revision')" ) );
$after_empty = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$malformed = $runtime->execute( new WP_Markdown_Query_Request( "DELETE FROM wp_postmeta WHERE post_id IN (SELECT ID, post_type FROM wp_posts)" ) );
$after_malformed = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$failed_inner = $runtime->execute( new WP_Markdown_Query_Request( 'DELETE FROM wp_postmeta WHERE post_id IN (SELECT ID FROM wp_missing)' ) );
$after_failed_inner = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$expression = $runtime->execute( new WP_Markdown_Query_Request( "DELETE FROM wp_postmeta WHERE post_id IN (SELECT CONCAT(post_type, '') AS candidate FROM wp_posts)" ) );
$after_expression = json_decode( (string) file_get_contents( $root . '/_tables/postmeta.json' ), true );
$post_subquery = $runtime->execute( new WP_Markdown_Query_Request( "DELETE FROM wp_posts WHERE ID IN (SELECT ID FROM wp_posts WHERE post_type = 'post')" ) );
$option_subquery = $runtime->execute( new WP_Markdown_Query_Request( "DELETE FROM wp_options WHERE option_name IN (SELECT ID FROM wp_posts)" ) );

$checks = array(
	'UPDATE applies to the typed IN selection only' => 1 === $update->return_value()
		&& array( 'one', 'page', 'three', null, 'five', 'six' ) === array_column( $after_update, 'meta_value' ),
	'string membership retains distinct numeric-looking values' => 4 === $string_members->return_value()
		&& array( 'member', 'member', 'member', 'member' ) === array( $after_string_members[0]['meta_value'], $after_string_members[1]['meta_value'], $after_string_members[4]['meta_value'], $after_string_members[5]['meta_value'] ),
	'grouped write subquery fails before mutation' => false === $grouped->return_value()
		&& 'unsupported_subquery_shape' === ( $grouped->diagnostic()['reason'] ?? null )
		&& $after_string_members === $after_grouped,
	'exact reset DELETE removes only matching post metadata' => array( 1, 1, 1, 1 ) === array_map( static fn( WP_Markdown_Query_Result $result ): int|bool => $result->return_value(), $posts )
		&& 2 === $delete->return_value()
		&& array( 2, 4, '5', '6' ) === array_column( $after_delete, 'post_id' )
		&& 'member' === $after_delete[0]['meta_value']
		&& null === $after_delete[1]['meta_key']
		&& null === $after_delete[1]['meta_value'],
	'self-target subquery fails before acquiring the target mutation lock' => false === $self_target->return_value()
		&& 'unsupported_subquery_shape' === ( $self_target->diagnostic()['reason'] ?? null )
		&& $after_delete === $after_self_target,
	'UNION write subqueries fail closed before a target-table branch can update rows' => false === $union_target->return_value()
		&& 'markdown_db_native_table_mutation_failed' === ( $union_target->diagnostic()['code'] ?? null )
		&& 'unsupported_subquery_shape' === ( $union_target->diagnostic()['reason'] ?? null )
		&& $after_self_target === $after_union_target,
	'JOIN write subqueries fail closed before a target-table branch can delete rows' => false === $join_target->return_value()
		&& 'markdown_db_native_table_mutation_failed' === ( $join_target->diagnostic()['code'] ?? null )
		&& 'unsupported_subquery_shape' === ( $join_target->diagnostic()['reason'] ?? null )
		&& $after_union_target === $after_join_target,
	'NULL members do not match a NULL outer value' => 3 === $null_member->return_value()
		&& array( array( 'meta_id' => 4, 'post_id' => 4, 'meta_key' => null, 'meta_value' => null ) ) === $after_null_member,
	'empty typed IN selection leaves rows unchanged' => 0 === $empty->return_value() && $after_null_member === $after_empty,
	'malformed multi-column IN fails before mutating' => false === $malformed->return_value()
		&& 'unsupported_subquery_shape' === ( $malformed->diagnostic()['reason'] ?? null )
		&& $after_empty === $after_malformed,
	'failed inner query propagates before mutation' => false === $failed_inner->return_value()
		&& 'unsupported_table' === ( $failed_inner->diagnostic()['reason'] ?? null )
		&& $after_malformed === $after_failed_inner,
	'expression projection fails closed without mutation' => false === $expression->return_value()
		&& 'unsupported_subquery_shape' === ( $expression->diagnostic()['reason'] ?? null )
		&& $after_failed_inner === $after_expression,
	'post and option writes reject IN subqueries without fatal errors' => false === $post_subquery->return_value()
		&& 'unsupported_subquery_shape' === ( $post_subquery->diagnostic()['reason'] ?? null )
		&& false === $option_subquery->return_value(),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . PHP_EOL;
	$failed = $failed || ! $passed;
}
mdi_write_subquery_remove_tree( $root );
exit( $failed ? 1 : 0 );
