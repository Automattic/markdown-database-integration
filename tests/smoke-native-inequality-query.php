<?php
/** Inequality filters, which WordPress admin list tables depend on. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

class wpdb {
	public string $prefix = '';
	public string $options = '';
	public bool $ready = false;
	public int $num_queries = 0;
	public int $num_rows = 0;
	public int $rows_affected = 0;
	public int $insert_id = 0;
	public string $last_error = '';
	public ?string $last_query = null;
	public string $func_call = '';
	public array $last_result = array();
	protected array $col_info = array();
	protected bool $check_current_query = true;
	protected bool $result = false;

	public function set_prefix( string $prefix ): string {
		$this->prefix  = $prefix;
		$this->options = $prefix . 'options';
		return $prefix;
	}

	public function flush(): void {
		$this->last_result = array();
		$this->col_info    = array();
		$this->last_error  = '';
		$this->num_rows    = 0;
	}

	public function add_placeholder_escape( string $value ): string {
		return $value;
	}

	public function remove_placeholder_escape( string $value ): string {
		return $value;
	}
}
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-wpdb.php';

$root = sys_get_temp_dir() . '/mdi-native-neq-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) || ! mkdir( $root . '/page', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the inequality fixture.' );
}

/** Write one canonical Markdown page. */
function write_status_post( string $root, int $id, string $slug, string $status ): void {
	$date = '2026-08-0' . max( 1, $id % 9 ) . ' 00:00:00';
	$document = <<<MARKDOWN
	---
	type: document
	title: {$slug}
	description: ""
	resource: "http://localhost/{$slug}/"
	tags:
	timestamp: "2026-08-01T00:00:00+00:00"
	wordpress:
	  id: {$id}
	  status: {$status}
	  type: page
	  author: 1
	  date: "{$date}"
	  date_gmt: "{$date}"
	  modified: "{$date}"
	  modified_gmt: "{$date}"
	  slug: {$slug}
	  parent: 0
	  menu_order: 0
	  comment_status: closed
	  ping_status: closed
	  guid: "http://localhost/{$slug}/"
	---

	Body
	MARKDOWN;
	file_put_contents( $root . '/page/' . $slug . '.md', preg_replace( '/^\t/m', '', $document ) . "\n" );
}

write_status_post( $root, 21, 'published', 'publish' );
write_status_post( $root, 22, 'drafted', 'draft' );
write_status_post( $root, 23, 'trashed', 'trash' );
write_status_post( $root, 24, 'auto', 'auto-draft' );

file_put_contents(
	$root . '/_tables/usermeta.json',
	json_encode(
		array(
			array( 'umeta_id' => '1', 'user_id' => '1', 'meta_key' => 'nickname', 'meta_value' => 'admin' ),
			array( 'umeta_id' => '2', 'user_id' => '1', 'meta_key' => null, 'meta_value' => 'orphan' ),
		),
		JSON_UNESCAPED_SLASHES
	)
);

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_', null, false, $root );

/** @return array<string,mixed> */
function ids( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): array {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
	$rows = $result->wpdb_state()['last_result'];
	$extracted = array_map( static fn( object $row ): string => (string) ( $row->ID ?? $row->umeta_id ?? '' ), $rows );
	sort( $extracted );
	return array(
		'return' => $result->return_value(),
		'reason' => $result->diagnostic()['reason'] ?? null,
		'ids'    => $extracted,
	);
}

$admin = ids(
	$runtime,
	"SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts WHERE 1=1 AND wp_posts.post_type = 'page' AND ((wp_posts.post_status <> 'trash' AND wp_posts.post_status <> 'auto-draft')) ORDER BY wp_posts.post_date DESC LIMIT 0, 20"
);
$bang = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_type = 'page' AND post_status != 'trash' ORDER BY post_date DESC" );
$nulls = ids( $runtime, "SELECT umeta_id FROM wp_usermeta WHERE meta_key <> 'nickname' AND user_id = 1 ORDER BY umeta_id ASC" );
$after = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_date > '2026-08-04 00:00:00'" );
$on_or_after = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_date >= '2026-08-04 00:00:00'" );
$between = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_date BETWEEN '2026-08-04 00:00:00' AND '2026-08-05 00:00:00'" );
$textual_range = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_status > 'draft'" );
$admin_or = ids(
	$runtime,
	"SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts WHERE 1=1 AND ((wp_posts.post_type = 'page' AND (wp_posts.post_status = 'publish' OR wp_posts.post_status = 'future' OR wp_posts.post_status = 'draft' OR wp_posts.post_status = 'pending' OR wp_posts.post_status = 'private'))) ORDER BY wp_posts.post_date DESC LIMIT 0, 20"
);
$cross_or = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_type = 'page' OR post_status = 'publish'" );
// The shape WordPress uses to show public posts plus this author's private ones.
$owner_or = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_type = 'page' AND ((post_status = 'publish') OR (post_author = 1 AND post_status = 'draft'))" );
$other_owner_or = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_type = 'page' AND ((post_status = 'publish') OR (post_author = 2 AND post_status = 'draft'))" );
$not_in = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_type = 'page' AND post_status NOT IN ('trash', 'auto-draft') ORDER BY post_date DESC" );

$checks = array(
	'admin list inequality excludes trash and auto-draft' => array( '21', '22' ) === $admin['ids']
		&& null === $admin['reason'],
	'bang-equals excludes the compared status' => array( '21', '22', '24' ) === $bang['ids'],
	'NULL never satisfies inequality' => array() === $nulls['ids']
		&& null === $nulls['reason'],
	'a date range excludes its own bound' => array( '23', '24' ) === $after['ids']
		&& null === $after['reason'],
	'an inclusive date range keeps its own bound' => array( '22', '23', '24' ) === $on_or_after['ids']
		&& null === $on_or_after['reason'],
	'BETWEEN bounds inclusively at both ends' => array( '22', '23' ) === $between['ids']
		&& null === $between['reason'],
	'a textual range stays fail-closed without a declared collation' => false === $textual_range['return'],
	'an OR alternative may be a conjunction' => array( '21', '22' ) === $owner_or['ids']
		&& null === $owner_or['reason'],
	'every branch of that conjunction still restricts' => array( '21' ) === $other_owner_or['ids']
		&& null === $other_owner_or['reason'],
	'admin status OR returns the visible statuses' => array( '21', '22' ) === $admin_or['ids']
		&& null === $admin_or['reason'],
	'cross-column equality OR returns either matching predicate' => array( '21', '22', '23', '24' ) === $cross_or['ids']
		&& null === $cross_or['reason'],
	'NOT IN excludes the listed statuses' => array( '21', '22' ) === $not_in['ids']
		&& null === $not_in['reason'],
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $root . '/page/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() );
array_map( 'unlink', glob( $root . '/_options/*' ) ?: array() );
@rmdir( $root . '/page' );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );

exit( $failed ? 1 : 0 );
