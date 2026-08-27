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

$failed = false;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failed = $failed || ! $passed;
}

@rmdir( $root . '/db' );
@rmdir( $root . '/markdown' );
@rmdir( $root );

exit( $failed ? 1 : 0 );
