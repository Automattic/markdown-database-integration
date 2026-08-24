<?php
/** wpdb query-helper facade for the bounded mdi-native runtime. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-markdown-native-query-runtime.php';

if ( ! class_exists( 'wpdb' ) ) {
	return;
}

final class WP_Markdown_Native_WPDB extends wpdb {

	public int|string $last_errno = 0;

	/** @var array{code:string,message:string,reason:string}|null */
	public ?array $last_runtime_diagnostic = null;

	private WP_Markdown_Query_Runtime $native_runtime;

	public function __construct( WP_Markdown_Query_Runtime $runtime, string $table_prefix = 'wp_' ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table_prefix ) ) {
			throw new InvalidArgumentException( 'The table prefix contains unsupported characters.' );
		}
		$this->native_runtime = $runtime;
		$this->set_prefix( $table_prefix );
		$this->last_result = array();
		$this->ready       = true;
		$this->check_current_query = false;
	}

	/** Execute one bounded native query and expose the normal wpdb result state. */
	public function query( $query ) {
		if ( ! is_string( $query ) || '' === trim( $query ) ) {
			return false;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$query = apply_filters( 'query', $query );
		}
		if ( ! is_string( $query ) || '' === trim( $query ) ) {
			$this->insert_id = 0;
			return false;
		}
		$query = $this->remove_placeholder_escape( $query );

		$this->flush();
		$this->func_call  = "\$db->query(\"$query\")";
		$this->last_query = $query;
		$query_start      = microtime( true );
		$result = $this->native_runtime->execute( new WP_Markdown_Query_Request( $query, $this->prefix ) );
		$state  = $result->wpdb_state();
		++$this->num_queries;

		$this->last_result            = $state['last_result'];
		$this->col_info               = $state['col_info'];
		$this->last_error             = $state['last_error'];
		$this->last_errno             = $state['last_errno'];
		$this->insert_id              = $state['insert_id'];
		$this->rows_affected          = $state['rows_affected'];
		$this->num_rows               = $state['num_rows'];
		$this->last_runtime_diagnostic = $result->diagnostic();
		$this->result                 = $result->succeeded();
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && method_exists( $this, 'log_query' ) ) {
			$this->log_query( $query, microtime( true ) - $query_start, method_exists( $this, 'get_caller' ) ? $this->get_caller() : '', $query_start, array() );
		}

		return $result->return_value();
	}

	/** Escape prepared string values without requiring a database connection. */
	public function _real_escape( $data ) {
		if ( ! is_scalar( $data ) ) {
			return '';
		}
		return $this->add_placeholder_escape( addslashes( (string) $data ) );
	}
}
