<?php
/**
 * Smoke test recovery ability/category hook registration order.
 *
 * Usage: php tests/smoke-sqlite-recovery-ability-hooks.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['mdi_actions']    = array();
$GLOBALS['mdi_categories'] = array();
$GLOBALS['mdi_abilities']  = array();

function add_action( string $hook, callable $callback ): void {
	$GLOBALS['mdi_actions'][ $hook ][] = $callback;
}

function doing_action( string $_hook ): bool {
	return false;
}

function did_action( string $_hook ): int {
	return 0;
}

function wp_register_ability_category( string $name, array $definition ): void {
	$GLOBALS['mdi_categories'][ $name ] = $definition;
}

function wp_has_ability_category( string $name ): bool {
	return isset( $GLOBALS['mdi_categories'][ $name ] );
}

function wp_register_ability( string $name, array $definition ): void {
	$GLOBALS['mdi_abilities'][ $name ] = $definition;
}

function wp_has_ability( string $name ): bool {
	return isset( $GLOBALS['mdi_abilities'][ $name ] );
}

require_once __DIR__ . '/../inc/class-wp-markdown-sqlite-recovery.php';

$passed = 0;
$failed = 0;

function mdi_assert( bool $condition, string $label ): void {
	global $passed, $failed;
	if ( $condition ) {
		echo "  ✓ {$label}\n";
		++$passed;
		return;
	}

	echo "  ✗ {$label}\n";
	++$failed;
}

echo "SQLite recovery ability hook smoke\n";

WP_Markdown_SQLite_Recovery::register();

mdi_assert( isset( $GLOBALS['mdi_actions']['wp_abilities_api_categories_init'] ), 'category callback is registered on category init hook' );
mdi_assert( isset( $GLOBALS['mdi_actions']['wp_abilities_api_init'] ), 'ability callback is registered on ability init hook' );

$GLOBALS['mdi_actions']['wp_abilities_api_categories_init'][0]();
$GLOBALS['mdi_actions']['wp_abilities_api_init'][0]();

mdi_assert( isset( $GLOBALS['mdi_categories']['markdown-db'] ), 'markdown-db category registered' );
mdi_assert( isset( $GLOBALS['mdi_abilities']['markdown-db/recover-sqlite-posts'] ), 'recovery ability registered' );
mdi_assert( 'markdown-db' === $GLOBALS['mdi_abilities']['markdown-db/recover-sqlite-posts']['category'], 'ability references registered category' );

if ( $failed > 0 ) {
	echo "FAILURES: {$failed}\n";
	exit( 1 );
}

echo "All tests passed ({$passed} assertions).\n";
