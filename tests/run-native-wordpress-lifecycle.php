<?php
/** Run a cold WordPress/WooCommerce lifecycle against mdi-native in WP Codebox. */

declare( strict_types=1 );

function mdi_native_lifecycle_remove_tree( string $root ): void {
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

function mdi_native_lifecycle_option( string $root, int $id, string $name, string $value, string $autoload = 'on' ): void {
	$encoded = json_encode(
		array(
			'option_id'    => $id,
			'option_name'  => $name,
			'option_value' => $value,
			'autoload'     => $autoload,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	);
	file_put_contents( $root . '/_options/' . $name . '.json', $encoded . "\n" );
}

/** @return array{run:array<string,mixed>|null,lifecycle:array<string,mixed>|null,output:string,status:int} */
function mdi_native_lifecycle_run( string $wp_codebox, string $recipe_path ): array {
	$command = escapeshellarg( $wp_codebox ) . ' recipe-run --recipe ' . escapeshellarg( $recipe_path ) . ' --timeout 10m --json';
	$output = array();
	exec( $command, $output, $status );
	$output_json = implode( "\n", $output );
	$run = json_decode( $output_json, true );
	$lifecycle = is_array( $run ) ? json_decode( (string) ( $run['executions'][0]['stdout'] ?? '' ), true ) : null;
	if ( 0 === $status && ( 'mdi-native-wordpress-lifecycle/v1' !== ( $lifecycle['schema'] ?? null ) || true !== ( $lifecycle['passed'] ?? false ) ) ) {
		$status = 1;
	}
	return array(
		'run'       => is_array( $run ) ? $run : null,
		'lifecycle' => is_array( $lifecycle ) ? $lifecycle : null,
		'output'    => $output_json,
		'status'    => $status,
	);
}

$repo = realpath( dirname( __DIR__ ) );
$woocommerce = realpath( (string) getenv( 'MDI_WOOCOMMERCE_DIR' ) );
if ( false === $repo || false === $woocommerce || ! is_file( $woocommerce . '/woocommerce.php' ) ) {
	fwrite( STDERR, "Usage: MDI_WOOCOMMERCE_DIR=/path/to/woocommerce php tests/run-native-wordpress-lifecycle.php\n" );
	exit( 2 );
}

$root = sys_get_temp_dir() . '/mdi-native-lifecycle-' . bin2hex( random_bytes( 6 ) );
$state = $root . '/state';
$artifacts = $root . '/artifacts';
$bootstrap_content = $root . '/bootstrap-wp-content';
mkdir( $state . '/_options', 0755, true );
mkdir( $state . '/_tables', 0755, true );
mkdir( $bootstrap_content . '/plugins', 0755, true );
copy( $repo . '/db.php', $bootstrap_content . '/db.php' );

$options = array(
	array( 1, 'siteurl', 'http://localhost' ),
	array( 2, 'home', 'http://localhost' ),
	array( 3, 'blogname', 'MDI Native Lifecycle' ),
	array( 4, 'blogdescription', '' ),
	array( 5, 'users_can_register', '0' ),
	array( 6, 'admin_email', 'admin@example.test' ),
	array( 7, 'start_of_week', '1' ),
	array( 8, 'use_balanceTags', '0' ),
	array( 9, 'use_smilies', '1' ),
	array( 10, 'require_name_email', '1' ),
	array( 11, 'comments_notify', '1' ),
	array( 12, 'posts_per_rss', '10' ),
	array( 13, 'rss_use_excerpt', '0' ),
	array( 14, 'mailserver_url', 'mail.example.test' ),
	array( 15, 'mailserver_login', 'login@example.test' ),
	array( 16, 'mailserver_pass', 'password' ),
	array( 17, 'mailserver_port', '110' ),
	array( 18, 'default_category', '1' ),
	array( 19, 'default_comment_status', 'open' ),
	array( 20, 'default_ping_status', 'open' ),
	array( 21, 'default_pingback_flag', '1' ),
	array( 22, 'posts_per_page', '10' ),
	array( 23, 'date_format', 'F j, Y' ),
	array( 24, 'time_format', 'g:i a' ),
	array( 25, 'links_updated_date_format', 'F j, Y g:i a' ),
	array( 26, 'timezone_string', '' ),
	array( 27, 'gmt_offset', '0' ),
	array( 28, 'active_plugins', 'a:0:{}' ),
	array( 29, 'db_version', '61833' ),
	array( 30, 'initial_db_version', '61833' ),
	array( 31, 'fresh_site', '1' ),
	array( 32, 'template', 'twentytwentyfive' ),
	array( 33, 'stylesheet', 'twentytwentyfive' ),
);
foreach ( $options as $option ) {
	mdi_native_lifecycle_option( $state, ...$option );
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
						'MARKDOWN_DB_STATE_DIR' => '/wordpress/wp-content/db',
						'MARKDOWN_DB_CONTENT_DIR' => '/wordpress/wp-content/db',
						'SAVEQUERIES' => true,
						'WP_DEBUG' => true,
						'WP_DEBUG_LOG' => true,
						'WP_DEBUG_DISPLAY' => false,
					),
				),
			),
		),
	),
	'inputs' => array(
		'mounts' => array(
			array( 'type' => 'directory', 'source' => $bootstrap_content, 'target' => '/wordpress/wp-content', 'mode' => 'readonly', 'phase' => 'pre-install' ),
			array( 'type' => 'directory', 'source' => $repo, 'target' => '/wordpress/wp-content/plugins/markdown-database-integration', 'mode' => 'readonly', 'phase' => 'pre-install' ),
			array( 'type' => 'directory', 'source' => $woocommerce, 'target' => '/wordpress/wp-content/plugins/woocommerce', 'mode' => 'readonly' ),
			array( 'type' => 'directory', 'source' => $state, 'target' => '/wordpress/wp-content/db', 'mode' => 'readwrite', 'phase' => 'pre-install' ),
		),
	),
	'workflow' => array(
		'steps' => array(
			array( 'command' => 'wordpress.run-php', 'args' => array( 'code-file=' . $repo . '/tests/probe-native-wordpress-lifecycle.php' ) ),
			array( 'command' => 'wordpress.wp-cli', 'args' => array( 'command=wp option get woocommerce_version' ) ),
			array( 'command' => 'wordpress.rest-request', 'args' => array( 'method=GET', 'path=/wp/v2/types' ) ),
			array( 'command' => 'wordpress.server-page-load', 'args' => array( 'surface=frontend', 'path=/', 'expect-status=200' ) ),
		),
	),
	'artifacts' => array( 'directory' => $artifacts ),
	'metadata' => array( 'purpose' => 'Cold WordPress and WooCommerce lifecycle through mdi-native canonical state' ),
);
$recipe_path = $root . '/recipe.json';
file_put_contents( $recipe_path, json_encode( $recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );

$wp_codebox = (string) ( getenv( 'MDI_WP_CODEBOX_BIN' ) ?: 'wp-codebox' );
$first = mdi_native_lifecycle_run( $wp_codebox, $recipe_path );
$second = null;
if ( 0 === $first['status'] ) {
	$recipe['runtime']['blueprint']['steps'][0]['consts']['MDI_NATIVE_LIFECYCLE_EXPECT_PERSISTED'] = true;
	file_put_contents( $recipe_path, json_encode( $recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
	$second = mdi_native_lifecycle_run( $wp_codebox, $recipe_path );
}
$status = 0 !== $first['status'] || null === $second || 0 !== $second['status'] ? 1 : 0;
$summary = array(
	'schema' => 'mdi-native-wordpress-lifecycle-run/v1',
	'passed' => 0 === $status,
	'boots'  => array(
		array(
			'phase'     => 'activation',
			'lifecycle' => $first['lifecycle'],
			'artifacts' => $first['run']['executions'][0]['metadata']['artifact_directory'] ?? null,
		),
		array(
			'phase'     => 'restart',
			'lifecycle' => $second['lifecycle'] ?? null,
			'artifacts' => $second['run']['executions'][0]['metadata']['artifact_directory'] ?? null,
		),
	),
);
fwrite( 0 === $status ? STDOUT : STDERR, json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
if ( 0 !== $status ) {
	if ( 0 !== $first['status'] ) {
		fwrite( STDERR, $first['output'] . "\n" );
	} elseif ( null !== $second ) {
		fwrite( STDERR, $second['output'] . "\n" );
	}
}
if ( 0 === $status && '1' !== getenv( 'MDI_KEEP_LIFECYCLE_ARTIFACTS' ) ) {
	mdi_native_lifecycle_remove_tree( $root );
} else {
	fwrite( STDERR, "Lifecycle artifacts: {$root}\n" );
}
exit( $status );
