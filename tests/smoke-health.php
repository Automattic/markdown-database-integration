<?php
/** Native and MySQL runtime health plus safe drop-in repair. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/class-wp-markdown-health.php';

$failed = 0;
function mdi_health_assert( bool $condition, string $message ): void {
	global $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $message . PHP_EOL;
	$failed += $condition ? 0 : 1;
}

$native = WP_Markdown_Health::diagnose( array( 'dropin_loaded' => true, 'native_runtime' => true ) );
mdi_health_assert( $native['healthy'] && 'healthy' === $native['status'], 'native canonical runtime reports healthy' );
mdi_health_assert( 'mdi-native' === $native['backend']['id'], 'native runtime is the default backend' );

$native_missing = WP_Markdown_Health::diagnose( array( 'dropin_loaded' => false, 'native_runtime' => false ) );
mdi_health_assert( ! $native_missing['healthy'] && 'dropin_missing_or_replaced' === $native_missing['status'], 'native runtime requires its drop-in' );

$mysql_content = WP_Markdown_Health::diagnose( array( 'managed_post_types' => 'post,page', 'backend_capabilities' => WP_Markdown_Backend_Capabilities::mysql_content() ) );
mdi_health_assert( $mysql_content['healthy'] && 'mysql-content' === $mysql_content['backend']['id'], 'MySQL content runtime remains healthy' );

$incomplete = new WP_Markdown_Backend_Capabilities( 'incomplete' );
$unsupported = WP_Markdown_Health::diagnose( array( 'backend_capabilities' => $incomplete, 'required_capabilities' => array( 'cold_reconstruction' ) ) );
mdi_health_assert( ! $unsupported['healthy'] && 'unsupported_backend_capability' === $unsupported['status'], 'unsupported capabilities fail closed' );

$root = sys_get_temp_dir() . '/mdi-health-' . bin2hex( random_bytes( 5 ) );
mkdir( $root, 0755, true );
$source = $root . '/source.php';
$destination = $root . '/db.php';
file_put_contents( $source, "<?php\n// @studio-keep\ndefine( 'MARKDOWN_DB_DROPIN', true );\n" );
$install = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination ) );
mdi_health_assert( $install['success'] && $install['changed'], 'missing native drop-in installs' );
$repeat = WP_Markdown_Health::repair_dropin( array( 'source' => $source, 'destination' => $destination ) );
mdi_health_assert( $repeat['success'] && ! $repeat['changed'], 'native drop-in repair is idempotent' );

unlink( $source );
unlink( $destination );
rmdir( $root );
exit( $failed ? 1 : 0 );
