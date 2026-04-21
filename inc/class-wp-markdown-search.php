<?php
/**
 * Markdown Database Search
 *
 * PHP-level full-text search over .md files on disk.
 *
 * After the Index/Map Architecture (PR #41), `post_content` is stored as
 * an empty string in SQLite and lazy-loaded from `.md` files on demand.
 * This means `WHERE post_content LIKE '%foo%'` matches nothing — WP's
 * default search (`?s=foo`) and any plugin that queries post_content
 * directly would silently return no results.
 *
 * The fix matches the PR #41 thesis ("files are the content store"):
 * rather than rebuild an FTS index inside SQLite (which would duplicate
 * file content back into the DB and introduce a new corruption surface),
 * we grep the `.md` files directly.
 *
 * The driver intercepts SELECT queries with `post_content LIKE` clauses,
 * passes them to this class, which:
 *
 *   1. Extracts the needle(s) from each LIKE pattern.
 *   2. Iterates files listed in `_markdown_file_index` (post_id → path).
 *   3. Returns the matching post IDs.
 *   4. Rewrites the query to replace `post_content LIKE '%foo%'` with
 *      `ID IN (1,2,3)` (or `0=1` when nothing matched).
 *
 * Complexity: O(files × avg_size). For small sites (<1,000 posts) this
 * is sub-millisecond per term; the Intelligence wiki (~50 articles) greps
 * in well under 5ms. Sites large enough to care can plug in an FTS5 or
 * Meilisearch backend via the `markdown_db_search_matching_ids` filter
 * without touching this class.
 *
 * See GitHub issue #43.
 *
 * @package Markdown_Database_Integration
 * @since   0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_Search {

	/**
	 * Reference to the owning driver (for file index access).
	 *
	 * @var WP_Markdown_Driver
	 */
	private $driver;

	/**
	 * Storage engine (for content dir + file reading).
	 *
	 * @var WP_Markdown_Storage
	 */
	private $storage;

	/**
	 * Per-request cache of `needle → int[] of matching post IDs`.
	 *
	 * Most WP_Query search executions evaluate the same needle multiple
	 * times (SELECT FOUND_ROWS(), ORDER BY clauses, etc.). A single-request
	 * cache prevents re-grepping the filesystem on each one.
	 *
	 * @var array<string, int[]>
	 */
	private $needle_cache = array();

	/**
	 * Constructor.
	 *
	 * @param WP_Markdown_Driver  $driver  The markdown driver instance.
	 * @param WP_Markdown_Storage $storage The storage engine.
	 */
	public function __construct( WP_Markdown_Driver $driver, WP_Markdown_Storage $storage ) {
		$this->driver  = $driver;
		$this->storage = $storage;
	}

	/**
	 * Attempt to rewrite a query's `post_content LIKE '...'` clauses.
	 *
	 * Each matching clause is replaced with `(table.)?ID IN (1,2,3)` based
	 * on the post IDs whose source `.md` file content contains the needle.
	 *
	 * Returns null when no rewrite is needed (query has no `post_content
	 * LIKE` clause, or every clause uses an unsupported pattern shape).
	 *
	 * Only the `%needle%` contains-match shape is rewritten; prefix,
	 * suffix, or embedded-wildcard patterns are left untouched so SQLite
	 * handles them against the (empty) post_content column — which at
	 * worst matches nothing, matching pre-PR-41 behavior for those edge
	 * cases.
	 *
	 * @param string $query Raw SQL query.
	 * @return string|null Rewritten query, or null if no rewrite applied.
	 */
	public function maybe_rewrite_query( string $query ): ?string {
		// Pre-check: cheap reject when there is no `post_content LIKE` at all.
		if ( ! preg_match( '/\bpost_content\s+LIKE\s+\'/i', $query ) ) {
			return null;
		}

		$rewrote_any = false;

		// Capture: (optional_table_prefix)post_content LIKE '<quoted-pattern>'
		// The inner pattern allows escaped quotes (\') and doubled quotes ('').
		$rewritten = preg_replace_callback(
			"/\b((?:\w+\.)?)post_content\s+LIKE\s+'((?:[^'\\\\]|\\\\.|'')*)'/i",
			function ( array $m ) use ( &$rewrote_any ) {
				$table_prefix = $m[1]; // e.g. "wp_posts." or "".
				$pattern      = $m[2]; // e.g. "%foo%".

				$needle = $this->extract_contains_needle( $pattern );
				if ( null === $needle ) {
					// Unsupported pattern shape — keep original LIKE.
					return $m[0];
				}

				$ids = $this->find_matching_ids( $needle );

				$rewrote_any = true;

				if ( empty( $ids ) ) {
					// No file matches — short-circuit this clause to false.
					return '0=1';
				}

				$id_list = implode( ',', array_map( 'intval', $ids ) );
				return $table_prefix . 'ID IN (' . $id_list . ')';
			},
			$query
		);

		if ( ! $rewrote_any || null === $rewritten ) {
			return null;
		}

		return $rewritten;
	}

	/**
	 * Find post IDs whose source `.md` file content contains the needle.
	 *
	 * Case-insensitive substring match. Results are cached per-request.
	 * Extension point: the `markdown_db_search_matching_ids` filter can
	 * short-circuit the default grep with an FTS5/Meilisearch/etc. backend.
	 *
	 * @param string $needle Unescaped search term.
	 * @return int[] Matching post IDs.
	 */
	public function find_matching_ids( string $needle ): array {
		if ( '' === $needle ) {
			// Empty needle matches everything; caller's `ID IN (...)` would
			// be enormous. Return early with an empty list and let SQLite
			// handle the `0=1` substitution — a true `%%` LIKE was never a
			// meaningful search intent in WP anyway.
			return array();
		}

		$cache_key = mb_strtolower( $needle );
		if ( isset( $this->needle_cache[ $cache_key ] ) ) {
			return $this->needle_cache[ $cache_key ];
		}

		/**
		 * Short-circuit the default grep with a custom search backend.
		 *
		 * Return an array of matching post IDs to skip the grep entirely,
		 * or return null (default) to use the built-in file-grep backend.
		 *
		 * Enables FTS5, Meilisearch, Elasticsearch, or any external index
		 * to replace the default implementation without patching core.
		 *
		 * @since 0.3.0
		 *
		 * @param int[]|null          $ids    Pre-computed IDs, or null for default grep.
		 * @param string              $needle Unescaped search term.
		 * @param WP_Markdown_Search  $search The search instance.
		 */
		$custom = apply_filters( 'markdown_db_search_matching_ids', null, $needle, $this );

		if ( is_array( $custom ) ) {
			$ids = array_values( array_map( 'intval', $custom ) );
		} else {
			$ids = $this->grep_files( $needle );
		}

		$this->needle_cache[ $cache_key ] = $ids;
		return $ids;
	}

	/**
	 * Extract a contains-match needle from a `LIKE` pattern.
	 *
	 * Supported shape: `%literal%` with no unescaped wildcards inside.
	 * SQL escape sequences (`\%`, `\_`, `\\`) are unescaped to their
	 * literal form for comparison against file content.
	 *
	 * Returns null for patterns this class cannot safely grep — the
	 * caller keeps the original LIKE in those cases.
	 *
	 * @param string $pattern Raw LIKE pattern (contents of the SQL string).
	 * @return string|null Unescaped needle, or null if shape unsupported.
	 */
	private function extract_contains_needle( string $pattern ): ?string {
		$len = strlen( $pattern );
		if ( $len < 2 ) {
			return null;
		}
		if ( '%' !== $pattern[0] || '%' !== $pattern[ $len - 1 ] ) {
			return null;
		}

		$inner = substr( $pattern , 1, $len - 2 );

		// Reject patterns with unescaped wildcards in the middle — those
		// aren't a simple contains-match and we defer to SQLite.
		if ( preg_match( '/(?<!\\\\)[%_]/', $inner ) ) {
			return null;
		}

		// Unescape SQL LIKE escapes produced by $wpdb->esc_like().
		$needle = strtr(
			$inner,
			array(
				'\\%'  => '%',
				'\\_'  => '_',
				'\\\\' => '\\',
				"\\'"  => "'",
				"''"   => "'",
			)
		);

		return $needle;
	}

	/**
	 * Grep the `.md` files on disk for posts containing the needle.
	 *
	 * Iterates `_markdown_file_index` (post_id → file_path) and case-
	 * insensitively searches each file body + frontmatter for the needle.
	 *
	 * @param string $needle Unescaped search term.
	 * @return int[] Matching post IDs (sorted ascending for determinism).
	 */
	private function grep_files( string $needle ): array {
		$file_index = $this->driver->get_file_index_cache();
		if ( empty( $file_index ) ) {
			return array();
		}

		$content_dir = rtrim( $this->storage->get_content_dir(), '/' );
		$matches     = array();

		foreach ( $file_index as $post_id => $relative_path ) {
			$path = $content_dir . '/' . ltrim( (string) $relative_path, '/' );

			$content = @file_get_contents( $path );
			if ( false === $content ) {
				continue;
			}

			if ( false !== mb_stripos( $content, $needle ) ) {
				$matches[] = (int) $post_id;
			}
		}

		sort( $matches, SORT_NUMERIC );
		return $matches;
	}

	/**
	 * Clear the per-request needle cache.
	 *
	 * Call when files have been written during a long-running process
	 * (WP-CLI batch jobs, cron, etc.) so subsequent searches see updates.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->needle_cache = array();
	}
}
