<?php
/** MySQL-owned row lookup and schema evidence for semantic outbox planning. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-mutation-impact.php';

final class WP_Markdown_MySQL_Impact_Adapter {
	private const GLOBAL_TABLES = array( 'blogs', 'blogmeta', 'registration_log', 'signups', 'site', 'sitemeta', 'usermeta', 'users' );

	public function __construct( private object $connection ) {}

	/** @return array<int,array<string,mixed>> */
	public function intents( array $record ): array {
		$payload  = $record['payload'] ?? null;
		$scope    = is_array( $payload ) ? ( $payload['scope'] ?? null ) : null;
		$mutation = is_array( $payload ) ? ( $payload['mutation'] ?? null ) : null;
		if ( ! is_array( $payload ) || ! is_array( $scope ) || ! is_array( $mutation ) ) {
			throw new RuntimeException( 'Outbox payload has an invalid semantic shape.' );
		}
		$prefix = $this->validated_prefix( $scope['table_prefix'] ?? null );
		$base   = $this->validated_prefix( $scope['base_prefix'] ?? null );
		$tables = $mutation['tables'] ?? null;
		if ( ! is_array( $tables ) || ! $tables || count( $tables ) !== count( array_unique( $tables, SORT_STRING ) ) || ! is_string( $payload['database'] ?? null ) || '' === $payload['database'] || ! is_int( $scope['blog_id'] ?? null ) || $scope['blog_id'] < 0 || ! in_array( $mutation['kind'] ?? null, array( 'table', 'schema' ), true ) || ! preg_match( '/^[A-Z]+$/', (string) ( $mutation['operation'] ?? '' ) ) || ! is_string( $mutation['sql'] ?? null ) ) {
			throw new RuntimeException( 'Outbox payload has incomplete mutation scope.' );
		}

		$operation = array( 'type' => 'schema' === $mutation['kind'] ? 'DDL' : 'DML', 'op' => $mutation['operation'] );
		if ( 'DML' === $operation['type'] && array_key_exists( 'rows_affected', $payload['result'] ?? array() ) && 0 === (int) $payload['result']['rows_affected'] ) {
			return array();
		}
		$out       = array();
		foreach ( $tables as $table ) {
			$table = $this->scoped_table( $table, $prefix, $base );
			foreach ( WP_Markdown_Mutation_Impact::for_query( $mutation['sql'], $operation, $table, (int) ( $payload['result']['insert_id'] ?? 0 ), fn( int $term_id ): array => $this->term_objects( $prefix, $term_id ) ) as $intent ) {
				if ( 'DELETE' === $intent['operation'] && ( str_ends_with( $table, 'postmeta' ) || str_ends_with( $table, 'term_relationships' ) ) ) {
					$intent['resource_ids'] = array( '*' );
					$intent['scope'] = array( 'resource_ids_by_column' => array(), 'assigned_columns' => array(), 'conservative' => true );
				}
				$intent['event_id']     = (string) ( $record['event_id'] ?? '' );
				$intent['stable_id']    = $intent['event_id'] . ':' . $intent['stable_id'];
				$intent['database']     = $payload['database'];
				$intent['blog_id']      = $scope['blog_id'];
				$intent['table_prefix'] = $prefix;
				$intent['base_prefix']  = $base;
				if ( 'schema' === $intent['kind'] ) {
					$intent['schema'] = 'DROP' === $intent['operation'] ? array( 'action' => 'delete' ) : array( 'action' => 'upsert', 'create_sql' => $this->show_create( $table ) );
				} elseif ( '*' !== $intent['resource_ids'][0] ) {
					$intent['current_rows'] = $this->rows_for_identity( $table, $intent['scope']['identity'] ?? array() );
				}
				$out[] = $intent;
			}
		}
		return $out;
	}

	private function validated_prefix( mixed $prefix ): string {
		if ( ! is_string( $prefix ) || ! preg_match( '/^[A-Za-z0-9_]+_$/', $prefix ) ) { throw new RuntimeException( 'Outbox payload has an invalid table prefix.' ); }
		return $prefix;
	}

	private function scoped_table( mixed $table, string $prefix, string $base ): string {
		if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) { throw new RuntimeException( 'Outbox payload has an invalid table name.' ); }
		if ( $prefix === $base && str_starts_with( $table, $base ) && preg_match( '/^\d+_/', substr( $table, strlen( $base ) ) ) ) { throw new RuntimeException( 'Outbox payload references another blog namespace.' ); }
		if ( str_starts_with( $table, $prefix ) ) { return $table; }
		if ( str_starts_with( $table, $base ) && in_array( substr( $table, strlen( $base ) ), self::GLOBAL_TABLES, true ) ) { return $table; }
		throw new RuntimeException( 'Outbox payload references a table outside its captured blog scope.' );
	}

	public function planner_diagnostics(): array { return array( 'ready' => is_object( $this->connection ), 'contract' => 'validated_scope_and_parameterized_row_lookup' ); }

	private function term_objects( string $prefix, int $term_id ): array { return array_map( static fn( array $row ): int => (int) $row['object_id'], $this->rows( "SELECT `object_id` FROM `{$prefix}term_relationships` WHERE `term_taxonomy_id`=?", 'i', array( $term_id ) ) ); }
	private function show_create( string $table ): string { $result = $this->connection->query( "SHOW CREATE TABLE `{$table}`" ); if ( ! is_object( $result ) || ! ( $row = $result->fetch_assoc() ) ) { throw new RuntimeException( 'Could not inspect current MySQL table schema.' ); } return (string) ( $row['Create Table'] ?? '' ); }
	private function rows_for_identity( string $table, array $identity ): array { $column = $identity['column'] ?? ''; $values = $identity['values'] ?? array(); if ( ! is_string( $column ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $column ) || ! is_array( $values ) || 1 !== count( $values ) || ! is_scalar( $values[0] ) ) { return array(); } return $this->rows( "SELECT * FROM `{$table}` WHERE `{$column}`=?", 's', array( (string) $values[0] ) ); }
	private function rows( string $sql, string $types, array $values ): array { $statement = $this->connection->prepare( $sql ); if ( false === $statement || ! $statement->bind_param( $types, ...$values ) || ! $statement->execute() ) { throw new RuntimeException( 'MySQL impact row lookup failed.' ); } $result = method_exists( $statement, 'get_result' ) ? $statement->get_result() : false; if ( is_object( $result ) ) { $rows = $result->fetch_all( MYSQLI_ASSOC ); $result->free(); $statement->close(); return $rows; } $metadata = $statement->result_metadata(); if ( ! is_object( $metadata ) ) { $statement->close(); return array(); } $row = array(); $references = array(); foreach ( $metadata->fetch_fields() as $field ) { $row[ $field->name ] = null; $references[] =& $row[ $field->name ]; } $statement->bind_result( ...$references ); $rows = array(); while ( $statement->fetch() ) { $rows[] = $row; } $metadata->free(); $statement->close(); return $rows; }
}
