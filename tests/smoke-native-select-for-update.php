<?php
/** SELECT ... FOR UPDATE is a no-op read under native single-writer semantics. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-for-update-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the FOR UPDATE fixture.' );
}
file_put_contents(
	$root . '/_options/siteurl.json',
	json_encode( array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'https://example.test', 'autoload' => 'on' ), JSON_THROW_ON_ERROR )
);

$runtime = new WP_Markdown_Native_Option_Query_Runtime( $root );
$plain_sql = "SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 1";
$locked_sql = $plain_sql . ' FOR UPDATE';
$plain = $runtime->execute( new WP_Markdown_Query_Request( $plain_sql ) );
$locked = $runtime->execute( new WP_Markdown_Query_Request( $locked_sql ) );
$parser = new WP_Markdown_Native_Query_Parser();
$trailing_sql = $locked_sql . ' NOWAIT';
$trailing = $parser->parse( $trailing_sql );

$checks = array(
	'FOR UPDATE preserves the native result count under single-writer semantics' => $plain->return_value() === $locked->return_value(),
	'FOR UPDATE preserves the native result value under single-writer semantics' => 'https://example.test' === ( $plain->wpdb_state()['last_result'][0]->option_value ?? null )
		&& 'https://example.test' === ( $locked->wpdb_state()['last_result'][0]->option_value ?? null ),
	'FOR UPDATE remains a terminal clause rather than accepting other lock grammar' => $trailing instanceof WP_Markdown_Query_Result
		&& false === $trailing->return_value()
		&& 'unsupported_grammar' === ( $trailing->diagnostic()['reason'] ?? null )
		&& strpos( $trailing_sql, 'NOWAIT' ) === ( $trailing->diagnostic()['sql_offset'] ?? null ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}
exit( $failed ? 1 : 0 );
