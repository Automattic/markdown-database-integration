<?php
/** Network installation routes global schema before multisite bootstrap completes. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_INSTALLING_NETWORK', true );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-install-network-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
$GLOBALS['wpdb'] = (object) array( 'base_prefix' => 'wptests_' );
$runtime = new WP_Markdown_Native_WordPress_Query_Runtime( $root, 'wptests_', $root );
$created = $runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wptests_blogs ( blog_id bigint(20) NOT NULL auto_increment, site_id bigint(20) NOT NULL default 0, domain varchar(200) NOT NULL default \'\', path varchar(100) NOT NULL default \'\', registered datetime NOT NULL default \'1970-01-01 00:00:00\', last_updated datetime NOT NULL default \'1970-01-01 00:00:00\', public tinyint(2) NOT NULL default 1, archived tinyint(2) NOT NULL default 0, mature tinyint(2) NOT NULL default 0, spam tinyint(2) NOT NULL default 0, deleted tinyint(2) NOT NULL default 0, lang_id int(11) NOT NULL default 0, PRIMARY KEY (blog_id), KEY domain (domain(50),path(5)), KEY lang_id (lang_id) )',
		'wptests_'
	)
);
$inserted = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wptests_blogs (blog_id, site_id, domain, path, registered, last_updated, public, archived, mature, spam, deleted, lang_id) VALUES (1, 1, 'example.com', '/', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 1, 0, 0, 0, 0, 0)",
		'wptests_'
	)
);
$blog = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT blog_id FROM wptests_blogs WHERE blog_id = 1', 'wptests_' ) );
$checks = array(
	'network installation creates the global blogs schema before multisite bootstrap' => true === $created->return_value(),
	'network installation persists the base blog row' => 1 === $inserted->return_value() && '1' === ( $blog->wpdb_state()['last_result'][0]->blog_id ?? null ),
);
$failed = false;
foreach ( $checks as $label => $passed ) {
	fwrite( $passed ? STDOUT : STDERR, ( $passed ? 'PASS' : 'FAIL' ) . ": {$label}\n" );
	$failed = $failed || ! $passed;
}

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
	$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
}
rmdir( $root );
exit( $failed ? 1 : 0 );
