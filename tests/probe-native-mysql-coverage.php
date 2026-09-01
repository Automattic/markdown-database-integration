<?php
/** Record which MySQL surfaces the native backend can and cannot answer. */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "FAIL: WordPress did not bootstrap.\n" );
	exit( 1 );
}

global $wpdb;

$report = array(
	'schema'   => 'mdi-native-mysql-coverage/v1',
	'backend'  => defined( 'MARKDOWN_DB_BACKEND' ) ? MARKDOWN_DB_BACKEND : null,
	'wpdb'     => is_object( $wpdb ) ? get_class( $wpdb ) : null,
	'statements' => array(),
	'operations' => array(),
);

/** Capture the failure a statement or operation produced, if any. */
function mdi_coverage_failure(): ?array {
	global $wpdb;
	$error = (string) $wpdb->last_error;
	if ( '' === $error ) {
		return null;
	}
	$diagnostic = $wpdb->last_runtime_diagnostic ?? null;
	return array(
		'reason'  => is_array( $diagnostic ) ? ( $diagnostic['reason'] ?? null ) : null,
		'code'    => is_array( $diagnostic ) ? ( $diagnostic['code'] ?? null ) : null,
		'message' => $error,
	);
}

function mdi_coverage_reset(): void {
	global $wpdb;
	$wpdb->last_error = '';
	if ( property_exists( $wpdb, 'last_runtime_diagnostic' ) ) {
		$wpdb->last_runtime_diagnostic = null;
	}
}

/** Run one raw statement and record whether the engine answered it. */
function mdi_coverage_statement( string $feature, string $sql ): void {
	global $wpdb, $report;
	mdi_coverage_reset();
	try {
		$wpdb->get_results( $sql );
		$failure = mdi_coverage_failure();
	} catch ( Throwable $error ) {
		$failure = array( 'reason' => 'exception', 'code' => get_class( $error ), 'message' => $error->getMessage() );
	}
	$report['statements'][] = array(
		'feature'   => $feature,
		'sql'       => $sql,
		'supported' => null === $failure,
		'failure'   => $failure,
	);
}

/** Run one WordPress operation and record whether it completed cleanly. */
function mdi_coverage_operation( string $label, callable $operation ): void {
	global $report;
	mdi_coverage_reset();
	$value = null;
	try {
		$value = $operation();
		$failure = mdi_coverage_failure();
	} catch ( Throwable $error ) {
		$failure = array( 'reason' => 'exception', 'code' => get_class( $error ), 'message' => $error->getMessage() );
	}
	$report['operations'][] = array(
		'operation' => $label,
		'result'    => is_scalar( $value ) || null === $value ? $value : gettype( $value ),
		'supported' => null === $failure,
		'failure'   => $failure,
	);
}

$posts = $wpdb->posts;
$postmeta = $wpdb->postmeta;
$terms = $wpdb->terms;
$tt = $wpdb->term_taxonomy;
$tr = $wpdb->term_relationships;
$options = $wpdb->options;
$users = $wpdb->users;

// Raw MySQL surfaces WordPress core and plugins depend on.
mdi_coverage_statement( 'select.basic', "SELECT ID, post_title FROM {$posts} LIMIT 5" );
mdi_coverage_statement( 'select.count', "SELECT COUNT(*) FROM {$posts}" );
mdi_coverage_statement( 'select.distinct', "SELECT DISTINCT post_type FROM {$posts}" );
mdi_coverage_statement( 'select.order.multi', "SELECT ID FROM {$posts} ORDER BY post_date DESC, ID ASC LIMIT 5" );
mdi_coverage_statement( 'select.limit.offset', "SELECT ID FROM {$posts} ORDER BY ID ASC LIMIT 2, 3" );
mdi_coverage_statement( 'select.in', "SELECT ID FROM {$posts} WHERE post_status IN ('publish','draft')" );
mdi_coverage_statement( 'select.not.in', "SELECT ID FROM {$posts} WHERE post_status NOT IN ('trash')" );
mdi_coverage_statement( 'select.between', "SELECT ID FROM {$posts} WHERE ID BETWEEN 1 AND 100" );
mdi_coverage_statement( 'select.like', "SELECT ID FROM {$posts} WHERE post_title LIKE '%a%'" );
mdi_coverage_statement( 'select.is.null', "SELECT ID FROM {$posts} WHERE post_password IS NULL OR post_password = ''" );
mdi_coverage_statement( 'select.group.by', "SELECT post_type, COUNT(*) AS total FROM {$posts} GROUP BY post_type" );
mdi_coverage_statement( 'select.having', "SELECT post_type, COUNT(*) AS total FROM {$posts} GROUP BY post_type HAVING total > 0" );
mdi_coverage_statement( 'select.aggregate.sum', "SELECT SUM(ID) AS total, AVG(ID) AS mean, MIN(ID) AS lowest, MAX(ID) AS highest FROM {$posts}" );
mdi_coverage_statement( 'select.join.inner', "SELECT p.ID, m.meta_key FROM {$posts} p INNER JOIN {$postmeta} m ON p.ID = m.post_id LIMIT 5" );
mdi_coverage_statement( 'select.join.left', "SELECT p.ID, m.meta_key FROM {$posts} p LEFT JOIN {$postmeta} m ON p.ID = m.post_id LIMIT 5" );
mdi_coverage_statement( 'select.join.three', "SELECT p.ID FROM {$posts} p INNER JOIN {$tr} r ON p.ID = r.object_id INNER JOIN {$tt} t ON r.term_taxonomy_id = t.term_taxonomy_id LIMIT 5" );
mdi_coverage_statement( 'select.subquery.in', "SELECT ID FROM {$posts} WHERE ID IN ( SELECT post_id FROM {$postmeta} WHERE meta_key = 'coverage_probe' )" );
mdi_coverage_statement( 'select.subquery.exists', "SELECT ID FROM {$posts} p WHERE EXISTS ( SELECT 1 FROM {$postmeta} m WHERE m.post_id = p.ID )" );
mdi_coverage_statement( 'select.union', "SELECT ID FROM {$posts} WHERE post_status = 'publish' UNION SELECT ID FROM {$posts} WHERE post_status = 'draft'" );
mdi_coverage_statement( 'select.case', "SELECT ID, CASE WHEN post_status = 'publish' THEN 1 ELSE 0 END AS is_live FROM {$posts} LIMIT 5" );
mdi_coverage_statement( 'select.concat', "SELECT CONCAT(post_title, '-', ID) AS label FROM {$posts} LIMIT 5" );
mdi_coverage_statement( 'select.coalesce', "SELECT COALESCE(post_excerpt, post_title) AS shown FROM {$posts} LIMIT 5" );
mdi_coverage_statement( 'select.substring', "SELECT SUBSTRING(post_title, 1, 3) AS head FROM {$posts} LIMIT 5" );
mdi_coverage_statement( 'select.cast', "SELECT CAST(ID AS UNSIGNED) AS numeric_id FROM {$posts} LIMIT 5" );
mdi_coverage_statement( 'select.date.year', "SELECT YEAR(post_date) AS y, MONTH(post_date) AS m FROM {$posts} LIMIT 5" );
mdi_coverage_statement( 'select.date.format', "SELECT DATE_FORMAT(post_date, '%Y-%m') AS period FROM {$posts} LIMIT 5" );
mdi_coverage_statement( 'select.group_concat', "SELECT post_type, GROUP_CONCAT(ID) AS ids FROM {$posts} GROUP BY post_type" );
mdi_coverage_statement( 'select.order.field', "SELECT ID FROM {$posts} ORDER BY FIELD(post_status, 'publish', 'draft') LIMIT 5" );
mdi_coverage_statement( 'select.regexp', "SELECT ID FROM {$posts} WHERE post_title REGEXP '^a'" );
mdi_coverage_statement( 'select.found.rows', "SELECT SQL_CALC_FOUND_ROWS ID FROM {$posts} LIMIT 2" );
mdi_coverage_statement( 'select.alias.table', "SELECT p.* FROM {$posts} AS p WHERE p.ID > 0 LIMIT 3" );
mdi_coverage_statement( 'select.option.autoload', "SELECT option_name, option_value FROM {$options} WHERE autoload IN ('yes','on')" );
mdi_coverage_statement( 'select.users.join.meta', "SELECT u.ID FROM {$users} u INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id LIMIT 5" );
mdi_coverage_statement( 'select.terms.join', "SELECT t.term_id, t.name FROM {$terms} t INNER JOIN {$tt} tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'category' LIMIT 5" );
mdi_coverage_statement( 'show.tables', 'SHOW TABLES' );
mdi_coverage_statement( 'describe.posts', "DESCRIBE {$posts}" );

// WordPress operations that exercise the query builder end to end.
$post_id = 0;
mdi_coverage_operation( 'post.insert', function () use ( &$post_id ) {
	$post_id = wp_insert_post( array(
		'post_title'   => 'Coverage probe post',
		'post_content' => 'Probe body with searchable coverage text.',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	), true );
	return is_wp_error( $post_id ) ? $post_id->get_error_message() : $post_id;
} );
mdi_coverage_operation( 'post.get', fn() => is_object( get_post( $post_id ) ) );
mdi_coverage_operation( 'post.update', fn() => wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Coverage probe updated' ), true ) === $post_id );
mdi_coverage_operation( 'meta.add', fn() => (bool) update_post_meta( $post_id, 'coverage_probe', '42' ) );
mdi_coverage_operation( 'meta.get', fn() => get_post_meta( $post_id, 'coverage_probe', true ) );
mdi_coverage_operation( 'meta.query.numeric', function () {
	$query = new WP_Query( array(
		'post_type'  => 'post',
		'meta_query' => array( array( 'key' => 'coverage_probe', 'value' => 10, 'compare' => '>', 'type' => 'NUMERIC' ) ),
		'fields'     => 'ids',
	) );
	return count( $query->posts );
} );
mdi_coverage_operation( 'query.search', function () {
	$query = new WP_Query( array( 's' => 'searchable coverage', 'fields' => 'ids' ) );
	return count( $query->posts );
} );
mdi_coverage_operation( 'query.orderby.meta', function () {
	$query = new WP_Query( array(
		'post_type' => 'post',
		'meta_key'  => 'coverage_probe',
		'orderby'   => 'meta_value_num',
		'order'     => 'DESC',
		'fields'    => 'ids',
	) );
	return count( $query->posts );
} );
mdi_coverage_operation( 'query.date', function () {
	$query = new WP_Query( array( 'date_query' => array( array( 'after' => '2000-01-01' ) ), 'fields' => 'ids' ) );
	return count( $query->posts );
} );
mdi_coverage_operation( 'query.pagination.found_rows', function () {
	$query = new WP_Query( array( 'post_type' => 'post', 'posts_per_page' => 1, 'paged' => 1 ) );
	return (int) $query->found_posts;
} );
mdi_coverage_operation( 'term.insert', function () {
	$term = wp_insert_term( 'Coverage Term', 'category' );
	return is_wp_error( $term ) ? $term->get_error_message() : (int) $term['term_id'];
} );
mdi_coverage_operation( 'term.assign', fn() => ! is_wp_error( wp_set_object_terms( $post_id, 'Coverage Term', 'category', false ) ) );
mdi_coverage_operation( 'tax.query', function () {
	$query = new WP_Query( array(
		'post_type' => 'post',
		'tax_query' => array( array( 'taxonomy' => 'category', 'field' => 'slug', 'terms' => 'coverage-term' ) ),
		'fields'    => 'ids',
	) );
	return count( $query->posts );
} );
mdi_coverage_operation( 'terms.get.counted', fn() => count( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) ) ) );
mdi_coverage_operation( 'counts.posts', fn() => (int) wp_count_posts( 'post' )->publish );
mdi_coverage_operation( 'user.insert', function () {
	$user = wp_insert_user( array( 'user_login' => 'coverage_user', 'user_pass' => 'probe-pass', 'user_email' => 'coverage@example.test' ) );
	return is_wp_error( $user ) ? $user->get_error_message() : (int) $user;
} );
mdi_coverage_operation( 'user.meta.query', function () {
	$found = get_users( array( 'meta_key' => 'coverage_user_meta', 'meta_value' => 'yes', 'fields' => 'ID' ) );
	return count( $found );
} );
mdi_coverage_operation( 'comment.insert', function () use ( $post_id ) {
	return (int) wp_insert_comment( array(
		'comment_post_ID' => $post_id,
		'comment_content' => 'Coverage probe comment',
		'comment_approved' => 1,
	) );
} );
mdi_coverage_operation( 'comment.query', fn() => count( get_comments( array( 'post_id' => $post_id ) ) ) );
mdi_coverage_operation( 'option.update', fn() => update_option( 'coverage_probe_option', array( 'nested' => true ) ) );
mdi_coverage_operation( 'option.get', fn() => is_array( get_option( 'coverage_probe_option' ) ) );
mdi_coverage_operation( 'option.delete', fn() => delete_option( 'coverage_probe_option' ) );
mdi_coverage_operation( 'transient.roundtrip', function () {
	set_transient( 'coverage_probe_transient', 'value', 60 );
	return (string) get_transient( 'coverage_probe_transient' );
} );
mdi_coverage_operation( 'post.delete', fn() => is_object( wp_delete_post( $post_id, true ) ) );

$statement_failures = array_values( array_filter( $report['statements'], static fn( array $row ): bool => ! $row['supported'] ) );
$operation_failures = array_values( array_filter( $report['operations'], static fn( array $row ): bool => ! $row['supported'] ) );

$reasons = array();
foreach ( array_merge( $statement_failures, $operation_failures ) as $failure ) {
	$reason = (string) ( $failure['failure']['reason'] ?? 'unknown' );
	$reasons[ $reason ] = ( $reasons[ $reason ] ?? 0 ) + 1;
}
arsort( $reasons );

$report['summary'] = array(
	'statements_total'      => count( $report['statements'] ),
	'statements_supported'  => count( $report['statements'] ) - count( $statement_failures ),
	'statements_failed'     => count( $statement_failures ),
	'operations_total'      => count( $report['operations'] ),
	'operations_supported'  => count( $report['operations'] ) - count( $operation_failures ),
	'operations_failed'     => count( $operation_failures ),
	'failure_reasons'       => $reasons,
	'unsupported_features'  => array_map( static fn( array $row ): string => $row['feature'], $statement_failures ),
	'unsupported_operations' => array_map( static fn( array $row ): string => $row['operation'], $operation_failures ),
);

// The probe reports coverage; it does not assert a coverage level.
fwrite( STDOUT, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
exit( 0 );
