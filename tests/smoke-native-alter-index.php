<?php
/** Bounded ALTER TABLE ADD INDEX and SHOW INDEX WHERE. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_native_alter_index_remove_tree( string $root ): void {
	if ( ! is_dir( $root ) ) {
		return;
	}
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}

$root = sys_get_temp_dir() . '/mdi-native-alter-index-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

$index_create = $runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_wc_product_attributes_lookup (product_id bigint(20) NOT NULL, product_or_parent_id bigint(20) NOT NULL, taxonomy varchar(32) NOT NULL, term_id bigint(20) NOT NULL, is_variation_attribute tinyint(1) NOT NULL, in_stock tinyint(1) NOT NULL, INDEX is_variation_attribute_term_id (is_variation_attribute, term_id), PRIMARY KEY (product_or_parent_id, term_id, product_id, taxonomy), KEY product_id (product_id))',
		'wp_'
	)
);
$plugin = $runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_wc_order_stats (order_id bigint unsigned NOT NULL, status varchar(20) NOT NULL, parent_id bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY (order_id))',
		'wp_'
	)
);
$added = $runtime->execute( new WP_Markdown_Query_Request( 'ALTER TABLE wp_wc_order_stats ADD INDEX status (status)', 'wp_' ) );
$again = $runtime->execute( new WP_Markdown_Query_Request( 'ALTER TABLE wp_wc_order_stats ADD INDEX status (status)', 'wp_' ) );
$shown = $runtime->execute( new WP_Markdown_Query_Request( "SHOW INDEX FROM wp_wc_order_stats WHERE key_name = 'status'", 'wp_' ) );
$keys = $runtime->execute( new WP_Markdown_Query_Request( "SHOW KEYS FROM wp_wc_order_stats WHERE Key_name = 'PRIMARY' AND Column_name = 'order_id'", 'wp_' ) );
$comments = $runtime->execute( new WP_Markdown_Query_Request( 'ALTER TABLE wp_comments ADD INDEX woo_idx_comment_type (comment_type)', 'wp_' ) );
$date_type = $runtime->execute(
	new WP_Markdown_Query_Request(
		'ALTER TABLE wp_comments ADD INDEX woo_idx_comment_date_type (comment_date_gmt, comment_type, comment_approved, comment_post_ID)',
		'wp_'
	)
);
$comment_shown = $runtime->execute( new WP_Markdown_Query_Request( "SHOW INDEX FROM wp_comments WHERE key_name = 'woo_idx_comment_type'", 'wp_' ) );
$persisted = (string) file_get_contents( $root . '/_schema/wc_order_stats.sql' );

$checks = array(
	'INDEX is a KEY synonym in CREATE TABLE' => false !== $index_create->return_value(),
	'ADD INDEX persists on a plugin table' => false !== $added->return_value()
		&& str_contains( $persisted, 'KEY `status` (`status`)' ),
	'ADD INDEX is idempotent' => false !== $again->return_value(),
	'SHOW INDEX WHERE filters by Key_name' => array( 'status' ) === array_map(
		static fn( object $row ): string => (string) $row->Key_name,
		$shown->wpdb_state()['last_result']
	),
	'SHOW KEYS WHERE accepts Key_name and Column_name' => array( 'PRIMARY' ) === array_map(
		static fn( object $row ): string => (string) $row->Key_name,
		$keys->wpdb_state()['last_result']
	),
	'ADD INDEX works on core comments without persisted DDL' => false !== $comments->return_value()
		&& false !== $date_type->return_value()
		&& array( 'woo_idx_comment_type' ) === array_map(
			static fn( object $row ): string => (string) $row->Key_name,
			$comment_shown->wpdb_state()['last_result']
		),
);

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

mdi_native_alter_index_remove_tree( $root );
exit( $failed ? 1 : 0 );
