<?php
/** SQLite adapter: the only semantic-boundary implementation that handles SQL/PDO. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-wp-markdown-backend-operations.php';

class WP_Markdown_SQLite_Operations implements WP_Markdown_Backend_Operations {
	private $driver;
	private $prefix;

	public function __construct( $driver, $prefix = 'wp_' ) {
		$this->driver = $driver;
		$this->prefix = is_callable( $prefix ) ? $prefix : static function () use ( $prefix ): string { return (string) $prefix; };
	}

	private function rows( string $query ): array {
		$result = method_exists( $this->driver, 'query' ) ? $this->driver->query( $query ) : $this->driver->get_connection()->get_pdo()->query( $query );
		if ( is_array( $result ) ) { return $result; }
		return $result instanceof \PDOStatement ? $result->fetchAll( \PDO::FETCH_OBJ ) : array();
	}
	private function table( string $suffix ): string { return ( $this->prefix )() . $suffix; }
	public function table_rows( string $table_suffix, ?array $policy = null ): iterable {
		$query = 'SELECT * FROM `' . $this->table( $table_suffix ) . '` ORDER BY 1';
		if ( is_array( $policy ) && isset( $policy['query'] ) ) { $query = (string) $policy['query']; }
		if ( is_array( $policy ) && isset( $policy['limit'] ) ) { $query .= ' LIMIT ' . max( 0, (int) $policy['limit'] ); }
		if ( function_exists( 'apply_filters' ) ) { $query = apply_filters( 'markdown_db_persistent_table_query', $query, $table_suffix, $this->table( $table_suffix ), $policy ); }
		$result = method_exists( $this->driver, 'query_cursor' ) ? $this->driver->query_cursor( $query ) : ( method_exists( $this->driver, 'query' ) ? $this->driver->query( $query ) : $this->driver->get_connection()->get_pdo()->query( $query ) );
		if ( $result instanceof \PDOStatement ) { while ( false !== ( $row = $result->fetch( \PDO::FETCH_OBJ ) ) ) { yield $row; } return; }
		foreach ( (array) $result as $row ) { yield $row; }
	}
	public function post_rows( array $post_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( empty( $ids ) ) { return array(); }
		$rows = array(); foreach ( $ids as $id ) { foreach ( $this->rows( 'SELECT * FROM `' . $this->table( 'posts' ) . '` WHERE ID = ' . $id ) as $row ) { $rows[] = $row; } } return $rows;
	}
	public function post_status( int $post_id ): ?string {
		$rows = $this->rows( 'SELECT post_status FROM `' . $this->table( 'posts' ) . '` WHERE ID = ' . $post_id );
		return empty( $rows ) ? null : (string) $rows[0]->post_status;
	}
	public function post_meta( int $post_id ): array { return $this->rows( 'SELECT meta_key, meta_value FROM `' . $this->table( 'postmeta' ) . '` WHERE post_id = ' . $post_id ); }
	public function post_terms( int $post_id ): array { $prefix = ( $this->prefix )(); return $this->rows( "SELECT tt.taxonomy, t.slug FROM `{$prefix}term_relationships` tr JOIN `{$prefix}term_taxonomy` tt ON tr.term_taxonomy_id = tt.term_taxonomy_id JOIN `{$prefix}terms` t ON tt.term_id = t.term_id WHERE tr.object_id = {$post_id}" ); }
	public function affected_post_ids( string $table_suffix, array $resource_ids, string $operation ): array {
		unset( $operation );
		if ( 'term_relationships' === $table_suffix && in_array( '*', $resource_ids, true ) ) {
			return array_map( static fn( $row ): int => (int) $row->ID, $this->rows( 'SELECT ID FROM `' . $this->table( 'posts' ) . '`' ) );
		}
		$ids = array_values( array_filter( array_map( 'intval', $resource_ids ) ) );
		if ( 'postmeta' === $table_suffix && ! empty( $ids ) ) { $rows = $this->rows( 'SELECT post_id FROM `' . $this->table( 'postmeta' ) . '` WHERE meta_id IN (' . implode( ',', $ids ) . ')' ); return array_map( static fn( $row ): int => (int) $row->post_id, $rows ); }
		return $ids;
	}
	public function options( array $names, bool $all = false ): array {
		$query = 'SELECT option_id, option_name, option_value, autoload FROM `' . $this->table( 'options' ) . '`';
		if ( ! $all ) { $query .= " WHERE option_name IN ('" . implode( "','", array_map( static fn( $name ): string => str_replace( "'", "''", $name ), $names ) ) . "')"; }
		$out = array(); foreach ( $this->rows( $query ) as $row ) { $out[ $row->option_name ] = (array) $row; } return $out;
	}
	public function option_names(): array { return array_map( static fn( $row ): string => (string) $row->option_name, $this->rows( 'SELECT option_name FROM `' . $this->table( 'options' ) . '` ORDER BY option_id' ) ); }
	public function insert_id(): int { return (int) $this->driver->get_insert_id(); }
	public function next_post_id( int $minimum = 1 ): int {
		$rows = $this->rows( 'SELECT COALESCE(MAX(ID), 0) AS max_id FROM `' . $this->table( 'posts' ) . '`' );
		return max( $minimum, (int) ( $rows[0]->max_id ?? 0 ) + 1 );
	}
	public function upsert_file_index( int $post_id, string $path, int $mtime, int $size ): void {
		if ( method_exists( $this->driver, 'update_file_index' ) ) {
			$this->driver->update_file_index( $post_id, $path, $mtime, $size );
			return;
		}
		if ( ! method_exists( $this->driver, 'get_connection' ) ) { return; }
		$pdo = $this->driver->get_connection()->get_pdo();
		$pdo->prepare( 'INSERT OR REPLACE INTO `_markdown_file_index` VALUES (?, ?, ?, ?)' )->execute( array( $post_id, $path, $mtime, $size ) );
		$pdo->prepare( 'UPDATE `' . $this->table( 'posts' ) . '` SET post_content = ? WHERE ID = ?' )->execute( array( '', $post_id ) );
	}
	public function delete_file_index( int $post_id ): void { if ( method_exists( $this->driver, 'remove_from_file_index' ) ) { $this->driver->remove_from_file_index( $post_id ); return; } if ( ! method_exists( $this->driver, 'get_connection' ) ) { return; } $this->driver->get_connection()->get_pdo()->prepare( 'DELETE FROM `_markdown_file_index` WHERE post_id = ?' )->execute( array( $post_id ) ); }
	public function upsert_options_index( array $rows ): void { try { if ( method_exists( $this->driver, 'upsert_options_index' ) ) { $this->driver->upsert_options_index( $rows ); return; } foreach ( $rows as $row ) { $this->driver->get_connection()->get_pdo()->prepare( 'INSERT OR REPLACE INTO `_options_file_index` VALUES (?, ?, ?, ?, ?, ?)' )->execute( array_values( $row ) ); } } catch ( \Throwable $e ) {} }
	public function delete_options_index( array $names ): void { try { if ( method_exists( $this->driver, 'remove_from_options_index' ) ) { $this->driver->remove_from_options_index( $names ); return; } foreach ( $names as $name ) { $this->driver->get_connection()->get_pdo()->prepare( 'DELETE FROM `_options_file_index` WHERE option_name = ?' )->execute( array( $name ) ); } } catch ( \Throwable $e ) {} }
	public function update_manifest( string $path, int $mtime, int $size ): void { try { $this->driver->get_connection()->get_pdo()->prepare( 'INSERT OR REPLACE INTO `_json_file_manifest` (file_name, file_mtime, file_size) VALUES (?, ?, ?)' )->execute( array( $path, $mtime, $size ) ); } catch ( \Throwable $e ) {} }
	public function persist_schema( string $table_suffix, string $operation ): ?string { unset( $operation ); $rows = $this->rows( 'SHOW CREATE TABLE `' . $this->table( $table_suffix ) . '`' ); return empty( $rows ) ? null : ( $rows[0]->{'Create Table'} ?? $rows[0]->{'create table'} ?? null ); }
	public function delete_schema( string $table_suffix ): void { unset( $table_suffix ); }
	public function manifest_entries(): array { try { $out = array(); foreach ( $this->rows( 'SELECT file_name, file_mtime, file_size FROM `_json_file_manifest`' ) as $row ) { $out[ $row->file_name ] = array( 'mtime' => (int) $row->file_mtime, 'size' => (int) $row->file_size ); } return $out; } catch ( \Throwable $e ) { return array(); } }
	public function ensure_reconciliation_state(): void {
		$pdo = $this->driver->get_connection()->get_pdo();
		$pdo->exec( 'CREATE TABLE IF NOT EXISTS `_json_file_manifest` (`file_name` TEXT PRIMARY KEY, `file_mtime` INTEGER NOT NULL, `file_size` INTEGER NOT NULL)' );
		$pdo->exec( 'CREATE TABLE IF NOT EXISTS `_markdown_file_index` (`post_id` INTEGER PRIMARY KEY, `file_path` TEXT NOT NULL, `file_mtime` INTEGER NOT NULL, `file_size` INTEGER NOT NULL)' );
		$pdo->exec( 'CREATE TABLE IF NOT EXISTS `_options_file_index` (`option_name` TEXT PRIMARY KEY, `file_path` TEXT NOT NULL, `file_mtime` INTEGER NOT NULL, `file_size` INTEGER NOT NULL, `option_id` INTEGER NOT NULL, `autoload` TEXT NOT NULL)' );
	}
	public function ensure_tables( array $schemas ): void {
		foreach ( $schemas as $schema ) {
			foreach ( preg_split( '/;\s*/', $schema, -1, PREG_SPLIT_NO_EMPTY ) as $statement ) {
				if ( preg_match( '/^\s*CREATE\s+TABLE/i', $statement ) ) {
					$statement = preg_replace( '/^(\s*CREATE\s+TABLE\s+)/i', '$1IF NOT EXISTS ', $statement );
				} elseif ( preg_match( '/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX/i', $statement ) ) {
					$statement = preg_replace( '/^(\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+)/i', '$1IF NOT EXISTS ', $statement );
				} else {
					continue;
				}
				try {
					$this->driver->query( $statement );
				} catch ( \Throwable $e ) {
				}
			}
		}
	}
	public function hydrate_options( array $rows ): void { foreach ( $rows as $row ) { $row = (array) $row; $this->driver->get_connection()->get_pdo()->prepare( 'INSERT OR REPLACE INTO `' . $this->table( 'options' ) . '` (option_id, option_name, option_value, autoload) VALUES (?, ?, ?, ?)' )->execute( array( $row['option_id'] ?? 0, $row['option_name'], $row['option_value'] ?? '', $row['autoload'] ?? 'yes' ) ); } }
	public function hydrate_table_snapshot( string $table_suffix, callable $rows, ?array $identity = null, ?array $partition = null ): bool {
		$pdo = $this->driver->get_connection()->get_pdo();
		$table = $this->table( $table_suffix );
		$pdo->exec( 'BEGIN IMMEDIATE' );
		try {
			$identity = $this->current_snapshot_identity( $identity );
			if ( null !== $identity && ( $this->manifest_entries()[ '_tables/' . $table_suffix . '.json' ] ?? null ) === array_intersect_key( $identity, array( 'mtime' => true, 'size' => true ) ) ) {
				$pdo->commit();
				return false;
			}
			$this->prepare_snapshot_partition( $pdo, $table, $partition );
			foreach ( $rows() as $row ) {
				$row = (array) $row;
				$columns = array_keys( $row );
				if ( empty( $columns ) ) { continue; }
				$pdo->prepare( 'INSERT OR IGNORE INTO `' . $table . '` (`' . implode( '`,`', $columns ) . '`) VALUES (' . implode( ',', array_fill( 0, count( $columns ), '?' ) ) . ')' )->execute( array_values( $row ) );
			}
			if ( null !== $identity ) {
				if ( $identity !== $this->current_snapshot_identity( $identity ) ) {
					throw new \RuntimeException( 'Table snapshot changed during hydration.' );
				}
				$this->update_manifest( '_tables/' . $table_suffix . '.json', $identity['mtime'], $identity['size'] );
			}
			$pdo->commit();
			return true;
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) { $pdo->rollBack(); }
			throw new \RuntimeException( "Canonical table hydration failed for {$table_suffix}: " . $e->getMessage(), 0, $e );
		}
	}
	private function prepare_snapshot_partition( \PDO $pdo, string $table, ?array $partition ): void {
		if ( null === $partition ) {
			$pdo->exec( "DELETE FROM `{$table}`" );
			return;
		}
		if ( isset( $partition['before_hydration'] ) && is_callable( $partition['before_hydration'] ) ) {
			$partition['before_hydration']();
			return;
		}
		if ( ! empty( $partition['replace_ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', $partition['replace_ids'] ) ) );
			if ( ! empty( $ids ) ) {
				$pdo->exec( "DELETE FROM `{$table}` WHERE ID IN (" . implode( ',', $ids ) . ')' );
			}
			return;
		}
		if ( ! empty( $partition['preserve_existing'] ) ) { return; }
		$post_types = array_values( array_filter( array_map( 'strval', $partition['post_types'] ?? array() ) ) );
		if ( empty( $post_types ) ) { return; }
		$quoted = implode( ',', array_map( array( $pdo, 'quote' ), $post_types ) );
		$posts = $this->table( 'posts' );
		switch ( $partition['kind'] ?? '' ) {
			case 'posts':
				$pdo->exec( "DELETE FROM `{$table}` WHERE post_type IN ({$quoted})" );
				break;
			case 'postmeta':
				$pdo->exec( "DELETE FROM `{$table}` WHERE post_id IN (SELECT ID FROM `{$posts}` WHERE post_type IN ({$quoted}))" );
				break;
			case 'term_relationships':
				$pdo->exec( "DELETE FROM `{$table}` WHERE object_id IN (SELECT ID FROM `{$posts}` WHERE post_type IN ({$quoted}))" );
				break;
		}
	}
	private function current_snapshot_identity( ?array $identity ): ?array {
		if ( null === $identity || empty( $identity['path'] ) ) { return $identity; }
		$path = (string) $identity['path'];
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) { return null; }
		return array( 'mtime' => (int) filemtime( $path ), 'size' => (int) filesize( $path ), 'path' => $path );
	}
	public function hydrate_markdown_posts( array $posts, ?iterable $fallback_posts ): void {
		if ( null !== $fallback_posts ) { $this->hydrate_table_snapshot( 'posts', static fn(): iterable => $fallback_posts ); }
		foreach ( $posts as $post ) {
			$this->hydrate_markdown_post( $post );
			if ( ! empty( $post->_source_file ) ) {
				$path = (string) ( $post->_source_identity ?? basename( $post->_source_file ) );
				$this->upsert_file_index( (int) $post->ID, $path, (int) filemtime( $post->_source_file ), (int) filesize( $post->_source_file ) );
			}
		}
	}
	private function hydrate_markdown_post( object $post ): void {
		$pdo = $this->driver->get_connection()->get_pdo();
		$id = (int) $post->ID;
		$pdo->exec( 'BEGIN IMMEDIATE' );
		try {
			$pdo->exec( 'DELETE FROM `' . $this->table( 'postmeta' ) . '` WHERE post_id = ' . $id );
			$pdo->exec( 'DELETE FROM `' . $this->table( 'term_relationships' ) . '` WHERE object_id = ' . $id );
			$pdo->exec( 'DELETE FROM `' . $this->table( 'posts' ) . '` WHERE ID = ' . $id );
			$row = array_filter( (array) $post, static fn( $value, $key ): bool => 'filter' !== $key && ! str_starts_with( (string) $key, '_' ), ARRAY_FILTER_USE_BOTH );
			$row['post_content'] = '';
			$this->insert_row( $pdo, $this->table( 'posts' ), $row );
			foreach ( (array) ( $post->_frontmatter_meta ?? array() ) as $key => $value ) {
				foreach ( is_array( $value ) ? $value : array( $value ) as $item ) {
					$this->insert_row( $pdo, $this->table( 'postmeta' ), array( 'post_id' => $id, 'meta_key' => (string) $key, 'meta_value' => (string) $item ) );
				}
			}
			$term_map = $this->term_map();
			foreach ( (array) ( $post->_frontmatter_terms ?? array() ) as $taxonomy => $slugs ) {
				foreach ( is_array( $slugs ) ? $slugs : array() as $slug ) {
					$key = $taxonomy . '::' . $slug;
					if ( isset( $term_map[ $key ] ) ) { $this->insert_row( $pdo, $this->table( 'term_relationships' ), array( 'object_id' => $id, 'term_taxonomy_id' => $term_map[ $key ], 'term_order' => 0 ) ); }
				}
			}
			$pdo->commit();
		} catch ( \Throwable $e ) {
			if ( $pdo->inTransaction() ) { $pdo->rollBack(); }
			throw $e;
		}
	}
	private function insert_row( \PDO $pdo, string $table, array $row ): void {
		$columns = array_keys( $row );
		$pdo->prepare( 'INSERT OR IGNORE INTO `' . $table . '` (`' . implode( '`,`', $columns ) . '`) VALUES (' . implode( ',', array_fill( 0, count( $columns ), '?' ) ) . ')' )->execute( array_values( $row ) );
	}
	private function term_map(): array {
		$prefix = ( $this->prefix )();
		$out = array();
		foreach ( $this->rows( "SELECT tt.term_taxonomy_id, tt.taxonomy, t.slug FROM `{$prefix}term_taxonomy` tt JOIN `{$prefix}terms` t ON tt.term_id = t.term_id" ) as $row ) { $out[ $row->taxonomy . '::' . $row->slug ] = (int) $row->term_taxonomy_id; }
		return $out;
	}
	public function reconcile_markdown( array $files, callable $parse_file ): array {
		$index = array();
		foreach ( $this->rows( 'SELECT post_id, file_path, file_mtime, file_size FROM `_markdown_file_index`' ) as $row ) { $index[ $row->file_path ] = $row; }
		$changed = $new = 0;
		foreach ( $files as $path => $file ) {
			$cached = $index[ $path ] ?? null;
			if ( $cached && (int) $cached->file_mtime === $file['mtime'] && (int) $cached->file_size === $file['size'] ) { unset( $index[ $path ] ); continue; }
			$post = $parse_file( $file['absolute'], $file['parent_id'] );
			if ( $post ) { $this->hydrate_markdown_posts( array( $post ), null ); $this->upsert_file_index( (int) $post->ID, $path, $file['mtime'], $file['size'] ); $cached ? $changed++ : $new++; }
			unset( $index[ $path ] );
		}
		$pdo = $this->driver->get_connection()->get_pdo();
		foreach ( $index as $row ) { $id = (int) $row->post_id; $pdo->exec( 'DELETE FROM `' . $this->table( 'postmeta' ) . '` WHERE post_id = ' . $id ); $pdo->exec( 'DELETE FROM `' . $this->table( 'term_relationships' ) . '` WHERE object_id = ' . $id ); $pdo->exec( 'DELETE FROM `' . $this->table( 'posts' ) . '` WHERE ID = ' . $id ); $this->delete_file_index( $id ); }
		return array( 'markdown_files_changed' => $changed, 'markdown_files_new' => $new, 'markdown_files_deleted' => count( $index ) );
	}
	public function mutations_for_query( string $query, array $operation ): array {
		$table = $operation['table']; $ids = array();
		if ( preg_match_all( '/\b(?:ID|post_id|object_id|option_name)\b\s*(?:=|IN\s*\()\s*(?:[\'\"]?([^\s,\)\'\"]+)|([^\)]*))/i', $query, $matches, PREG_SET_ORDER ) ) { foreach ( $matches as $match ) { foreach ( explode( ',', $match[1] ?: $match[2] ) as $id ) { $id = trim( $id, " \t\n\r\0\x0B'\""); if ( '' !== $id ) { $ids[ $id ] = true; } } } }
		if ( empty( $ids ) && 'term_relationships' === $this->strip_prefix( $table ) ) {
			$object_id = $this->inserted_column_value( $query, 'object_id' );
			if ( null !== $object_id ) { $ids[ $object_id ] = true; }
			if ( empty( $ids ) && preg_match( '/\bterm_taxonomy_id\b\s*=\s*(\d+)/i', $query, $match ) ) {
				foreach ( $this->rows( 'SELECT object_id FROM `' . $this->table( 'term_relationships' ) . '` WHERE term_taxonomy_id = ' . (int) $match[1] ) as $row ) { $ids[ (string) $row->object_id ] = true; }
			}
		}
		if ( empty( $ids ) && 'options' === $this->strip_prefix( $table ) && in_array( $operation['op'], array( 'INSERT', 'REPLACE' ), true ) ) {
			$name = $this->inserted_column_value( $query, 'option_name' );
			if ( null !== $name ) { $ids[ $name ] = true; }
		}
		if ( empty( $ids ) && in_array( $operation['op'], array( 'INSERT', 'REPLACE' ), true ) ) { $ids[ (string) $this->driver->get_insert_id() ] = true; }
		if ( empty( $ids ) ) { $ids['*'] = true; }
		return array_map( static fn( string $id ): array => array( 'stable_id' => $table . ':' . $id, 'kind' => 'DDL' === $operation['type'] ? 'schema' : 'table', 'operation' => $operation['op'], 'table' => $table, 'resource_ids' => array( $id ), 'scope' => array() ), array_keys( $ids ) );
	}
	private function strip_prefix( string $table ): string { $prefix = ( $this->prefix )(); return str_starts_with( $table, $prefix ) ? substr( $table, strlen( $prefix ) ) : $table; }
	private function inserted_column_value( string $query, string $column ): ?string {
		if ( ! preg_match( '/\(([^)]*)\)\s*VALUES\s*\((.*)\)/is', $query, $matches ) ) { return null; }
		$columns = str_getcsv( $matches[1], ',', '`', '\\' );
		$values = str_getcsv( $matches[2], ',', "'", '\\' );
		foreach ( $columns as $index => $name ) {
			if ( trim( $name, " \t\n\r\0\x0B`\"'" ) === $column && isset( $values[ $index ] ) ) { return stripslashes( trim( $values[ $index ], " \t\n\r\0\x0B'\"" ) ); }
		}
		return null;
	}
}

function wp_markdown_backend_operations_from_legacy( $driver, $prefix = 'wp_' ): WP_Markdown_Backend_Operations { return new WP_Markdown_SQLite_Operations( $driver, $prefix ); }

function wp_markdown_runtime_adapter( $connection, string $database, WP_Markdown_Storage $storage, $prefix = 'wp_', ?WP_Markdown_Backend_Capabilities $capabilities = null ): array {
	$driver = new WP_Markdown_Driver( $connection, $database, $storage, $capabilities );
	return array( new WP_Markdown_SQLite_Operations( $driver, $prefix ), $driver );
}

function wp_markdown_runtime_identity_error( bool $mismatch ): string {
	return $mismatch
		? 'The supplied SQLite cache identity does not match the canonical files.'
		: 'A canonical identity is required for a warm SQLite cache.';
}
