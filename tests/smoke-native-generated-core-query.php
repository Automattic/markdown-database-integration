<?php
/** Native execution for generated core tables with generic flat snapshots. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-generated-core-' . bin2hex( random_bytes( 6 ) );
mkdir( $root . '/_options', 0755, true );
mkdir( $root . '/_tables', 0755, true );
file_put_contents(
	$root . '/_tables/commentmeta.json',
	json_encode(
		array(
			array( 'meta_id' => '1', 'comment_id' => 123, 'meta_key' => 'private@example.test', 'meta_value' => 'retained' ),
		),
		JSON_THROW_ON_ERROR
	)
);
file_put_contents(
	$root . '/_tables/terms.json',
	json_encode(
		array(
			array( 'term_id' => 4, 'name' => 'Native Term', 'slug' => 'native-term', 'term_group' => '0' ),
		),
		JSON_THROW_ON_ERROR
	)
);
file_put_contents(
	$root . '/_tables/term_relationships.json',
	json_encode(
		array(
			array( 'object_id' => '4', 'term_taxonomy_id' => 9, 'term_order' => '0' ),
			array( 'object_id' => 4, 'term_taxonomy_id' => '7', 'term_order' => 1 ),
		),
		JSON_THROW_ON_ERROR
	)
);

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$commentmeta = $runtime->execute( new WP_Markdown_Query_Request( "SELECT meta_value FROM wp_commentmeta WHERE meta_key = 'PRIVATE@EXAMPLE.TEST' AND comment_id = 123" ) );
$term = $runtime->execute( new WP_Markdown_Query_Request( "SELECT term_id, name FROM wp_terms WHERE slug = 'NATIVE-TERM'" ) );
$unicode = $runtime->execute( new WP_Markdown_Query_Request( "SELECT meta_id FROM wp_commentmeta WHERE meta_key = 'métà'" ) );
$relationships = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT term_taxonomy_id, term_order FROM wp_term_relationships WHERE object_id = 4' ) );

$eligible = array(
	'termmeta'      => 'meta_id',
	'terms'         => 'term_id',
	'term_taxonomy' => 'term_taxonomy_id',
	'term_relationships' => 'object_id',
	'commentmeta'   => 'meta_id',
	'links'         => 'link_id',
	'postmeta'      => 'meta_id',
);
$registered = true;
foreach ( $eligible as $table => $identity ) {
	$result = $runtime->execute( new WP_Markdown_Query_Request( "SELECT {$identity} FROM wp_{$table} LIMIT 0" ) );
	$registered = $registered && 0 === $result->return_value();
}

$multisite = WP_Markdown_Native_Runtime_Factory::runtime( $root, 'wp_2_', 'wp_', true );
$network_tables = array(
	'blogs'            => 'blog_id',
	'blogmeta'         => 'meta_id',
	'registration_log' => 'ID',
	'site'             => 'id',
	'sitemeta'         => 'meta_id',
	'signups'          => 'signup_id',
);
$network_registered = true;
foreach ( $network_tables as $table => $identity ) {
	$result = $multisite->execute( new WP_Markdown_Query_Request( "SELECT {$identity} FROM wp_{$table} LIMIT 0", 'wp_2_' ) );
	$network_registered = $network_registered && 0 === $result->return_value();
}
$site_termmeta = $multisite->execute( new WP_Markdown_Query_Request( 'SELECT meta_id FROM wp_2_termmeta LIMIT 0', 'wp_2_' ) );
$wrong_network_prefix = $multisite->execute( new WP_Markdown_Query_Request( 'SELECT blog_id FROM wp_2_blogs LIMIT 0', 'wp_2_' ) );

$checks = array(
	'generated commentmeta schema executes the retained conjunctive blocker' => 'retained' === ( $commentmeta->wpdb_state()['last_result'][0]->meta_value ?? null ),
	'indexed core strings retain ASCII case-insensitive WordPress semantics' => '4' === ( $term->wpdb_state()['last_result'][0]->term_id ?? null )
		&& 'Native Term' === ( $term->wpdb_state()['last_result'][0]->name ?? null ),
	'unknown Unicode collation behavior fails closed' => false === $unicode->return_value()
		&& 'unsupported_lookup' === ( $unicode->diagnostic()['reason'] ?? null ),
	'all eligible single-site core snapshots register from generated definitions' => $registered,
	'composite numeric identities deduplicate and deterministically order generated rows' => array( '7', '9' ) === array_map(
		static fn( object $row ): string => $row->term_taxonomy_id,
		$relationships->wpdb_state()['last_result']
	),
	'eligible multisite globals use base_prefix while site tables use active prefix' => $network_registered
		&& 0 === $site_termmeta->return_value()
		&& false === $wrong_network_prefix->return_value(),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	fwrite( $passed ? STDOUT : STDERR, ( $passed ? 'PASS' : 'FAIL' ) . ": {$label}\n" );
	$failed = $failed || ! $passed;
}

@unlink( $root . '/_tables/commentmeta.json' );
@unlink( $root . '/_tables/terms.json' );
@unlink( $root . '/_tables/term_relationships.json' );
@rmdir( $root . '/_tables' );
@rmdir( $root . '/_options' );
@rmdir( $root );
exit( $failed ? 1 : 0 );
