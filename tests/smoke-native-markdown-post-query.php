<?php
/** Native wp_posts reads directly from canonical Markdown. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	unset( $hook, $args );
	return $value;
}
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-query-runtime.php';
require_once __DIR__ . '/../inc/native/class-wp-markdown-native-shadow-verifier.php';

final class MDI_Observed_Post_Storage extends WP_Markdown_Storage {
	public int $metadata_reads = 0;
	public int $content_reads = 0;

	public function read_file( string $file_path, bool $metadata_only = false, ?int $parent_id = null ): ?object {
		$metadata_only ? ++$this->metadata_reads : ++$this->content_reads;
		return parent::read_file( $file_path, $metadata_only, $parent_id );
	}
}

final class MDI_Replacing_Post_Storage extends WP_Markdown_Storage {
	public string $target = '';
	public bool $replaced = false;

	public function read_file( string $file_path, bool $metadata_only = false, ?int $parent_id = null ): ?object {
		$post = parent::read_file( $file_path, $metadata_only, $parent_id );
		// Persisted metadata projections deliberately skip a metadata parse, so
		// replace after whichever verified file read the provider actually needs.
		if ( ! $this->replaced && $file_path === $this->target ) {
			$stat = lstat( $file_path );
			$raw = file_get_contents( $file_path );
			if ( false === $stat || false === $raw ) {
				throw new RuntimeException( 'Failed to inspect the replacement fixture.' );
			}
			$replacement = $file_path . '.replacement';
			$changed = str_replace( array( 'Private title', 'Private body' ), array( 'Changed title', 'Changed body' ), $raw );
			if ( strlen( $raw ) !== strlen( $changed )
				|| false === file_put_contents( $replacement, $changed )
				|| ! touch( $replacement, (int) $stat['mtime'], (int) $stat['atime'] )
				|| ! rename( $replacement, $file_path )
			) {
				throw new RuntimeException( 'Failed to atomically replace the post fixture.' );
			}
			$current = lstat( $file_path );
			if ( false === $current
				|| $stat['size'] !== $current['size']
				|| $stat['mtime'] !== $current['mtime']
				|| ( $stat['dev'] === $current['dev'] && $stat['ino'] === $current['ino'] )
			) {
				throw new RuntimeException( 'The replacement fixture did not preserve weak file identity.' );
			}
			$this->replaced = true;
		}
		return $post;
	}
}

final class MDI_Post_Shadow_DB {
	public string $prefix = 'wp_';
	public array $last_result = array();
	public string $last_error = '';
	public int $last_errno = 0;
	public int $insert_id = 0;
	public int $rows_affected = 0;
	public int $num_rows = 0;
	protected array $col_info = array();

	public function result( array $rows, array $columns ): void {
		$this->last_result = array_map( static fn( array $row ): object => (object) $row, $rows );
		$this->col_info = array_map( static fn( array $column ): object => (object) $column, $columns );
		$this->num_rows = count( $rows );
	}

	public function get_col_info( string $field ): array {
		return array_map( static fn( object $column ): mixed => $column->{$field} ?? null, $this->col_info );
	}
}

$root    = sys_get_temp_dir() . '/mdi-native-posts-' . bin2hex( random_bytes( 6 ) );
$state   = $root . '/state';
$content = $root . '/content';
if ( ! mkdir( $state . '/_options', 0777, true ) || ! mkdir( $content . '/post', 0777, true ) ) {
	throw new RuntimeException( 'Failed to create native post fixtures.' );
}
$write = static function ( string $path, int $id, string $title, int $author, string $body, string $status = 'publish' ): void {
	file_put_contents(
		$path,
		"---\nid: {$id}\ntitle: {$title}\nstatus: {$status}\ntype: post\nauthor: {$author}\ndate: 2026-08-24 12:00:00\nmodified: 2026-08-24 13:00:00\nslug: post-{$id}\ncomment_status: open\nping_status: open\n---\n\n{$body}\n"
	);
};
$write( $content . '/post/private.md', 41, 'Private title', 7, "Private body\nsecond line" );
$write( $content . '/post/other.md', 42, 'Other title', 7, 'Other body' );
$write( $content . '/post/draft.md', 43, 'Private title', 8, 'Draft body', 'draft' );
$write( $state . '/state-only.md', 99, 'Wrong root', 7, 'Must not appear' );

$runtime = WP_Markdown_Native_Runtime_Factory::runtime( $state, 'wp_', null, false, $content );
$blocker_sql = "SELECT ID FROM wp_posts WHERE post_title = 'Private title' AND post_author = 7";
$blocker = $runtime->execute( new WP_Markdown_Query_Request( $blocker_sql ) );
$exact = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID, post_title, post_status FROM wp_posts WHERE ID = 42 LIMIT 1' ) );
$body = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_content FROM wp_posts WHERE ID = 41 LIMIT 1' ) );
$all = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT * FROM wp_posts WHERE ID = 41 LIMIT 1' ) );
$wrong_root = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts WHERE ID = 99 LIMIT 1' ) );
$site_runtime = WP_Markdown_Native_Runtime_Factory::runtime( $state, 'wp_2_', 'wp_', true, $content );
$site_post = $site_runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_2_posts WHERE ID = 41', 'wp_2_' ) );

$observed_storage = new MDI_Observed_Post_Storage( $content );
$observed_schema  = WP_Markdown_Native_Runtime_Factory::posts_schema();
$observed_registry = new WP_Markdown_Native_Table_Registry();
$observed_registry->register( 'wp_posts', $observed_schema, new WP_Markdown_Native_Post_Provider( $content, $observed_schema, $observed_storage ) );
$observed_runtime = new WP_Markdown_Native_Query_Runtime( $observed_registry );
$observed_runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_title FROM wp_posts WHERE ID = 41 LIMIT 1' ) );
$content_reads_before = $observed_storage->content_reads;
$observed_runtime->execute( new WP_Markdown_Query_Request( 'SELECT post_content FROM wp_posts WHERE ID = 41 LIMIT 1' ) );

$shadow_db = new MDI_Post_Shadow_DB();
$shadow_db->result( array( array( 'ID' => '41' ) ), array( array( 'name' => 'ID', 'type' => 8 ) ) );
$shadow = new WP_Markdown_Native_Shadow_Verifier( $runtime );
$shadow->observe( $blocker_sql, 1, $shadow_db );
$shadow_report = $shadow->report();

$replacing_storage = new MDI_Replacing_Post_Storage( $content );
$replacing_storage->target = $content . '/post/private.md';
$replacing_registry = new WP_Markdown_Native_Table_Registry();
$replacing_registry->register( 'wp_posts', $observed_schema, new WP_Markdown_Native_Post_Provider( $content, $observed_schema, $replacing_storage ) );
$replaced = ( new WP_Markdown_Native_Query_Runtime( $replacing_registry ) )->execute(
	new WP_Markdown_Query_Request( 'SELECT post_title, post_content FROM wp_posts WHERE ID = 41 LIMIT 1' )
);

$checks = array(
	'conjunctive WordPress blocker executes from canonical Markdown' => 1 === $blocker->return_value()
		&& '41' === ( $blocker->wpdb_state()['last_result'][0]->ID ?? null ),
	'exact ID lookup preserves projection and MySQL metadata' => array( 'ID' => '42', 'post_title' => 'Other title', 'post_status' => 'publish' ) === get_object_vars( $exact->wpdb_state()['last_result'][0] ?? (object) array() )
		&& array( 8, 252, 253 ) === array_map( static fn( object $column ): int => $column->type, $exact->wpdb_state()['col_info'] ),
	'post_content projections preserve canonical body bytes' => "Private body\nsecond line" === ( $body->wpdb_state()['last_result'][0]->post_content ?? null ),
	'full post projection exposes the complete WordPress row shape' => 23 === count( get_object_vars( $all->wpdb_state()['last_result'][0] ?? (object) array() ) )
		&& array( 'ID', 'post_author', 'post_date' ) === array_slice( array_keys( get_object_vars( $all->wpdb_state()['last_result'][0] ?? (object) array() ) ), 0, 3 ),
	'content and state roots remain independent and posts use the active site prefix' => 0 === $wrong_root->return_value()
		&& 1 === $site_post->return_value(),
	'metadata projections skip bodies and content hydration is bounded to selected posts' => 0 === $content_reads_before
		&& 1 === $observed_storage->content_reads
		&& 0 < $observed_storage->metadata_reads,
	'same-size same-mtime atomic replacement fails closed' => $replacing_storage->replaced
		&& false === $replaced->return_value()
		&& array() === $replaced->wpdb_state()['last_result']
		&& 'markdown_db_native_malformed_post' === ( $replaced->diagnostic()['code'] ?? null )
		&& 'changed_post' === ( $replaced->diagnostic()['reason'] ?? null ),
	'shadow verifier compares the recorded post blocker exactly' => 1 === ( $shadow_report['counts']['compatible'] ?? 0 )
		&& null === ( $shadow_report['first_blocker'] ?? null ),
);

$malformed_path = $content . '/post/malformed.md';
file_put_contents( $malformed_path, "---\nid: 44\ntitle: Broken\n" );
$malformed = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts WHERE post_author = 7' ) );
@unlink( $malformed_path );
$write( $content . '/post/duplicate.md', 41, 'Duplicate', 7, 'Duplicate body' );
$duplicate = $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts WHERE post_author = 7' ) );
@unlink( $content . '/post/duplicate.md' );
$outside = $root . '/outside.md';
$write( $outside, 45, 'Outside', 7, 'Outside body' );
$linked = function_exists( 'symlink' ) && @symlink( $outside, $content . '/post/linked.md' );
$unsafe = $linked ? $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts WHERE post_author = 7' ) ) : null;
if ( $linked ) {
	@unlink( $content . '/post/linked.md' );
}
$hardlinked = function_exists( 'link' ) && @link( $outside, $content . '/post/linked.md' );
$unsafe_hardlink = $hardlinked ? $runtime->execute( new WP_Markdown_Query_Request( 'SELECT ID FROM wp_posts WHERE post_author = 7' ) ) : null;

$checks['malformed and duplicate Markdown fail without partial rows'] = false === $malformed->return_value()
	&& false === $duplicate->return_value()
	&& array() === $malformed->wpdb_state()['last_result']
	&& 'markdown_db_native_malformed_post' === ( $duplicate->diagnostic()['code'] ?? null );
$checks['linked Markdown paths fail closed'] = ( ! $linked || ( false === $unsafe->return_value()
	&& 'markdown_db_native_malformed_post' === ( $unsafe->diagnostic()['code'] ?? null ) ) )
	&& ( ! $hardlinked || ( false === $unsafe_hardlink->return_value()
	&& 'markdown_db_native_malformed_post' === ( $unsafe_hardlink->diagnostic()['code'] ?? null ) ) );

if ( 1 !== ( $shadow_report['counts']['compatible'] ?? 0 ) ) {
	fwrite( STDERR, json_encode( $shadow_report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL );
}

$failed = 0;
foreach ( $checks as $label => $passed ) {
	echo ( $passed ? 'PASS' : 'FAIL' ) . ': ' . $label . PHP_EOL;
	if ( ! $passed ) {
		++$failed;
	}
}

@unlink( $content . '/post/linked.md' );
@unlink( $outside );
foreach ( array( 'private.md', 'other.md', 'draft.md' ) as $file ) {
	@unlink( $content . '/post/' . $file );
}
@unlink( $state . '/state-only.md' );
@rmdir( $content . '/post' );
@rmdir( $content );
@rmdir( $state . '/_options' );
@rmdir( $state );
@rmdir( $root );
exit( $failed ? 1 : 0 );
