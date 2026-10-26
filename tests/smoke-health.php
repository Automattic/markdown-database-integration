<?php
/** Native runtime health and safe drop-in repair coverage. */
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
	if ( ! $condition ) { $failures[] = $message; }
}
$healthy = WP_Markdown_Health::diagnose( array( 'native_runtime' => true, 'dropin_loaded' => true ) );
mdi_health_assert( 'healthy' === $healthy['status'] && $healthy['healthy'], 'native runtime is healthy' );
mdi_health_assert( 'mdi-native' === $healthy['backend']['id'], 'health identifies native backend' );
mdi_health_assert( $healthy['backend']['capabilities']['canonical_option_select'], 'health reports native capability contract' );
$missing = WP_Markdown_Health::diagnose( array( 'configured' => true, 'native_runtime' => false, 'dropin_loaded' => false ) );
mdi_health_assert( 'dropin_missing_or_replaced' === $missing['status'] && ! $missing['healthy'], 'configured native runtime without drop-in is broken' );
$replaced = WP_Markdown_Health::diagnose( array( 'native_runtime' => false, 'dropin_loaded' => true ) );
mdi_health_assert( 'dropin_missing_or_replaced' === $replaced['status'], 'drop-in marker alone does not prove native runtime' );
$mysql = WP_Markdown_Health::diagnose( array( 'configured' => false, 'native_runtime' => false, 'dropin_loaded' => false ) );
mdi_health_assert( 'not_applicable' === $mysql['status'] && $mysql['healthy'], 'import/export-only runtime is not broken' );
mdi_health_assert( 'none' === $mysql['backend']['id'], 'import/export-only runtime has no active backend' );
$unsupported = WP_Markdown_Health::diagnose( array( 'backend_capabilities' => new WP_Markdown_Backend_Capabilities( 'incomplete' ), 'required_capabilities' => array( 'schema_persistence' ) ) );
mdi_health_assert( 'unsupported_backend_capability' === $unsupported['status'] && ! $unsupported['healthy'], 'required unsupported capability fails closed' );
mdi_health_assert( 'markdown_db_unsupported_backend_capability' === $unsupported['diagnostic']['code'], 'health retains structured diagnostics' );

$install = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination ) );
mdi_health_assert( $install['success'] && $install['changed'] && file_exists( $destination ), 'missing drop-in installs' );
$repeat = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination ) );
mdi_health_assert( $repeat['success'] && ! $repeat['changed'] && 'already_installed' === $repeat['status'], 'healthy drop-in repair is idempotent' );
$outdated_dropin = "<?php\n// @studio-keep\ndefine( 'MARKDOWN_DB_DROPIN', true );\n// outdated\n";
file_put_contents( $destination, $outdated_dropin );
$updated = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination ) );
mdi_health_assert( $updated['success'] && $updated['changed'] && 'updated' === $updated['status'], 'outdated MDI drop-in is refreshed' );
mdi_health_assert( file_get_contents( $source ) === file_get_contents( $destination ), 'refreshed MDI drop-in matches the packaged source' );
mdi_health_assert( empty( glob( $destination . '.markdown-db-tmp-*' ) ?: array() ), 'drop-in refresh leaves no staged files' );
$unrelated_dropin = "<?php\n// unrelated\n";
file_put_contents( $destination, $unrelated_dropin );
$refusal = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination ) );
mdi_health_assert( ! $refusal['success'] && str_contains( $refusal['message'], '--force' ), 'unrelated drop-in is not silently overwritten' );
$forced = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination, 'force' => true ) );
mdi_health_assert( $forced['success'] && file_exists( $destination . '.markdown-db-backup' ), 'forced repair creates deterministic backup' );
mdi_health_assert( $unrelated_dropin === file_get_contents( $destination . '.markdown-db-backup' ), 'forced repair preserves unrelated drop-in bytes' );

// Plugin load refreshes only an installed MDI drop-in that differs from the packaged copy.
$outdated_report = WP_Markdown_Health::diagnose( array( 'native_runtime' => true, 'dropin_loaded' => true, 'dropin_outdated' => true ) );
mdi_health_assert( 'dropin_outdated' === $outdated_report['status'] && ! $outdated_report['healthy'], 'doctor reports a drop-in that could not be refreshed' );
mkdir( $root . '/plugin' );
mkdir( $root . '/content' );
define( 'MARKDOWN_DB_PLUGIN_DIR', $root . '/plugin/' );
define( 'WP_CONTENT_DIR', $root . '/content' );
$packaged = "<?php\n// @studio-keep\ndefine( 'MARKDOWN_DB_DROPIN', true );\n// v2\n";
file_put_contents( MARKDOWN_DB_PLUGIN_DIR . 'db.php', $packaged );
$installed_path = WP_CONTENT_DIR . '/db.php';
foreach ( array(
	'outdated MDI drop-in is replaced' => array( "<?php\n// @studio-keep\ndefine( 'MARKDOWN_DB_DROPIN', true );\n// v1\n", 'updated', $packaged ),
	'current MDI drop-in is untouched'  => array( $packaged, null, $packaged ),
	'unrelated drop-in is untouched'    => array( "<?php\n// other plugin\n", null, "<?php\n// other plugin\n" ),
	'absent drop-in is not installed'   => array( null, null, null ),
) as $label => list( $before, $status, $after ) ) {
	if ( file_exists( $installed_path ) ) { unlink( $installed_path ); }
	if ( null !== $before ) { file_put_contents( $installed_path, $before ); }
	$result = WP_Markdown_Health::refresh_outdated_dropin();
	$contents = file_exists( $installed_path ) ? file_get_contents( $installed_path ) : null;
	mdi_health_assert( $status === ( $result['status'] ?? null ) && $after === $contents, $label );
}
foreach ( array( $installed_path, MARKDOWN_DB_PLUGIN_DIR . 'db.php' ) as $path ) { if ( file_exists( $path ) ) { unlink( $path ); } }
rmdir( $root . '/plugin' );
rmdir( $root . '/content' );
foreach ( glob( $root . '/*' ) ?: array() as $path ) { unlink( $path ); }
rmdir( $root );
if ( $failures ) {
	foreach ( $failures as $failure ) { echo 'FAIL: ' . $failure . PHP_EOL; }
	exit( 1 );
}
echo "All health checks passed.\n";
