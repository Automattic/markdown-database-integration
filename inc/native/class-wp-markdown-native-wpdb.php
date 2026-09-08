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
	private string $native_table_prefix;
	private bool $multisite_switch_hook_registered = false;

	public function __construct( WP_Markdown_Query_Runtime $runtime, string $table_prefix = 'wp_' ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table_prefix ) ) {
			throw new InvalidArgumentException( 'The table prefix contains unsupported characters.' );
		}
		$this->native_runtime = $runtime;
		$this->native_table_prefix = $table_prefix;
		$this->set_prefix( $table_prefix );
		// db.php replaces wpdb after its normal constructor would establish the
		// primary site. Multisite switch_to_blog() requires that initial scope.
		if ( property_exists( $this, 'blogid' ) ) {
			$this->blogid = 1;
		}
		if ( property_exists( $this, 'siteid' ) ) {
			$this->siteid = 1;
		}
		$this->last_result = array();
		$this->ready       = true;
		$this->check_current_query = false;
	}

	/** Keep the active native request scope aligned with WordPress blog switches. */
	public function set_blog_id( $blog_id, $network_id = 0 ) {
		$result = parent::set_blog_id( $blog_id, $network_id );
		$this->blogid = (int) $blog_id;
		if ( 0 !== (int) $network_id ) {
			$this->siteid = (int) $network_id;
		}
		if ( is_multisite() ) {
			$this->prefix = 1 === $this->blogid ? $this->base_prefix : $this->base_prefix . $this->blogid . '_';
			foreach ( $this->tables( 'blog' ) as $table ) {
				$this->{$table} = $this->prefix . $table;
			}
		}
		return $result;
	}

	/** Restore the native table properties when WordPress changes blog scope. */
	public function synchronize_blog_scope( $new_blog_id, $previous_blog_id = 0, $context = 'switch' ): void {
		$this->set_blog_id( (int) $new_blog_id );
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
		if ( ! $this->multisite_switch_hook_registered && function_exists( 'add_action' ) ) {
			add_action( 'switch_blog', array( $this, 'synchronize_blog_scope' ), 0, 3 );
			$this->multisite_switch_hook_registered = true;
		}

		$this->flush();
		$this->func_call  = "\$db->query(\"$query\")";
		$this->last_query = $query;
		$query_start      = microtime( true );
		// wp-settings can temporarily clear $wpdb->prefix before multisite has
		// selected its current blog. Continue serving that bootstrap query from
		// the base canonical scope.
		$result = $this->native_runtime->execute( new WP_Markdown_Query_Request( $query, '' === $this->prefix ? $this->native_table_prefix : $this->prefix ) );
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

	/** Identify the native engine without dereferencing wpdb's absent mysqli handle. */
	public function db_server_info() {
		return WP_Markdown_Native_Schema_Catalog::SERVER_VERSION;
	}

	/** Report the MySQL semantics the engine implements, from that same identity. */
	public function db_version() {
		return (string) preg_replace( '/[^0-9.].*/', '', WP_Markdown_Native_Schema_Catalog::SERVER_VERSION );
	}
}
