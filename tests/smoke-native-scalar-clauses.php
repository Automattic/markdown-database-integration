<?php
/** Scalar expressions are shared by filters, groups, ordering, and HAVING. */

declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-scalar-clauses-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0777, true );
mkdir( $root . '/_tables', 0777, true );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$runtime->execute( new WP_Markdown_Query_Request( 'CREATE TABLE wp_dates (id int unsigned NOT NULL auto_increment, published_at datetime NOT NULL, latitude decimal(8,4) NOT NULL, label varchar(32) NOT NULL, PRIMARY KEY (id))', 'wp_' ) );
foreach ( array( array( '2024-01-15 12:00:00', '10.0', 'one' ), array( '2024-01-25 12:00:00', '20.0', 'two' ), array( '2024-02-01 12:00:00', '30.0', 'eclair' ) ) as $row ) {
	$runtime->execute( new WP_Markdown_Query_Request( "INSERT INTO wp_dates (published_at, latitude, label) VALUES ('{$row[0]}', {$row[1]}, '{$row[2]}')", 'wp_' ) );
}
$where = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_dates WHERE DATE_ADD(published_at, INTERVAL 1 DAY) >= '2024-01-26 00:00:00' ORDER BY id", 'wp_' ) );
$subtracted = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_dates WHERE DATE_SUB(published_at, INTERVAL 1 DAY) < '2024-01-15 00:00:00' ORDER BY id", 'wp_' ) );
$infix_subtracted = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_dates WHERE DATE(published_at - INTERVAL 12 HOUR) = '2024-01-15' ORDER BY id", 'wp_' ) );
$difference = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT id FROM wp_dates WHERE TIMESTAMPDIFF(DAY, published_at, published_at) = 0 ORDER BY id', 'wp_' ) );
$date_or = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_dates WHERE (DATE_ADD(published_at, INTERVAL 1 DAY) < '2024-01-17 00:00:00' OR DATE_ADD(published_at, INTERVAL 1 DAY) >= '2024-02-02 00:00:00') AND label <> 'two' ORDER BY id", 'wp_' ) );
$late_limit = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id FROM wp_dates WHERE DATE_ADD(published_at, INTERVAL 1 DAY) >= '2024-02-02 00:00:00' ORDER BY id LIMIT 1", 'wp_' ) );
$group = $runtime->execute( new WP_Markdown_Query_Request( "SELECT DATE_FORMAT(published_at, '%Y-%m') AS bucket, COUNT(*) AS total FROM wp_dates GROUP BY DATE_FORMAT(published_at, '%Y-%m') HAVING ABS(total) > 1", 'wp_' ) );
$multi_group = $runtime->execute( new WP_Markdown_Query_Request( "SELECT DATE(published_at) AS start_date, DATE_ADD(published_at, INTERVAL 1 DAY) AS end_date, COUNT(*) AS bucket_count FROM wp_dates GROUP BY DATE(published_at), DATE_ADD(published_at, INTERVAL 1 DAY)", 'wp_' ) );
$qualified_group = $runtime->execute( new WP_Markdown_Query_Request( "SELECT DATE(wp_dates.published_at) AS start_date, COUNT(*) AS bucket_count FROM wp_dates GROUP BY DATE(wp_dates.published_at)", 'wp_' ) );
$haversine = '(6371 * ACOS(COS(RADIANS(latitude)) * COS(RADIANS(0)) * COS(RADIANS(0) - RADIANS(0)) + SIN(RADIANS(latitude)) * SIN(RADIANS(0))))';
$ordered = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id, {$haversine} AS distance FROM wp_dates HAVING {$haversine} > 0 ORDER BY {$haversine} DESC", 'wp_' ) );
$aliased_having = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id, {$haversine} AS distance FROM wp_dates HAVING distance > 0 ORDER BY distance DESC", 'wp_' ) );
$coordinate_distance = "(3959 * ACOS(LEAST(1.0, GREATEST(-1.0, COS(RADIANS(40)) * COS(RADIANS(CAST(SUBSTRING_INDEX('40,-74', ',', 1) AS DECIMAL(10,7)))) * COS(RADIANS(CAST(SUBSTRING_INDEX('40,-74', ',', -1) AS DECIMAL(10,7))) - RADIANS(-74)) + SIN(RADIANS(40)) * SIN(RADIANS(CAST(SUBSTRING_INDEX('40,-74', ',', 1) AS DECIMAL(10,7))))))))";
$coordinate_alias = $runtime->execute( new WP_Markdown_Query_Request( "SELECT id, {$coordinate_distance} AS distance FROM wp_dates HAVING distance <= 1 ORDER BY distance ASC", 'wp_' ) );
$functions = $runtime->execute( new WP_Markdown_Query_Request( "SELECT UNIX_TIMESTAMP() AS current_timestamp, RAND(123) AS seeded_random, DATE_ADD('2024-01-15', INTERVAL -1 DAY) AS added_negative, DATE_SUB('2024-01-15', INTERVAL -1 DAY) AS subtracted_negative FROM wp_dates LIMIT 1", 'wp_' ) );
$semantics = $runtime->execute( new WP_Markdown_Query_Request( "SELECT DATE_ADD('2024-01-31', INTERVAL 1 MONTH) AS month_end, DATE_SUB('2024-03-31', INTERVAL 1 MONTH) AS previous_month_end, TIMESTAMPDIFF(MONTH, '2024-01-31', '2024-02-29') AS months, CHAR_LENGTH('éclair') AS characters, LEFT('éclair', 1) AS first_letter, ACOS(2) AS invalid_domain, NULLIF(1, '1') AS coerced_null, ROUND(-1.5) AS rounded, DATE('0000-00-00 00:00:00') AS zero_date FROM wp_dates LIMIT 1", 'wp_' ) );
$calendar = $runtime->execute( new WP_Markdown_Query_Request( "SELECT DAYOFWEEK('2024-01-14') AS sunday, DAYOFMONTH(published_at) AS day, DAYOFYEAR(published_at) AS ordinal, WEEKDAY(published_at) AS weekday, WEEK(published_at, 1) AS week, SECOND(published_at) AS second, ABS(1 + 2 * 3) AS precedence FROM wp_dates WHERE id = 1", 'wp_' ) );
$formatted = $runtime->execute( new WP_Markdown_Query_Request( "SELECT DATE_FORMAT('2021-01-01 13:02:03.123456', '%a|%W|%b|%M|%c|%D|%d|%e|%f|%H|%h|%I|%i|%j|%k|%l|%m|%p|%r|%S|%s|%T|%U|%u|%V|%v|%w|%X|%x|%Y|%y|%%|%q') AS formatted FROM wp_dates LIMIT 1", 'wp_' ) );
$decimal = $runtime->execute( new WP_Markdown_Query_Request( "SELECT CAST('1.235' AS DECIMAL(5,2)) AS rounded, CAST('-1.235' AS DECIMAL(5,2)) AS negative, CAST('12.9' AS DECIMAL) AS default_decimal, CAST('1e3' AS DECIMAL(10,0)) AS exponent, CAST('0.125' AS DECIMAL(3,3)) AS fractional, CAST('-0.001' AS DECIMAL(3,2)) AS negative_zero, CAST('9999' AS DECIMAL(3,1)) AS overflow, CAST('99.96' AS DECIMAL(3,1)) AS round_overflow, SUBSTRING_INDEX('a,b,c', '', 1) AS empty_delimiter, SUBSTRING_INDEX('a,b,c', ',', 0) AS zero_count, SUBSTRING_INDEX('a,b,c', ',', -2) AS negative_count FROM wp_dates LIMIT 1", 'wp_' ) );
$json_valid = $runtime->execute( new WP_Markdown_Query_Request( "SELECT JSON_VALID('{\"event\":true}')", 'wp_' ) );
$json_invalid = $runtime->execute( new WP_Markdown_Query_Request( "SELECT JSON_VALID('{broken}')", 'wp_' ) );
$json_null = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT JSON_VALID(NULL)', 'wp_' ) );
$json_alias = $runtime->execute( new WP_Markdown_Query_Request( "SELECT JSON_VALID('[]') AS valid_json", 'wp_' ) );
$checks = array(
	'WP date WHERE evaluates DATE_ADD INTERVAL after the bounded read' => array( '2', '3' ) === array_map( static fn( object $row ): string => $row->id, $where->wpdb_state()['last_result'] ),
	'DATE_SUB INTERVAL and TIMESTAMPDIFF use the shared WHERE scalar path' => array( '1' ) === array_map( static fn( object $row ): string => $row->id, $subtracted->wpdb_state()['last_result'] )
		&& array( '1', '2', '3' ) === array_map( static fn( object $row ): string => $row->id, $difference->wpdb_state()['last_result'] ),
	'infix datetime minus INTERVAL reuses DATE_SUB scalar semantics' => array( '1' ) === array_map( static fn( object $row ): string => $row->id, $infix_subtracted->wpdb_state()['last_result'] ),
	'WP_Date_Query scalar OR composes with ordinary AND predicates without dropping disjuncts' => array( '1', '3' ) === array_map( static fn( object $row ): string => $row->id, $date_or->wpdb_state()['last_result'] ),
	'scalar residual filtering precedes LIMIT even when the matching row is outside the provider bound' => array( '3' ) === array_map( static fn( object $row ): string => $row->id, $late_limit->wpdb_state()['last_result'] ),
	'date bucketing applies scalar HAVING after aggregate inputs are filtered and grouped' => array( '2024-01' => '2' ) === array_reduce( $group->wpdb_state()['last_result'], static function ( array $values, object $row ): array { $values[ $row->bucket ] = $row->total; return $values; }, array() ),
	'multiple scalar grouping expressions preserve every selected alias and representative value' => array(
		array( 'start_date' => '2024-01-15', 'end_date' => '2024-01-16 12:00:00', 'bucket_count' => '1' ),
		array( 'start_date' => '2024-01-25', 'end_date' => '2024-01-26 12:00:00', 'bucket_count' => '1' ),
		array( 'start_date' => '2024-02-01', 'end_date' => '2024-02-02 12:00:00', 'bucket_count' => '1' ),
	) === array_map( 'get_object_vars', $multi_group->wpdb_state()['last_result'] ),
	'qualified single-table scalar groups evaluate against flat provider rows' => array(
		array( 'start_date' => '2024-01-15', 'bucket_count' => '1' ),
		array( 'start_date' => '2024-01-25', 'bucket_count' => '1' ),
		array( 'start_date' => '2024-02-01', 'bucket_count' => '1' ),
	) === array_map( 'get_object_vars', $qualified_group->wpdb_state()['last_result'] ),
	'parenthesized Haversine projection, HAVING, and ORDER BY share scalar evaluation' => array( '3', '2', '1' ) === array_map( static fn( object $row ): string => $row->id, $ordered->wpdb_state()['last_result'] ),
	'an ungrouped HAVING scalar alias evaluates its selected expression' => array( '3', '2', '1' ) === array_map( static fn( object $row ): string => $row->id, $aliased_having->wpdb_state()['last_result'] ),
	'coordinate Haversine expressions support nested decimal casts and scalar aliases' => array( '1', '2', '3' ) === array_map( static fn( object $row ): string => $row->id, $coordinate_alias->wpdb_state()['last_result'] ),
	'UNIX_TIMESTAMP accepts zero arguments, RAND(seed) is repeatable, and negative intervals invert direction' => is_numeric( $functions->wpdb_state()['last_result'][0]->current_timestamp ?? null )
		&& ( $functions->wpdb_state()['last_result'][0]->seeded_random ?? null ) === ( $runtime->execute( new WP_Markdown_Query_Request( 'SELECT RAND(123) AS seeded_random FROM wp_dates LIMIT 1', 'wp_' ) )->wpdb_state()['last_result'][0]->seeded_random ?? null )
		&& array( 'added_negative' => '2024-01-14', 'subtracted_negative' => '2024-01-16' ) === array_intersect_key( (array) ( $functions->wpdb_state()['last_result'][0] ?? array() ), array_flip( array( 'added_negative', 'subtracted_negative' ) ) ),
	'MariaDB month boundaries, Unicode characters, null coercion, and numeric domains retain scalar semantics' => array( 'month_end' => '2024-02-29', 'previous_month_end' => '2024-02-29', 'months' => '0', 'characters' => '6', 'first_letter' => 'é', 'invalid_domain' => null, 'coerced_null' => null, 'rounded' => '-2', 'zero_date' => '0000-00-00' ) === (array) ( $semantics->wpdb_state()['last_result'][0] ?? array() ),
	'WP_Date_Query calendar parts and arithmetic precedence match MySQL' => array( 'sunday' => '1', 'day' => '15', 'ordinal' => '15', 'weekday' => '0', 'week' => '3', 'second' => '00', 'precedence' => '7' ) === (array) ( $calendar->wpdb_state()['last_result'][0] ?? array() ),
	'DATE_FORMAT handles names, ordinals, 12-hour time, fractions, week modes, escapes, and unknown specifiers' => 'Fri|Friday|Jan|January|1|1st|01|1|123456|13|01|01|02|001|13|1|01|PM|01:02:03 PM|03|03|13:02:03|00|00|52|53|5|2020|2020|2021|21|%|q' === ( $formatted->wpdb_state()['last_result'][0]->formatted ?? null ),
	'DECIMAL precision, exponents, saturation, and SUBSTRING_INDEX edge semantics match MariaDB' => array( 'rounded' => '1.24', 'negative' => '-1.24', 'default_decimal' => '13', 'exponent' => '1000', 'fractional' => '0.125', 'negative_zero' => '0.00', 'overflow' => '99.9', 'round_overflow' => '99.9', 'empty_delimiter' => '', 'zero_count' => '', 'negative_count' => 'b,c' ) === (array) ( $decimal->wpdb_state()['last_result'][0] ?? array() ),
	'tableless JSON_VALID preserves valid, invalid, NULL, and column metadata semantics' => '1' === ( $json_valid->wpdb_state()['last_result'][0]->{'JSON_VALID(\'{"event":true}\')'} ?? null )
		&& '0' === ( $json_invalid->wpdb_state()['last_result'][0]->{'JSON_VALID(\'{broken}\')'} ?? null )
		&& null === ( $json_null->wpdb_state()['last_result'][0]->{'JSON_VALID(NULL)'} ?? null )
		&& 'JSON_VALID(\'{"event":true}\')' === ( $json_valid->wpdb_state()['col_info'][0]->name ?? null )
		&& 8 === ( $json_valid->wpdb_state()['col_info'][0]->type ?? null )
		&& '1' === ( $json_alias->wpdb_state()['last_result'][0]->valid_json ?? null ),
);
$failed = false;
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n"; $failed = $failed || ! $passed; }
array_map( 'unlink', glob( $root . '/_tables/*' ) ?: array() ); array_map( 'unlink', glob( $root . '/_schema/*' ) ?: array() );
@rmdir( $root . '/_tables' ); @rmdir( $root . '/_schema' ); @rmdir( $root . '/_options' ); @rmdir( $root );
exit( $failed ? 1 : 0 );
