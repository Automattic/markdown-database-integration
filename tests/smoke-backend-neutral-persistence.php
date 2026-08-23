<?php
/** Semantic backend boundary smoke test. Usage: php tests/smoke-backend-neutral-persistence.php */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../inc/interface-wp-markdown-backend-operations.php';
require_once __DIR__ . '/../inc/class-wp-markdown-frontmatter-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-content-layout-profiles.php';
require_once __DIR__ . '/../inc/class-wp-markdown-storage.php';

class MDI_Fake_Backend implements WP_Markdown_Backend_Operations {
	public array $calls = array();
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable { $this->calls[] = "rows:$table_suffix"; return array(); }
	public function post_rows( array $post_ids ): array { $this->calls[] = 'posts:' . implode( ',', $post_ids ); return array(); }
	public function post_status( int $post_id ): ?string { $this->calls[] = "post-status:$post_id"; return null; }
	public function post_meta( int $post_id ): array { $this->calls[] = "post-meta:$post_id"; return array(); }
	public function post_terms( int $post_id ): array { $this->calls[] = "post-terms:$post_id"; return array(); }
	public function affected_post_ids( string $table_suffix, array $resource_ids, string $operation, array $scope = array() ): array { unset( $scope ); $this->calls[] = "affected:$table_suffix:$operation"; return array_map( 'intval', $resource_ids ); }
	public function options( array $names, bool $all = false ): array { $this->calls[] = 'options:' . implode( ',', $names ); return array(); }
	public function option_names(): array { return array(); }
	public function insert_id(): int { return 0; }
	public function next_post_id( int $minimum = 1 ): int { return $minimum; }
	public function upsert_file_index( int $post_id, string $path, int $mtime, int $size ): void { $this->calls[] = "index:$post_id:$path"; }
	public function delete_file_index( int $post_id ): void { $this->calls[] = "delete-index:$post_id"; }
	public function upsert_options_index( array $rows ): void { $this->calls[] = 'option-index:' . count( $rows ); }
	public function delete_options_index( array $names ): void { $this->calls[] = 'delete-options:' . count( $names ); }
	public function update_manifest( string $path, int $mtime, int $size ): void { $this->calls[] = "manifest:$path"; }
	public function persist_schema( string $table_suffix, string $operation ): ?string { $this->calls[] = "schema:$table_suffix:$operation"; return 'CREATE TABLE fake (id INTEGER)'; }
	public function delete_schema( string $table_suffix ): void { $this->calls[] = "delete-schema:$table_suffix"; }
	public function manifest_entries(): array { return array(); }
	public function hydrate_markdown_posts( array $posts, ?iterable $fallback_posts ): void { $this->calls[] = 'hydrate-posts:' . count( $posts ); }
	public function hydrate_table_snapshot( string $table_suffix, callable $rows, ?array $identity = null, ?array $partition = null ): bool { $this->calls[] = "replace:$table_suffix"; return true; }
	public function reconcile_markdown( array $files, callable $parse_file ): array { $this->calls[] = 'reconcile:' . count( $files ); return array(); }
	public function hydrate_options( array $rows ): void { $this->calls[] = 'hydrate-options:' . count( $rows ); }
	public function ensure_tables( array $schemas ): void { $this->calls[] = 'ensure-tables:' . count( $schemas ); }
	public function ensure_reconciliation_state(): void { $this->calls[] = 'ensure-state'; }
	public function mutations_for_query( string $query, array $operation ): array { unset( $query, $operation ); return array(); }
}

$root = sys_get_temp_dir() . '/mdi-neutral-' . bin2hex( random_bytes( 4 ) );
mkdir( $root, 0755, true );
$storage = new WP_Markdown_Storage( $root );
$backend = new MDI_Fake_Backend();
$post = (object) array( 'ID' => 7, 'post_type' => 'post', 'post_name' => 'neutral', 'post_title' => 'Neutral', 'post_status' => 'publish', 'post_content' => 'canonical' );
$path = $storage->write_post( $post );
$backend->upsert_file_index( 7, 'post/neutral.md', (int) filemtime( $path ), (int) filesize( $path ) );
$backend->affected_post_ids( 'postmeta', array( '7' ), 'update' );
$backend->options( array( 'siteurl', 'blogname' ) );
$backend->persist_schema( 'plugin_rows', 'alter' );
$backend->update_manifest( '_tables/plugin_rows.json', 1, 2 );
$ok = is_file( $path ) && array(
	'index:7:post/neutral.md', 'affected:postmeta:update', 'options:siteurl,blogname',
	'schema:plugin_rows:alter', 'manifest:_tables/plugin_rows.json',
) === $backend->calls;
unlink( $path ); rmdir( dirname( $path ) ); rmdir( $root );
if ( ! $ok ) { echo "FAIL: backend-neutral persistence contract\n"; exit( 1 ); }
echo "All backend-neutral persistence checks passed.\n";
