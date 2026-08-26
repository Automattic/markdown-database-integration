<?php
/** Pure-PHP SQL tokenization, typed SELECT AST, and plan lowering. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-query-runtime.php';

$tokenizer = new WP_Markdown_Native_SQL_Tokenizer();
$token_sql = "SELECT `option_name`, option_value FROM wp_options WHERE option_name = 'tab\\tback\\\\slash' LIMIT 1";
$tokens    = $tokenizer->tokenize( $token_sql );
$parser    = new WP_Markdown_Native_Query_Parser( $tokenizer );

$ast_sql = "\nseLEct `option_name`, option_value FROM `wp_options` WHERE option_name IN ('siteurl', 'tab\\tback\\\\slash', 'siteurl') AND autoload = 'on' ORDER BY option_id aSc LIMIT 2\t";
$ast     = $parser->parse_ast( $ast_sql );
$plan    = $ast instanceof WP_Markdown_Native_SQL_Select ? $parser->lower( $ast ) : $ast;

$star = $parser->parse( 'SELECT * FROM wp_options' );
$zero = $parser->parse( 'SELECT option_value FROM wp_options WHERE option_id = 000 LIMIT 000' );
$count_ast = $parser->parse_ast( 'SELECT count(*) FROM wp_rows WHERE row_id = 1 LIMIT 1' );
$count = $count_ast instanceof WP_Markdown_Native_SQL_Select ? $parser->lower( $count_ast ) : $count_ast;
$found_rows_ast = $parser->parse_ast( 'SELECT SQL_CALC_FOUND_ROWS row_id FROM wp_rows LIMIT 1' );
$found_rows_plan = $found_rows_ast instanceof WP_Markdown_Native_SQL_Select ? $parser->lower( $found_rows_ast ) : $found_rows_ast;
$qualified_star_ast = $parser->parse_ast( 'SELECT wp_rows.* FROM wp_rows WHERE wp_rows.row_id = 1' );
$qualified_star_plan = $qualified_star_ast instanceof WP_Markdown_Native_SQL_Select ? $parser->lower( $qualified_star_ast ) : $qualified_star_ast;
$wrong_qualifier_sql = 'SELECT other.* FROM wp_rows';
$wrong_qualifier = $parser->parse( $wrong_qualifier_sql );
$wordpress_posts_ast = $parser->parse_ast( "SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts WHERE 1=1 AND ((wp_posts.post_type = 'post' AND (wp_posts.post_status = 'publish'))) ORDER BY wp_posts.post_date DESC LIMIT 0, 10" );
$wordpress_posts_plan = $wordpress_posts_ast instanceof WP_Markdown_Native_SQL_Select ? $parser->lower( $wordpress_posts_ast ) : $wordpress_posts_ast;
$found_rows_query_ast = $parser->parse_ast( 'SELECT FOUND_ROWS()' );
$found_rows_query_plan = $found_rows_query_ast instanceof WP_Markdown_Native_SQL_Found_Rows ? $parser->lower( $found_rows_query_ast ) : $found_rows_query_ast;

$duplicate_sql = 'SELECT first, second, first FROM example';
$duplicate     = $parser->parse( $duplicate_sql );
$scalar_sql    = 'SELECT value FROM example WHERE id = 999999999999999999999999999999';
$scalar        = $parser->parse( $scalar_sql );
$limit_sql     = 'SELECT value FROM example LIMIT 999999999999999999999999999999';
$limit         = $parser->parse( $limit_sql );
$escape_sql    = "SELECT value FROM example WHERE id = 'bad\\q'";
$escape        = $parser->parse( $escape_sql );
$trailing_sql  = 'SELECT value FROM example ORDER BY id ASC DESC';
$trailing      = $parser->parse( $trailing_sql );
$unterminated_sql = "SELECT value FROM example WHERE id = 'open";
$unterminated     = $parser->parse( $unterminated_sql );
$malformed_and_sql = 'SELECT value FROM example WHERE id = 1 AND ORDER BY id ASC';
$malformed_and     = $parser->parse( $malformed_and_sql );
$count_column_sql = 'SELECT COUNT(row_id) FROM wp_rows';
$count_column     = $parser->parse( $count_column_sql );
$mixed_count_sql  = 'SELECT COUNT(*), row_id FROM wp_rows';
$mixed_count      = $parser->parse( $mixed_count_sql );
$aliased_count_sql = 'SELECT COUNT(*) AS total FROM wp_rows';
$aliased_count     = $parser->parse( $aliased_count_sql );
$grouped_count_sql = 'SELECT COUNT(*) FROM wp_rows GROUP BY row_id';
$grouped_count     = $parser->parse( $grouped_count_sql );
$distinct_count_sql = 'SELECT COUNT(DISTINCT row_id) FROM wp_rows';
$distinct_count     = $parser->parse( $distinct_count_sql );
$unsupported_function_sql = 'SELECT SUM(*) FROM wp_rows';
$unsupported_function     = $parser->parse( $unsupported_function_sql );

$checks = array(
	'tokenizer emits typed tokens with exact source offsets and decoded values' => array(
		WP_Markdown_Native_SQL_Token::KEYWORD,
		WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER,
		WP_Markdown_Native_SQL_Token::COMMA,
		WP_Markdown_Native_SQL_Token::WORD,
	) === array_map( static fn( WP_Markdown_Native_SQL_Token $token ): string => $token->type(), array_slice( $tokens, 0, 4 ) )
		&& 7 === $tokens[1]->sql_offset()
		&& "tab\tback\\slash" === $tokens[9]->value()
		&& WP_Markdown_Native_SQL_Token::END === $tokens[12]->type()
		&& strlen( $token_sql ) === $tokens[12]->sql_offset(),
	'parser produces a typed AST independent from execution and storage' => $ast instanceof WP_Markdown_Native_SQL_Select
		&& ! $ast->selects_all()
		&& array( 'option_name', 'option_value' ) === array_map( static fn( WP_Markdown_Native_SQL_Identifier $column ): string => $column->name(), $ast->projection() )
		&& 'wp_options' === $ast->table()->name()
		&& 'IN' === $ast->predicate()?->operator()
		&& "tab\tback\\slash" === $ast->predicate()?->values()[1]->value()
		&& 2 === count( $ast->predicates() )
		&& 'autoload' === $ast->predicates()[1]->column()->name()
		&& 'option_id' === $ast->order()?->name()
		&& 2 === $ast->limit(),
	'AST lowering preserves the existing bounded query-plan contract' => $plan instanceof WP_Markdown_Native_Query_Plan
		&& array( 'option_name', 'option_value' ) === $plan->projection()
		&& array( 'siteurl', "tab\tback\\slash" ) === $plan->predicate()?->values()
		&& 2 === count( $plan->predicates() )
		&& array( 'on' ) === $plan->predicates()[1]->values()
		&& 'option_id' === $plan->order()
		&& 2 === $plan->limit(),
	'legacy star, integer normalization, and zero LIMIT behavior are preserved' => $star instanceof WP_Markdown_Native_Query_Plan
		&& array( '*' ) === $star->projection()
		&& PHP_INT_MAX === $star->limit()
		&& $zero instanceof WP_Markdown_Native_Query_Plan
		&& array( 0 ) === $zero->predicate()?->values()
		&& 0 === $zero->limit(),
	'COUNT(*) has explicit typed AST and plan intent' => $count_ast instanceof WP_Markdown_Native_SQL_Select
		&& $count_ast->counts_all()
		&& ! $count_ast->selects_all()
		&& array() === $count_ast->projection()
		&& $count instanceof WP_Markdown_Native_Query_Plan
		&& $count->counts_all()
		&& array() === $count->projection()
		&& 1 === $count->limit(),
	'SQL_CALC_FOUND_ROWS is retained as typed AST and plan intent' => $found_rows_ast instanceof WP_Markdown_Native_SQL_Select
		&& $found_rows_ast->calculates_found_rows()
		&& $found_rows_plan instanceof WP_Markdown_Native_Query_Plan
		&& $found_rows_plan->calculates_found_rows()
		&& array( 'row_id' ) === $found_rows_plan->projection(),
	'qualified single-table wildcards lower to the complete row projection' => $qualified_star_ast instanceof WP_Markdown_Native_SQL_Select
		&& array( '*' ) === array_map( static fn( WP_Markdown_Native_SQL_Identifier $column ): string => $column->name(), $qualified_star_ast->projection() )
		&& array( 'wp_rows' ) === array_map( static fn( WP_Markdown_Native_SQL_Identifier $column ): ?string => $column->qualifier(), $qualified_star_ast->projection() )
		&& $qualified_star_plan instanceof WP_Markdown_Native_Query_Plan
		&& array( '*' ) === $qualified_star_plan->projection(),
	'unknown single-table qualifiers fail closed at their source position' => $wrong_qualifier instanceof WP_Markdown_Query_Result
		&& 'unsupported_qualifier' === ( $wrong_qualifier->diagnostic()['reason'] ?? null )
		&& strpos( $wrong_qualifier_sql, 'other' ) === ( $wrong_qualifier->diagnostic()['sql_offset'] ?? null ),
	'WordPress posts query grammar retains grouped predicates, descending order, and offset LIMIT' => $wordpress_posts_plan instanceof WP_Markdown_Native_Query_Plan
		&& 2 === count( $wordpress_posts_plan->predicates() )
		&& 'post_date' === $wordpress_posts_plan->order()
		&& $wordpress_posts_plan->order_descending()
		&& 0 === $wordpress_posts_plan->limit_offset()
		&& 10 === $wordpress_posts_plan->limit(),
	'FOUND_ROWS lowers to explicit runtime state retrieval intent' => $found_rows_query_ast instanceof WP_Markdown_Native_SQL_Found_Rows
		&& $found_rows_query_plan instanceof WP_Markdown_Native_Found_Rows_Plan,
	'duplicate projections report the duplicate source position' => $duplicate instanceof WP_Markdown_Query_Result
		&& 'duplicate_projection' === ( $duplicate->diagnostic()['reason'] ?? null )
		&& strrpos( $duplicate_sql, 'first' ) === ( $duplicate->diagnostic()['sql_offset'] ?? null ),
	'scalar and LIMIT overflow retain distinct deterministic diagnostics' => $scalar instanceof WP_Markdown_Query_Result
		&& 'overflow_scalar' === ( $scalar->diagnostic()['reason'] ?? null )
		&& strpos( $scalar_sql, '999' ) === ( $scalar->diagnostic()['sql_offset'] ?? null )
		&& $limit instanceof WP_Markdown_Query_Result
		&& 'overflow_limit' === ( $limit->diagnostic()['reason'] ?? null )
		&& strpos( $limit_sql, '999' ) === ( $limit->diagnostic()['sql_offset'] ?? null ),
	'malformed literals and unsupported trailing grammar fail at exact offsets' => $escape instanceof WP_Markdown_Query_Result
		&& 'unsupported_literal' === ( $escape->diagnostic()['reason'] ?? null )
		&& strpos( $escape_sql, '\\q' ) === ( $escape->diagnostic()['sql_offset'] ?? null )
		&& $trailing instanceof WP_Markdown_Query_Result
		&& 'unsupported_grammar' === ( $trailing->diagnostic()['reason'] ?? null )
		&& strrpos( $trailing_sql, 'DESC' ) === ( $trailing->diagnostic()['sql_offset'] ?? null )
		&& $unterminated instanceof WP_Markdown_Query_Result
		&& strpos( $unterminated_sql, "'open" ) === ( $unterminated->diagnostic()['sql_offset'] ?? null )
		&& $malformed_and instanceof WP_Markdown_Query_Result
		&& strpos( $malformed_and_sql, 'BY' ) === ( $malformed_and->diagnostic()['sql_offset'] ?? null ),
	'unsupported aggregate shapes fail closed at exact source positions' => $count_column instanceof WP_Markdown_Query_Result
		&& strpos( $count_column_sql, 'row_id' ) === ( $count_column->diagnostic()['sql_offset'] ?? null )
		&& $mixed_count instanceof WP_Markdown_Query_Result
		&& strpos( $mixed_count_sql, ',' ) === ( $mixed_count->diagnostic()['sql_offset'] ?? null )
		&& $aliased_count instanceof WP_Markdown_Query_Result
		&& strpos( $aliased_count_sql, 'AS' ) === ( $aliased_count->diagnostic()['sql_offset'] ?? null )
		&& $grouped_count instanceof WP_Markdown_Query_Result
		&& strpos( $grouped_count_sql, 'GROUP' ) === ( $grouped_count->diagnostic()['sql_offset'] ?? null )
		&& $distinct_count instanceof WP_Markdown_Query_Result
		&& strpos( $distinct_count_sql, 'DISTINCT' ) === ( $distinct_count->diagnostic()['sql_offset'] ?? null )
		&& $unsupported_function instanceof WP_Markdown_Query_Result
		&& strpos( $unsupported_function_sql, '(' ) === ( $unsupported_function->diagnostic()['sql_offset'] ?? null )
		&& array_reduce(
			array( $count_column, $mixed_count, $aliased_count, $grouped_count, $distinct_count, $unsupported_function ),
			static fn( bool $valid, WP_Markdown_Query_Result $result ): bool => $valid && 'unsupported_grammar' === ( $result->diagnostic()['reason'] ?? null ),
			true
		),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}
exit( $failed ? 1 : 0 );
