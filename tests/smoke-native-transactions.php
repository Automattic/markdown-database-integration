<?php
/** Native transaction journal segment and durability proof. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';

function mdi_native_transactions_remove_tree( string $root ): void {
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $entries as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}

/** @return list<array{path:string,existed:bool,contents:?string}> */
function mdi_native_transactions_entries( string $root ): array {
	$journals = glob( $root . '/_journal/native-transaction-*.json' ) ?: array();
	if ( 1 !== count( $journals ) ) {
		return array();
	}
	$entries = json_decode( (string) file_get_contents( $journals[0] ), true );
	return is_array( $entries ) ? $entries : array();
}

function mdi_native_transactions_journal_inode( string $root ): int|false {
	$journals = glob( $root . '/_journal/native-transaction-*.json' ) ?: array();
	return 1 === count( $journals ) ? fileinode( $journals[0] ) : false;
}

$root = sys_get_temp_dir() . '/mdi-native-transactions-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0755 );
$path = $root . '/row.json';
file_put_contents( $path, 'initial' );

$journal = new WP_Markdown_Native_Transaction_Journal( $root );
$journal->begin();
$journal->record( $path );
file_put_contents( $path, 'outer' );
$outer_entries = mdi_native_transactions_entries( $root );
$outer_journal_inode = mdi_native_transactions_journal_inode( $root );
$journal->record( $path );
$duplicate_outer_entries = mdi_native_transactions_entries( $root );
$duplicate_outer_journal_inode = mdi_native_transactions_journal_inode( $root );

$journal->savepoint( 'first' );
$journal->record( $path );
file_put_contents( $path, 'first' );
$journal->record( $path );
$first_entries = mdi_native_transactions_entries( $root );

$journal->savepoint( 'second' );
$journal->record( $path );
file_put_contents( $path, 'second' );
$journal->record( $path );
$second_entries = mdi_native_transactions_entries( $root );
$journal->rollback_to( 'second' );
$after_second_rollback = (string) file_get_contents( $path );
$second_rollback_entries = mdi_native_transactions_entries( $root );

$journal->record( $path );
file_put_contents( $path, 'second-retry' );
$journal->release_savepoint( 'second' );
$journal->rollback_to( 'first' );
$after_first_rollback = (string) file_get_contents( $path );
$first_rollback_entries = mdi_native_transactions_entries( $root );
$journal->release_savepoint( 'first' );
$journal->rollback();
$after_transaction_rollback = (string) file_get_contents( $path );

$journal->set_autocommit( false );
$journal->record( $path );
file_put_contents( $path, 'implicit-one' );
$journal->commit();
$after_implicit_commit = (string) file_get_contents( $path );
$implicit_empty_entries = mdi_native_transactions_entries( $root );
$journal->record( $path );
file_put_contents( $path, 'implicit-two' );
$journal->rollback();
$after_implicit_rollback = (string) file_get_contents( $path );
$journal->set_autocommit( true );
$journals_after_autocommit = glob( $root . '/_journal/native-transaction-*.json' ) ?: array();

$checks = array(
	'a transaction records its initial pre-image' => 1 === count( $outer_entries ) && 'initial' === base64_decode( (string) $outer_entries[0]['contents'], true ),
	'repeated writes in one segment do not grow or republish the journal' => $outer_entries === $duplicate_outer_entries
		&& $outer_journal_inode === $duplicate_outer_journal_inode,
	'a savepoint records one new pre-image for its segment' => 2 === count( $first_entries ),
	'a nested savepoint records one new pre-image for its segment' => 3 === count( $second_entries ),
	'rollback to a nested savepoint restores its exact pre-image and slices entries' => 'first' === $after_second_rollback && 2 === count( $second_rollback_entries ),
	'rollback to an outer savepoint restores its exact pre-image and slices released work' => 'outer' === $after_first_rollback && 1 === count( $first_rollback_entries ),
	'a full rollback restores the transaction-start pre-image' => 'initial' === $after_transaction_rollback,
	'autocommit-disabled commit retains work and reopens an empty implicit transaction' => 'implicit-one' === $after_implicit_commit && array() === $implicit_empty_entries,
	'autocommit-disabled rollback restores the new implicit transaction pre-image' => 'implicit-one' === $after_implicit_rollback,
	'reenabling autocommit closes and clears the implicit transaction' => array() === $journals_after_autocommit,
);

mdi_native_transactions_remove_tree( $root );

$passed = ! in_array( false, $checks, true );
foreach ( $checks as $description => $result ) {
	fwrite( $passed ? STDOUT : STDERR, sprintf( "%s: %s\n", $result ? 'PASS' : 'FAIL', $description ) );
}
exit( $passed ? 0 : 1 );
