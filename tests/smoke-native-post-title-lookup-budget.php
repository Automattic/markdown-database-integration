<?php
/** Exact title lookup source-work budget. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-title-budget-' . bin2hex( random_bytes( 6 ) );
$state = $root . '/state';
$content = $root . '/content';
if ( ! mkdir( $state . '/_options', 0777, true ) || ! mkdir( $content . '/post', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the title lookup budget fixture.' );
}

for ( $id = 1; $id <= 1025; $id++ ) {
	file_put_contents(
		$content . '/post/post-' . $id . '.md',
		"---\nid: {$id}\ntitle: Other {$id}\nstatus: publish\ntype: post\nauthor: 1\ndate: 2026-08-27 12:00:00\nmodified: 2026-08-27 12:00:00\nslug: post-{$id}\ncomment_status: open\nping_status: open\n---\n\nBody\n"
	);
}

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $state, 'wp_', null, false, $content );
$result = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE post_title = 'Absent' ORDER BY post_date_gmt DESC LIMIT 1", 'wp_' ) );
$passed = false === $result->return_value()
	&& 'title_lookup_source_budget' === ( $result->diagnostic()['reason'] ?? null );

echo ( $passed ? 'PASS' : 'FAIL' ) . ": title lookup stops after its explicit source-work budget\n";
array_map( 'unlink', glob( $content . '/post/*' ) ?: array() );
@rmdir( $content . '/post' );
@rmdir( $content );
array_map( 'unlink', glob( $state . '/_options/*' ) ?: array() );
@rmdir( $state . '/_options' );
@rmdir( $state );
@rmdir( $root );

exit( $passed ? 0 : 1 );
