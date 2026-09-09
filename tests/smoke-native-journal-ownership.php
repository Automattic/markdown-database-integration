<?php
/** A journal is only recovered by a writer that can prove its owner is gone. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_native_journal_remove_tree( string $root ): void {
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

$root = sys_get_temp_dir() . '/mdi-native-journal-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
mkdir( $root . '/_options', 0755 );
$table = $root . '/_tables';
mkdir( $table, 0755 );
file_put_contents( $table . '/plugin_records.json', "[\n]\n" );

// A writer running a transaction owns its journal and holds it.
$live = new WP_Markdown_Native_Transaction_Journal( $root );
$live->begin();
$live->record( $table . '/plugin_records.json' );
file_put_contents( $table . '/plugin_records.json', '[{"changed":true}]' );

$journals = glob( $root . '/_journal/native-transaction-*.json' ) ?: array();

// Another writer starting up must leave that transaction alone.
$other = new WP_Markdown_Native_Transaction_Journal( $root );
$recovered_live = $other->recover();
$during = (string) file_get_contents( $table . '/plugin_records.json' );

// B was constructed while A held the journal. Once A crashes, B must recover
// before publishing its own write, so a later cold recovery cannot erase B.
unset( $live );
gc_collect_cycles();
$recovered_before_write = $other->begin_write();
$after_recovery = (string) file_get_contents( $table . '/plugin_records.json' );
file_put_contents( $table . '/plugin_records.json', '[{"survived":true}]' );
$other->finish_write();
$cold = new WP_Markdown_Native_Transaction_Journal( $root );
$cold_recovery = $cold->recover();
$after_cold_recovery = (string) file_get_contents( $table . '/plugin_records.json' );

// A journal whose owner is gone holds no lock, so it is recovered.
file_put_contents( $table . '/plugin_records.json', '[{"abandoned":true}]' );
file_put_contents(
	$root . '/_journal/native-transaction-deadbeefdeadbeef.json',
	json_encode( array( array( 'path' => $table . '/plugin_records.json', 'existed' => true, 'contents' => base64_encode( '[{"restored":true}]' ) ) ) )
);
$survivor = new WP_Markdown_Native_Transaction_Journal( $root );
$recovered_dead = $survivor->recover();
$after_explicit_recovery = (string) file_get_contents( $table . '/plugin_records.json' );

// A failed abandoned-journal restore while this writer owns the root lock must
// be retried before a later mutation can be admitted.
$retry_root = $root . '/retry';
mkdir( $retry_root, 0755 );
$retry = new WP_Markdown_Native_Transaction_Journal( $root );
$retry->begin();
file_put_contents(
	$root . '/_journal/native-transaction-retrydeadbeef.json',
	json_encode( array( array( 'path' => $retry_root . '/missing/value.json', 'existed' => true, 'contents' => base64_encode( '{"restored":true}' ) ) ) )
);
$first_retry = $retry->begin_write();
$second_retry = $retry->begin_write();
mkdir( $retry_root . '/missing', 0755 );
$third_retry = $retry->begin_write();
$retry->rollback();

// Root lock files must never follow aliases or use a multi-link inode.
$lock_path = $root . '/_journal/native-transaction--write.lock';
$lock_target = $root . '/lock-target';
file_put_contents( $lock_target, 'lock' );
$hardlinked = @link( $lock_target, $lock_path );
$unsafe_lock = $hardlinked ? ( new WP_Markdown_Native_Transaction_Journal( $root ) )->begin_write() : null;
@unlink( $lock_path );
$fifo_lock = function_exists( 'posix_mkfifo' ) && @posix_mkfifo( $lock_path, 0600 );
$unsafe_fifo_lock = $fifo_lock ? ( new WP_Markdown_Native_Transaction_Journal( $root ) )->begin_write() : null;

$checks = array(
	'a running transaction writes an owned journal' => 1 === count( $journals ),
	'another writer does not recover a held journal' => false === $recovered_live,
	'a held transaction is left in place' => '[{"changed":true}]' === $during,
	'writer B recovers A after A crashes despite its earlier startup miss' => true === $recovered_before_write && "[\n]\n" === $after_recovery,
	'a cold recovery cannot erase B after abandoned-owner recovery' => false === $cold_recovery && '[{"survived":true}]' === $after_cold_recovery,
	'an abandoned journal is recovered' => true === $recovered_dead,
	'an abandoned transaction is rolled back' => '[{"restored":true}]' === $after_explicit_recovery,
	'failed recovery blocks repeated active writes' => true !== $first_retry && true !== $second_retry,
	'active writer retries recovery before later admission' => true === $third_retry && is_file( $retry_root . '/missing/value.json' ),
	'hardlinked root lock is rejected' => ! $hardlinked || true !== $unsafe_lock,
	'nonregular root lock is rejected' => ! $fifo_lock || true !== $unsafe_fifo_lock,
);

mdi_native_journal_remove_tree( $root );

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
