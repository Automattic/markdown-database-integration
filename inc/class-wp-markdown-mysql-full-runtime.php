<?php
/** Runtime owner for draining mysql-full mutations into canonical files. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WP_Markdown_MySQL_Full_Runtime {
	private static ?self $instance = null;
	private string $worker_token;
	private bool $draining = false;

	public static function bootstrap(): void {
		if ( self::$instance || ! defined( 'MARKDOWN_DB_BACKEND' ) || 'mysql-full' !== MARKDOWN_DB_BACKEND ) { return; }
		$drain = $GLOBALS['markdown_db_mysql_semantic_drain'] ?? null;
		global $wpdb;
		if ( ! $drain instanceof WP_Markdown_MySQL_Semantic_Drain || ! $wpdb instanceof WP_Markdown_MySQL_WPDB ) { return; }
		self::$instance = new self( $drain, new WP_Markdown_MySQL_Canonical_Publisher( $wpdb->markdown_db_mysql_connection() ) );
	}

	public static function flush_now( int $limit = 100 ): array {
		if ( ! self::$instance ) { throw new LogicException( 'The mysql-full canonical runtime is not active.' ); }
		return self::$instance->drain( $limit );
	}
	public static function is_active(): bool { return null !== self::$instance; }

	private function __construct( private WP_Markdown_MySQL_Semantic_Drain $semantic_drain, private WP_Markdown_MySQL_Canonical_Publisher $publisher ) {
		$this->worker_token = 'canonical-' . substr( hash( 'sha256', getmypid() . ':' . microtime( true ) . ':' . random_bytes( 16 ) ), 0, 48 );
		add_action( 'init', array( $this, 'recover' ), 99 );
		add_action( 'shutdown', array( $this, 'flush' ), 1 );
	}

	public function recover(): void { $this->flush(); }
	public function flush(): void {
		try { $this->drain( $this->limit() ); }
		catch ( Throwable $error ) { error_log( 'Markdown DB mysql-full canonical drain failed: ' . $error->getMessage() ); }
	}

	private function drain( int $limit ): array {
		if ( $this->draining ) { return array( 'claimed' => 0, 'acknowledged' => 0, 'failed' => 0, 'intents' => 0, 'fenced' => 0, 'changes' => array( 'created' => array(), 'changed' => array(), 'deleted' => array() ) ); }
		$this->draining = true;
		$changes = array( 'created' => array(), 'changed' => array(), 'deleted' => array() );
		try {
			$result = $this->semantic_drain->drain(
				$this->worker_token,
				function ( array $envelope ) use ( &$changes ): bool {
					$this->publisher->publish( $envelope );
					foreach ( $this->publisher->last_changes() as $kind => $paths ) { $changes[ $kind ] = array_merge( $changes[ $kind ], $paths ); }
					return true;
				},
				max( 1, min( 1000, $limit ) )
			);
			foreach ( $changes as &$paths ) { $paths = array_values( array_unique( $paths ) ); sort( $paths, SORT_STRING ); } unset( $paths );
			$result['changes'] = $changes;
			if ( function_exists( 'do_action' ) ) { do_action( 'markdown_database_integration_mysql_full_drained', $result ); }
			return $result;
		} finally { $this->draining = false; }
	}

	private function limit(): int { return defined( 'MARKDOWN_DB_MYSQL_FULL_DRAIN_LIMIT' ) ? max( 1, min( 1000, (int) MARKDOWN_DB_MYSQL_FULL_DRAIN_LIMIT ) ) : 100; }
}

function wp_markdown_mysql_full_flush( int $limit = 100 ): array { return WP_Markdown_MySQL_Full_Runtime::flush_now( $limit ); }
