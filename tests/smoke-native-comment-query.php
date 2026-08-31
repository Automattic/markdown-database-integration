<?php
/** Native wp_comments reads over the canonical JSON snapshot. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-shadow-verifier.php';

final class MDI_Comment_Shadow_DB {
	public string $prefix = 'wp_';
	public array $last_result = array();
	public string $last_error = '';
	public int $last_errno = 0;
	public int $insert_id = 0;
	public int $rows_affected = 0;
	public int $num_rows = 0;
	protected array $col_info = array();

	public function result( array $rows, array $columns ): void {
		$this->last_result = array_map( static fn( array $row ): object => (object) $row, $rows );
		$this->col_info = array_map( static fn( array $column ): object => (object) $column, $columns );
		$this->num_rows = count( $rows );
	}

	public function get_col_info( string $field ): array {
		return array_map( static fn( object $column ): mixed => $column->{$field} ?? null, $this->col_info );
	}
}

function mdi_comment_row( string $id, string $post_id, string $email, string $content, string $parent = '0' ): array {
	return array(
		'comment_ID'           => $id,
		'comment_post_ID'      => $post_id,
		'comment_author'       => 'Commenter ' . $id,
		'comment_author_email' => $email,
		'comment_author_url'   => '',
		'comment_author_IP'    => '127.0.0.1',
		'comment_date'         => '2026-08-24 12:00:0' . $id,
		'comment_date_gmt'     => '2026-08-24 12:00:0' . $id,
		'comment_content'      => $content,
		'comment_karma'        => '0',
		'comment_approved'     => '1',
		'comment_agent'        => 'native-test',
		'comment_type'         => 'comment',
		'comment_parent'       => $parent,
		'user_id'              => '0',
	);
}

$root = sys_get_temp_dir() . '/mdi-native-comments-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create native comment fixtures.' );
}
$comments = array(
	mdi_comment_row( '1', '123', 'private@example.test', 'First' ),
	mdi_comment_row( '2', '124', 'private@example.test', 'Second' ),
	mdi_comment_row( '3', '123', 'other@example.test', 'Reply', '1' ),
);
$path = $root . '/_tables/comments.json';
file_put_contents( $path, json_encode( $comments, JSON_THROW_ON_ERROR ) );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$blocker_sql = "SELECT comment_ID FROM wp_comments WHERE comment_author_email = 'private@example.test' AND comment_post_ID = 123";
$blocker = $runtime->execute( new WP_Markdown_Query_Request( $blocker_sql ) );
$case_insensitive = $runtime->execute( new WP_Markdown_Query_Request( "SELECT comment_ID FROM wp_comments WHERE comment_author_email = 'PRIVATE@EXAMPLE.TEST' AND comment_post_ID = 123" ) );
$exact = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT * FROM wp_comments WHERE comment_ID = 2 LIMIT 1' ) );
$parent = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT comment_ID, comment_content FROM wp_comments WHERE comment_parent IN (1) ORDER BY comment_ID ASC LIMIT 1' ) );
$site_runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_2_', 'wp_', true );
$site_comment = $site_runtime->execute( new WP_Markdown_Query_Request( 'SELECT comment_ID FROM wp_2_comments WHERE comment_ID = 1', 'wp_2_' ) );

$shadow_db = new MDI_Comment_Shadow_DB();
$shadow_db->result( array( array( 'comment_ID' => '1' ) ), array( array( 'name' => 'comment_ID', 'type' => 8 ) ) );
$shadow = new WP_Markdown_Native_Shadow_Verifier( $runtime );
$shadow->observe( $blocker_sql, 1, $shadow_db );
$report = $shadow->report();

$checks = array(
	'conjunctive comment blocker executes through pushdown and residual filtering' => 1 === $blocker->return_value()
		&& '1' === ( $blocker->wpdb_state()['last_result'][0]->comment_ID ?? null ),
	'comment email lookup uses ASCII case-insensitive normalization' => '1' === ( $case_insensitive->wpdb_state()['last_result'][0]->comment_ID ?? null ),
	'exact comments expose the complete WordPress row and metadata shape' => 15 === count( get_object_vars( $exact->wpdb_state()['last_result'][0] ?? (object) array() ) )
		&& array( 8, 8, 252, 253 ) === array_slice( array_map( static fn( object $column ): int => $column->type, $exact->wpdb_state()['col_info'] ), 0, 4 ),
	'parent lookup, ordering, projection, and LIMIT remain provider-neutral' => array( 'comment_ID' => '3', 'comment_content' => 'Reply' ) === get_object_vars( $parent->wpdb_state()['last_result'][0] ?? (object) array() ),
	'comments use the active multisite table prefix' => 1 === $site_comment->return_value(),
	'shadow verifier compares the retained comment blocker exactly' => 1 === ( $report['counts']['compatible'] ?? 0 )
		&& null === ( $report['first_blocker'] ?? null ),
);

$invalid = $comments[0];
$invalid['comment_author_email'] = str_repeat( 'x', 101 );
file_put_contents( $path, json_encode( array( $comments[0], $invalid ), JSON_THROW_ON_ERROR ) );
$malformed = WP_Markdown_Native_Runtime_Factory::runtime( $root )->execute( new WP_Markdown_Query_Request( 'SELECT comment_ID FROM wp_comments' ) );
@unlink( $path );
$absent = WP_Markdown_Native_Runtime_Factory::runtime( $root )->execute( new WP_Markdown_Query_Request( 'SELECT comment_ID FROM wp_comments' ) );
$outside = dirname( $root ) . '/mdi-native-comments-outside-' . bin2hex( random_bytes( 4 ) ) . '.json';
file_put_contents( $outside, json_encode( $comments, JSON_THROW_ON_ERROR ) );
$linked = function_exists( 'symlink' ) && @symlink( $outside, $path );
$unsafe = $linked ? WP_Markdown_Native_Runtime_Factory::runtime( $root )->execute( new WP_Markdown_Query_Request( 'SELECT comment_ID FROM wp_comments' ) ) : null;

$checks['malformed snapshots fail without partial rows and absent snapshots are empty'] = false === $malformed->return_value()
	&& array() === $malformed->wpdb_state()['last_result']
	&& 0 === $absent->return_value();
$checks['linked comment snapshots fail closed'] = ! $linked || ( false === $unsafe->return_value()
	&& 'markdown_db_native_unsafe_path' === ( $unsafe->diagnostic()['code'] ?? null ) );

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

@unlink( $path );
@unlink( $outside );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
