<?php
/** Per-runtime storage and catalog state for connection-scoped temporary tables. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Temporary_Tables {
	private string $root;
	/** @var array<string,array{schema:?WP_Markdown_Native_Table_Schema,provider:?WP_Markdown_Native_Table_Provider,definition:array<string,mixed>}> */
	private array $tables = array();

	public function __construct() {
		try {
			$this->root = sys_get_temp_dir() . '/mdi-native-session-' . bin2hex( random_bytes( 16 ) );
		} catch ( Throwable ) {
			throw new RuntimeException( 'A temporary table namespace could not be created.' );
		}
		if ( ! @mkdir( $this->root . '/_schema', 0700, true ) || ! @mkdir( $this->root . '/_tables', 0700, true ) ) {
			throw new RuntimeException( 'A temporary table namespace could not be materialized.' );
		}
		$root = realpath( $this->root );
		if ( false === $root || is_link( $this->root ) ) {
			throw new RuntimeException( 'A temporary table namespace is unsafe.' );
		}
		$this->root = $root;
	}

	public function root(): string {
		return $this->root;
	}

	public function has( string $table ): bool {
		return isset( $this->tables[ $table ] );
	}

	/** @param array<string,mixed> $definition */
	public function add( string $table, ?WP_Markdown_Native_Table_Schema $schema, ?WP_Markdown_Native_Table_Provider $provider, array $definition ): void {
		$this->tables[ $table ] = array( 'schema' => $schema, 'provider' => $provider, 'definition' => $definition );
	}

	/** @return array{schema:?WP_Markdown_Native_Table_Schema,provider:?WP_Markdown_Native_Table_Provider,definition:array<string,mixed>}|null */
	public function table( string $table ): ?array {
		return $this->tables[ $table ] ?? null;
	}

	public function remove( string $table ): void {
		unset( $this->tables[ $table ] );
	}
}
