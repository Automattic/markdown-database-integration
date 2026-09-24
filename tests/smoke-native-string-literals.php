<?php
/**
 * String literals in MySQL's default SQL mode (issue #427).
 *
 * Without ANSI_QUOTES, MySQL reads "…" as a string just like '…', and inside
 * either style a doubled delimiter is one literal quote. mdi-native rejected
 * double-quoted strings outright and split 'it''s' into two tokens.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$tokenizer = new WP_Markdown_Native_SQL_Tokenizer();
$strings   = static function ( string $sql ) use ( $tokenizer ): array {
	$out = array();
	foreach ( $tokenizer->tokenize( $sql ) as $token ) {
		if ( WP_Markdown_Native_SQL_Token::STRING === $token->type() ) {
			$out[] = $token->value();
		}
	}
	return $out;
};

$root = sys_get_temp_dir() . '/mdi-native-string-literals-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the string literal fixture.' );
}
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$run     = static fn( string $sql ): WP_Markdown_Query_Result => $runtime->execute( new WP_Markdown_Query_Request( $sql, 'wp_' ) );
$labels  = static function ( string $where ) use ( $run ): array {
	$result = $run( 'SELECT id, label FROM wp_notes WHERE ' . $where . ' ORDER BY id ASC' );
	return array_map( static fn( object $row ): string => (string) $row->label, $result->wpdb_state()['last_result'] ?? array() );
};

$run( 'CREATE TABLE wp_notes (id BIGINT NOT NULL AUTO_INCREMENT, label VARCHAR(60) NULL, PRIMARY KEY (id))' );
$insert_double  = $run( 'INSERT INTO wp_notes (label) VALUES ("double")' );
$insert_doubled = $run( "INSERT INTO wp_notes (label) VALUES ('it''s')" );
$insert_nested  = $run( 'INSERT INTO wp_notes (label) VALUES ("say ""hi"" it\'s")' );
$update_double  = $run( 'UPDATE wp_notes SET label = "renamed" WHERE label = "double" LIMIT 1' );

// Long literals with dense escapes decode exactly (the run-copying fast path).
$long_value = str_repeat( "{\"key\":\"it's\\path\\x\",\n\t\"n\":1};", 20000 );
$long_sql   = "UPDATE wp_notes SET label = '" . addslashes( $long_value ) . "' WHERE id = 1";
$long_token = null;
foreach ( $tokenizer->tokenize( $long_sql ) as $token ) {
	if ( WP_Markdown_Native_SQL_Token::STRING === $token->type() ) {
		$long_token = $token;
	}
}
$doubled_value = str_repeat( "a''b", 50000 );
$doubled_token = $strings( "SELECT '" . $doubled_value . "'" );
$memo_first  = $tokenizer->tokenize( "SELECT 'first'" );
$memo_second = ( new WP_Markdown_Native_SQL_Tokenizer() )->tokenize( "SELECT 'second'" );
$memo_again  = $tokenizer->tokenize( "SELECT 'first'" );

$checks = array(
	'a double-quoted literal tokenizes as a string'                  => array( 'x' ) === $strings( 'SELECT "x"' ),
	'a doubled single quote is one literal quote'                    => array( "it's" ) === $strings( "SELECT 'it''s'" ),
	'a doubled double quote is one literal quote'                    => array( 'say "hi"' ) === $strings( 'SELECT "say ""hi"""' ),
	'the other quote style needs no escaping inside a literal'       => array( "it's", 'say "hi"' ) === $strings( "SELECT \"it's\", 'say \"hi\"'" ),
	'backslash escapes still apply inside double quotes'             => array( "a\"b\nc" ) === $strings( 'SELECT "a\\"b\\nc"' ),
	'adjacent separate literals stay separate'                       => array( 'a', 'b' ) === $strings( "SELECT 'a' , 'b'" ),
	'an unterminated double-quoted literal is still rejected'        => ( static function () use ( $tokenizer ): bool {
		try {
			$tokenizer->tokenize( 'SELECT "open' );
			return false;
		} catch ( WP_Markdown_Native_SQL_Parse_Error $error ) {
			return true;
		}
	} )(),
	'backticks remain quoted identifiers'                            => WP_Markdown_Native_SQL_Token::QUOTED_IDENTIFIER === $tokenizer->tokenize( 'SELECT `label` FROM wp_notes' )[1]->type(),
	'a semicolon inside a double-quoted literal is not a separator'  => ! WP_Markdown_Native_SQL_Tokenizer::contains_statement_separator( 'SELECT "a;b"' ),
	'double-quoted INSERT, UPDATE, and WHERE values round-trip'      => 1 === $insert_double->return_value() && 1 === $update_double->return_value() && array( 'renamed' ) === $labels( 'label = "renamed"' ),
	'a doubled-quote value is stored and matched as one quote'       => 1 === $insert_doubled->return_value() && array( "it's" ) === $labels( "label = 'it''s'" ) && array( "it's" ) === $labels( "label = \"it's\"" ),
	'a long literal with dense escapes decodes exactly'           => null !== $long_token && $long_value === $long_token->value() && addslashes( $long_value ) === substr( $long_token->lexeme(), 1, -1 ),
	'a long run of doubled quotes decodes to single quotes'        => array( str_repeat( "a'b", 50000 ) ) === $doubled_token,
	'a semicolon deep inside a long literal is not a separator'    => ! WP_Markdown_Native_SQL_Tokenizer::contains_statement_separator( $long_sql ) && WP_Markdown_Native_SQL_Tokenizer::contains_statement_separator( $long_sql . '; SELECT 1' ),
	'memoized tokens always belong to the requested statement'     => 'first' === $memo_first[1]->value() && 'second' === $memo_second[1]->value() && 'first' === $memo_again[1]->value(),
	'mixed quoting stores the decoded value'                         => 1 === $insert_nested->return_value() && array( 'say "hi" it\'s' ) === $labels( "label = 'say \"hi\" it\\'s'" ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $files as $file ) {
	$file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
}
@rmdir( $root );

exit( $failed ? 1 : 0 );
