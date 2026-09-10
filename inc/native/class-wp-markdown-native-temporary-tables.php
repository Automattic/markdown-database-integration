<?php
/** Per-runtime storage and catalog state for connection-scoped temporary tables. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Temporary_Tables {
	private string $root;
	/** @var array<string,true> */
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

	public function add( string $table ): void {
		$this->tables[ $table ] = true;
	}

	public function remove( string $table ): void {
		unset( $this->tables[ $table ] );
	}
}
