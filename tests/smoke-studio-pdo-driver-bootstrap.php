<?php
/**
 * Verify Studio's pre-rename canonical PDO driver can boot MDI.
 *
 * Usage: php tests/smoke-studio-pdo-driver-bootstrap.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_PDO_MySQL_On_SQLite {}

require_once dirname( __DIR__ ) . '/inc/class-wp-markdown-driver.php';

if ( ! class_exists( 'WP_MySQL_On_SQLite', false ) ) {
	fwrite( STDERR, "FAIL: Studio PDO driver alias was not registered.\n" );
	exit( 1 );
}

if ( ! is_subclass_of( 'WP_Markdown_Driver', 'WP_PDO_MySQL_On_SQLite' ) ) {
	fwrite( STDERR, "FAIL: Markdown driver does not extend Studio's PDO driver.\n" );
	exit( 1 );
}

echo "PASS: Studio PDO driver bootstrap compatibility verified.\n";
