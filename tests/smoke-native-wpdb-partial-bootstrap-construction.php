<?php
/**
 * Regression test: WP_Markdown_Native_WPDB must construct without a fatal error
 * when WordPress core's is_multisite() has not been loaded yet.
 *
 * WP-CLI's `wp db query` (and `db import`/`db export`/`db create`/etc.) load
 * wp-content/db.php via DB_Command_SQLite::maybe_load_sqlite_dropin() after only
 * wp-config.php, wp-includes/compat.php, plugin.php, functions.php, and
 * class-wpdb.php -- never wp-includes/load.php, which is where core defines
 * is_multisite(). The inherited wpdb::set_prefix() (and other inherited wpdb
 * methods called from it, such as tables() and get_blog_prefix()) call the bare
 * global is_multisite() unconditionally, so constructing WP_Markdown_Native_WPDB
 * in that environment previously fataled with:
 *
 *   Call to undefined function is_multisite() in wp-includes/class-wpdb.php
 *   #0 inc/native/class-wp-markdown-native-wpdb.php(35): wpdb->set_prefix('wp_')
 *
 * This test runs two isolated subprocesses so each can observe a clean global
 * function table:
 *
 *   - "missing": is_multisite() is NOT defined before the drop-in class loads.
 *     Construction must succeed and behave like a non-multisite site (the only
 *     topology WP-CLI's fast db-command bootstrap can observe, since it never
 *     defines MULTISITE/SUBDOMAIN_INSTALL/VHOST/SUNRISE itself).
 *   - "present": is_multisite() IS already defined (as it always is by the time
 *     db.php loads during any full WordPress boot) before the drop-in class
 *     loads. Construction must use that pre-existing function unchanged, proving
 *     the drop-in never silently substitutes its own multisite policy when the
 *     real one is available.
 *
 * Usage: php tests/smoke-native-wpdb-partial-bootstrap-construction.php
 */

declare( strict_types=1 );

if ( isset( $argv[1] ) && in_array( $argv[1], array( 'missing', 'present' ), true ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DB_NAME', 'wordpress_tests' );

	if ( 'present' === $argv[1] ) {
		// Stand in for WordPress core's already-loaded is_multisite(), as it always
		// is by the time db.php runs during any full WordPress boot. Track calls so
		// the test can prove the drop-in invokes this pre-existing function instead
		// of installing its own.
		$GLOBALS['mdi_test_is_multisite_calls'] = 0;
		function is_multisite() {
			++$GLOBALS['mdi_test_is_multisite_calls'];
			return false;
		}
	}

	// A faithful reproduction of the subset of wp-includes/class-wpdb.php that
	// WP_Markdown_Native_WPDB's constructor exercises: set_prefix(), which calls
	// the bare global is_multisite() directly and through tables() and
	// get_blog_prefix(), exactly like core. Existing test stubs for this class
	// trivialize set_prefix() and never reproduce this fatal.
	class wpdb {
		public string $prefix = '';
		public string $base_prefix = '';
		public bool $ready = false;
		public int $num_queries = 0;
		public int $num_rows = 0;
		public int $rows_affected = 0;
		public int $insert_id = 0;
		public string $last_error = '';
		public ?string $last_query = null;
		public ?bool $is_mysql = null;
		public string $func_call = '';
		public array $last_result = array();
		public int $blogid = 0;
		public int $siteid = 0;
		protected array $col_info = array();
		protected bool $check_current_query = true;
		protected bool $result = false;

		public array $tables = array( 'posts', 'options' );
		public array $old_tables = array( 'categories' );
		public array $global_tables = array( 'users', 'usermeta' );
		public array $ms_global_tables = array( 'blogs', 'site' );
		public array $old_ms_global_tables = array( 'sitecategories' );

		public string $posts = '';
		public string $options = '';
		public string $categories = '';
		public string $users = '';
		public string $usermeta = '';
		public string $blogs = '';
		public string $site = '';
		public string $sitecategories = '';

		/** Verbatim shape of wpdb::tables(), minus CUSTOM_USER_TABLE overrides. */
		public function tables( $scope = 'all', $prefix = true, $blog_id = 0 ) {
			switch ( $scope ) {
				case 'all':
					$tables = array_merge( $this->global_tables, $this->tables );
					if ( is_multisite() ) {
						$tables = array_merge( $tables, $this->ms_global_tables );
					}
					break;
				case 'blog':
					$tables = $this->tables;
					break;
				case 'global':
					$tables = $this->global_tables;
					if ( is_multisite() ) {
						$tables = array_merge( $tables, $this->ms_global_tables );
					}
					break;
				case 'old':
					$tables = $this->old_tables;
					if ( is_multisite() ) {
						$tables = array_merge( $tables, $this->old_ms_global_tables );
					}
					break;
				default:
					return array();
			}

			if ( $prefix ) {
				if ( ! $blog_id ) {
					$blog_id = $this->blogid;
				}
				$blog_prefix = $this->get_blog_prefix( $blog_id );
				$base_prefix = $this->base_prefix;
				$global_tables = array_merge( $this->global_tables, $this->ms_global_tables );
				foreach ( $tables as $k => $table ) {
					if ( in_array( $table, $global_tables, true ) ) {
						$tables[ $table ] = $base_prefix . $table;
					} else {
						$tables[ $table ] = $blog_prefix . $table;
					}
					unset( $tables[ $k ] );
				}
			}

			return $tables;
		}

		/** Verbatim shape of wpdb::get_blog_prefix(). */
		public function get_blog_prefix( $blog_id = null ) {
			if ( is_multisite() ) {
				if ( null === $blog_id ) {
					$blog_id = $this->blogid;
				}
				$blog_id = (int) $blog_id;
				if ( defined( 'MULTISITE' ) && ( 0 === $blog_id || 1 === $blog_id ) ) {
					return $this->base_prefix;
				}
				return $this->base_prefix . $blog_id . '_';
			}
			return $this->base_prefix;
		}

		/** Verbatim shape of wpdb::set_prefix(). */
		public function set_prefix( $prefix, $set_table_names = true ) {
			if ( preg_match( '|[^a-z0-9_]|i', $prefix ) ) {
				return new InvalidArgumentException( 'Invalid database prefix' );
			}

			$old_prefix = is_multisite() ? '' : $prefix;

			if ( isset( $this->base_prefix ) && '' !== $this->base_prefix ) {
				$old_prefix = $this->base_prefix;
			}

			$this->base_prefix = $prefix;

			if ( $set_table_names ) {
				foreach ( $this->tables( 'global' ) as $table => $prefixed_table ) {
					$this->$table = $prefixed_table;
				}

				if ( is_multisite() && empty( $this->blogid ) ) {
					return $old_prefix;
				}

				$this->prefix = $this->get_blog_prefix();

				foreach ( $this->tables( 'blog' ) as $table => $prefixed_table ) {
					$this->$table = $prefixed_table;
				}

				foreach ( $this->tables( 'old' ) as $table => $prefixed_table ) {
					$this->$table = $prefixed_table;
				}
			}
			return $old_prefix;
		}

		public function flush(): void {
			$this->last_result = array();
			$this->col_info = array();
			$this->last_error = '';
		}

		public function add_placeholder_escape( string $value ): string { return $value; }
		public function remove_placeholder_escape( string $value ): string { return $value; }
	}

	require_once __DIR__ . '/../inc/native/class-wp-markdown-native-wpdb.php';

	final class MDI_Partial_Bootstrap_Runtime implements WP_Markdown_Query_Runtime {
		public function execute( WP_Markdown_Query_Request $request ): WP_Markdown_Query_Result {
			unset( $request );
			return WP_Markdown_Query_Result::selected( array(), array() );
		}
	}

	$database = new WP_Markdown_Native_WPDB( new MDI_Partial_Bootstrap_Runtime(), 'wp_' );
	$select_result = $database->query( 'SELECT 1' );

	echo json_encode(
		array(
			'constructed'                 => true,
			'is_multisite_defined_before' => 'present' === $argv[1],
			'is_multisite_defined_after'  => function_exists( 'is_multisite' ),
			'is_multisite_result'         => is_multisite(),
			'prefix'                      => $database->prefix,
			'base_prefix'                 => $database->base_prefix,
			'options_table'               => $database->options,
			'ready'                       => $database->ready,
			'query_succeeded'             => 0 === $select_result && '' === $database->last_error,
			'is_multisite_calls'          => $GLOBALS['mdi_test_is_multisite_calls'] ?? null,
		)
	);
	exit;
}

$failures = array();
function mdi_partial_bootstrap_assert( bool $condition, string $message ): void {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$run = static function ( string $mode ): array {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $mode ) . ' 2>' . escapeshellarg( sys_get_temp_dir() . '/mdi-partial-bootstrap-stderr.log' );
	$output  = shell_exec( $command );
	$decoded = json_decode( (string) $output, true );
	return array(
		'decoded' => is_array( $decoded ) ? $decoded : null,
		'raw'     => (string) $output,
	);
};

$missing = $run( 'missing' );
mdi_partial_bootstrap_assert(
	null !== $missing['decoded'],
	'construction without a pre-loaded is_multisite() does not fatal (raw output: ' . trim( $missing['raw'] ) . ')'
);
$missing_data = $missing['decoded'] ?? array();
mdi_partial_bootstrap_assert( true === ( $missing_data['constructed'] ?? false ), 'the drop-in wpdb constructs successfully without core is_multisite()' );
mdi_partial_bootstrap_assert( false === ( $missing_data['is_multisite_defined_before'] ?? true ), 'the subprocess started without is_multisite() defined' );
mdi_partial_bootstrap_assert( true === ( $missing_data['is_multisite_defined_after'] ?? false ), 'the drop-in defines a capability shim so later core calls do not fatal either' );
mdi_partial_bootstrap_assert( false === ( $missing_data['is_multisite_result'] ?? true ), 'the shim reports single-site by default, matching WordPress core when no multisite constants are defined' );
mdi_partial_bootstrap_assert( 'wp_' === ( $missing_data['prefix'] ?? null ), 'the canonical table prefix is preserved' );
mdi_partial_bootstrap_assert( 'wp_' === ( $missing_data['base_prefix'] ?? null ), 'the canonical base prefix is preserved' );
mdi_partial_bootstrap_assert( 'wp_options' === ( $missing_data['options_table'] ?? null ), 'set_prefix() still resolves table name properties' );
mdi_partial_bootstrap_assert( true === ( $missing_data['ready'] ?? false ), 'the constructed wpdb reports ready' );
mdi_partial_bootstrap_assert( true === ( $missing_data['query_succeeded'] ?? false ), 'a query executes normally after construction' );

$present = $run( 'present' );
mdi_partial_bootstrap_assert(
	null !== $present['decoded'],
	'construction with a pre-loaded is_multisite() does not fatal (raw output: ' . trim( $present['raw'] ) . ')'
);
$present_data = $present['decoded'] ?? array();
mdi_partial_bootstrap_assert( true === ( $present_data['constructed'] ?? false ), 'the drop-in wpdb constructs successfully when core is_multisite() is already loaded' );
mdi_partial_bootstrap_assert( true === ( $present_data['is_multisite_defined_before'] ?? false ), 'the subprocess started with is_multisite() already defined' );
mdi_partial_bootstrap_assert( ( $present_data['is_multisite_calls'] ?? 0 ) > 0, 'construction actually invoked the pre-existing is_multisite() rather than skipping it' );
mdi_partial_bootstrap_assert( 'wp_' === ( $present_data['prefix'] ?? null ), 'the canonical table prefix is preserved when is_multisite() pre-exists' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL: ' . $failure . PHP_EOL;
	}
	exit( 1 );
}

echo "All native wpdb partial-bootstrap construction checks passed.\n";
