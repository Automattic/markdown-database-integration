<?php
/** Run the native dbDelta probe in an isolated WordPress runtime. */

declare( strict_types=1 );

require_once __DIR__ . '/lib-native-lifecycle-fixture.php';

$repo = realpath( dirname( __DIR__ ) );
if ( false === $repo ) {
	fwrite( STDERR, "Unable to resolve the MDI checkout.\n" );
	exit( 2 );
}

$root = sys_get_temp_dir() . '/mdi-native-dbdelta-' . bin2hex( random_bytes( 6 ) );
$state = $root . '/state';
$artifacts = $root . '/artifacts';
$bootstrap_content = $root . '/bootstrap-wp-content';
mkdir( $state . '/_options', 0755, true );
mkdir( $state . '/_tables', 0755, true );
mkdir( $bootstrap_content . '/plugins', 0755, true );
copy( $repo . '/db.php', $bootstrap_content . '/db.php' );
mdi_native_lifecycle_seed_options( $state );
mdi_native_lifecycle_seed_administrator( $state );

$recipe = array(
	'schema' => 'wp-codebox/workspace-recipe/v1',
	'runtime' => array(
		'backend' => 'wordpress-playground',
		'wp' => '7.1',
		'phpVersion' => '8.3',
		'databaseSetup' => 'custom-drop-in',
		'blueprint' => array( 'preferredVersions' => array( 'php' => '8.3', 'wp' => '7.1' ) ),
	),
	'inputs' => array(
		'mounts' => array(
			array( 'type' => 'directory', 'source' => $bootstrap_content, 'target' => '/wordpress/wp-content', 'mode' => 'readonly', 'phase' => 'pre-install' ),
			array( 'type' => 'directory', 'source' => $repo, 'target' => '/wordpress/wp-content/plugins/markdown-database-integration', 'mode' => 'readonly', 'phase' => 'pre-install' ),
			array( 'type' => 'directory', 'source' => $state, 'target' => '/wordpress/wp-content/db', 'mode' => 'readwrite', 'phase' => 'pre-install' ),
		),
	),
	'workflow' => array( 'steps' => array( array( 'command' => 'wordpress.run-php', 'args' => array( 'code-file=' . $repo . '/tests/probe-native-dbdelta.php' ) ) ) ),
	'artifacts' => array( 'directory' => $artifacts ),
);
$recipe_path = $root . '/recipe.json';
file_put_contents( $recipe_path, json_encode( $recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );

$wp_codebox = (string) ( getenv( 'MDI_WP_CODEBOX_BIN' ) ?: 'wp-codebox' );
$output = array();
exec( escapeshellarg( $wp_codebox ) . ' recipe-run --recipe ' . escapeshellarg( $recipe_path ) . ' --timeout 20m --json', $output, $status );
$run = json_decode( implode( "\n", $output ), true );
$probe = is_array( $run ) ? json_decode( (string) ( $run['executions'][0]['stdout'] ?? '' ), true ) : null;
if ( ! is_array( $probe ) || 'mdi-native-dbdelta-probe/v1' !== ( $probe['schema'] ?? null ) ) {
	fwrite( STDERR, "dbDelta probe did not report a result.\n" . implode( "\n", $output ) . "\n" );
	$status = 1;
} else {
	fwrite( STDOUT, json_encode( $probe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
}

$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $entries as $entry ) {
	$entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
}
rmdir( $root );
exit( $status );
