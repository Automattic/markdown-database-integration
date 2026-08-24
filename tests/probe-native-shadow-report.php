<?php
/** Emit the bounded sanitized mdi-native shadow report after WordPress bootstrap. */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "SKIP: requires WordPress bootstrap.\n" );
	exit( 0 );
}

$verifier = $GLOBALS['markdown_db_native_shadow_verifier'] ?? null;
if ( ! $verifier instanceof WP_Markdown_Native_Shadow_Verifier ) {
	fwrite( STDERR, "SKIP: define MARKDOWN_DB_NATIVE_SHADOW as true in a disposable runtime.\n" );
	exit( 0 );
}

$report = $verifier->report();
if ( 0 === (int) ( $report['observed'] ?? 0 ) ) {
	throw new RuntimeException( 'The native shadow verifier did not observe WordPress bootstrap queries.' );
}

echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
