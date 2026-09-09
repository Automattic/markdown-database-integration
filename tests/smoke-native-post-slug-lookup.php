<?php
/** Permalink slug resolution over canonical Markdown posts. */

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

$root = sys_get_temp_dir() . '/mdi-native-slug-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) || ! mkdir( $root . '/page', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the slug lookup fixture.' );
}

/** Write one canonical Markdown post. */
function write_post( string $root, int $id, string $slug, string $title, string $type = 'page', string $status = 'publish', string $body = '', ?string $filename = null ): void {
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
	  status: {$status}
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

	{$body}
	MARKDOWN;
	file_put_contents( $root . '/' . $type . '/' . ( $filename ?? $slug ) . '.md', preg_replace( '/^\t/m', '', $document ) . "\n" );
}

write_post( $root, 11, 'index', 'Index' );
write_post( $root, 12, 'about-us', 'About Us' );
write_post( $root, 13, 'Contact', 'Contact' );
mkdir( $root . '/post', 0777, true );
write_post( $root, 14, 'index', 'Older trashed collision', 'post', 'trash', 'wrong body' );
write_post( $root, 15, 'index', 'Newer trashed collision', 'page', 'trash', 'needle body', 'newer-collision' );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_', null, false, $root );

/** @return array<string,mixed> */
function lookup( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): array {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
	$rows = $result->wpdb_state()['last_result'];
	return array(
		'return' => $result->return_value(),
		'reason' => $result->diagnostic()['reason'] ?? null,
		'ids'    => array_map( static fn( object $row ): string => (string) ( $row->ID ?? '' ), $rows ),
	);
}

$exact = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_name = 'index'" );
$hyphenated = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_name = 'about-us'" );
$case_insensitive = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_name = 'contact'" );
$set = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_name IN ('index', 'about-us')" );
$missing = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_name = 'absent'" );
$non_ascii = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_name = 'ind\u{00e9}x'" );
$scoped = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_name = 'index' AND post_type = 'page'" );
$collision_sql = "SELECT wp_posts.* FROM wp_posts WHERE 1=1 AND post_name='index' AND ID NOT IN(0) AND post_type IN ('post', 'page') AND ((post_status='trash')) ORDER BY post_date DESC LIMIT 1";
$strict_collision_sql = "SELECT post_name FROM wp_posts WHERE post_name = 'index' AND post_type = 'page' AND ID != 999 LIMIT 1";
WP_Markdown_Operation_Profile::start();
$collision = lookup( $runtime, $collision_sql );
$collision_profile = WP_Markdown_Operation_Profile::stop();
$body_residual = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_name = 'index' AND post_type IN ('post', 'page') AND post_status = 'trash' AND post_content LIKE '%needle%' ORDER BY post_date DESC LIMIT 1" );
$cached_trash = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_type IN ('post', 'page') AND post_status = 'trash' ORDER BY post_date DESC LIMIT 10" );
$different_filter = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_type IN ('post', 'page') AND post_status = 'publish' ORDER BY post_date DESC LIMIT 10" );
$title_order = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_type = 'page' ORDER BY post_title ASC" );
$unsupported_order = lookup( $runtime, "SELECT ID FROM wp_posts WHERE post_type = 'page' ORDER BY guid ASC" );

// A matching first file cannot hide a later unsafe or malformed canonical file.
symlink( $root . '/page/index.md', $root . '/page/unsafe.md' );
$unsafe = lookup( $runtime, $strict_collision_sql );
unlink( $root . '/page/unsafe.md' );
file_put_contents( $root . '/page/malformed.md', "---\ntitle: Missing identity\n---\n" );
$malformed = lookup( $runtime, $strict_collision_sql );

$checks = array(
	'a slug resolves to every matching post' => array( '11', '14', '15' ) === $exact['ids'],
	'a hyphenated slug resolves' => array( '12' ) === $hyphenated['ids'],
	'slug matching is ASCII case-insensitive' => array( '13' ) === $case_insensitive['ids'],
	'a slug set resolves every member' => array( '11', '12', '14', '15' ) === $set['ids'],
	'an absent slug returns no rows without failing' => array() === $missing['ids']
		&& null === $missing['reason'],
	'a non-ASCII slug fails closed' => false === $non_ascii['return']
		&& 'unsupported_lookup' === $non_ascii['reason'],
	'a slug combines with a post type restriction' => array( '11', '15' ) === $scoped['ids'],
	'a WordPress slug collision lookup orders filtered candidates before applying its limit' => array( '15' ) === $collision['ids']
		&& 1 === ( $collision_profile['manifest_scans'] ?? 0 )
		&& 5 === ( $collision_profile['manifest_files'] ?? 0 ),
	'a mixed metadata and body filter hydrates before the executor applies the limit' => array( '15' ) === $body_residual['ids'],
	'a scoped full-corpus cache remains available to a later differing filter' => array( '15', '14' ) === $cached_trash['ids']
		&& array( '13', '12', '11' ) === $different_filter['ids'],
	'an unsafe external symlink still fails a slug collision lookup' => false === $unsafe['return']
		&& 'unsafe_post_storage' === $unsafe['reason'],
	'a malformed external file still fails a slug collision lookup' => false === $malformed['return']
		&& 'invalid_post' === $malformed['reason'],
	'title ordering resolves ASCII titles' => array( '12', '13', '11', '15' ) === $title_order['ids'],
	'undeclared guid ordering still fails closed' => false === $unsupported_order['return']
		&& 'unsupported_order' === $unsupported_order['reason'],
);

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
