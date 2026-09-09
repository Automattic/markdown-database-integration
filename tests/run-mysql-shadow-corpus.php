<?php
/** Run a managed MySQL PHPUnit corpus while mysql-full shadows its queries. */

declare( strict_types=1 );

$repo = realpath( dirname( __DIR__ ) );
$plugin_dir = realpath( (string) getenv( 'MDI_SHADOW_PLUGIN_DIR' ) );
$plugin_slug = trim( (string) getenv( 'MDI_SHADOW_PLUGIN_SLUG' ) );
$phpunit_args = json_decode( (string) getenv( 'MDI_SHADOW_PHPUNIT_ARGS_JSON' ), true );
$dependency_dirs = json_decode( (string) ( getenv( 'MDI_SHADOW_DEPENDENCY_DIRS_JSON' ) ?: '{}' ), true );
$harness_dir = getenv( 'MDI_SHADOW_HARNESS_DIR' );

if ( false === $repo || false === $plugin_dir || '' === $plugin_slug || ! is_array( $phpunit_args ) || array_filter( $phpunit_args, 'is_string' ) !== $phpunit_args || ! is_array( $dependency_dirs ) ) {
	fwrite( STDERR, "Usage: MDI_SHADOW_PLUGIN_DIR=/path/to/plugin MDI_SHADOW_PLUGIN_SLUG=plugin MDI_SHADOW_PHPUNIT_ARGS_JSON='[\"test-file=tests/Test.php\"]' [MDI_SHADOW_DEPENDENCY_DIRS_JSON='{\"dependency\":\"/path/to/dependency\"}'] php tests/run-mysql-shadow-corpus.php\n" );
	exit( 2 );
}
if ( false !== $harness_dir && false === realpath( $harness_dir ) ) {
	fwrite( STDERR, "MDI_SHADOW_HARNESS_DIR must name an existing wp-phpunit vendor directory.\n" );
	exit( 2 );
}

$wp_codebox = (string) ( getenv( 'MDI_WP_CODEBOX_BIN' ) ?: 'wp-codebox' );
$root = sys_get_temp_dir() . '/mdi-mysql-shadow-corpus-' . bin2hex( random_bytes( 6 ) );
$bootstrap = $root . '/bootstrap-wp-content';
$state = $root . '/state';
$artifacts = $root . '/artifacts';
$report_path = '/tmp/mdi-shadow-report.json';
$report_name = 'mdi-shadow-report';
$revision = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $repo ) . ' rev-parse HEAD' ) );
mkdir( $bootstrap, 0755, true );
mkdir( $state, 0755, true );
copy( $repo . '/db.php', $bootstrap . '/db.php' );

$mounts = array(
	array( 'type' => 'directory', 'source' => $bootstrap, 'target' => '/wordpress/wp-content', 'mode' => 'readwrite', 'phase' => 'pre-install' ),
	array( 'type' => 'directory', 'source' => $repo, 'target' => '/wordpress/wp-content/plugins/markdown-database-integration', 'mode' => 'readonly', 'phase' => 'pre-install' ),
	array( 'type' => 'directory', 'source' => $plugin_dir, 'target' => '/wordpress/wp-content/plugins/' . $plugin_slug, 'mode' => 'readonly', 'phase' => 'pre-install' ),
);
if ( false !== $harness_dir ) {
	$mounts[] = array( 'type' => 'directory', 'source' => realpath( $harness_dir ), 'target' => '/wordpress/wp-content/mdi-shadow-phpunit', 'mode' => 'readonly', 'phase' => 'pre-install' );
}
$dependency_mounts = array();
foreach ( $dependency_dirs as $slug => $directory ) {
	$source = is_string( $directory ) ? realpath( $directory ) : false;
	if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) || false === $source ) {
		fwrite( STDERR, "Each dependency must map a plugin slug to an existing directory.\n" );
		exit( 2 );
	}
	$target = '/wordpress/wp-content/plugins/' . $slug;
	$mounts[] = array( 'type' => 'directory', 'source' => $source, 'target' => $target, 'mode' => 'readonly', 'phase' => 'pre-install' );
	$dependency_mounts[] = $target;
}

$recipe = array(
	'schema' => 'wp-codebox/workspace-recipe/v1',
	'runtime' => array(
		'backend' => 'wordpress-playground',
		'wp' => '7.1',
		'phpVersion' => '8.3',
		'blueprint' => array(
			'preferredVersions' => array( 'php' => '8.3', 'wp' => '7.1' ),
			'steps' => array( array( 'step' => 'defineWpConfigConsts', 'consts' => array(
				'WP_DEBUG' => true,
				'WP_DEBUG_DISPLAY' => false,
			) ) ),
		),
	),
	'inputs' => array(
		'mounts' => $mounts,
		'runtimeEnv' => array(
			'MARKDOWN_DB_BACKEND' => 'mysql-full',
			'MARKDOWN_DB_NATIVE_SHADOW' => 'true',
			'MARKDOWN_DB_NATIVE_SHADOW_MAX' => '10000',
			'MARKDOWN_DB_NATIVE_SHADOW_INPUT_MODE' => 'sql_snapshot',
			'MARKDOWN_DB_NATIVE_SHADOW_REPORT_PATH' => $report_path,
		),
		'services' => array( array(
			'id' => 'mysql',
			'kind' => 'mysql',
			'configuration' => array( 'rootAuthentication' => 'empty-password' ),
			'outputs' => array( 'host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USER', 'password' => 'DB_PASSWORD', 'database' => 'DB_NAME' ),
		) ),
	),
	'workflow' => array( 'steps' => array(
		array(
			'command' => 'wordpress.phpunit',
			'args' => array_merge( array( 'plugin-slug=' . $plugin_slug, 'database-type=mysql', 'multisite=1' ), false === $harness_dir ? array() : array( 'autoload-file=/wordpress/wp-content/mdi-shadow-phpunit/autoload.php', 'tests-dir=/wordpress/wp-content/mdi-shadow-phpunit/wp-phpunit/wp-phpunit' ), array() === $dependency_mounts ? array() : array( 'dependency-mounts=' . implode( ',', $dependency_mounts ) ), $phpunit_args ),
			'resultPaths' => array( array( 'name' => $report_name, 'type' => 'mdi-native-shadow-report/v1', 'path' => $report_path, 'required' => true, 'maxBytes' => 1048576 ) ),
		),
	) ),
	'artifacts' => array( 'directory' => $artifacts ),
	'metadata' => array( 'purpose' => 'Authoritative MySQL corpus with mdi-native shadow verification' ),
);

$recipe_path = $root . '/recipe.json';
file_put_contents( $recipe_path, json_encode( $recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
$command = escapeshellarg( $wp_codebox ) . ' recipe-run --recipe ' . escapeshellarg( $recipe_path ) . ' --timeout 30m --json';
$output = array();
exec( $command, $output, $status );
$run = json_decode( implode( "\n", $output ), true );
$executions = is_array( $run ) && is_array( $run['executions'] ?? null ) ? $run['executions'] : array();
$phpunit = array_values( array_filter( $executions, static fn( array $execution ): bool => 'wordpress.phpunit' === ( $execution['command'] ?? null ) ) );
$shadow = null;
$report_artifact = null;
foreach ( $phpunit as $execution ) {
	foreach ( (array) ( $execution['artifactRefs'] ?? array() ) as $artifact ) {
		if ( $report_name === ( $artifact['id'] ?? null ) && 'mdi-native-shadow-report/v1' === ( $artifact['kind'] ?? null ) && is_string( $artifact['path'] ?? null ) ) {
			$report_artifact = $artifact;
			break 2;
		}
	}
}
if ( is_array( $report_artifact ) ) {
	$matches = glob( $artifacts . '/*/' . ltrim( $report_artifact['path'], '/' ) );
	if ( 1 === count( $matches ) && is_file( $matches[0] ) ) {
		$shadow = json_decode( (string) file_get_contents( $matches[0] ), true );
	}
}
// A failed PHPUnit command has no execution envelope, but f898+ still writes
// its same-command result artifact beneath the configured artifact directory.
if ( ! is_array( $shadow ) ) {
	$matches = glob( $artifacts . '/*/files/command-results/' . $report_name . '.json' );
	if ( 1 === count( $matches ) && is_file( $matches[0] ) ) {
		$shadow = json_decode( (string) file_get_contents( $matches[0] ), true );
	}
}
if ( ! is_array( $shadow ) || 'mdi-native-shadow-report/v1' !== ( $shadow['schema'] ?? null ) || 0 === (int) ( $shadow['observed'] ?? 0 ) ) {
	fwrite( STDERR, "Shadow report was absent or empty. Artifacts: {$root}\n" );
	exit( 1 );
}

$result = array(
	'schema' => 'mdi-mysql-shadow-corpus/v1',
	'phpunit' => $phpunit,
	'shadow' => $shadow,
	'shadow_artifact' => $report_artifact,
	'provenance' => array( 'source_revision' => $revision, 'authoritative_backend' => 'mysql-full', 'shadow_backend' => 'mdi-native', 'report_path' => $report_path ),
);
fwrite( 0 === $status ? STDOUT : STDERR, json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
fwrite( STDERR, "Shadow artifacts: {$root}\n" );
exit( 0 === $status ? 0 : 1 );
