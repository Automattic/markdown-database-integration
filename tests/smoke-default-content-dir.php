<?php
/** Default canonical root is wp-content/db, with a legacy markdown fallback. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/markdown-db-paths.php';

$root = sys_get_temp_dir() . '/mdi-default-root-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0777, true );
define( 'WP_CONTENT_DIR', $root );

$fresh = markdown_db_default_content_dir();
mkdir( $root . '/markdown', 0777, true );
$legacy = markdown_db_default_content_dir();
mkdir( $root . '/db', 0777, true );
$both = markdown_db_default_content_dir();

$checks = array(
	'an empty content dir defaults to wp-content/db' => $root . '/db' === $fresh,
	'an existing markdown store is kept when db is absent' => $root . '/markdown' === $legacy,
	'wp-content/db wins when both stores exist' => $root . '/db' === $both,
);

// Bootstrap guards protect only existing roots inside ABSPATH and never overwrite.
require_once __DIR__ . '/../inc/markdown-db-store-guards.php';
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';
$inside = ABSPATH . 'mdi-guards-' . bin2hex( random_bytes( 6 ) );
mkdir( $inside );
$checks['guards are created inside ABSPATH'] = markdown_db_guard_store_root( $inside )
	&& "Require all denied\n" === file_get_contents( $inside . '/.htaccess' )
	&& "<?php // Silence is golden.\n" === file_get_contents( $inside . '/index.php' );
$storage = new WP_Markdown_Storage( $inside );
$checks['root guard files are invisible to storage scanning'] = array() === iterator_to_array( $storage->get_markdown_file_manifest_iterator() );
file_put_contents( $inside . '/.htaccess', 'custom deny policy' );
file_put_contents( $inside . '/index.php', '<?php // custom' );
$checks['existing guard contents are preserved'] = markdown_db_guard_store_root( $inside )
	&& 'custom deny policy' === file_get_contents( $inside . '/.htaccess' )
	&& '<?php // custom' === file_get_contents( $inside . '/index.php' );
$checks['no guards are written outside ABSPATH'] = ! markdown_db_guard_store_root( $root . '/db' )
	&& ! file_exists( $root . '/db/.htaccess' ) && ! file_exists( $root . '/db/index.php' );
unlink( $inside . '/.htaccess' );
unlink( $inside . '/index.php' );
rmdir( $inside );

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

@rmdir( $root . '/db' );
@rmdir( $root . '/markdown' );
@rmdir( $root );

exit( $failed ? 1 : 0 );
