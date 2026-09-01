<?php
/** Typed bounded IN, correlated EXISTS, and UNION execution. */

declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

final class MDI_Subquery_Array_Provider implements WP_Markdown_Native_Table_Provider {
	public function __construct( private array $rows ) {}
	public function read( WP_Markdown_Native_Table_Access $access ): iterable|WP_Markdown_Query_Result {
		return array_map( static fn( array $source ): array => array_intersect_key( $source, array_flip( $access->projection() ) ), $this->rows );
	}
}

$integer = static fn( bool $nullable = false ): WP_Markdown_Native_Column => new WP_Markdown_Native_Column( 8, $nullable, static fn( mixed $value ): bool => is_int( $value ), static fn( mixed $value ): ?string => is_int( $value ) ? (string) $value : null, array( '=', 'IN' ) );
$text = static fn(): WP_Markdown_Native_Column => new WP_Markdown_Native_Column( 253, false, 'is_string' );
$posts_schema = new WP_Markdown_Native_Table_Schema( array( 'ID' => $integer(), 'post_status' => $text() ), 'ID' );
$meta_schema = new WP_Markdown_Native_Table_Schema( array( 'meta_id' => $integer(), 'post_id' => $integer( true ), 'meta_key' => $text() ), 'meta_id' );
$registry = new WP_Markdown_Native_Table_Registry();
$registry->register( 'wp_posts', $posts_schema, new MDI_Subquery_Array_Provider( array( array( 'ID' => 1, 'post_status' => 'publish' ), array( 'ID' => 2, 'post_status' => 'draft' ), array( 'ID' => 3, 'post_status' => 'publish' ) ) ) );
$registry->register( 'wp_postmeta', $meta_schema, new MDI_Subquery_Array_Provider( array( array( 'meta_id' => 1, 'post_id' => 1, 'meta_key' => 'coverage_probe' ), array( 'meta_id' => 2, 'post_id' => null, 'meta_key' => 'coverage_probe' ), array( 'meta_id' => 3, 'post_id' => 3, 'meta_key' => 'other' ) ) ) );
$runtime = new WP_Markdown_Native_Query_Runtime( $registry );
$rows = static fn( WP_Markdown_Query_Result $result ): array => array_map( 'get_object_vars', $result->wpdb_state()['last_result'] ?? array() );
$in = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE ID IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = 'coverage_probe' )" ) );
$not_in = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE ID NOT IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = 'coverage_probe' )" ) );
$exists = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts p WHERE EXISTS ( SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID )' ) );
$union = $runtime->execute( new WP_Markdown_Query_Request( "SELECT ID FROM wp_posts WHERE post_status = 'publish' UNION SELECT ID FROM wp_posts WHERE post_status = 'draft'" ) );
$invalid = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts WHERE ID IN ( SELECT post_id, meta_id FROM wp_postmeta )' ) );
$checks = array(
	'IN materializes one typed column and treats NULL non-matches as SQL unknown' => array( array( 'ID' => '1' ) ) === $rows( $in ),
	'NOT IN becomes unknown for non-matches when the subquery contains NULL' => array() === $rows( $not_in ),
	'EXISTS indexes one qualified correlation against the outer row' => array( array( 'ID' => '1' ), array( 'ID' => '3' ) ) === $rows( $exists ),
	'UNION deduplicates compatible projections with first-branch metadata' => array( array( 'ID' => '1' ), array( 'ID' => '3' ), array( 'ID' => '2' ) ) === $rows( $union ) && 'wp_posts' === ( $union->wpdb_state()['col_info'][0]->table ?? null ),
	'multi-column IN subqueries fail closed' => false === $invalid->return_value() && 'unsupported_subquery_shape' === ( $invalid->diagnostic()['reason'] ?? null ),
);
$failed = 0;
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL; $failed += ! $passed; }
exit( $failed ? 1 : 0 );
