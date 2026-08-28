<?php
/** One INSERT statement carrying many rows. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_native_multi_row_remove_tree( string $root ): void {
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

/** @return array<int,array<string,mixed>> */
function mdi_native_multi_row_rows( WP_Markdown_Query_Result $result ): array {
	$rows = $result->wpdb_state()['last_result'] ?? array();
	return is_array( $rows ) ? array_map( static fn( $row ): array => (array) $row, $rows ) : array();
}

$root = sys_get_temp_dir() . '/mdi-native-multi-row-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
mkdir( $root . '/_options', 0755 );
$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $root );

// Many canonical options arrive in one statement.
$options = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('template', 'a-theme', 'yes'), ('stylesheet', 'a-theme', 'yes'), ('posts_per_page', '10', 'yes')",
		'wp_'
	)
);
$read_back = $runtime->execute(
	new WP_Markdown_Query_Request( "SELECT option_value FROM wp_options WHERE option_name = 'stylesheet'", 'wp_' )
);

// A generic table reports the rows it inserted and its first identifier.
$runtime->execute(
	new WP_Markdown_Query_Request(
		'CREATE TABLE wp_plugin_records (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, label varchar(40) NOT NULL, PRIMARY KEY (id), UNIQUE KEY label (label))',
		'wp_'
	)
);
$rows = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_plugin_records (label) VALUES ('first'), ('second'), ('third')",
		'wp_'
	)
);
$listed = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT label FROM wp_plugin_records ORDER BY id ASC', 'wp_' )
);

// A statement that cannot finish leaves nothing behind.
$conflict = $runtime->execute(
	new WP_Markdown_Query_Request(
		"INSERT INTO wp_plugin_records (label) VALUES ('fourth'), ('second')",
		'wp_'
	)
);
$after_conflict = $runtime->execute(
	new WP_Markdown_Query_Request( 'SELECT label FROM wp_plugin_records ORDER BY id ASC', 'wp_' )
);

$labels = array_column( mdi_native_multi_row_rows( $listed ), 'label' );
$after_labels = array_column( mdi_native_multi_row_rows( $after_conflict ), 'label' );

$checks = array(
	'a multi-row option INSERT reports every row' => 3 === $options->return_value(),
	'a multi-row option INSERT stores each option' => 'a-theme' === ( mdi_native_multi_row_rows( $read_back )[0]['option_value'] ?? null ),
	'a multi-row table INSERT reports every row' => 3 === $rows->return_value(),
	'a multi-row table INSERT reports its first identifier' => 1 === ( $rows->wpdb_state()['insert_id'] ?? null ),
	'a multi-row table INSERT stores each row in order' => array( 'first', 'second', 'third' ) === $labels,
	'a conflicting multi-row INSERT fails closed' => false === $conflict->return_value(),
	'a conflicting multi-row INSERT leaves the table untouched' => array( 'first', 'second', 'third' ) === $after_labels,
);

mdi_native_multi_row_remove_tree( $root );

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
