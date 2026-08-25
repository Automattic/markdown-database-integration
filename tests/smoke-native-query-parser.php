<?php
/** Pure-PHP SQL tokenization, typed SELECT AST, and plan lowering. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-query-runtime.php';

$tokenizer = new WP_Markdown_Native_SQL_Tokenizer();
$token_sql = "SELECT `option_name`, option_value FROM wp_options WHERE option_name = 'tab\\tback\\\\slash' LIMIT 1";
$tokens    = $tokenizer->tokenize( $token_sql );
$parser    = new WP_Markdown_Native_Query_Parser( $tokenizer );

$ast_sql = "\nseLEct `option_name`, option_value FROM `wp_options` WHERE option_name IN ('siteurl', 'tab\\tback\\\\slash', 'siteurl') ORDER BY option_id aSc LIMIT 2\t";
$ast     = $parser->parse_ast( $ast_sql );
$plan    = $ast instanceof WP_Markdown_Native_SQL_Select ? $parser->lower( $ast ) : $ast;

$star = $parser->parse( 'SELECT * FROM wp_options' );
$zero = $parser->parse( 'SELECT option_value FROM wp_options WHERE option_id = 000 LIMIT 000' );

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
		&& 'option_id' === $ast->order()?->name()
		&& 2 === $ast->limit(),
	'AST lowering preserves the existing bounded query-plan contract' => $plan instanceof WP_Markdown_Native_Query_Plan
		&& array( 'option_name', 'option_value' ) === $plan->projection()
		&& array( 'siteurl', "tab\tback\\slash" ) === $plan->predicate()?->values()
		&& 'option_id' === $plan->order()
		&& 2 === $plan->limit(),
	'legacy star, integer normalization, and zero LIMIT behavior are preserved' => $star instanceof WP_Markdown_Native_Query_Plan
		&& array( '*' ) === $star->projection()
		&& PHP_INT_MAX === $star->limit()
		&& $zero instanceof WP_Markdown_Native_Query_Plan
		&& array( 0 ) === $zero->predicate()?->values()
		&& 0 === $zero->limit(),
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
		&& strpos( $unterminated_sql, "'open" ) === ( $unterminated->diagnostic()['sql_offset'] ?? null ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}
exit( $failed ? 1 : 0 );
