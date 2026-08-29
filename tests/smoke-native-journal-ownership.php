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

// The owner finishes and its work stands.
$live->commit();
$after_commit = (string) file_get_contents( $table . '/plugin_records.json' );
$journals_after = glob( $root . '/_journal/native-transaction-*.json' ) ?: array();

// A journal whose owner is gone holds no lock, so it is recovered.
file_put_contents( $table . '/plugin_records.json', '[{"abandoned":true}]' );
file_put_contents(
	$root . '/_journal/native-transaction-deadbeefdeadbeef.json',
	json_encode( array( array( 'path' => $table . '/plugin_records.json', 'existed' => true, 'contents' => base64_encode( '[{"restored":true}]' ) ) ) )
);
$survivor = new WP_Markdown_Native_Transaction_Journal( $root );
$recovered_dead = $survivor->recover();
$after_recovery = (string) file_get_contents( $table . '/plugin_records.json' );

$checks = array(
	'a running transaction writes an owned journal' => 1 === count( $journals ),
	'another writer does not recover a held journal' => false === $recovered_live,
	'a held transaction is left in place' => '[{"changed":true}]' === $during,
	'the owner keeps its work on commit' => '[{"changed":true}]' === $after_commit,
	'a committed journal is cleaned up' => array() === $journals_after,
	'an abandoned journal is recovered' => true === $recovered_dead,
	'an abandoned transaction is rolled back' => '[{"restored":true}]' === $after_recovery,
);

mdi_native_journal_remove_tree( $root );

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
