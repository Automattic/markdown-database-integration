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
mdi_health_assert( 'sqlite' === $healthy['backend']['id'], 'SQLite is identified as the active backend' );
mdi_health_assert( $healthy['backend']['capabilities']['explicit_flush'], 'health reports SQLite explicit flush support' );
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
mdi_health_assert( 'none' === $mysql['backend']['id'], 'non-SQLite runtime reports no active MDI backend' );
$mysql_content_unconfigured = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_content() ) );
mdi_health_assert( 'not_configured' === $mysql_content_unconfigured['status'] && ! $mysql_content_unconfigured['healthy'], 'MySQL content-primary fails closed without an explicit managed post-type scope' );
$mysql_content = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'managed_post_types' => 'post,page', 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_content() ) );
mdi_health_assert( 'healthy' === $mysql_content['status'] && $mysql_content['healthy'], 'MySQL content-primary runtime is healthy without a db.php drop-in' );
mdi_health_assert( 'mysql-content' === $mysql_content['backend']['id'], 'MySQL content-primary reports its active backend' );
$mysql_content_dropin = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'dropin_loaded' => true, 'managed_post_types' => 'post', 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_content() ) );
mdi_health_assert( 'mysql_content_dropin_migration_required' === $mysql_content_dropin['status'] && str_contains( $mysql_content_dropin['message'], 'Remove' ), 'MySQL content-primary gives explicit db.php migration instructions' );
$mysql_full_missing = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'mysql_full_boundary' => false, 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_full() ) );
mdi_health_assert( 'mysql_full_dropin_required' === $mysql_full_missing['status'] && ! $mysql_full_missing['healthy'], 'MySQL full-primary reports its missing early wpdb boundary' );
$bootstrap_diagnostic = array( 'code' => 'markdown_db_mysql_full_bootstrap_incompatible', 'message' => 'outbox schema mismatch' );
$mysql_full_bootstrap_failed = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'mysql_full_boundary' => false, 'mysql_full_diagnostic' => $bootstrap_diagnostic, 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_full() ) );
mdi_health_assert( 'mysql_full_bootstrap_failed' === $mysql_full_bootstrap_failed['status'] && $bootstrap_diagnostic === $mysql_full_bootstrap_failed['diagnostic'], 'MySQL full-primary preserves the actionable bootstrap failure when the boundary could not load' );
$mysql_full_no_outbox = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'mysql_full_boundary' => true, 'mysql_full_sink' => false, 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_full() ) );
mdi_health_assert( 'mysql_full_outbox_required' === $mysql_full_no_outbox['status'] && ! $mysql_full_no_outbox['healthy'], 'MySQL full-primary does not claim capture without its durable outbox' );
$mysql_full_unavailable = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'mysql_full_boundary' => true, 'mysql_full_sink' => true, 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_full() ) );
mdi_health_assert( 'mysql_full_outbox_unavailable' === $mysql_full_unavailable['status'] && ! $mysql_full_unavailable['healthy'], 'MySQL full-primary fails closed when outbox schema diagnostics are unavailable' );
$throwing_outbox = new class() { public function diagnostics(): array { throw new RuntimeException( 'connection unavailable' ); } };
$mysql_full_diagnostics_failed = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'mysql_full_boundary' => true, 'mysql_full_sink' => true, 'mysql_full_outbox' => $throwing_outbox, 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_full() ) );
mdi_health_assert( 'mysql_full_outbox_unavailable' === $mysql_full_diagnostics_failed['status'] && 'connection unavailable' === $mysql_full_diagnostics_failed['outbox']['error'], 'MySQL full-primary health converts outbox query errors into structured degradation' );
$outbox_diagnostics = array( 'ready' => true, 'schema_version' => 1, 'backlog' => array( 'pending' => 2, 'leased' => 1, 'failed' => 1, 'unsupported_boundaries' => 1, 'oldest_record_age_seconds' => 30, 'attempts' => 4, 'reclaims' => 1, 'failures' => 1 ) );
$semantic_drain = new class() { public function diagnostics(): array { return array( 'ready' => true, 'planner_ready' => true, 'publication' => 'not implemented' ); } };
$mysql_full = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'mysql_full_boundary' => true, 'mysql_full_sink' => true, 'mysql_full_outbox_diagnostics' => $outbox_diagnostics, 'mysql_full_semantic_drain' => $semantic_drain, 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_full() ) );
mdi_health_assert( 'mysql_full_semantic_drain_ready' === $mysql_full['status'] && $mysql_full['healthy'] && $outbox_diagnostics === $mysql_full['outbox'] && 'not implemented' === $mysql_full['semantic_drain']['publication'], 'MySQL full-primary reports semantic drain readiness without claiming canonical publication' );
$throwing_drain = new class() { public function diagnostics(): array { throw new RuntimeException( 'drain unavailable' ); } };
$mysql_full_drain_failed = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'mysql_full_boundary' => true, 'mysql_full_sink' => true, 'mysql_full_outbox_diagnostics' => $outbox_diagnostics, 'mysql_full_semantic_drain' => $throwing_drain, 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_full() ) );
mdi_health_assert( 'mysql_full_semantic_drain_unavailable' === $mysql_full_drain_failed['status'] && 'drain unavailable' === $mysql_full_drain_failed['semantic_drain']['error'], 'MySQL full-primary converts semantic drain diagnostics failures into structured degradation' );
$unready_planner = new class() { public function diagnostics(): array { return array( 'ready' => true, 'planner_ready' => false ); } };
$mysql_full_planner_unready = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'mysql_full_boundary' => true, 'mysql_full_sink' => true, 'mysql_full_outbox_diagnostics' => $outbox_diagnostics, 'mysql_full_semantic_drain' => $unready_planner, 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_full() ) );
mdi_health_assert( 'mysql_full_semantic_drain_unavailable' === $mysql_full_planner_unready['status'], 'MySQL full-primary does not infer planner readiness from outbox readiness' );
$mysql_full_failed = WP_Markdown_Health::diagnose( array( 'mode' => 'primary', 'sqlite_runtime' => false, 'mysql_full_boundary' => true, 'mysql_full_sink' => true, 'mysql_full_diagnostic' => array( 'code' => 'markdown_db_mysql_full_observer_failed', 'message' => 'sink failed' ), 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_full() ) );
mdi_health_assert( 'mysql_full_observer_failed' === $mysql_full_failed['status'] && ! $mysql_full_failed['healthy'] && 'markdown_db_mysql_full_observer_failed' === $mysql_full_failed['diagnostic']['code'], 'MySQL full-primary exposes a sink failure without claiming capture is healthy' );
$incomplete_backend = new WP_Markdown_Backend_Capabilities( 'incomplete', array() );
$unsupported = WP_Markdown_Health::diagnose( array( 'sqlite_runtime' => true, 'backend_capabilities' => $incomplete_backend, 'required_capabilities' => array( 'cold_reconstruction' ) ) );
mdi_health_assert( 'unsupported_backend_capability' === $unsupported['status'] && ! $unsupported['healthy'], 'health fails closed for a required unsupported capability' );
mdi_health_assert( 'markdown_db_unsupported_backend_capability' === $unsupported['diagnostic']['code'], 'health exposes structured unsupported-capability diagnostics' );
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
