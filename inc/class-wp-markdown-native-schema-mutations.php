<?php
/** Atomic native CREATE TABLE persistence over the canonical schema catalog. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_Schema_Mutation_Runtime {
	private string $state_root;

	public function __construct(
		string $state_root,
		private WP_Markdown_Native_Table_Registry $registry
	) {
		$root = realpath( $state_root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new InvalidArgumentException( 'The canonical state root must be an existing directory.' );
		}
		$this->state_root = rtrim( $root, DIRECTORY_SEPARATOR );
	}

	public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
		$sql = trim( $request->sql() );
		if ( str_ends_with( $sql, ';' ) ) {
			$sql = rtrim( substr( $sql, 0, -1 ) );
		}
		if ( '' === $sql || str_contains( $sql, ';' ) ) {
			return $this->failure( 'unsupported_grammar', 'mdi-native requires one bounded CREATE TABLE statement.' );
		}

		try {
			$definitions = WP_Markdown_Native_Schema_Catalog::compile( $sql, array( $request->table_prefix() ) );
		} catch ( InvalidArgumentException ) {
			return $this->failure( 'unsupported_schema', 'mdi-native cannot compile the requested table definition.' );
		}
		if ( 1 !== count( $definitions ) ) {
			return $this->failure( 'unsupported_schema', 'mdi-native requires one prefixed table definition.' );
		}

		$suffix = (string) array_key_first( $definitions );
		$table = $request->table_prefix() . $suffix;
		$definition = $definitions[ $suffix ];
		if ( null !== $this->registry->definition( $table ) ) {
			return $this->failure( 'table_exists', 'mdi-native cannot create a table that already exists.' );
		}

		$directory = $this->schema_directory();
		if ( $directory instanceof WP_Markdown_Query_Result ) {
			return $directory;
		}
		$lock = @fopen( $directory . '/.mdi-native.lock', 'c+b' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return $this->failure( 'mutation_lock_failed', 'The canonical schema mutation lock could not be acquired.' );
		}

		try {
			$path = $directory . '/' . $suffix . '.sql';
			if ( file_exists( $path ) || is_link( $path ) || null !== $this->registry->definition( $table ) ) {
				return $this->failure( 'table_exists', 'mdi-native cannot create a table that already exists.' );
			}
			$written = $this->write( $path, $sql . ";\n" );
			if ( $written instanceof WP_Markdown_Query_Result ) {
				return $written;
			}
			$schema = WP_Markdown_Native_Schema_Catalog::indexed_snapshot_schema( $definition );
			if ( null === $schema ) {
				$this->registry->register_definition( $table, $definition );
			} else {
				$this->registry->register(
					$table,
					$schema,
					new WP_Markdown_Native_JSON_Snapshot_Provider( $this->state_root, $schema, $suffix . '.json' )
				);
			}
			return WP_Markdown_Query_Result::schema_changed();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function schema_directory(): string|WP_Markdown_Query_Result {
		$path = $this->state_root . '/_schema';
		if ( ! file_exists( $path ) && ! @mkdir( $path, 0755 ) && ! is_dir( $path ) ) {
			return $this->failure( 'schema_directory_failed', 'The canonical schema directory could not be created.' );
		}
		$root = realpath( $path );
		if ( false === $root || ! is_dir( $root ) || is_link( $path ) || dirname( $root ) !== $this->state_root ) {
			return $this->failure( 'unsafe_schema_directory', 'The canonical schema directory is unavailable or unsafe.' );
		}
		return $root;
	}

	private function write( string $path, string $contents ): true|WP_Markdown_Query_Result {
		try {
			$temp = $path . '.tmp-' . getmypid() . '-' . bin2hex( random_bytes( 8 ) );
		} catch ( Throwable ) {
			return $this->failure( 'schema_temp_failed', 'The canonical schema temporary path could not be created.' );
		}
		$handle = @fopen( $temp, 'x+b' );
		if ( false === $handle ) {
			return $this->failure( 'schema_temp_failed', 'The canonical schema temporary file could not be created.' );
		}
		$error = null;
		try {
			$length = strlen( $contents );
			$offset = 0;
			while ( $offset < $length ) {
				$written = fwrite( $handle, substr( $contents, $offset ) );
				if ( false === $written || 0 === $written ) {
					$error = $this->failure( 'schema_write_failed', 'The canonical schema could not be written.' );
					break;
				}
				$offset += $written;
			}
			if ( null === $error && ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) ) {
				$error = $this->failure( 'schema_flush_failed', 'The canonical schema could not be flushed.' );
			}
		} finally {
			fclose( $handle );
		}
		if ( null !== $error ) {
			@unlink( $temp );
			return $error;
		}
		if ( ! @rename( $temp, $path ) ) {
			@unlink( $temp );
			return $this->failure( 'schema_publish_failed', 'The canonical schema could not be atomically published.' );
		}
		return true;
	}

	private function failure( string $reason, string $message ): WP_Markdown_Query_Result {
		return WP_Markdown_Query_Result::failure(
			array(
				'code'    => 'markdown_db_native_schema_mutation_failed',
				'reason'  => $reason,
				'message' => $message,
			)
		);
	}
}
