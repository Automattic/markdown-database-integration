<?php
/** Generated WordPress core schema catalog integrity and runtime composition. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-native-query-runtime.php';

$ddl = "CREATE TABLE wp_example (\n"
	. " ID bigint(20) unsigned NOT NULL auto_increment,\n"
	. " label varchar(191) NOT NULL default '',\n"
	. " payload longtext default NULL,\n"
	. " PRIMARY KEY  (ID),\n"
	. " KEY label (label(12))\n"
	. ') ENGINE=InnoDB;';
$prefixed = WP_Markdown_Native_Schema_Catalog::compile( $ddl, array( 'wp_' ) );
$other_prefix = WP_Markdown_Native_Schema_Catalog::compile(
	str_replace( 'wp_example', 'network_2_example', $ddl ),
	array( 'network_', 'network_2_' )
);
$artifact = WP_Markdown_Native_Schema_Catalog::artifact();
$single = $artifact['single_site'];
$multisite = $artifact['multisite'];
$expected_single = array( 'users', 'usermeta', 'termmeta', 'terms', 'term_taxonomy', 'term_relationships', 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts' );
$expected_multisite = array_merge( $expected_single, array( 'blogs', 'blogmeta', 'registration_log', 'site', 'sitemeta', 'signups' ) );
$comments_schema = WP_Markdown_Native_Runtime_Factory::comments_schema();
$multisite_users_schema = WP_Markdown_Native_Runtime_Factory::users_schema( true );

$checks = array(
	'compiler output is deterministic across canonical prefixes' => $prefixed === $other_prefix,
	'compiler preserves column and index semantics' => array(
		'type'           => 'bigint',
		'length'         => 20,
		'unsigned'       => true,
		'nullable'       => false,
		'default'        => null,
		'auto_increment' => true,
	) === ( $prefixed['example']['columns']['ID'] ?? null )
		&& null === ( $prefixed['example']['columns']['payload']['default'] ?? null )
		&& 12 === ( $prefixed['example']['indexes'][1]['columns'][0]['length'] ?? null ),
	'generated scope hashes authenticate their complete definitions' => hash_equals( $artifact['hashes']['single_site'], WP_Markdown_Native_Schema_Catalog::hash( $single ) )
		&& hash_equals( $artifact['hashes']['multisite'], WP_Markdown_Native_Schema_Catalog::hash( $multisite ) ),
	'single-site artifact contains the exact WordPress core table inventory' => $expected_single === array_keys( $single ),
	'multisite artifact adds the exact network table inventory' => $expected_multisite === array_keys( $multisite ),
	'multisite users retain their two network-specific columns' => 10 === count( $single['users']['columns'] )
		&& 12 === count( $multisite['users']['columns'] )
		&& $multisite_users_schema->has_column( 'spam' )
		&& $multisite_users_schema->has_column( 'deleted' ),
	'runtime schema columns come directly from the generated catalog' => array_keys( $single['comments']['columns'] ) === $comments_schema->column_names(),
	'runtime capabilities remain explicit overlays' => $comments_schema->allows_lookup( 'comment_author_email', '=', array( 'USER@EXAMPLE.TEST' ) )
		&& ! $comments_schema->allows_lookup( 'comment_content', '=', array( 'content' ) )
		&& $comments_schema->allows_order( 'comment_date_gmt' ),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	if ( ! $passed ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		$failed = true;
	} else {
		fwrite( STDOUT, "PASS: {$label}\n" );
	}
}

exit( $failed ? 1 : 0 );
