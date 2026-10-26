<?php
/** Minimal semantic operations for canonical persistence unit tests. */
require_once __DIR__ . '/../../inc/interface-wp-markdown-backend-operations.php';

class MDI_Stub_Backend_Operations implements WP_Markdown_Backend_Operations {
	public array $rows = array();
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable { return $this->rows[ $table_suffix ] ?? array(); }
	public function post_rows( array $post_ids ): array { return array(); }
	public function post_status( int $post_id ): ?string { return null; }
	public function post_meta( int $post_id ): array { return array(); }
	public function post_terms( int $post_id ): array { return array(); }
	public function affected_post_ids( string $table_suffix, array $resource_ids, string $operation, array $scope = array() ): array { return array(); }
	public function options( array $names, bool $all = false ): array { return $this->rows['options'] ?? array(); }
	public function option_names(): array { return array(); }
	public function insert_id(): int { return 0; }
	public function next_post_id( int $minimum = 1 ): int { return $minimum; }
	public function upsert_file_index( int $post_id, string $path, int $mtime, int $size ): void {}
	public function delete_file_index( int $post_id ): void {}
	public function upsert_options_index( array $rows ): void {}
	public function delete_options_index( array $names ): void {}
	public function update_manifest( string $path, int $mtime, int $size ): void {}
	public function persist_schema( string $table_suffix, string $operation ): ?string { return null; }
	public function delete_schema( string $table_suffix ): void {}
	public function manifest_entries(): array { return array(); }
	public function hydrate_markdown_posts( array $posts, ?iterable $fallback_posts ): void {}
	public function hydrate_table_snapshot( string $table_suffix, callable $rows, ?array $identity = null, ?array $partition = null ): bool { return false; }
	public function reconcile_markdown( array $files, callable $parse_file ): array { return array(); }
	public function hydrate_options( array $rows ): void {}
	public function ensure_tables( array $schemas ): void {}
	public function ensure_reconciliation_state(): void {}
	public function mutations_for_query( string $query, array $operation ): array {
		return array( array( 'stable_id' => $operation['table'], 'operation' => $operation['op'], 'table' => $operation['table'], 'resource_ids' => array( '*' ), 'scope' => array() ) );
	}
}
