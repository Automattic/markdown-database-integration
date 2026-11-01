<?php
/**
 * Smoke test for content-format import/export conversion.
 *
 * Usage: php tests/smoke-bfb-import-export-conversion.php
 *
 * @package Markdown_Database_Integration
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$GLOBALS['mdi_format_filters'] = array();
$GLOBALS['mdi_format_actions'] = array();
$GLOBALS['mdi_format_fail']    = false;

function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['mdi_format_filters'][ $hook_name ][] = array( $callback, $priority, $accepted_args );
}

function apply_filters( string $hook_name, $value, ...$args ) {
	$callbacks = $GLOBALS['mdi_format_filters'][ $hook_name ] ?? array();
	usort(
		$callbacks,
		static fn( array $a, array $b ): int => $a[1] <=> $b[1]
	);

	foreach ( $callbacks as $callback ) {
		$accepted = max( 1, (int) $callback[2] );
		$value    = call_user_func_array( $callback[0], array_slice( array_merge( array( $value ), $args ), 0, $accepted ) );
	}

	return $value;
}

function blocks_engine_php_transformer_convert_format( string $content, string $from, string $to, array $context ): array {
	return array(
		'status' => 'success',
		'serialized_blocks' => "[blocks-engine:{$from}:{$to}]{$content}",
	);
}

function do_action( string $hook_name, ...$args ): void {
	$GLOBALS['mdi_format_actions'][] = array( $hook_name, $args );
}

function sanitize_key( $key ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
}

require dirname( __DIR__ ) . '/inc/class-wp-markdown-cli.php';

$failures = array();

// Storage runtime must not depend on specific consumer plugins.
$runtime_files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/inc', FilesystemIterator::SKIP_DOTS )
);
foreach ( $runtime_files as $runtime_file ) {
	if ( ! $runtime_file->isFile() || in_array( 'vendor', explode( DIRECTORY_SEPARATOR, $runtime_file->getPathname() ), true ) ) {
		continue;
	}
	if ( preg_match( '/datamachine|intelligence_wiki/i', file_get_contents( $runtime_file->getPathname() ) ) ) {
		$failures[] = 'consumer plugin reference in runtime: ' . $runtime_file->getPathname();
	}
}

$method   = new ReflectionMethod( WP_Markdown_CLI::class, 'apply_transform_filter' );
if ( PHP_VERSION_ID < 80100 ) {
	$method->setAccessible( true );
}

$source_post = (object) array(
	'post_type' => 'page',
);

add_filter(
	'markdown_db_content_format_convert',
	static function ( $converted, string $content, string $from, string $to ) {
		if ( $GLOBALS['mdi_format_fail'] ) {
			return new WP_Error( 'transformer_failed', 'Content format conversion failed.' );
		}

		return "[{$from}:{$to}]{$content}";
	},
	10,
	5
);

$imported = $method->invoke(
	null,
	'markdown_db_import_post_content',
	'# Hello',
	array( 'operation' => 'import', 'post_type' => 'page' ),
	array( 'post_type' => 'page' ),
	$source_post
);

if ( '[markdown:blocks]# Hello' !== $imported ) {
	$failures[] = 'import did not convert markdown to blocks when a content-format converter is available';
}

$exported = $method->invoke(
	null,
	'markdown_db_export_post_content',
	'<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
	array( 'operation' => 'export', 'post_type' => 'page' ),
	(object) array( 'post_type' => 'page' ),
	$source_post
);

if ( '[blocks:markdown]<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' !== $exported ) {
	$failures[] = 'export did not convert blocks to markdown when a content-format converter is available';
}

$context_disabled = $method->invoke(
	null,
	'markdown_db_import_post_content',
	'# Raw',
	array( 'operation' => 'import', 'post_type' => 'page', 'conversion' => array( 'enabled' => false ) ),
	array( 'post_type' => 'page' ),
	$source_post
);

if ( '# Raw' !== $context_disabled ) {
	$failures[] = 'context conversion policy did not disable content-format conversion';
}

add_filter(
	'markdown_db_content_format_conversion',
	static function ( array $conversion ): array {
		$conversion['enabled'] = false;
		return $conversion;
	}
);

$disabled = $method->invoke(
	null,
	'markdown_db_import_post_content',
	'# Unchanged',
	array( 'operation' => 'import', 'post_type' => 'page' ),
	array( 'post_type' => 'page' ),
	$source_post
);

if ( '# Unchanged' !== $disabled ) {
	$failures[] = 'conversion policy filter did not disable content-format conversion';
}

$GLOBALS['mdi_format_filters']['markdown_db_content_format_conversion'] = array();
$GLOBALS['mdi_format_fail'] = true;
$failed_conversion       = $method->invoke(
	null,
	'markdown_db_import_post_content',
	'# Original',
	array( 'operation' => 'import', 'post_type' => 'page' ),
	array( 'post_type' => 'page' ),
	$source_post
);

if ( ! is_wp_error( $failed_conversion ) ) {
	$failures[] = 'failed content-format conversion did not return an error';
}

if ( 'markdown_db_content_format_conversion_failed' !== ( $GLOBALS['mdi_format_actions'][0][0] ?? '' ) ) {
	$failures[] = 'failed content-format conversion did not emit diagnostic action';
}

$GLOBALS['mdi_format_filters']['markdown_db_content_format_convert'] = array();
add_filter( 'markdown_db_content_format_convert', static fn( $converted ) => null );
add_filter(
	'datamachine_content_format_convert',
	static function () {
		throw new RuntimeException( 'Consumer-owned conversion hook must not be called.' );
	}
);
$fallback = $method->invoke(
	null,
	'markdown_db_import_post_content',
	'# Fallback',
	array( 'operation' => 'import', 'post_type' => 'page' ),
	array( 'post_type' => 'page' ),
	$source_post
);
if ( '[blocks-engine:markdown:blocks]# Fallback' !== $fallback ) {
	$failures[] = 'null converter result did not fall back to Blocks Engine';
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo 'PASS: import/export content conversion is self-contained and policy-controlled' . PHP_EOL;
