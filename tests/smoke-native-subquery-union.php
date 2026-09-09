<?php
/** Typed bounded IN, correlated EXISTS, and UNION execution. */

declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

final class MDI_Subquery_Array_Provider implements WP_Markdown_Native_Table_Provider {
	public function __construct( private array $rows ) {}
	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		$predicate = $access->predicate();
		$rows = null === $predicate ? $this->rows : array_filter( $this->rows, static fn( array $row ): bool => in_array( $row[ $predicate->column() ] ?? null, $predicate->values(), true ) );
		if ( $access->order_descending() ) {
			$rows = array_reverse( $rows );
		}
		return array_map( static function ( array $source ) use ( $access ): array {
			$row = array();
			foreach ( $access->projection() as $column ) { $row[ $column ] = $source[ $column ]; }
			return $row;
		}, $rows );
	}
}

$integer = static fn( bool $nullable = false ): WP_Markdown_Native_Column => new WP_Markdown_Native_Column( 8, $nullable, static fn( mixed $value ): bool => is_int( $value ), static fn( mixed $value ): ?string => is_int( $value ) ? (string) $value : null, array( '=', 'IN' ) );
$text = static fn( array $lookups = array() ): WP_Markdown_Native_Column => new WP_Markdown_Native_Column( 253, false, 'is_string', null, $lookups );
$posts_schema = new WP_Markdown_Native_Table_Schema( array( 'ID' => $integer(), 'post_status' => $text(), 'created_at' => $text() ), 'ID' );
$meta_schema = new WP_Markdown_Native_Table_Schema( array( 'meta_id' => $integer(), 'post_id' => $integer( true ), 'meta_key' => $text( array( '=' ) ), 'observed_at' => $text() ), 'meta_id' );
$registry = new WP_Markdown_Native_Table_Registry();
$registry->register( 'wp_posts', $posts_schema, new MDI_Subquery_Array_Provider( array( array( 'ID' => 1, 'post_status' => 'publish', 'created_at' => '2024-01-02 03:04:05' ), array( 'ID' => 2, 'post_status' => 'draft', 'created_at' => '2024-01-02 06:07:08' ), array( 'ID' => 3, 'post_status' => 'publish', 'created_at' => '2024-01-03 09:10:11' ) ) ) );
$registry->register( 'wp_postmeta', $meta_schema, new MDI_Subquery_Array_Provider( array( array( 'meta_id' => 1, 'post_id' => 1, 'meta_key' => 'coverage_probe', 'observed_at' => '2024-01-02 03:04:05' ), array( 'meta_id' => 2, 'post_id' => null, 'meta_key' => 'coverage_probe', 'observed_at' => '2024-01-03 03:04:05' ), array( 'meta_id' => 3, 'post_id' => 3, 'meta_key' => 'other', 'observed_at' => '2024-01-02 03:04:05' ) ) ) );
$runtime = new WP_Markdown_Native_Query_Runtime( $registry );
$rows = static fn( WP_Markdown_Query_Result $result ): array => array_map( 'get_object_vars', $result->wpdb_state()['last_result'] ?? array() );
$in = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = 'coverage_probe' )" ) );
$not_in = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE ID NOT IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = 'coverage_probe' )" ) );
$exists = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts p WHERE EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID )' ) );
$scalar_in = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE DATE(created_at) = '2024-01-02' AND ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = 'coverage_probe' )" ) );
$scalar_subquery = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE ID IN ( SELECT post_id FROM wp_postmeta WHERE DATE(observed_at) = '2024-01-03' )" ) );
$nested_boolean = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts p WHERE ( DATE(created_at) = '2024-01-02' AND ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = 'coverage_probe' ) ) OR ( EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID ) AND ID NOT IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = 'other' ) )" ) );
$joined_exists = $runtime->execute( new WP_Markdown_Query_Request( "SELECT p.ID FROM wp_posts p INNER JOIN wp_postmeta j ON j.post_id = p.ID WHERE EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID AND m.meta_key = 'coverage_probe' )" ) );
$joined_alias_exists = $runtime->execute( new WP_Markdown_Query_Request( "SELECT p.ID FROM wp_posts p INNER JOIN wp_postmeta j ON j.post_id = p.ID WHERE EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.meta_id = j.meta_id AND m.meta_key = 'coverage_probe' )" ) );
$joined_in = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE ID IN ( SELECT m.post_id FROM wp_postmeta m INNER JOIN wp_posts p ON p.ID = m.post_id WHERE p.post_status = 'publish' )" ) );
$aggregate_in = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE ID IN ( SELECT MAX(post_id) AS post_id FROM wp_postmeta WHERE meta_key = 'other' )" ) );
$two_outer_keys = $runtime->execute( new WP_Markdown_Query_Request( "SELECT p.ID FROM wp_posts p INNER JOIN wp_postmeta j ON j.post_id = p.ID WHERE EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID AND ( m.meta_id = j.meta_id OR m.meta_key = 'other' ) )" ) );
$correlated_join = $runtime->execute( new WP_Markdown_Query_Request( "SELECT p.ID FROM wp_posts p INNER JOIN wp_postmeta j ON j.post_id = p.ID WHERE EXISTS ( SELECT 1 FROM wp_postmeta m INNER JOIN wp_posts q ON q.ID = m.post_id WHERE m.meta_id = j.meta_id AND q.post_status = p.post_status )" ) );
$correlated_aggregate_in = $runtime->execute( new WP_Markdown_Query_Request( "SELECT p.ID FROM wp_posts p WHERE p.ID IN ( SELECT MAX(m.post_id) AS post_id FROM wp_postmeta m WHERE m.post_id = p.ID )" ) );
$boolean_only_correlation = $runtime->execute( new WP_Markdown_Query_Request( "SELECT p.ID FROM wp_posts p WHERE EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.meta_key = 'missing' OR m.post_id = p.ID )" ) );
$union = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE post_status = 'publish' UNION SELECT ID FROM wp_posts WHERE post_status = 'draft'" ) );
$ordered_union = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE post_status = 'publish' UNION SELECT ID FROM wp_posts WHERE post_status = 'draft' ORDER BY ID DESC LIMIT 1" ) );
$ordinal_union = $runtime->execute( new WP_Markdown_Query_Request( "SELECT DATE(created_at) AS day FROM wp_posts WHERE ID = 1 UNION ALL SELECT DATE(created_at) AS day FROM wp_posts WHERE ID = 3 ORDER BY 1 DESC LIMIT 1" ) );
$chained_union = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE ID = 1 UNION ALL SELECT ID FROM wp_posts WHERE ID = 3 UNION SELECT ID FROM wp_posts WHERE ID = 2 ORDER BY ID DESC LIMIT 1, 1" ) );
$parenthesized_union = $runtime->execute( new WP_Markdown_Query_Request( '(SELECT ID FROM wp_posts ORDER BY ID DESC LIMIT 2) UNION ALL (SELECT ID FROM wp_posts ORDER BY ID LIMIT 1) ORDER BY ID LIMIT 2 OFFSET 1' ) );
$nested_parenthesized_union = $runtime->execute( new WP_Markdown_Query_Request( '((SELECT ID FROM wp_posts WHERE ID = 1) UNION ALL (SELECT ID FROM wp_posts WHERE ID = 2)) UNION ALL (SELECT ID FROM wp_posts WHERE ID = 3) ORDER BY ID' ) );
$mixed_parenthesized_union = $runtime->execute( new WP_Markdown_Query_Request( '(SELECT ID FROM wp_posts WHERE ID = 1) UNION ALL SELECT ID FROM wp_posts WHERE ID = 2 ORDER BY ID DESC LIMIT 1' ) );
$found_rows_union = $runtime->execute( new WP_Markdown_Query_Request( "SELECT SQL_CALC_FOUND_ROWS ID FROM wp_posts WHERE ID = 1 UNION ALL SELECT ID FROM wp_posts WHERE ID = 2 UNION ALL SELECT ID FROM wp_posts WHERE ID = 3 ORDER BY ID DESC LIMIT 1" ) );
$found_rows = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT FOUND_ROWS()' ) );
$invalid = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts WHERE ID IN ( SELECT post_id, meta_id FROM wp_postmeta )' ) );
$invalid_boolean = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE DATE(created_at) = '2024-01-02' AND ID IN ( SELECT post_id, meta_id FROM wp_postmeta )" ) );
$checks = array(
	'IN materializes one typed column and treats NULL non-matches as SQL unknown' => array( array( 'ID' => '1' ) ) === $rows( $in ),
	'NOT IN becomes unknown for non-matches when the subquery contains NULL' => array() === $rows( $not_in ),
	'EXISTS indexes one qualified correlation against the outer row' => array( array( 'ID' => '1' ), array( 'ID' => '3' ) ) === $rows( $exists ),
	'scalar and IN terms compose in one boolean plan' => array( array( 'ID' => '1' ) ) === $rows( $scalar_in ),
	'subquery scalar filters request and evaluate hidden source columns' => array() === $rows( $scalar_subquery ),
	'nested OR preserves EXISTS, NOT IN NULL semantics, and scalar terms' => array( array( 'ID' => '1' ) ) === $rows( $nested_boolean ),
	'correlated EXISTS retains its base outer source through a JOIN' => array( array( 'ID' => '1' ) ) === $rows( $joined_exists ),
	'correlated EXISTS can retain any joined outer alias through a JOIN' => array( array( 'ID' => '1' ) ) === $rows( $joined_alias_exists ),
	'IN executes joined subquery plans through the shared executor' => array( array( 'ID' => '1' ), array( 'ID' => '3' ) ) === $rows( $joined_in ),
	'IN executes aggregate subquery plans through the shared executor' => array( array( 'ID' => '3' ) ) === $rows( $aggregate_in ),
	'correlated EXISTS binds multiple outer aliases through boolean branches' => array( array( 'ID' => '1' ), array( 'ID' => '3' ) ) === $rows( $two_outer_keys ),
	'correlated EXISTS executes an inner JOIN through the shared plan executor' => array( array( 'ID' => '1' ), array( 'ID' => '3' ) ) === $rows( $correlated_join ),
	'correlated IN executes aggregate and scalar-projected child plans' => array( array( 'ID' => '1' ), array( 'ID' => '3' ) ) === $rows( $correlated_aggregate_in ),
	'correlated predicates in a child boolean branch retain their lexical outer binding' => array( array( 'ID' => '1' ), array( 'ID' => '3' ) ) === $rows( $boolean_only_correlation ),
	'UNION deduplicates compatible projections with first-branch metadata' => array( array( 'ID' => '1' ), array( 'ID' => '3' ), array( 'ID' => '2' ) ) === $rows( $union ) && 'wp_posts' === ( $union->wpdb_state()['col_info'][0]->table ?? null ),
	'UNION ORDER BY and LIMIT apply after all branches accumulate' => array( array( 'ID' => '3' ) ) === $rows( $ordered_union ),
	'UNION global ORDER BY accepts output ordinals and scalar aliases' => array( array( 'day' => '2024-01-03' ) ) === $rows( $ordinal_union ),
	'chained UNION operators apply one global ORDER BY, LIMIT, and offset' => array( array( 'ID' => '2' ) ) === $rows( $chained_union ),
	'parenthesized UNION branches retain local bounds before global ordering and offset' => array( array( 'ID' => '2' ), array( 'ID' => '3' ) ) === $rows( $parenthesized_union ),
	'nested parenthesized UNION expressions retain every branch' => array( array( 'ID' => '1' ), array( 'ID' => '2' ), array( 'ID' => '3' ) ) === $rows( $nested_parenthesized_union ),
	'mixed parenthesized and unparenthesized UNION operands retain global clauses' => array( array( 'ID' => '2' ) ) === $rows( $mixed_parenthesized_union ),
	'UNION SQL_CALC_FOUND_ROWS reports the combined result before its limit' => array( array( 'ID' => '3' ) ) === $rows( $found_rows_union ) && array( array( 'FOUND_ROWS()' => '3' ) ) === $rows( $found_rows ),
	'multi-column IN subqueries fail closed' => false === $invalid->return_value() && 'unsupported_subquery_shape' === ( $invalid->diagnostic()['reason'] ?? null ),
	'unsupported boolean subqueries fail closed without a parser exception' => false === $invalid_boolean->return_value() && 'unsupported_subquery_shape' === ( $invalid_boolean->diagnostic()['reason'] ?? null ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL; $failed += ! $passed; }
exit( $failed ? 1 : 0 );
