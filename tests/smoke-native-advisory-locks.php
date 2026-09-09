<?php
/** Verify root-scoped native GET_LOCK/RELEASE_LOCK ownership and cleanup. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

$root = sys_get_temp_dir() . '/mdi-native-advisory-locks-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $root . '/_options', 0777, true ) || ! mkdir( $root . '/_tables', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create the advisory-lock probe fixture.' );
}

$first = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$second = WP_Markdown_Native_Runtime_Factory::runtime( $root );

/** @return int|string|null */
function advisory_lock_value( WP_Markdown_Query_Runtime $runtime, string $sql, string $prefix = 'wp_' ): int|string|null {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql, $prefix ) );
	$rows = $result->wpdb_state()['last_result'];
	return isset( $rows[0] ) ? current( get_object_vars( $rows[0] ) ) : null;
}

$first_acquire = advisory_lock_value( $first, "SELECT GET_LOCK('native-lock', 0)" );
$first_reentrant = advisory_lock_value( $first, "SELECT GET_LOCK('native-lock', 0)" );
$second_contended = advisory_lock_value( $second, "SELECT GET_LOCK('native-lock', 0)" );
$first_release_once = advisory_lock_value( $first, "SELECT RELEASE_LOCK('native-lock')" );
$second_still_contended = advisory_lock_value( $second, "SELECT GET_LOCK('native-lock', 0)" );
$first_release_final = advisory_lock_value( $first, "SELECT RELEASE_LOCK('native-lock')" );
$second_acquire = advisory_lock_value( $second, "SELECT GET_LOCK('native-lock', 0)" );
$second->close();
$first_after_close = advisory_lock_value( $first, "SELECT GET_LOCK('native-lock', 0)" );
$unknown_release = advisory_lock_value( $first, "SELECT RELEASE_LOCK('missing-native-lock')" );
$persistent_owner = advisory_lock_value( $first, "SELECT GET_LOCK('persistent-unowned-lock', 0)" );
$failed_acquire = advisory_lock_value( $second, "SELECT GET_LOCK('persistent-unowned-lock', 0)" );
$persistent_owner_release = advisory_lock_value( $first, "SELECT RELEASE_LOCK('persistent-unowned-lock')" );
$unowned_persistent_release = advisory_lock_value( $first, "SELECT RELEASE_LOCK('persistent-unowned-lock')" );
$escaped_name = advisory_lock_value( $first, "SELECT GET_LOCK('escaped\\nlock', 0)" );
$escaped_column = $first->execute( new WP_Markdown_Query_Request( "SELECT GET_LOCK('escaped\\nlock', 0)" ) )->wpdb_state()['col_info'][0]->name ?? null;
$escaped_release = advisory_lock_value( $first, "SELECT RELEASE_LOCK('escaped\\nlock')" );
$unsupported_release_arity = $first->execute( new WP_Markdown_Query_Request( "SELECT RELEASE_LOCK('native-lock', 0)" ) );
$unsupported_timeout = $first->execute( new WP_Markdown_Query_Request( "SELECT GET_LOCK('native-lock', 5.1)" ) );

// The worker has an open descriptor before this owner releases the stable inode.
$race_owner = advisory_lock_value( $first, "SELECT GET_LOCK('inode-race-lock', 0)" );
$worker = proc_open(
	escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/native-advisory-lock-worker.php' ) . ' ' . escapeshellarg( $root ) . ' inode-race-lock',
	array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$pipes
);
$worker_descriptor_open = is_resource( $worker ) && 'descriptor-open' === rtrim( (string) fgets( $pipes[1] ) );
$race_owner_release = advisory_lock_value( $first, "SELECT RELEASE_LOCK('inode-race-lock')" );
$worker_acquired = $worker_descriptor_open && 'acquired' === rtrim( (string) fgets( $pipes[1] ) );
$third = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$third_contended = advisory_lock_value( $third, "SELECT GET_LOCK('inode-race-lock', 0)" );
if ( is_resource( $worker ) ) {
	fclose( $pipes[0] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$worker_status = proc_close( $worker );
} else {
	$worker_status = 1;
}

$multisite = new WP_Markdown_Native_Multisite_Query_Runtime( $root, 'wp_', $root );
$multisite_base = advisory_lock_value( $multisite, "SELECT GET_LOCK('multisite-lock', 0)" );
$multisite_site = advisory_lock_value( $multisite, "SELECT GET_LOCK('multisite-lock', 0)", 'wp_2_' );
$external = WP_Markdown_Native_Runtime_Factory::runtime( $root );
$multisite_release_once = advisory_lock_value( $multisite, "SELECT RELEASE_LOCK('multisite-lock')", 'wp_2_' );
$external_contended = advisory_lock_value( $external, "SELECT GET_LOCK('multisite-lock', 0)" );
$multisite_release_final = advisory_lock_value( $multisite, "SELECT RELEASE_LOCK('multisite-lock')" );
$external_acquire = advisory_lock_value( $external, "SELECT GET_LOCK('multisite-lock', 0)" );

$assertions = array(
	'acquisition returns a selected MySQL scalar' => '1' === $first_acquire,
	'the owning logical connection is reentrant' => '1' === $first_reentrant,
	'an independent runtime is excluded without waiting' => '0' === $second_contended,
	'one reentrant release retains ownership' => '1' === $first_release_once && '0' === $second_still_contended,
	'the final release transfers ownership' => '1' === $first_release_final && '1' === $second_acquire,
	'close releases all locks owned by its runtime' => '1' === $first_after_close,
	'unknown release has MySQL null shape' => null === $unknown_release,
	'a stale lock file is not treated as a live owner' => '1' === $persistent_owner && '0' === $failed_acquire && '1' === $persistent_owner_release && null === $unowned_persistent_release,
	'lock literals and result metadata retain SQL semantics' => '1' === $escaped_name && "GET_LOCK('escaped\\nlock', 0)" === $escaped_column && '1' === $escaped_release,
	'release arity and timeout bounds fail explicitly' => false === $unsupported_release_arity->return_value() && false === $unsupported_timeout->return_value(),
	'a pre-opened waiter cannot split ownership onto an unlinked inode' => '1' === $race_owner && $worker_descriptor_open && '1' === $race_owner_release && $worker_acquired && '0' === $third_contended && 0 === $worker_status,
	'multisite prefixes share one logical connection lock owner' => '1' === $multisite_base && '1' === $multisite_site && '1' === $multisite_release_once && '0' === $external_contended && '1' === $multisite_release_final && '1' === $external_acquire,
);
$passed = ! in_array( false, $assertions, true );
fwrite( $passed ? STDOUT : STDERR, json_encode( array( 'assertions' => $assertions, 'passed' => $passed ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n" );
exit( $passed ? 0 : 1 );
