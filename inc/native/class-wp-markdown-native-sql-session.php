<?php
/** SQL mode and statement diagnostics shared by one logical native connection. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_Native_SQL_Session {
	private string $sql_mode = '';
	private array $warnings = array();
	private int $warning_count = 0;

	public function sql_mode(): string { return $this->sql_mode; }

	public function strict(): bool {
		return in_array( 'STRICT_TRANS_TABLES', explode( ',', $this->sql_mode ), true ) || in_array( 'STRICT_ALL_TABLES', explode( ',', $this->sql_mode ), true );
	}

	public function reset(): void {
		$this->sql_mode = '';
		$this->warnings = array();
		$this->warning_count = 0;
	}

	public function warn_missing_default( string $column, bool $error = false ): void {
		++$this->warning_count;
		if ( count( $this->warnings ) < 64 ) {
			$this->warnings[] = array( 'Level' => $error ? 'Error' : 'Warning', 'Code' => '1364', 'Message' => "Field '{$column}' doesn't have a default value" );
		}
	}

	/** Handle supported session statements, otherwise start a new diagnostic area. */
	public function execute( string $sql ): ?WP_Markdown_Query_Result {
		if ( 1 === preg_match( '/^\s*SHOW\s+WARNINGS\s*;?\s*$/i', $sql ) ) {
			return WP_Markdown_Query_Result::selected( $this->warnings, array(
				array( 'name' => 'Level', 'table' => '', 'type' => 253 ),
				array( 'name' => 'Code', 'table' => '', 'type' => 3 ),
				array( 'name' => 'Message', 'table' => '', 'type' => 253 ),
			) );
		}
		if ( 1 === preg_match( '/^\s*SELECT\s+(@@(?:SESSION\.)?warning_count)\s*;?\s*$/i', $sql, $match ) ) {
			return WP_Markdown_Query_Result::selected( array( array( $match[1] => (string) $this->warning_count ) ), array( array( 'name' => $match[1], 'table' => '', 'type' => 8 ) ) );
		}
		$this->warnings = array();
		$this->warning_count = 0;
		if ( 1 === preg_match( '/^\s*SELECT\s+(@@(?:SESSION\.)?sql_mode)\s*;?\s*$/i', $sql, $match ) ) {
			return WP_Markdown_Query_Result::selected( array( array( $match[1] => $this->sql_mode ) ), array( array( 'name' => $match[1], 'table' => '', 'type' => 253 ) ) );
		}
		if ( 1 !== preg_match( "/^\\s*SET\\s+(?:SESSION\\s+|@@(?:SESSION\\.)?)?sql_mode\\s*=\\s*'([A-Za-z_, ]*)'\\s*;?\\s*$/i", $sql, $match ) ) {
			return null;
		}
		$modes = array_values( array_unique( array_filter( array_map( static fn( string $mode ): string => strtoupper( trim( $mode ) ), explode( ',', $match[1] ) ) ) ) );
		$supported = array( 'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES', 'NO_ENGINE_SUBSTITUTION' );
		if ( array_diff( $modes, $supported ) ) {
			return WP_Markdown_Query_Result::failure( array( 'code' => 'markdown_db_native_unsupported_query', 'reason' => 'unsupported_sql_mode', 'message' => 'The requested SQL mode includes semantics not implemented by the native session.' ) );
		}
		$this->sql_mode = implode( ',', array_values( array_intersect( $supported, $modes ) ) );
		return WP_Markdown_Query_Result::mutated( 0 );
	}
}
