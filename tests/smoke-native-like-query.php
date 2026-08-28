<?php
/** ASCII LIKE filters over canonical Markdown posts. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-like-' . bin2hex( random_bytes( 6 ) );
$state = $root . '/state';
$content = $root . '/content';
if ( ! mkdir( $state . '/_options', 0777, true ) || ! mkdir( $content . '/post', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the LIKE fixture.' );
}

$write = static function ( string $slug, int $id, string $title, string $body ) use ( $content ): void {
	file_put_contents(
		$content . '/post/' . $slug . '.md',
		"---\nid: {$id}\ntitle: {$title}\nstatus: publish\ntype: post\nauthor: 1\ndate: 2026-08-27 12:00:00\nmodified: 2026-08-27 12:00:00\nslug: {$slug}\ncomment_status: open\nping_status: open\n---\n\n{$body}\n"
	);
};
$write( 'alpha', 11, 'Hello World', 'Alpha body' );
$write( 'beta', 12, 'Goodbye Moon', 'Beta hello there' );
$write( 'gamma', 13, 'Unrelated', 'No match here' );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $state, 'wp_', null, false, $content );

$contains = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE post_title LIKE '%Hello%'", 'wp_' ) );
$prefix = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE post_title LIKE 'Good%'", 'wp_' ) );
$ci = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE post_title LIKE '%hello%'", 'wp_' ) );
$content_like = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE post_content LIKE '%hello%'", 'wp_' ) );
$unicode = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE post_title LIKE '%Café%'", 'wp_' ) );
$integer = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE ID LIKE '1%'", 'wp_' ) );

$ids = static function ( WP_Markdown_Query_Result $result ): array {
	return array_map( static fn( object $row ): string => (string) $row->ID, $result->wpdb_state()['last_result'] );
};

$checks = array(
	'a contains-pattern matches ASCII titles' => array( '11' ) === $ids( $contains ),
	'a prefix-pattern matches ASCII titles' => array( '12' ) === $ids( $prefix ),
	'LIKE matching is ASCII case-insensitive' => array( '11' ) === $ids( $ci ),
	'LIKE can scan post_content' => array( '12' ) === $ids( $content_like ),
	'a non-ASCII LIKE pattern fails closed' => false === $unicode->return_value()
		&& 'unsupported_lookup' === ( $unicode->diagnostic()['reason'] ?? null ),
	'LIKE on an integer column fails closed' => false === $integer->return_value()
		&& 'unsupported_lookup' === ( $integer->diagnostic()['reason'] ?? null ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

array_map( 'unlink', glob( $content . '/post/*' ) ?: array() );
@rmdir( $content . '/post' );
@rmdir( $content );
array_map( 'unlink', glob( $state . '/_options/*' ) ?: array() );
@rmdir( $state . '/_options' );
@rmdir( $state );
@rmdir( $root );

exit( $failed ? 1 : 0 );
