<?php
/** Integer ORDER BY keys need no collation, so every schema orders them numerically. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-integer-order-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the integer order fixture.' );
}

// user_id 9 and 10 order differently as numbers and as strings, and the
// meta_key case differs so the folded comparison is exercised.
$usermeta = array(
	array( 'umeta_id' => '1', 'user_id' => '10', 'meta_key' => 'x_b', 'meta_value' => 'ten-b' ),
	array( 'umeta_id' => '2', 'user_id' => '9', 'meta_key' => 'x_a', 'meta_value' => 'nine-a' ),
	array( 'umeta_id' => '3', 'user_id' => '10', 'meta_key' => 'X_A', 'meta_value' => 'ten-a' ),
	array( 'umeta_id' => '4', 'user_id' => '2', 'meta_key' => 'x_c', 'meta_value' => 'two-c' ),
	array( 'umeta_id' => '5', 'user_id' => '9', 'meta_key' => 'other', 'meta_value' => 'skip' ),
	array( 'umeta_id' => '6', 'user_id' => '9', 'meta_key' => 'x_a', 'meta_value' => 'nine-a-again' ),
);
$commentmeta = array(
	array( 'meta_id' => '1', 'comment_id' => '30', 'meta_key' => 'k', 'meta_value' => 'thirty' ),
	array( 'meta_id' => '2', 'comment_id' => '4', 'meta_key' => 'k', 'meta_value' => 'four' ),
	array( 'meta_id' => '3', 'comment_id' => '100', 'meta_key' => 'k', 'meta_value' => 'hundred' ),
);
file_put_contents( $root . '/_tables/usermeta.json', json_encode( $usermeta, JSON_THROW_ON_ERROR ) );
file_put_contents( $root . '/_tables/commentmeta.json', json_encode( $commentmeta, JSON_THROW_ON_ERROR ) );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$run = static fn( string $query ): WP_Markdown_Query_Result => $runtime->execute( new WP_Markdown_Query_Request( $query ) );
$column = static fn( WP_Markdown_Query_Result $result, string $name ): array => array_map(
	static fn( object $row ): mixed => $row->{$name},
	$result->wpdb_state()['last_result']
);
$unsupported = static fn( WP_Markdown_Query_Result $result ): bool => false === $result->return_value()
	&& 'mdi-native cannot apply the requested ordering collation.' === $result->wpdb_state()['last_error']
	&& array() === $result->wpdb_state()['last_result'];

$issue = $run( "SELECT user_id, meta_key, meta_value FROM wp_usermeta WHERE meta_key LIKE 'x\\_%' ORDER BY user_id ASC, meta_key ASC" );
$descending = $run( "SELECT user_id, meta_key, meta_value FROM wp_usermeta WHERE meta_key LIKE 'x\\_%' ORDER BY user_id DESC, meta_key DESC" );
$mixed = $run( "SELECT meta_value FROM wp_usermeta WHERE meta_key LIKE 'x\\_%' ORDER BY user_id DESC, meta_key ASC" );
$single = $run( 'SELECT user_id FROM wp_usermeta ORDER BY user_id ASC' );
$single_descending = $run( 'SELECT meta_value FROM wp_usermeta ORDER BY user_id DESC' );
$generated = $run( 'SELECT meta_value FROM wp_commentmeta ORDER BY comment_id DESC' );
$string_leading = $run( 'SELECT meta_value FROM wp_usermeta ORDER BY meta_key ASC' );
$string_leading_then_integer = $run( 'SELECT meta_value FROM wp_usermeta ORDER BY meta_key ASC, user_id ASC' );
$uncompared_string = $run( 'SELECT meta_value FROM wp_usermeta ORDER BY user_id ASC, meta_value ASC' );

$usermeta[] = array( 'umeta_id' => '7', 'user_id' => '9', 'meta_key' => "x_\xC3\xA9", 'meta_value' => 'accented' );
file_put_contents( $root . '/_tables/usermeta.json', json_encode( $usermeta, JSON_THROW_ON_ERROR ) );
$non_ascii = WP_Markdown_Native_Runtime_Factory::runtime( $root )->execute(
	new WP_Markdown_Query_Request( 'SELECT meta_value FROM wp_usermeta ORDER BY user_id ASC, meta_key ASC' )
);

$checks = array(
	'the issue query returns its rows' => 5 === $issue->return_value(),
	'the issue query orders by user_id numerically, then meta_key' => array( '2', '9', '9', '10', '10' ) === $column( $issue, 'user_id' )
		&& array( 'two-c', 'nine-a', 'nine-a-again', 'ten-a', 'ten-b' ) === $column( $issue, 'meta_value' ),
	'DESC reverses both terms and keeps ties in read order' => 5 === $descending->return_value()
		&& array( '10', '10', '9', '9', '2' ) === $column( $descending, 'user_id' )
		&& array( 'ten-b', 'ten-a', 'nine-a', 'nine-a-again', 'two-c' ) === $column( $descending, 'meta_value' ),
	'each term keeps its own direction' => array( 'ten-a', 'ten-b', 'nine-a', 'nine-a-again', 'two-c' ) === $column( $mixed, 'meta_value' ),
	'a lone integer term orders numerically' => array( '2', '9', '9', '9', '10', '10' ) === $column( $single, 'user_id' ),
	'a lone descending integer term keeps ties in read order' => array( 'ten-b', 'ten-a', 'nine-a', 'skip', 'nine-a-again', 'two-c' ) === $column( $single_descending, 'meta_value' ),
	'a generated core table orders its integer columns numerically' => array( 'hundred', 'thirty', 'four' ) === $column( $generated, 'meta_value' ),
	'a leading string term still fails closed' => $unsupported( $string_leading ) && $unsupported( $string_leading_then_integer ),
	'a later string term without an exact comparison still fails closed' => $unsupported( $uncompared_string ),
	'a later string term with a non-ASCII value still fails closed' => $unsupported( $non_ascii ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

foreach ( array( 'usermeta', 'commentmeta' ) as $table ) {
	@unlink( $root . '/_tables/' . $table . '.json' );
}
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
