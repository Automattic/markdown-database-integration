<?php
/** Ordered reads for admin list tables, with an ASCII collation guard. */

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

$root = sys_get_temp_dir() . '/mdi-native-order-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) || ! mkdir( $root . '/page', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the order fixture.' );
}

/** Write one canonical Markdown page. */
function write_ordered_page( string $root, int $id, string $slug, string $title, int $menu_order ): void {
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
	  type: page
	  author: 1
	  date: "{$date}"
	  date_gmt: "{$date}"
	  modified: "{$date}"
	  modified_gmt: "{$date}"
	  slug: {$slug}
	  parent: 0
	  menu_order: {$menu_order}
	  comment_status: closed
	  ping_status: closed
	  guid: "http://localhost/{$slug}/"
	---

	Body
	MARKDOWN;
	file_put_contents( $root . '/page/' . $slug . '.md', preg_replace( '/^\t/m', '', $document ) . "\n" );
}

write_ordered_page( $root, 31, 'zeta', 'Zeta', 0 );
write_ordered_page( $root, 32, 'alpha', 'alpha', 0 );
write_ordered_page( $root, 33, 'mid', 'Mid', 5 );

file_put_contents(
	$root . '/_tables/users.json',
	json_encode(
		array(
			array(
				'ID' => '2',
				'user_login' => 'bob',
				'user_pass' => 'x',
				'user_nicename' => 'bob',
				'user_email' => 'bob@example.com',
				'user_url' => '',
				'user_registered' => '2026-01-02 00:00:00',
				'user_activation_key' => '',
				'user_status' => '0',
				'display_name' => 'Bob',
			),
			array(
				'ID' => '1',
				'user_login' => 'Admin',
				'user_pass' => 'x',
				'user_nicename' => 'admin',
				'user_email' => 'admin@example.com',
				'user_url' => '',
				'user_registered' => '2026-01-01 00:00:00',
				'user_activation_key' => '',
				'user_status' => '0',
				'display_name' => 'Admin',
			),
		),
		JSON_UNESCAPED_SLASHES
	)
);

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_', null, false, $root );

/** @return array<string,mixed> */
function column_values( WP_Markdown_Native_Query_Runtime $runtime, string $sql, string $column ): array {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
	$rows = $result->wpdb_state()['last_result'];
	return array(
		'return' => $result->return_value(),
		'reason' => $result->diagnostic()['reason'] ?? null,
		'values' => array_map( static fn( object $row ): string => (string) ( $row->{$column} ?? '' ), $rows ),
	);
}

$users = column_values( $runtime, 'SELECT ID, user_login FROM wp_users WHERE 1=1 ORDER BY user_login ASC LIMIT 0, 20', 'user_login' );
$pages = column_values( $runtime, 'SELECT ID, post_title FROM wp_posts WHERE post_type = \'page\' ORDER BY menu_order ASC, post_title ASC', 'post_title' );
$titles = column_values( $runtime, 'SELECT post_title FROM wp_posts WHERE post_type = \'page\' ORDER BY post_title ASC', 'post_title' );

write_ordered_page( $root, 34, 'cafe', 'Café', 0 );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_', null, false, $root );
$unicode = column_values( $runtime, 'SELECT post_title FROM wp_posts WHERE post_type = \'page\' ORDER BY post_title ASC', 'post_title' );

$checks = array(
	'users list by login is ASCII case-insensitive' => array( 'Admin', 'bob' ) === $users['values']
		&& null === $users['reason'],
	'pages list uses menu_order then title' => array( 'alpha', 'Zeta', 'Mid' ) === $pages['values']
		&& null === $pages['reason'],
	'title order is ASCII case-insensitive' => array( 'alpha', 'Mid', 'Zeta' ) === $titles['values'],
	'a non-ASCII title fails closed' => false === $unicode['return']
		&& 'unsupported_order' === $unicode['reason'],
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
