<?php
/** Semantic backend boundary for canonical persistence and reconstruction. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface WP_Markdown_Backend_Operations {
	/** @return iterable<array<string,mixed>|object> */
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable;
	/** @return array<int,array<string,mixed>|object> */
	public function post_rows( array $post_ids ): array;
	public function post_status( int $post_id ): ?string;
	/** @return array<int,array<string,mixed>|object> */
	public function post_meta( int $post_id ): array;
	/** @return array<int,array<string,mixed>|object> */
	public function post_terms( int $post_id ): array;
	/** @return array<int,int> */
	public function affected_post_ids( string $table_suffix, array $resource_ids, string $operation, array $scope = array() ): array;
	/** @return array<string,array<string,mixed>> keyed by option name */
	public function options( array $names, bool $all = false ): array;
	/** @return string[] */
	public function option_names(): array;
	public function insert_id(): int;
	public function next_post_id( int $minimum = 1 ): int;
	public function upsert_file_index( int $post_id, string $path, int $mtime, int $size ): void;
	public function delete_file_index( int $post_id ): void;
	/** @param array<int,array<string,mixed>> $rows */
	public function upsert_options_index( array $rows ): void;
	/** @param string[] $names */
	public function delete_options_index( array $names ): void;
	public function update_manifest( string $path, int $mtime, int $size ): void;
	public function persist_schema( string $table_suffix, string $operation ): ?string;
	public function delete_schema( string $table_suffix ): void;
	/** @return array<string,array{mtime:int,size:int}> */
	public function manifest_entries(): array;
	/** @param array<int,object> $posts @param iterable<array<string,mixed>> $fallback_posts */
	public function hydrate_markdown_posts( array $posts, ?iterable $fallback_posts ): void;
	/**
	 * Atomically replace a canonical table snapshot. The factory is evaluated
	 * after write ownership is acquired and returns a fresh row stream.
	 *
	 * @param callable():iterable<array<string,mixed>> $rows
	 */
	public function hydrate_table_snapshot( string $table_suffix, callable $rows, ?array $identity = null, ?array $partition = null ): bool;
	/** @param array<string,array{mtime:int,size:int,absolute:string,parent_id:int|null}> $files */
	public function reconcile_markdown( array $files, callable $parse_file ): array;
	/** @param array<int,array<string,mixed>> $rows */
	public function hydrate_options( array $rows ): void;
	/** @param array<string,string> $schemas */
	public function ensure_tables( array $schemas ): void;
	public function ensure_reconciliation_state(): void;
	/** @return array<int,array{stable_id:string,kind:string,operation:string,table:string,resource_ids:array<int,string>,scope:array<string,mixed>}> */
	public function mutations_for_query( string $query, array $operation ): array;
}
