<?php
/** Post type restricted reads return the same rows as an unrestricted read. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-query-runtime.php';

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
require_once __DIR__ . '/../inc/class-wp-markdown-native-wpdb.php';

$root = sys_get_temp_dir() . '/mdi-native-scope-' . bin2hex( random_bytes( 6 ) );
foreach ( array( '_options', '_tables', 'page', 'post', 'wiki' ) as $directory ) {
	if ( ! mkdir( $root . '/' . $directory, 0777, true ) ) {
		throw new RuntimeException( 'Failed to create the post type scope fixture.' );
	}
}

/** Write one canonical Markdown post. */
function write_scope_post( string $root, int $id, string $slug, string $title, string $type ): void {
	$date = '2026-08-0' . max( 1, $id % 9 ) . ' 00:00:00';
	$document = <<<MARKDOWN
	---
	type: document
	title: {$title}
	description: ""
	resource: "http://localhost/{$slug}/"
	tags:
	timestamp: "2026-08-01T00:00:00+00:00"
	wordpress:
	  id: {$id}
	  status: publish
	  type: {$type}
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

	Body for {$slug}
	MARKDOWN;
	file_put_contents( $root . '/' . $type . '/' . $slug . '.md', preg_replace( '/^\t/m', '', $document ) . "\n" );
}

write_scope_post( $root, 21, 'home', 'Home', 'page' );
write_scope_post( $root, 22, 'about', 'About', 'page' );
write_scope_post( $root, 23, 'hello', 'Hello', 'post' );
write_scope_post( $root, 24, 'engine', 'Engine', 'wiki' );
write_scope_post( $root, 25, 'storage', 'Storage', 'wiki' );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_', null, false, $root );

/** @return array<int,string> */
function ids( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): array {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
	$rows = $result->wpdb_state()['last_result'];
	$ids = array_map( static fn( object $row ): string => (string) ( $row->ID ?? '' ), $rows );
	sort( $ids, SORT_STRING );
	return $ids;
}

$all = ids( $runtime, 'SELECT ID FROM wp_posts' );
$pages = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_type = 'page'" );
$wiki = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_type = 'wiki'" );
$either = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_type IN ('page', 'post')" );
$absent_type = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_type = 'absent'" );
$by_id = ids( $runtime, 'SELECT ID FROM wp_posts WHERE ID = 24' );
$by_slug = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_name = 'storage'" );
$slug_and_type = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_name = 'about' AND post_type = 'page'" );
$wrong_type = ids( $runtime, "SELECT ID FROM wp_posts WHERE post_name = 'about' AND post_type = 'wiki'" );

$checks = array(
	'an unrestricted read sees every post type' => array( '21', '22', '23', '24', '25' ) === $all,
	'a restricted read returns only its type' => array( '21', '22' ) === $pages,
	'a second restricted read returns only its type' => array( '24', '25' ) === $wiki,
	'a multi-type restriction returns every member' => array( '21', '22', '23' ) === $either,
	'an unknown post type returns no rows' => array() === $absent_type,
	'an identifier lookup still reaches every type' => array( '24' ) === $by_id,
	'a slug lookup still reaches every type' => array( '25' ) === $by_slug,
	'a slug combined with its type resolves' => array( '22' ) === $slug_and_type,
	'a slug under the wrong type resolves to nothing' => array() === $wrong_type,
);

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
