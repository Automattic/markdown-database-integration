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
function advisory_lock_value( WP_Markdown_Native_Query_Runtime $runtime, string $sql ): int|string|null {
	$result = $runtime->execute( new WP_Markdown_Query_Request( $sql ) );
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

$assertions = array(
	'acquisition returns a selected MySQL scalar' => '1' === $first_acquire,
	'the owning logical connection is reentrant' => '1' === $first_reentrant,
	'an independent runtime is excluded without waiting' => '0' === $second_contended,
	'one reentrant release retains ownership' => '1' === $first_release_once && '0' === $second_still_contended,
	'the final release transfers ownership' => '1' === $first_release_final && '1' === $second_acquire,
	'close releases all locks owned by its runtime' => '1' === $first_after_close,
	'unknown release has MySQL null shape' => null === $unknown_release,
);
$passed = ! in_array( false, $assertions, true );
fwrite( $passed ? STDOUT : STDERR, json_encode( array( 'assertions' => $assertions, 'passed' => $passed ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n" );
exit( $passed ? 0 : 1 );
