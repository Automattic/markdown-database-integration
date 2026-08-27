<?php
/** mysql-full semantic envelope to canonical file publication checks. */
declare( strict_types=1 );

$root = sys_get_temp_dir() . '/mdi-mysql-publication-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', $root );
define( 'MARKDOWN_DB_CONTENT_DIR', $root . '/content' );
define( 'MARKDOWN_DB_STATE_DIR', $root . '/state' );
define( 'MARKDOWN_DB_EXCLUDED_TYPES', 'revision' );
define( 'MARKDOWN_DB_CONTENT_LAYOUT_PROFILE', 'post-type-hierarchy' );

function apply_filters( string $name, mixed $value, mixed ...$args ): mixed {
	unset( $args );
	if ( 'markdown_db_mysql_full_roots' === $name && isset( $GLOBALS['mdi_publication_roots'] ) ) { return $GLOBALS['mdi_publication_roots']; }
	if ( 'markdown_db_table_persistence_policy' === $name && isset( $GLOBALS['mdi_publication_policy'] ) ) { return $GLOBALS['mdi_publication_policy']; }
	return $value;
}
function has_filter( string $name ): bool { unset( $name ); return false; }

require_once __DIR__ . '/../inc/class-wp-markdown-frontmatter-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-content-layout-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';
require_once __DIR__ . '/../inc/class-wp-markdown-durable-reconciliation-operations.php';
require_once __DIR__ . '/../inc/class-wp-markdown-reconciliation-adapters.php';
require_once __DIR__ . '/../inc/class-wp-markdown-canonical-persistence.php';
require_once __DIR__ . '/../inc/mysql/class-wp-markdown-mysql-operations.php';
require_once __DIR__ . '/../inc/mysql/class-wp-markdown-mysql-canonical-publisher.php';

final class MDI_Publication_Result {
	private int $offset = 0;
	public function __construct( private array $rows ) {}
	public function fetch_assoc(): array|false { return $this->rows[ $this->offset++ ] ?? false; }
	public function free(): void {}
}
final class MDI_Publication_Statement {
	private array $values = array();
	public function __construct( private MDI_Publication_Connection $connection, private string $query ) {}
	public function bind_param( string $types, mixed &...$values ): bool { unset( $types ); $this->values = $values; return true; }
	public function execute(): bool { return true; }
	public function get_result(): MDI_Publication_Result { return new MDI_Publication_Result( $this->connection->prepared_rows( $this->query, $this->values ) ); }
	public function close(): void {}
}
final class MDI_Publication_Connection {
	private array $post;
	public function __construct() { $this->post = array( 'ID' => '7', 'post_author' => '1', 'post_date' => '2026-08-23 12:00:00', 'post_date_gmt' => '2026-08-23 12:00:00', 'post_content' => 'Published from MariaDB.', 'post_title' => 'Published', 'post_excerpt' => '', 'post_status' => 'publish', 'comment_status' => 'open', 'ping_status' => 'open', 'post_password' => '', 'post_name' => 'published', 'to_ping' => '', 'pinged' => '', 'post_modified' => '2026-08-23 12:00:00', 'post_modified_gmt' => '2026-08-23 12:00:00', 'post_content_filtered' => '', 'post_parent' => '0', 'guid' => '', 'menu_order' => '0', 'post_type' => 'post', 'post_mime_type' => '', 'comment_count' => '0' ); }
	public function query( string $query ): MDI_Publication_Result|false {
		if ( str_starts_with( $query, 'SHOW CREATE TABLE `wp_events`' ) ) { return new MDI_Publication_Result( array( array( 'Create Table' => 'CREATE TABLE `wp_events` (`event_id` varchar(64) NOT NULL, `payload` longtext NOT NULL, PRIMARY KEY (`event_id`)) ENGINE=InnoDB' ) ) ); }
		if ( str_contains( $query, 'FROM `wp_posts` WHERE `ID` IN (7)' ) ) { return new MDI_Publication_Result( array( $this->post ) ); }
		if ( str_contains( $query, 'FROM `wp_posts` WHERE `ID`=7' ) ) { return new MDI_Publication_Result( array( array( 'post_status' => 'publish' ) ) ); }
		if ( str_contains( $query, 'FROM `wp_postmeta` WHERE `post_id`=7' ) ) { return new MDI_Publication_Result( array( array( 'meta_key' => 'audience', 'meta_value' => 'builders' ) ) ); }
		if ( str_contains( $query, 'FROM `wp_term_relationships` tr' ) ) { return new MDI_Publication_Result( array( array( 'taxonomy' => 'category', 'slug' => 'news' ) ) ); }
		if ( str_contains( $query, 'SELECT * FROM `wp_events` ORDER BY 1' ) ) { return new MDI_Publication_Result( array( array( 'event_id' => 'evt-7', 'payload' => 'durable' ) ) ); }
		if ( str_contains( $query, 'SELECT * FROM `wp_2_events` ORDER BY 1' ) ) { return new MDI_Publication_Result( array( array( 'event_id' => 'site-2', 'payload' => 'local' ) ) ); }
		if ( str_contains( $query, 'SELECT * FROM `wp_3_events` ORDER BY 1' ) ) { return new MDI_Publication_Result( array( array( 'event_id' => 'site-3', 'payload' => 'local' ) ) ); }
		if ( str_contains( $query, 'SELECT * FROM `wp_sitemeta` ORDER BY 1' ) ) { return new MDI_Publication_Result( array( array( 'meta_id' => '1', 'site_id' => '1', 'meta_key' => 'network', 'meta_value' => 'global' ) ) ); }
		return false;
	}
	public function prepare( string $query ): MDI_Publication_Statement { return new MDI_Publication_Statement( $this, $query ); }
	public function prepared_rows( string $query, array $values ): array {
		if ( str_contains( $query, 'FROM `wp_options`' ) && array( 'siteurl' ) === $values ) { return array( array( 'option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'https://example.test', 'autoload' => 'on' ) ); }
		return array();
	}
}

function mdi_publication_intent( string $event, string $kind, string $operation, string $table, array $ids, array $scope, int $blog_id = 1, string $prefix = 'wp_', string $base = 'wp_' ): array {
	return array( 'event_id' => $event, 'stable_id' => $event . ':' . $table, 'kind' => $kind, 'operation' => $operation, 'table' => $table, 'resource_ids' => $ids, 'scope' => $scope, 'database' => 'wordpress', 'blog_id' => $blog_id, 'table_prefix' => $prefix, 'base_prefix' => $base );
}

$event = 'event-publication-1';
$envelope = array(
	'event_id' => $event,
	'outbox_id' => 1,
	'payload_sha256' => hash( 'sha256', $event ),
	'intents' => array(
		mdi_publication_intent( $event, 'table', 'UPDATE', 'wp_posts', array( '7' ), array( 'identity' => array( 'column' => 'id', 'values' => array( '7' ) ), 'resource_ids_by_column' => array( 'id' => array( '7' ) ), 'assigned_columns' => array( 'post_title' ) ) ),
		mdi_publication_intent( $event, 'table', 'UPDATE', 'wp_options', array( 'siteurl' ), array( 'identity' => array( 'column' => 'option_name', 'values' => array( 'siteurl' ) ), 'resource_ids_by_column' => array( 'option_name' => array( 'siteurl' ) ), 'assigned_columns' => array( 'option_value' ) ) ),
		mdi_publication_intent( $event, 'table', 'UPDATE', 'wp_events', array( 'evt-7' ), array( 'identity' => array( 'column' => 'event_id', 'values' => array( 'evt-7' ) ), 'resource_ids_by_column' => array( 'event_id' => array( 'evt-7' ) ), 'assigned_columns' => array( 'payload' ) ) ),
		mdi_publication_intent( $event, 'schema', 'ALTER', 'wp_events', array( '*' ), array( 'resource_ids_by_column' => array(), 'assigned_columns' => array(), 'conservative' => true ) ),
	),
);

$publisher = new WP_Markdown_MySQL_Canonical_Publisher( new MDI_Publication_Connection() );
$checks = array();
$publisher->publish( array( 'event_id' => 'event-no-op', 'intents' => array() ) );
$checks['empty semantic envelope is an acknowledged no-op'] = array( 'created' => array(), 'changed' => array(), 'deleted' => array() ) === $publisher->last_changes();
$publisher->publish( $envelope );
$changes = $publisher->last_changes();
$post_file = MARKDOWN_DB_CONTENT_DIR . '/post/published.md';
$option_file = MARKDOWN_DB_STATE_DIR . '/_options/siteurl.json';
$table_file = MARKDOWN_DB_STATE_DIR . '/_tables/events.json';
$schema_file = MARKDOWN_DB_STATE_DIR . '/_schema/events.sql';
$checks = array_merge( $checks, array(
	'post publication' => is_file( $post_file ) && str_contains( (string) file_get_contents( $post_file ), 'Published from MariaDB.' ) && str_contains( (string) file_get_contents( $post_file ), 'audience' ) && str_contains( (string) file_get_contents( $post_file ), 'news' ),
	'option publication' => is_file( $option_file ) && 'https://example.test' === ( json_decode( (string) file_get_contents( $option_file ), true )['option_value'] ?? '' ),
	'plugin table publication' => array( array( 'event_id' => 'evt-7', 'payload' => 'durable' ) ) === json_decode( (string) file_get_contents( $table_file ), true ),
	'schema publication' => is_file( $schema_file ) && str_contains( (string) file_get_contents( $schema_file ), 'ENGINE=InnoDB' ),
	'changed path receipts' => array( '_options/siteurl.json', '_schema/events.sql', '_tables/events.json', 'post/published.md' ) === array_merge( $changes['created'], $changes['changed'] ),
) );
$publisher->publish( $envelope );
$checks['idempotent replay'] = array( 'created' => array(), 'changed' => array(), 'deleted' => array() ) === $publisher->last_changes();
$blocked = $root . '/blocked-root';
file_put_contents( $blocked, 'not a directory' );
$GLOBALS['mdi_publication_roots'] = array( 'content_dir' => $blocked, 'state_dir' => $blocked );
$failed_closed = false;
try { $publisher->publish( array( 'event_id' => 'event-failure', 'intents' => array( mdi_publication_intent( 'event-failure', 'table', 'UPDATE', 'wp_options', array( 'siteurl' ), array( 'identity' => array( 'column' => 'option_name', 'values' => array( 'siteurl' ) ), 'resource_ids_by_column' => array( 'option_name' => array( 'siteurl' ) ), 'assigned_columns' => array( 'option_value' ) ) ) ) ) ); }
catch ( RuntimeException $error ) { $failed_closed = true; }
unset( $GLOBALS['mdi_publication_roots'] );
unlink( $blocked );
$checks['publication failure remains retryable'] = $failed_closed;

$mixed_event = 'event-mixed-roots';
$publisher->publish( array( 'event_id' => $mixed_event, 'intents' => array(
	mdi_publication_intent( $mixed_event, 'table', 'UPDATE', 'wp_2_events', array( '*' ), array( 'resource_ids_by_column' => array(), 'assigned_columns' => array(), 'conservative' => true ), 2, 'wp_2_', 'wp_' ),
	mdi_publication_intent( $mixed_event, 'table', 'UPDATE', 'wp_sitemeta', array( '*' ), array( 'resource_ids_by_column' => array(), 'assigned_columns' => array(), 'conservative' => true ), 2, 'wp_2_', 'wp_' ),
) ) );
$mixed_changes = $publisher->last_changes();
$checks['mixed multisite roots'] = is_file( MARKDOWN_DB_STATE_DIR . '/sites/2/_tables/events.json' ) && is_file( MARKDOWN_DB_STATE_DIR . '/_tables/sitemeta.json' ) && ! is_file( MARKDOWN_DB_STATE_DIR . '/sites/2/_tables/sitemeta.json' );
$checks['multisite receipts qualify roots'] = in_array( 'sites/2/_tables/events.json', $mixed_changes['created'], true ) && in_array( '_tables/sitemeta.json', $mixed_changes['created'], true );

$site_three_event = 'event-site-three';
$publisher->publish( array( 'event_id' => $site_three_event, 'intents' => array( mdi_publication_intent( $site_three_event, 'table', 'UPDATE', 'wp_3_events', array( '*' ), array( 'resource_ids_by_column' => array(), 'assigned_columns' => array(), 'conservative' => true ), 3, 'wp_3_', 'wp_' ) ) ) );
$checks['equal multisite paths remain distinct'] = array( 'sites/3/_tables/events.json' ) === $publisher->last_changes()['created'];

$truncate_event = 'event-truncate';
$truncate = mdi_publication_intent( $truncate_event, 'schema', 'TRUNCATE', 'wp_events', array( '*' ), array( 'resource_ids_by_column' => array(), 'assigned_columns' => array(), 'conservative' => true ) );
$truncate['schema'] = array( 'action' => 'upsert', 'create_sql' => 'CREATE TABLE `wp_events` (`event_id` varchar(64) NOT NULL, PRIMARY KEY (`event_id`)) ENGINE=InnoDB' );
$publisher->publish( array( 'event_id' => $truncate_event, 'intents' => array( $truncate ) ) );
$checks['truncate clears data and retains schema'] = ! is_file( $table_file ) && is_file( $schema_file );

$linked_target = $root . '/linked-target';
mkdir( $linked_target );
$linked_content = MARKDOWN_DB_CONTENT_DIR . '/linked-content';
$strict_truncate = false;
if ( symlink( $linked_target, $linked_content ) ) {
	$posts_truncate_event = 'event-posts-truncate';
	$posts_truncate = mdi_publication_intent( $posts_truncate_event, 'schema', 'TRUNCATE', 'wp_posts', array( '*' ), array( 'resource_ids_by_column' => array(), 'assigned_columns' => array(), 'conservative' => true ) );
	$posts_truncate['schema'] = array( 'action' => 'upsert', 'create_sql' => 'CREATE TABLE `wp_posts` (`ID` bigint unsigned NOT NULL, PRIMARY KEY (`ID`)) ENGINE=InnoDB' );
	try { $publisher->publish( array( 'event_id' => $posts_truncate_event, 'intents' => array( $posts_truncate ) ) ); }
	catch ( RuntimeException $error ) { $strict_truncate = true; }
	unlink( $linked_content );
}
rmdir( $linked_target );
$checks['post truncate fails closed'] = $strict_truncate;

$reseed_event = 'event-reseed';
$publisher->publish( array( 'event_id' => $reseed_event, 'intents' => array( mdi_publication_intent( $reseed_event, 'table', 'UPDATE', 'wp_events', array( 'evt-7' ), array( 'identity' => array( 'column' => 'event_id', 'values' => array( 'evt-7' ) ), 'resource_ids_by_column' => array( 'event_id' => array( 'evt-7' ) ), 'assigned_columns' => array( 'payload' ) ) ) ) ) );
$stale_event = 'event-stale-evidence';
$stale = mdi_publication_intent( $stale_event, 'table', 'UPDATE', 'wp_events', array( 'evt-7' ), array( 'identity' => array( 'column' => 'event_id', 'values' => array( 'evt-7' ) ), 'resource_ids_by_column' => array( 'event_id' => array( 'evt-7' ) ), 'assigned_columns' => array( 'payload' ) ) );
$stale['current_rows'] = array( array( 'event_id' => 'evt-7', 'payload' => 'captured-before-drop' ) );
$publisher->publish( array( 'event_id' => $stale_event, 'intents' => array( $stale ) ) );
$checks['captured rows survive stale live table'] = 'captured-before-drop' === ( json_decode( (string) file_get_contents( $table_file ), true )[0]['payload'] ?? '' );

$GLOBALS['mdi_publication_policy'] = array( 'events' => array( 'partition_by' => 'event_id' ) );
$partition_event = 'event-partition';
$partition = mdi_publication_intent( $partition_event, 'table', 'UPDATE', 'wp_events', array( 'evt-7' ), array( 'identity' => array( 'column' => 'event_id', 'values' => array( 'evt-7' ) ), 'resource_ids_by_column' => array( 'event_id' => array( 'evt-7' ) ), 'assigned_columns' => array( 'payload' ) ) );
$partition['current_rows'] = array( array( 'event_id' => 'evt-7', 'payload' => 'partitioned' ) );
$publisher->publish( array( 'event_id' => $partition_event, 'intents' => array( $partition ) ) );
$partition_dir = MARKDOWN_DB_STATE_DIR . '/_tables/events';
$drop_event = 'event-drop-partition';
$drop = mdi_publication_intent( $drop_event, 'schema', 'DROP', 'wp_events', array( '*' ), array( 'resource_ids_by_column' => array(), 'assigned_columns' => array(), 'conservative' => true ) );
$drop['schema'] = array( 'action' => 'delete' );
$publisher->publish( array( 'event_id' => $drop_event, 'intents' => array( $drop ) ) );
unset( $GLOBALS['mdi_publication_policy'] );
$checks['drop removes partition generations'] = ! is_dir( $partition_dir ) && ! is_file( $schema_file );

$failed = 0;
foreach ( $checks as $label => $passed ) { echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL; if ( ! $passed ) { ++$failed; } }
if ( $failed ) {
	$paths = array(); $debug = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $debug as $path ) { if ( $path->isFile() ) { $paths[] = substr( $path->getPathname(), strlen( $root ) + 1 ); } }
	fwrite( STDERR, 'Published paths: ' . json_encode( $paths ) . '; changes: ' . json_encode( $changes ) . PHP_EOL );
	if ( is_file( $post_file ) ) { fwrite( STDERR, "Post bytes:\n" . file_get_contents( $post_file ) . PHP_EOL ); }
}

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $iterator as $path ) { $path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() ); }
rmdir( $root );
exit( $failed ? 1 : 0 );
