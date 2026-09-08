<?php
/** Run the native multisite acceptance probe in an isolated WP Codebox runtime. */

declare( strict_types=1 );

require_once __DIR__ . '/lib-native-lifecycle-fixture.php';

function mdi_native_multisite_runner_remove_tree( string $root ): void {
	if ( ! is_dir( $root ) ) {
		return;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $entry ) {
		$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $root );
}

$repo = realpath( dirname( __DIR__ ) );
if ( false === $repo ) {
	fwrite( STDERR, "Unable to resolve the MDI checkout.\n" );
	exit( 2 );
}

$root = sys_get_temp_dir() . '/mdi-native-multisite-' . bin2hex( random_bytes( 6 ) );
$state = $root . '/state';
$bootstrap = $root . '/bootstrap-wp-content';
$artifacts = $root . '/artifacts';
mkdir( $state . '/_options', 0755, true );
mkdir( $state . '/_tables', 0755, true );
mkdir( $bootstrap . '/plugins', 0755, true );
copy( $repo . '/db.php', $bootstrap . '/db.php' );
mdi_native_lifecycle_seed_options( $state );
mdi_native_lifecycle_seed_administrator( $state );
// Multisite boot resolves the current network before probe code runs. These
// are canonical network records, not a substitute for the site-two lifecycle
// that the probe creates through WordPress APIs.
file_put_contents( $state . '/_tables/site.json', json_encode( array( array( 'id' => '1', 'domain' => 'example.com', 'path' => '/' ) ), JSON_THROW_ON_ERROR ) );
file_put_contents( $state . '/_tables/blogs.json', json_encode( array( array( 'blog_id' => '1', 'site_id' => '1', 'domain' => 'example.com', 'path' => '/', 'registered' => '2026-01-01 00:00:00', 'last_updated' => '2026-01-01 00:00:00', 'public' => '1', 'archived' => '0', 'mature' => '0', 'spam' => '0', 'deleted' => '0', 'lang_id' => '0' ) ), JSON_THROW_ON_ERROR ) );

$recipe = array(
	'schema' => 'wp-codebox/workspace-recipe/v1',
	'runtime' => array(
		'backend' => 'wordpress-playground',
		'wp' => '7.1',
		'phpVersion' => '8.3',
		'databaseSetup' => 'custom-drop-in',
		'blueprint' => array(
			'preferredVersions' => array( 'php' => '8.3', 'wp' => '7.1' ),
			'steps' => array( array( 'step' => 'defineWpConfigConsts', 'consts' => array(
				'MARKDOWN_DB_BACKEND' => 'mdi-native',
				'MARKDOWN_DB_STATE_DIR' => '/wordpress/wp-content/db',
				'MARKDOWN_DB_CONTENT_DIR' => '/wordpress/wp-content/db',
				'MULTISITE' => true,
				'SUBDOMAIN_INSTALL' => false,
				'DOMAIN_CURRENT_SITE' => 'example.com',
				'PATH_CURRENT_SITE' => '/',
				'SITE_ID_CURRENT_SITE' => 1,
				'BLOG_ID_CURRENT_SITE' => 1,
				'WP_DEBUG' => true,
				'WP_DEBUG_DISPLAY' => false,
			) ) ),
		),
	),
	'inputs' => array( 'mounts' => array(
		array( 'type' => 'directory', 'source' => $bootstrap, 'target' => '/wordpress/wp-content', 'mode' => 'readonly', 'phase' => 'pre-install' ),
		array( 'type' => 'directory', 'source' => $repo, 'target' => '/wordpress/wp-content/plugins/markdown-database-integration', 'mode' => 'readonly', 'phase' => 'pre-install' ),
		array( 'type' => 'directory', 'source' => $state, 'target' => '/wordpress/wp-content/db', 'mode' => 'readwrite', 'phase' => 'pre-install' ),
	) ),
	'workflow' => array( 'steps' => array( array( 'command' => 'wordpress.run-php', 'args' => array( 'code-file=' . $repo . '/tests/probe-native-multisite-wordpress.php' ) ) ) ),
	'artifacts' => array( 'directory' => $artifacts ),
	'metadata' => array( 'purpose' => 'Native WordPress multisite creation, switching, persistence, and reload' ),
);

$recipe_path = $root . '/recipe.json';
file_put_contents( $recipe_path, json_encode( $recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
$wp_codebox = (string) ( getenv( 'MDI_WP_CODEBOX_BIN' ) ?: 'wp-codebox' );
$command = escapeshellarg( $wp_codebox ) . ' recipe-run --recipe ' . escapeshellarg( $recipe_path ) . ' --timeout 20m --json';
$runs = array();
foreach ( array( 'install_and_switch', 'cold_reload' ) as $phase ) {
	$output = array();
	exec( $command, $output, $status );
	$run = json_decode( implode( "\n", $output ), true );
	$report = is_array( $run ) ? json_decode( (string) ( $run['executions'][0]['stdout'] ?? $run['stepFailures'][0]['stdout'] ?? '' ), true ) : null;
	$runs[ $phase ] = array( 'status' => $status, 'report' => $report, 'output' => $output );
}
$passed = array_reduce( $runs, static fn( bool $passed, array $run ): bool => $passed && 0 === $run['status'] && is_array( $run['report'] ) && true === ( $run['report']['passed'] ?? false ), true );
$candidate = (string) getenv( 'MDI_CANDIDATE_SHA' );
if ( '' === $candidate ) {
	$candidate = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $repo ) . ' rev-parse HEAD' ) );
}
$summary = array( 'schema' => 'mdi-native-multisite-wordpress-run/v1', 'candidate' => $candidate, 'passed' => $passed, 'runs' => array_map( static fn( array $run ): ?array => $run['report'], $runs ) );
fwrite( $passed ? STDOUT : STDERR, json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
if ( ! $passed ) {
	foreach ( $runs as $run ) {
		fwrite( STDERR, implode( "\n", $run['output'] ) . "\n" );
	}
}
if ( $passed && '1' !== getenv( 'MDI_KEEP_MULTISITE_ARTIFACTS' ) ) {
	mdi_native_multisite_runner_remove_tree( $root );
} else {
	fwrite( STDERR, "Multisite artifacts: {$root}\n" );
}
exit( $passed ? 0 : 1 );
