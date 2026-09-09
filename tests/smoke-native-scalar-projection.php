<?php
/** Scalar SELECT projections retain aliases, metadata, and SQL NULL behavior. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

final class MDI_Scalar_Provider implements WP_Markdown_Native_Table_Provider {
	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$source = array( 'ID' => 7, 'post_title' => 'Hello', 'post_excerpt' => null, 'post_status' => 'publish', 'post_date' => '2026-09-01 12:00:00' );
		$row = array();
		foreach ( $access->projection() as $column ) { $row[ $column ] = $source[ $column ]; }
		return array( $row );
	}
}

$schema = new WP_Markdown_Native_Table_Schema(
	array(
		'ID' => new WP_Markdown_Native_Column( 8, false ),
		'post_title' => new WP_Markdown_Native_Column( 253, false ),
		'post_excerpt' => new WP_Markdown_Native_Column( 253, true ),
		'post_status' => new WP_Markdown_Native_Column( 253, false ),
		'post_date' => new WP_Markdown_Native_Column( 253, false ),
	),
	'ID',
	array( 'ID' )
);
$registry = new WP_Markdown_Native_Table_Registry();
$registry->register( 'wp_posts', $schema, new MDI_Scalar_Provider() );
$runtime = new WP_Markdown_Native_Query_Runtime( $registry );
$sql = "SELECT ID, CASE WHEN post_status <> 'draft' THEN 1 ELSE 0 END AS is_live, CONCAT(post_title, '-', ID) AS label, COALESCE(post_excerpt, post_title) AS shown, SUBSTRING(post_title, 1, 3) AS head, CAST(ID AS UNSIGNED) AS numeric_id, YEAR(post_date) AS y, MONTH(post_date) AS m, DATE_FORMAT(post_date, '%Y-%m') AS period, DATE(post_date) AS day, HOUR(post_date) AS hour, DATEDIFF(post_date, '2026-08-30') AS age, GREATEST(ID, 9) AS high, LEAST(ID, 3) AS low, IFNULL(post_excerpt, 'fallback') AS fallback, NULLIF(post_title, 'Hello') AS null_title, LOWER(post_title) AS lower_title, UPPER(post_title) AS upper_title, TRIM(' hello ') AS trimmed, LENGTH(post_title) AS bytes, REPLACE(post_title, 'ell', 'ipp') AS replaced, LEFT(post_title, 2) AS left_title, RIGHT(post_title, 2) AS right_title, LOCATE('ell', post_title) AS located, MD5(post_title) AS md5_title, SHA1(post_title) AS sha1_title, ROUND(ACOS(COS(RADIANS(60))), 4) AS angle FROM wp_posts";
$result = $runtime->execute( new WP_Markdown_Query_Request( $sql ) );
$invalid = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT YEAR(ID, 1) AS y FROM wp_posts' ) );
$plan = ( new WP_Markdown_Native_Query_Parser() )->parse( $sql );
$corpus = $result->corpus_result();
$row = $corpus['rows'][0] ?? array();
$checks = array(
	'scalar projection evaluates requested MySQL forms with aliases' => array( 'ID' => '7', 'is_live' => '1', 'label' => 'Hello-7', 'shown' => 'Hello', 'head' => 'Hel', 'numeric_id' => '7', 'y' => '2026', 'm' => '09', 'period' => '2026-09', 'day' => '2026-09-01', 'hour' => '12', 'age' => '2', 'high' => '9', 'low' => '3', 'fallback' => 'fallback', 'null_title' => null, 'lower_title' => 'hello', 'upper_title' => 'HELLO', 'trimmed' => 'hello', 'bytes' => '5', 'replaced' => 'Hippo', 'left_title' => 'He', 'right_title' => 'lo', 'located' => '2', 'md5_title' => '8b1a9953c4611296a827abf8c47804d7', 'sha1_title' => 'f7ff9e8b7bb2e09b70935a5d785e0cc5d9d0abf0', 'angle' => '1.0472' ) === $row,
	'scalar projection metadata keeps source and computed columns distinct' => array( 'ID', 'is_live', 'label', 'shown', 'head', 'numeric_id', 'y', 'm', 'period', 'day', 'hour', 'age', 'high', 'low', 'fallback', 'null_title', 'lower_title', 'upper_title', 'trimmed', 'bytes', 'replaced', 'left_title', 'right_title', 'located', 'md5_title', 'sha1_title', 'angle' ) === array_column( $corpus['columns'], 'name' ),
	'scalar plans use backend-neutral expressions and lowered CASE predicates' => $plan instanceof WP_Markdown_Native_Query_Plan
		&& $plan->scalar_projection()[0]['expression'] instanceof WP_Markdown_Native_Query_Scalar_Expression
		&& $plan->scalar_projection()[0]['expression']->branches()[0]['predicates'][0] instanceof WP_Markdown_Native_Query_Predicate,
	'unsupported scalar function shapes fail closed' => false === $invalid->return_value() && 'unsupported_grammar' === ( $invalid->diagnostic()['reason'] ?? null ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	$failed += $passed ? 0 : 1;
}
exit( $failed );
