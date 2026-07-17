<?php
/**
 * Smoke tests for MDI runtime health and safe drop-in repair.
 *
 * Usage: php tests/smoke-health.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-health.php';

$root = sys_get_temp_dir() . '/mdi-health-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );
$source = $root . '/source.php';
$destination = $root . '/db.php';
file_put_contents( $source, "<?php\n// @studio-keep\ndefine( 'MARKDOWN_DB_DROPIN', true );\n" );

$failures = array();
function mdi_health_assert( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$healthy = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => true, 'dropin_loaded' => true, 'runtime_classes' => array( true, true, true, true ), 'markdown_runtime' => true ) );
mdi_health_assert( 'healthy' === $healthy['status'], 'healthy MDI runtime is healthy' );
$healthy_mirror = WP_Markdown_Health::diagnose( array( 'mode' => 'mirror', 'sqlite_runtime' => true, 'dropin_loaded' => true, 'runtime_classes' => array( true, true, true, true ), 'markdown_runtime' => true ) );
mdi_health_assert( 'healthy' === $healthy_mirror['status'], 'healthy MDI mirror runtime is healthy' );
$standard_sqlite = WP_Markdown_Health::diagnose( array( 'mode' => 'mirror', 'sqlite_runtime' => true, 'dropin_loaded' => true, 'runtime_classes' => array( true, true, true, true ), 'markdown_runtime' => false ) );
mdi_health_assert( 'dropin_missing_or_replaced' === $standard_sqlite['status'], 'standard SQLite runtime is not mistaken for MDI' );
$primary_degraded = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => true, 'dropin_loaded' => false, 'runtime_classes' => array( false, false, false, false ) ) );
mdi_health_assert( 'dropin_missing_or_replaced' === $primary_degraded['status'], 'degraded primary reports replaced drop-in' );
$mirror_degraded = WP_Markdown_Health::diagnose( array( 'mode' => 'mirror', 'sqlite_runtime' => true, 'dropin_loaded' => false, 'runtime_classes' => array( false, false, false, false ) ) );
mdi_health_assert( 'dropin_missing_or_replaced' === $mirror_degraded['status'], 'degraded mirror reports replaced drop-in' );
$mysql = WP_Markdown_Health::diagnose( array( 'mode' => 'mirror', 'sqlite_runtime' => false ) );
mdi_health_assert( 'not_applicable' === $mysql['status'] && $mysql['healthy'], 'MySQL import/export runtime is not reported broken' );
$fallback = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => true, 'dropin_loaded' => true, 'install_fallback' => true, 'runtime_classes' => array( false, false, false, false ) ) );
mdi_health_assert( 'install_fallback' === $fallback['status'] && $fallback['healthy'], 'primary install fallback is distinguished from degradation' );

$install = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination ) );
mdi_health_assert( $install['success'] && $install['changed'] && file_exists( $destination ), 'missing drop-in installs' );
$repeat = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination ) );
mdi_health_assert( $repeat['success'] && ! $repeat['changed'] && 'already_installed' === $repeat['status'], 'healthy drop-in repair is idempotent' );
$unrelated_dropin = "<?php\n// unrelated\n";
file_put_contents( $destination, $unrelated_dropin );
$refusal = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination ) );
mdi_health_assert( ! $refusal['success'] && str_contains( $refusal['message'], '--force' ), 'unrelated drop-in is not silently overwritten' );
$forced = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination, 'force' => true ) );
mdi_health_assert( $forced['success'] && file_exists( $destination . '.markdown-db-backup' ), 'forced repair creates deterministic backup' );
mdi_health_assert( $unrelated_dropin === file_get_contents( $destination . '.markdown-db-backup' ), 'forced repair preserves unrelated drop-in bytes' );

foreach ( glob( $root . '/*' ) ?: array() as $path ) {
	unlink( $path );
}
rmdir( $root );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo "All health checks passed.\n";
