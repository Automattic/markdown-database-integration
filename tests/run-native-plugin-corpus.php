<?php
/** Activate a real plugin corpus against mdi-native in a disposable WP Codebox runtime. */

declare( strict_types=1 );

require_once __DIR__ . '/lib-native-lifecycle-fixture.php';

$repo = realpath( dirname( __DIR__ ) );
$plugins_root = realpath( (string) getenv( 'MDI_PLUGINS_DIR' ) );
$corpus = array_values(
	array_filter(
		array_map( 'trim', explode( ',', (string) getenv( 'MDI_PLUGIN_CORPUS' ) ) ),
		static fn( string $slug ): bool => '' !== $slug
	)
);

if ( false === $repo || false === $plugins_root || array() === $corpus ) {
	fwrite(
		STDERR,
		"Usage: MDI_PLUGINS_DIR=/path/to/wp-content/plugins MDI_PLUGIN_CORPUS=slug-a,slug-b php tests/run-native-plugin-corpus.php\n"
	);
	exit( 2 );
}

$root = sys_get_temp_dir() . '/mdi-native-corpus-' . bin2hex( random_bytes( 6 ) );
$state = $root . '/state';
$artifacts = $root . '/artifacts';
$bootstrap_content = $root . '/bootstrap-wp-content';
mkdir( $state . '/_options', 0755, true );
mkdir( $state . '/_tables', 0755, true );
mkdir( $bootstrap_content . '/plugins', 0755, true );
copy( $repo . '/db.php', $bootstrap_content . '/db.php' );
mdi_native_lifecycle_seed_options( $state );

$mounts = array(
	array( 'type' => 'directory', 'source' => $bootstrap_content, 'target' => '/wordpress/wp-content', 'mode' => 'readonly', 'phase' => 'pre-install' ),
	array( 'type' => 'directory', 'source' => $repo, 'target' => '/wordpress/wp-content/plugins/markdown-database-integration', 'mode' => 'readonly', 'phase' => 'pre-install' ),
	array( 'type' => 'directory', 'source' => $state, 'target' => '/wordpress/wp-content/markdown', 'mode' => 'readwrite', 'phase' => 'pre-install' ),
);
$mounted = array();
foreach ( $corpus as $slug ) {
	$source = realpath( $plugins_root . '/' . $slug );
	if ( false === $source || ! is_dir( $source ) ) {
		fwrite( STDERR, sprintf( "Plugin %s was not found under %s.\n", $slug, $plugins_root ) );
		exit( 2 );
	}
	if ( 'markdown-database-integration' === $slug ) {
		continue;
	}
	$mounted[] = $slug;
	$mounts[] = array(
		'type'   => 'directory',
		'source' => $source,
		'target' => '/wordpress/wp-content/plugins/' . $slug,
		'mode'   => 'readonly',
	);
}

$recipe = array(
	'schema' => 'wp-codebox/workspace-recipe/v1',
	'runtime' => array(
		'backend' => 'wordpress-playground',
		'wp' => '7.1',
		'phpVersion' => '8.3',
		'databaseSetup' => 'custom-drop-in',
		'blueprint' => array(
			'preferredVersions' => array( 'php' => '8.3', 'wp' => '7.1' ),
			'steps' => array(
				array(
					'step' => 'defineWpConfigConsts',
					'consts' => array(
						'MARKDOWN_DB_BACKEND' => 'mdi-native',
						'MARKDOWN_DB_STATE_DIR' => '/wordpress/wp-content/markdown',
						'MARKDOWN_DB_CONTENT_DIR' => '/wordpress/wp-content/markdown',
						'SAVEQUERIES' => true,
						'WP_DEBUG' => true,
						'WP_DEBUG_LOG' => true,
						'WP_DEBUG_DISPLAY' => false,
					),
				),
			),
		),
	),
	'inputs' => array( 'mounts' => $mounts ),
	'workflow' => array(
		'steps' => array(
			array(
				'command' => 'wordpress.run-php',
				'args' => array(
					'code-file=' . $repo . '/tests/probe-native-plugin-corpus.php',
					'env-json={"MDI_PLUGIN_CORPUS":"' . implode( ',', $mounted ) . '"}',
				),
			),
		),
	),
	'artifacts' => array( 'directory' => $artifacts ),
	'metadata' => array( 'purpose' => 'Activate a real plugin corpus through mdi-native canonical state' ),
);

$recipe_path = $root . '/recipe.json';
file_put_contents( $recipe_path, json_encode( $recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );

$wp_codebox = (string) ( getenv( 'MDI_WP_CODEBOX_BIN' ) ?: 'wp-codebox' );
$command = escapeshellarg( $wp_codebox ) . ' recipe-run --recipe ' . escapeshellarg( $recipe_path ) . ' --timeout 20m --json';
$output = array();
exec( $command, $output, $status );
$output_json = implode( "\n", $output );
$run = json_decode( $output_json, true );
$corpus_result = is_array( $run )
	? json_decode( (string) ( $run['executions'][0]['stdout'] ?? $run['stepFailures'][0]['stdout'] ?? '' ), true )
	: null;

if ( ! is_array( $corpus_result ) ) {
	fwrite( STDERR, $output_json . "\n" );
	fwrite( STDERR, "Corpus command did not return a structured result.\n" );
	fwrite( STDERR, "Corpus artifacts: {$root}\n" );
	exit( 1 );
}

fwrite(
	true === ( $corpus_result['passed'] ?? false ) ? STDOUT : STDERR,
	json_encode( $corpus_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n"
);
fwrite( STDERR, "Corpus artifacts: {$root}\n" );
exit( true === ( $corpus_result['passed'] ?? false ) ? 0 : 1 );
