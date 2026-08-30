<?php
/** Prove warm bootstrap never executes the synchronization owner inline. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MARKDOWN_DB_MODE', 'primary' );

class WP_SQLite_DB {
	public string $dbname = '';
}

require_once __DIR__ . '/../inc/class-wp-markdown-loader.php';
require_once __DIR__ . '/../inc/class-wp-markdown-db.php';

class MDI_Bounded_Warm_Loader extends WP_Markdown_Loader {
	public int $retained = 0;
	public int $synchronized = 0;

	public function retain_previous_index( string $reason ): WP_Markdown_Loader_Outcome {
		++$this->retained;
		return WP_Markdown_Loader_Outcome::retained( $reason );
	}

	public function sync_incremental_if_available( string $runtime_identity, ?callable $before_sync = null ): bool {
		++$this->synchronized;
		if ( null !== $before_sync ) { $before_sync(); }
		return true;
	}
}

$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . PHP_EOL;
	if ( ! $condition ) { $failures[] = $message; }
};

$database = ( new ReflectionClass( WP_Markdown_DB::class ) )->newInstanceWithoutConstructor();
$loader   = ( new ReflectionClass( MDI_Bounded_Warm_Loader::class ) )->newInstanceWithoutConstructor();

foreach ( array(
	'loader'                   => $loader,
	'backend_capabilities'     => WP_Markdown_Backend_Capabilities::sqlite(),
	'primary_runtime_identity' => '/tmp/markdown-index.sqlite',
) as $property => $value ) {
	$reflection = new ReflectionProperty( WP_Markdown_DB::class, $property );
	$reflection->setValue( $database, $value );
}

$database->dbname = 'wordpress';

$run = new ReflectionMethod( WP_Markdown_DB::class, 'run_primary_loader_action' );
$run->invoke( $database, 'sync_incremental' );

$assert( 1 === $loader->retained, 'warm bootstrap retains the complete index' );
$assert( 0 === $loader->synchronized, 'warm bootstrap does not execute synchronization inline' );
$assert( true === $database->synchronize_primary_index(), 'explicit maintenance acquires synchronization ownership' );
$assert( 1 === $loader->synchronized, 'synchronization runs only from the explicit boundary' );

exit( $failures ? 1 : 0 );
