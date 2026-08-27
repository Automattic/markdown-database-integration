<?php
/** Native MySQL/MariaDB operations for canonical publication. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../interface-wp-markdown-backend-operations.php';
require_once __DIR__ . '/../class-wp-markdown-mutation-impact.php';

final class WP_Markdown_MySQL_Operations implements WP_Markdown_Backend_Operations {
	private const GLOBAL_TABLES = array( 'blogs', 'blogmeta', 'registration_log', 'signups', 'site', 'sitemeta', 'usermeta', 'users' );

	public function __construct( private object $connection, private string $table_prefix, private string $base_prefix, private ?array $captured_intent = null ) {
		if ( ! self::valid_prefix( $table_prefix ) || ! self::valid_prefix( $base_prefix ) ) {
			throw new InvalidArgumentException( 'MySQL operations require valid WordPress table prefixes.' );
		}
	}

	public static function logical_table_suffix( string $table, string $table_prefix, string $base_prefix ): string {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || ! self::valid_prefix( $table_prefix ) || ! self::valid_prefix( $base_prefix ) ) {
			throw new InvalidArgumentException( 'Invalid MySQL table scope.' );
		}
		if ( str_starts_with( $table, $table_prefix ) ) {
			return substr( $table, strlen( $table_prefix ) );
		}
		$suffix = str_starts_with( $table, $base_prefix ) ? substr( $table, strlen( $base_prefix ) ) : '';
		if ( in_array( $suffix, self::GLOBAL_TABLES, true ) ) {
			return $suffix;
		}
		throw new RuntimeException( 'MySQL table is outside the captured WordPress scope.' );
	}

	public function table_rows( string $table_suffix, ?array $policy = null ): iterable {
		$captured = $this->captured_rows( $table_suffix );
		if ( null !== $captured && is_array( $policy ) && isset( $policy['partition_by'], $policy['resource_ids'] ) ) {
			return $captured;
		}
		$table = $this->table( $table_suffix );
		$query = 'SELECT * FROM `' . $table . '` ORDER BY 1';
		if ( is_array( $policy ) && isset( $policy['query'] ) ) {
			$query = (string) $policy['query'];
		}
		if ( is_array( $policy ) && isset( $policy['limit'] ) ) {
			$query .= ' LIMIT ' . max( 0, (int) $policy['limit'] );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$query = (string) apply_filters( 'markdown_db_persistent_table_query', $query, $table_suffix, $table, $policy );
		}
		if ( is_array( $policy ) && isset( $policy['partition_by'], $policy['resource_ids'] ) ) {
			$column = strtolower( (string) $policy['partition_by'] );
			$values = array_values( array_map( 'strval', (array) $policy['resource_ids'] ) );
			if ( ! preg_match( '/^[a-z_][a-z0-9_]*$/', $column ) || ! $values ) {
				throw new RuntimeException( 'MySQL table partition policy is invalid.' );
			}
			$query = 'SELECT * FROM ( ' . $query . ' ) AS mdi_partition_source WHERE `' . $column . '` IN (' . implode( ',', array_fill( 0, count( $values ), '?' ) ) . ') ORDER BY `' . $column . '`';
			return $this->prepared_rows( $query, str_repeat( 's', count( $values ) ), $values );
		}
		return $this->rows( $query );
	}

	public function post_rows( array $post_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$captured = $this->captured_rows( 'posts' );
		if ( null !== $captured ) { return array_values( array_filter( $captured, static fn( array $row ): bool => in_array( (int) ( $row['ID'] ?? 0 ), $ids, true ) ) ); }
		return $ids ? $this->rows( 'SELECT * FROM `' . $this->table( 'posts' ) . '` WHERE `ID` IN (' . implode( ',', $ids ) . ') ORDER BY `ID`' ) : array();
	}

	public function post_status( int $post_id ): ?string {
		$captured = $this->captured_rows( 'posts' );
		if ( null !== $captured ) { foreach ( $captured as $row ) { if ( $post_id === (int) ( $row['ID'] ?? 0 ) ) { return (string) ( $row['post_status'] ?? '' ); } } return null; }
		$rows = $this->rows( 'SELECT `post_status` FROM `' . $this->table( 'posts' ) . '` WHERE `ID`=' . max( 0, $post_id ) );
		return $rows ? (string) $rows[0]['post_status'] : null;
	}

	public function post_meta( int $post_id ): array { return array_map( static fn( array $row ): object => (object) $row, $this->rows( 'SELECT `meta_key`,`meta_value` FROM `' . $this->table( 'postmeta' ) . '` WHERE `post_id`=' . max( 0, $post_id ) . ' ORDER BY `meta_id`' ) ); }
	public function post_terms( int $post_id ): array { $prefix = $this->table_prefix; return array_map( static fn( array $row ): object => (object) $row, $this->rows( "SELECT tt.`taxonomy`,t.`slug` FROM `{$prefix}term_relationships` tr JOIN `{$prefix}term_taxonomy` tt ON tr.`term_taxonomy_id`=tt.`term_taxonomy_id` JOIN `{$prefix}terms` t ON tt.`term_id`=t.`term_id` WHERE tr.`object_id`=" . max( 0, $post_id ) . ' ORDER BY tt.`taxonomy`,t.`slug`' ) ); }

	public function affected_post_ids( string $table_suffix, array $resource_ids, string $operation, array $scope = array() ): array {
		unset( $operation );
		$identity = strtolower( (string) ( $scope['identity']['column'] ?? '' ) );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $resource_ids ) ) ) );
		if ( in_array( '*', $resource_ids, true ) ) {
			return array_map( static fn( array $row ): int => (int) $row['ID'], $this->rows( 'SELECT `ID` FROM `' . $this->table( 'posts' ) . '` ORDER BY `ID`' ) );
		}
		if ( 'postmeta' === $table_suffix ) {
			if ( 'post_id' === $identity ) { return $ids; }
			if ( null !== ( $captured = $this->captured_rows( $table_suffix ) ) ) { return array_values( array_unique( array_map( static fn( array $row ): int => (int) ( $row['post_id'] ?? 0 ), $captured ) ) ); }
			return $this->integer_column( 'SELECT `post_id` FROM `' . $this->table( 'postmeta' ) . '` WHERE `meta_id` IN (' . implode( ',', $ids ?: array( 0 ) ) . ')', 'post_id' );
		}
		if ( 'term_relationships' === $table_suffix ) {
			if ( 'object_id' === $identity ) { return $ids; }
			return $this->integer_column( 'SELECT DISTINCT `object_id` FROM `' . $this->table( 'term_relationships' ) . '` WHERE `term_taxonomy_id` IN (' . implode( ',', $ids ?: array( 0 ) ) . ')', 'object_id' );
		}
		if ( 'term_taxonomy' === $table_suffix ) {
			$column = 'term_id' === $identity ? 'tt.`term_id`' : 'tt.`term_taxonomy_id`';
			return $this->integer_column( 'SELECT DISTINCT tr.`object_id` FROM `' . $this->table( 'term_relationships' ) . '` tr JOIN `' . $this->table( 'term_taxonomy' ) . '` tt ON tr.`term_taxonomy_id`=tt.`term_taxonomy_id` WHERE ' . $column . ' IN (' . implode( ',', $ids ?: array( 0 ) ) . ')', 'object_id' );
		}
		if ( 'terms' === $table_suffix ) {
			return $this->integer_column( 'SELECT DISTINCT tr.`object_id` FROM `' . $this->table( 'term_relationships' ) . '` tr JOIN `' . $this->table( 'term_taxonomy' ) . '` tt ON tr.`term_taxonomy_id`=tt.`term_taxonomy_id` WHERE tt.`term_id` IN (' . implode( ',', $ids ?: array( 0 ) ) . ')', 'object_id' );
		}
		return $ids;
	}

	public function options( array $names, bool $all = false ): array {
		$captured = $this->captured_rows( 'options' );
		if ( null !== $captured && ! $all ) { $rows = array_filter( $captured, static fn( array $row ): bool => in_array( (string) ( $row['option_name'] ?? '' ), $names, true ) ); $out = array(); foreach ( $rows as $row ) { $out[ (string) $row['option_name'] ] = $row; } return $out; }
		$query = 'SELECT `option_id`,`option_name`,`option_value`,`autoload` FROM `' . $this->table( 'options' ) . '`';
		$rows = $all ? $this->rows( $query ) : ( $names ? $this->prepared_rows( $query . ' WHERE `option_name` IN (' . implode( ',', array_fill( 0, count( $names ), '?' ) ) . ')', str_repeat( 's', count( $names ) ), array_values( array_map( 'strval', $names ) ) ) : array() );
		$out = array(); foreach ( $rows as $row ) { $out[ (string) $row['option_name'] ] = $row; } return $out;
	}
	public function option_names(): array { return array_map( static fn( array $row ): string => (string) $row['option_name'], $this->rows( 'SELECT `option_name` FROM `' . $this->table( 'options' ) . '` ORDER BY `option_id`' ) ); }
	public function insert_id(): int { return 0; }
	public function next_post_id( int $minimum = 1 ): int { $rows = $this->rows( 'SELECT COALESCE(MAX(`ID`),0) AS `max_id` FROM `' . $this->table( 'posts' ) . '`' ); return max( $minimum, (int) ( $rows[0]['max_id'] ?? 0 ) + 1 ); }
	public function upsert_file_index( int $post_id, string $path, int $mtime, int $size ): void { unset( $post_id, $path, $mtime, $size ); }
	public function delete_file_index( int $post_id ): void { unset( $post_id ); }
	public function upsert_options_index( array $rows ): void { unset( $rows ); }
	public function delete_options_index( array $names ): void { unset( $names ); }
	public function update_manifest( string $path, int $mtime, int $size ): void { unset( $path, $mtime, $size ); }
	public function persist_schema( string $table_suffix, string $operation ): ?string { unset( $operation ); $captured = $this->captured_intent['schema']['create_sql'] ?? null; if ( is_string( $captured ) && '' !== $captured ) { return $captured; } $rows = $this->rows( 'SHOW CREATE TABLE `' . $this->table( $table_suffix ) . '`' ); return $rows ? (string) ( $rows[0]['Create Table'] ?? '' ) : null; }
	public function delete_schema( string $table_suffix ): void { unset( $table_suffix ); }
	public function manifest_entries(): array { return array(); }
	public function hydrate_markdown_posts( array $posts, ?iterable $fallback_posts ): void { unset( $posts, $fallback_posts ); $this->unsupported_reconstruction(); }
	public function hydrate_table_snapshot( string $table_suffix, callable $rows, ?array $identity = null, ?array $partition = null ): bool { unset( $table_suffix, $rows, $identity, $partition ); $this->unsupported_reconstruction(); }
	public function reconcile_markdown( array $files, callable $parse_file ): array { unset( $files, $parse_file ); $this->unsupported_reconstruction(); }
	public function hydrate_options( array $rows ): void { unset( $rows ); $this->unsupported_reconstruction(); }
	public function ensure_tables( array $schemas ): void { unset( $schemas ); $this->unsupported_reconstruction(); }
	public function ensure_reconciliation_state(): void {}
	public function mutations_for_query( string $query, array $operation ): array { $table = (string) ( $operation['table'] ?? '' ); return WP_Markdown_Mutation_Impact::for_query( $query, $operation, $table, 0 ); }

	private static function valid_prefix( string $prefix ): bool { return 1 === preg_match( '/^[A-Za-z0-9_]+_$/', $prefix ); }
	private function captured_rows( string $table_suffix ): ?array { if ( ! is_array( $this->captured_intent ) || ! array_key_exists( 'current_rows', $this->captured_intent ) || $table_suffix !== ( $this->captured_intent['table_suffix'] ?? null ) || ! is_array( $this->captured_intent['current_rows'] ) ) { return null; } return array_values( array_map( static fn( array $row ): array => $row, $this->captured_intent['current_rows'] ) ); }
	private function table( string $suffix ): string { if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $suffix ) ) { throw new InvalidArgumentException( 'Invalid MySQL table suffix.' ); } return ( in_array( $suffix, self::GLOBAL_TABLES, true ) ? $this->base_prefix : $this->table_prefix ) . $suffix; }
	private function unsupported_reconstruction(): never { throw new LogicException( 'mysql-full cold reconstruction is not implemented.' ); }
	private function integer_column( string $query, string $column ): array { return array_values( array_unique( array_map( static fn( array $row ): int => (int) $row[ $column ], $this->rows( $query ) ) ) ); }
	private function rows( string $query ): array { $result = $this->connection->query( $query ); if ( false === $result ) { throw new RuntimeException( 'MySQL canonical read failed.' ); } if ( ! is_object( $result ) || ! method_exists( $result, 'fetch_assoc' ) ) { return array(); } $rows = array(); while ( $row = $result->fetch_assoc() ) { $rows[] = $row; } if ( method_exists( $result, 'free' ) ) { $result->free(); } return $rows; }
	private function prepared_rows( string $query, string $types, array $values ): array { $statement = $this->connection->prepare( $query ); if ( false === $statement || ! $statement->bind_param( $types, ...$values ) || ! $statement->execute() ) { throw new RuntimeException( 'MySQL canonical prepared read failed.' ); } $rows = array(); if ( method_exists( $statement, 'get_result' ) && is_object( $result = $statement->get_result() ) ) { while ( $row = $result->fetch_assoc() ) { $rows[] = $row; } $result->free(); } else { $metadata = $statement->result_metadata(); if ( is_object( $metadata ) ) { $row = array(); $refs = array(); foreach ( $metadata->fetch_fields() as $field ) { $row[ $field->name ] = null; $refs[] =& $row[ $field->name ]; } $statement->bind_result( ...$refs ); while ( $statement->fetch() ) { $rows[] = $row; } $metadata->free(); } } $statement->close(); return $rows; }
}
