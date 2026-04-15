<?php
/**
 * Boot Loader — Populates in-memory SQLite from markdown/YAML files.
 *
 * This is the heart of Phase 2: on every boot, it reads all content
 * from markdown files on disk and INSERTs them into the in-memory
 * SQLite database so WordPress can query normally.
 *
 * Order matters:
 *   1. Create all WordPress tables (via MySQL CREATE TABLE statements)
 *   2. Load wp_options (WordPress needs these first — wp_load_alloptions)
 *   3. Load wp_users / wp_usermeta
 *   4. Load wp_terms / wp_term_taxonomy / wp_termmeta
 *   5. Load wp_posts (from markdown files)
 *   6. Load wp_postmeta / wp_term_relationships (from post frontmatter or separate files)
 *   7. Load wp_comments / wp_commentmeta
 *   8. Load wp_links
 *   9. Load any plugin tables that have YAML data files
 *
 * Refs: GitHub issues #1, #2
 *
 * @package Markdown_Database_Integration
 * @since 0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_Loader {

	/**
	 * The base content directory for markdown/YAML files.
	 *
	 * @var string
	 */
	private $content_dir;

	/**
	 * The driver to execute SQL queries on.
	 *
	 * @var WP_SQLite_Driver
	 */
	private $driver;

	/**
	 * The table prefix (usually 'wp_').
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * The markdown storage engine (for posts).
	 *
	 * @var WP_Markdown_Storage
	 */
	private $storage;

	/**
	 * Timing data for debugging.
	 *
	 * @var array
	 */
	private $timings = array();

	/**
	 * Posts loaded from markdown files (with frontmatter meta/terms).
	 * Populated by load_posts(), consumed by load_frontmatter_meta/terms().
	 *
	 * @var object[]
	 */
	private $loaded_posts = array();

	/**
	 * Constructor.
	 *
	 * @param string              $content_dir The markdown content directory.
	 * @param WP_SQLite_Driver    $driver      The SQLite driver.
	 * @param WP_Markdown_Storage $storage     The markdown storage engine.
	 * @param string              $prefix      Table prefix.
	 */
	public function __construct(
		string $content_dir,
		WP_SQLite_Driver $driver,
		WP_Markdown_Storage $storage,
		string $prefix = 'wp_'
	) {
		$this->content_dir = rtrim( $content_dir, '/' );
		$this->driver      = $driver;
		$this->storage     = $storage;
		$this->prefix      = $prefix;
	}

	/**
	 * Load everything from disk into in-memory SQLite.
	 *
	 * This is the main entry point, called during db_connect().
	 */
	public function load_all(): void {
		$start = microtime( true );

		try {
			// 1. Create WordPress core tables.
			$this->create_core_tables();

			// 2. Load options first — WordPress needs them immediately.
			$this->load_options();

			// 3. Load users and usermeta.
			$this->load_table_from_json( 'users' );
			$this->load_table_from_json( 'usermeta' );

			// 4. Load taxonomy tables.
			$this->load_table_from_json( 'terms' );
			$this->load_table_from_json( 'term_taxonomy' );
			$this->load_table_from_json( 'termmeta' );

			// 5. Load posts from markdown files.
			$this->load_posts();

			// 5b. Load postmeta and term relationships from frontmatter.
			// Markdown-type posts embed their meta and terms in the .md file.
			// The JSON files only hold rows for non-markdown posts. See issue #6.
			$this->load_frontmatter_meta();
			$this->load_frontmatter_terms();

			// 6. Load remaining post relationships (non-markdown posts only).
			$this->load_table_from_json( 'postmeta' );
			$this->load_table_from_json( 'term_relationships' );

			// 7. Load comments.
			$this->load_table_from_json( 'comments' );
			$this->load_table_from_json( 'commentmeta' );

			// 8. Load links.
			$this->load_table_from_json( 'links' );

			// 9. Load any plugin tables.
			$this->load_plugin_tables();
		} catch ( \Throwable $e ) {
			// Boot failures should log but not kill WordPress.
			error_log( 'Markdown DB Loader error: ' . $e->getMessage() . "\n" . $e->getTraceAsString() );
		}

		$this->timings['total'] = microtime( true ) - $start;
	}

	/**
	 * Create all WordPress core tables using MySQL syntax.
	 *
	 * The WP_SQLite_Driver translates these to SQLite automatically.
	 * We use CREATE TABLE IF NOT EXISTS so it's idempotent.
	 */
	private function create_core_tables(): void {
		$start  = microtime( true );
		$prefix = $this->prefix;

		// Check if we have a schema cache file.
		$schema_file = $this->content_dir . '/_schema/create_tables.sql';
		if ( file_exists( $schema_file ) ) {
			$sql = file_get_contents( $schema_file );
			$statements = $this->split_sql( $sql );
			foreach ( $statements as $stmt ) {
				$stmt = trim( $stmt );
				if ( ! empty( $stmt ) ) {
					try {
						$this->driver->query( $stmt );
					} catch ( \Throwable $e ) {
						error_log( 'Markdown DB: Failed to execute schema statement: ' . $e->getMessage() );
					}
				}
			}
			$this->timings['create_tables'] = microtime( true ) - $start;
			return;
		}

		// Inline core table definitions (MySQL syntax — driver translates to SQLite).
		$tables = array();

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}options` (
  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`),
  KEY `autoload` (`autoload`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}users` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL DEFAULT '',
  `user_pass` varchar(255) NOT NULL DEFAULT '',
  `user_nicename` varchar(50) NOT NULL DEFAULT '',
  `user_email` varchar(100) NOT NULL DEFAULT '',
  `user_url` varchar(100) NOT NULL DEFAULT '',
  `user_registered` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `user_activation_key` varchar(255) NOT NULL DEFAULT '',
  `user_status` int(11) NOT NULL DEFAULT 0,
  `display_name` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`),
  KEY `user_login_key` (`user_login`),
  KEY `user_nicename` (`user_nicename`),
  KEY `user_email` (`user_email`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}usermeta` (
  `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`umeta_id`),
  KEY `user_id` (`user_id`),
  KEY `meta_key` (`meta_key`(191))
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}posts` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint(20) unsigned NOT NULL DEFAULT 0,
  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_excerpt` text NOT NULL,
  `post_status` varchar(20) NOT NULL DEFAULT 'publish',
  `comment_status` varchar(20) NOT NULL DEFAULT 'open',
  `ping_status` varchar(20) NOT NULL DEFAULT 'open',
  `post_password` varchar(255) NOT NULL DEFAULT '',
  `post_name` varchar(200) NOT NULL DEFAULT '',
  `to_ping` text NOT NULL,
  `pinged` text NOT NULL,
  `post_modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_modified_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content_filtered` longtext NOT NULL,
  `post_parent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `guid` varchar(255) NOT NULL DEFAULT '',
  `menu_order` int(11) NOT NULL DEFAULT 0,
  `post_type` varchar(20) NOT NULL DEFAULT 'post',
  `post_mime_type` varchar(100) NOT NULL DEFAULT '',
  `comment_count` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `post_name` (`post_name`(191)),
  KEY `type_status_date` (`post_type`,`post_status`,`post_date`,`ID`),
  KEY `post_parent` (`post_parent`),
  KEY `post_author` (`post_author`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}terms` (
  `term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL DEFAULT '',
  `slug` varchar(200) NOT NULL DEFAULT '',
  `term_group` bigint(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (`term_id`),
  KEY `slug` (`slug`(191)),
  KEY `name` (`name`(191))
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}term_taxonomy` (
  `term_taxonomy_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `term_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `taxonomy` varchar(32) NOT NULL DEFAULT '',
  `description` longtext NOT NULL,
  `parent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `count` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`term_taxonomy_id`),
  UNIQUE KEY `term_id_taxonomy` (`term_id`,`taxonomy`),
  KEY `taxonomy` (`taxonomy`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}term_relationships` (
  `object_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `term_taxonomy_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `term_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`object_id`,`term_taxonomy_id`),
  KEY `term_taxonomy_id` (`term_taxonomy_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}termmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `term_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `term_id` (`term_id`),
  KEY `meta_key` (`meta_key`(191))
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}comments` (
  `comment_ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `comment_post_ID` bigint(20) unsigned NOT NULL DEFAULT 0,
  `comment_author` tinytext NOT NULL,
  `comment_author_email` varchar(100) NOT NULL DEFAULT '',
  `comment_author_url` varchar(200) NOT NULL DEFAULT '',
  `comment_author_IP` varchar(100) NOT NULL DEFAULT '',
  `comment_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `comment_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `comment_content` text NOT NULL,
  `comment_karma` int(11) NOT NULL DEFAULT 0,
  `comment_approved` varchar(20) NOT NULL DEFAULT '1',
  `comment_agent` varchar(255) NOT NULL DEFAULT '',
  `comment_type` varchar(20) NOT NULL DEFAULT 'comment',
  `comment_parent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`comment_ID`),
  KEY `comment_post_ID` (`comment_post_ID`),
  KEY `comment_approved_date_gmt` (`comment_approved`,`comment_date_gmt`),
  KEY `comment_date_gmt` (`comment_date_gmt`),
  KEY `comment_parent` (`comment_parent`),
  KEY `comment_author_email` (`comment_author_email`(10))
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}commentmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `comment_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `comment_id` (`comment_id`),
  KEY `meta_key` (`meta_key`(191))
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		$tables[] = "CREATE TABLE IF NOT EXISTS `{$prefix}links` (
  `link_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `link_url` varchar(255) NOT NULL DEFAULT '',
  `link_name` varchar(255) NOT NULL DEFAULT '',
  `link_image` varchar(255) NOT NULL DEFAULT '',
  `link_target` varchar(25) NOT NULL DEFAULT '',
  `link_description` varchar(255) NOT NULL DEFAULT '',
  `link_visible` varchar(20) NOT NULL DEFAULT 'Y',
  `link_owner` bigint(20) unsigned NOT NULL DEFAULT 1,
  `link_rating` int(11) NOT NULL DEFAULT 0,
  `link_updated` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `link_rel` varchar(255) NOT NULL DEFAULT '',
  `link_notes` mediumtext NOT NULL,
  `link_rss` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`link_id`),
  KEY `link_visible` (`link_visible`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

		foreach ( $tables as $sql ) {
			try {
				$this->driver->query( $sql );
			} catch ( \Throwable $e ) {
				error_log( 'Markdown DB: Failed to create table: ' . $e->getMessage() );
			}
		}

		$this->timings['create_tables'] = microtime( true ) - $start;
	}

	/**
	 * Load wp_options from options.json file.
	 *
	 * Uses JSON because option values contain serialized PHP which
	 * would be mangled by YAML encoding. JSON preserves them exactly.
	 */
	private function load_options(): void {
		$start = microtime( true );
		$table = $this->prefix . 'options';
		$file  = $this->content_dir . '/options.json';

		if ( ! file_exists( $file ) ) {
			$this->timings['load_options'] = microtime( true ) - $start;
			return;
		}

		$json = file_get_contents( $file );
		$options = json_decode( $json, true );

		if ( ! is_array( $options ) ) {
			error_log( 'Markdown DB: Failed to parse options.json' );
			return;
		}

		// Use the raw PDO for bulk inserts — much faster than going through
		// the MySQL-to-SQLite translation layer for simple INSERTs.
		$pdo = $this->driver->get_connection()->get_pdo();

		$pdo->exec( 'BEGIN TRANSACTION' );
		try {
			$stmt = $pdo->prepare(
				"INSERT OR IGNORE INTO `{$table}` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES (?, ?, ?, ?)"
			);

			foreach ( $options as $opt ) {
				$stmt->execute( array(
					(int) $opt['option_id'],
					$opt['option_name'],
					$opt['option_value'],
					$opt['autoload'],
				) );
			}

			$pdo->exec( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			error_log( 'Markdown DB: Failed to load options: ' . $e->getMessage() );
		}

		$this->timings['load_options'] = microtime( true ) - $start;
	}

	/**
	 * Load a table from a JSON file.
	 *
	 * Generic loader for tables like users, usermeta, terms, etc.
	 * File is at {content_dir}/_tables/{table_name}.json
	 *
	 * @param string $table_suffix Table name without prefix (e.g. 'users').
	 */
	private function load_table_from_json( string $table_suffix ): void {
		$start = microtime( true );
		$table = $this->prefix . $table_suffix;
		$file  = $this->content_dir . '/_tables/' . $table_suffix . '.json';

		if ( ! file_exists( $file ) ) {
			$this->timings[ 'load_' . $table_suffix ] = microtime( true ) - $start;
			return;
		}

		$json = file_get_contents( $file );
		$rows = json_decode( $json, true );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			$this->timings[ 'load_' . $table_suffix ] = microtime( true ) - $start;
			return;
		}

		$pdo = $this->driver->get_connection()->get_pdo();
		$pdo->exec( 'BEGIN TRANSACTION' );

		try {
			// Build INSERT from the first row's keys.
			$columns = array_keys( $rows[0] );
			$placeholders = implode( ', ', array_fill( 0, count( $columns ), '?' ) );
			$col_names = implode( ', ', array_map( fn( $c ) => "`{$c}`", $columns ) );

			$stmt = $pdo->prepare(
				"INSERT OR IGNORE INTO `{$table}` ({$col_names}) VALUES ({$placeholders})"
			);

			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $columns as $col ) {
					$values[] = $row[ $col ] ?? null;
				}
				$stmt->execute( $values );
			}

			$pdo->exec( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			error_log( "Markdown DB: Failed to load {$table_suffix}: " . $e->getMessage() );
		}

		$this->timings[ 'load_' . $table_suffix ] = microtime( true ) - $start;
	}

	/**
	 * Load posts from markdown files into wp_posts.
	 *
	 * Uses WP_Markdown_Storage::get_all_posts() to read all .md files,
	 * then INSERTs them into the in-memory SQLite.
	 */
	private function load_posts(): void {
		$start = microtime( true );
		$table = $this->prefix . 'posts';

		// First load posts from markdown files.
		$posts = $this->storage->get_all_posts();

		// Raw markdown goes into SQLite as-is.
		// Markdown → HTML conversion happens at render time via filters:
		//   - the_content filter: markdown → HTML (frontend)
		//   - rest_prepare_{type} filter: markdown → HTML → blocks (editor)
		// This ensures that code reading post_content directly (CLI, abilities,
		// plugins) gets raw markdown — WordPress is markdown-native.
		// See GitHub issue #30.

		// Store posts for load_frontmatter_meta() and load_frontmatter_terms().
		$this->loaded_posts = $posts;

		// Also load any non-markdown posts from the JSON fallback.
		$json_file = $this->content_dir . '/_tables/posts.json';
		$json_posts = array();
		if ( file_exists( $json_file ) ) {
			$json = file_get_contents( $json_file );
			$json_posts = json_decode( $json, true );
			if ( ! is_array( $json_posts ) ) {
				$json_posts = array();
			}
		}

		$pdo = $this->driver->get_connection()->get_pdo();
		$pdo->exec( 'BEGIN TRANSACTION' );

		try {
			// Track IDs loaded from markdown so JSON fallback doesn't overwrite them.
			// Note: get_all_posts() already deduplicates markdown files (issue #9).
			// INSERT OR IGNORE is the safety net for any remaining edge cases.
			$loaded_ids = array();

			$columns = array(
				'ID', 'post_author', 'post_date', 'post_date_gmt',
				'post_content', 'post_title', 'post_excerpt', 'post_status',
				'comment_status', 'ping_status', 'post_password', 'post_name',
				'to_ping', 'pinged', 'post_modified', 'post_modified_gmt',
				'post_content_filtered', 'post_parent', 'guid', 'menu_order',
				'post_type', 'post_mime_type', 'comment_count',
			);
			$placeholders = implode( ', ', array_fill( 0, count( $columns ), '?' ) );
			$col_names = implode( ', ', array_map( fn( $c ) => "`{$c}`", $columns ) );

			// INSERT OR IGNORE: safety net for duplicate IDs. See issue #9.
			$stmt = $pdo->prepare(
				"INSERT OR IGNORE INTO `{$table}` ({$col_names}) VALUES ({$placeholders})"
			);

			foreach ( $posts as $post ) {
				$id = (int) $post->ID;

				$stmt->execute( array(
					$id,
					(int) $post->post_author,
					$post->post_date,
					$post->post_date_gmt,
					$post->post_content ?? '',
					$post->post_title ?? '',
					$post->post_excerpt ?? '',
					$post->post_status ?? 'publish',
					$post->comment_status ?? 'open',
					$post->ping_status ?? 'open',
					$post->post_password ?? '',
					$post->post_name ?? '',
					$post->to_ping ?? '',
					$post->pinged ?? '',
					$post->post_modified ?? '0000-00-00 00:00:00',
					$post->post_modified_gmt ?? '0000-00-00 00:00:00',
					$post->post_content_filtered ?? '',
					(int) ( $post->post_parent ?? 0 ),
					$post->guid ?? '',
					(int) ( $post->menu_order ?? 0 ),
					$post->post_type ?? 'post',
					$post->post_mime_type ?? '',
					(int) ( $post->comment_count ?? 0 ),
				) );
				$loaded_ids[ $id ] = true;
			}

			// Load non-markdown posts from JSON (revisions, nav items, etc.).
			// Skip any ID already loaded from markdown — markdown wins.
			foreach ( $json_posts as $row ) {
				$id = (int) ( $row['ID'] ?? 0 );
				if ( isset( $loaded_ids[ $id ] ) ) {
					continue;
				}
				$values = array();
				foreach ( $columns as $col ) {
					$values[] = $row[ $col ] ?? '';
				}
				$stmt->execute( $values );
			}

			$pdo->exec( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			error_log( 'Markdown DB: Failed to load posts: ' . $e->getMessage() );
		}

		$this->timings['load_posts'] = microtime( true ) - $start;
	}

	/**
	 * Load postmeta from frontmatter into wp_postmeta.
	 *
	 * Each markdown post's _frontmatter_meta is INSERTed as postmeta rows.
	 * See GitHub issue #6.
	 */
	private function load_frontmatter_meta(): void {
		$start = microtime( true );
		$table = $this->prefix . 'postmeta';

		$pdo = $this->driver->get_connection()->get_pdo();
		$pdo->exec( 'BEGIN TRANSACTION' );

		try {
			$stmt = $pdo->prepare(
				"INSERT OR IGNORE INTO `{$table}` (post_id, meta_key, meta_value) VALUES (?, ?, ?)"
			);

			foreach ( $this->loaded_posts as $post ) {
				$id   = (int) ( $post->ID ?? 0 );
				$meta = $post->_frontmatter_meta ?? array();

				if ( $id <= 0 || empty( $meta ) ) {
					continue;
				}

				foreach ( $meta as $key => $value ) {
					$stmt->execute( array( $id, (string) $key, (string) $value ) );
				}
			}

			$pdo->exec( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			error_log( 'Markdown DB: Failed to load frontmatter meta: ' . $e->getMessage() );
		}

		$this->timings['load_frontmatter_meta'] = microtime( true ) - $start;
	}

	/**
	 * Load term relationships from frontmatter into wp_term_relationships.
	 *
	 * Each markdown post's _frontmatter_terms maps taxonomy → [slugs].
	 * We resolve slugs to term_taxonomy_ids and INSERT relationships.
	 * See GitHub issue #6.
	 */
	private function load_frontmatter_terms(): void {
		$start = microtime( true );
		$rel_table      = $this->prefix . 'term_relationships';
		$taxonomy_table = $this->prefix . 'term_taxonomy';
		$terms_table    = $this->prefix . 'terms';

		$pdo = $this->driver->get_connection()->get_pdo();

		// Build a lookup: (taxonomy, slug) → term_taxonomy_id.
		$term_map = array();
		try {
			$rows = $pdo->query(
				"SELECT tt.term_taxonomy_id, tt.taxonomy, t.slug
				 FROM `{$taxonomy_table}` tt
				 JOIN `{$terms_table}` t ON tt.term_id = t.term_id"
			)->fetchAll( PDO::FETCH_OBJ );

			foreach ( $rows as $row ) {
				$key = $row->taxonomy . '::' . $row->slug;
				$term_map[ $key ] = (int) $row->term_taxonomy_id;
			}
		} catch ( \Throwable $e ) {
			error_log( 'Markdown DB: Failed to build term map: ' . $e->getMessage() );
			$this->timings['load_frontmatter_terms'] = microtime( true ) - $start;
			return;
		}

		$pdo->exec( 'BEGIN TRANSACTION' );

		try {
			$stmt = $pdo->prepare(
				"INSERT OR IGNORE INTO `{$rel_table}` (object_id, term_taxonomy_id, term_order) VALUES (?, ?, 0)"
			);

			foreach ( $this->loaded_posts as $post ) {
				$id    = (int) ( $post->ID ?? 0 );
				$terms = $post->_frontmatter_terms ?? array();

				if ( $id <= 0 || empty( $terms ) ) {
					continue;
				}

				foreach ( $terms as $taxonomy => $slugs ) {
					if ( ! is_array( $slugs ) ) {
						continue;
					}
					foreach ( $slugs as $slug ) {
						$key = $taxonomy . '::' . $slug;
						if ( isset( $term_map[ $key ] ) ) {
							$stmt->execute( array( $id, $term_map[ $key ] ) );
						}
					}
				}
			}

			$pdo->exec( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			error_log( 'Markdown DB: Failed to load frontmatter terms: ' . $e->getMessage() );
		}

		// Free memory — no longer needed after boot.
		$this->loaded_posts = array();

		$this->timings['load_frontmatter_terms'] = microtime( true ) - $start;
	}

	/**
	 * Load plugin tables from their JSON files.
	 *
	 * Scans {content_dir}/_tables/ for any .json files that correspond
	 * to tables NOT in the core set (those are loaded explicitly above).
	 *
	 * Schema files may contain either MySQL or SQLite syntax. We try the
	 * driver first (MySQL path — populates the information schema) and
	 * fall back to raw PDO (SQLite path) for legacy schema files. After
	 * the fallback path, we synchronize the information schema so the
	 * translator can find column metadata for these tables.
	 */
	private function load_plugin_tables(): void {
		$start = microtime( true );
		$dir   = $this->content_dir . '/_tables';

		if ( ! is_dir( $dir ) ) {
			$this->timings['load_plugin_tables'] = microtime( true ) - $start;
			return;
		}

		$core_tables = array(
			'options', 'users', 'usermeta', 'posts', 'postmeta',
			'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
			'comments', 'commentmeta', 'links',
		);

		$files = glob( $dir . '/*.json' );
		if ( ! $files ) {
			$this->timings['load_plugin_tables'] = microtime( true ) - $start;
			return;
		}

		$pdo                     = $this->driver->get_connection()->get_pdo();
		$needs_schema_rebuild    = false;

		foreach ( $files as $file ) {
			$basename = basename( $file, '.json' );
			if ( in_array( $basename, $core_tables, true ) ) {
				continue; // Already loaded.
			}

			// Check if this table has a corresponding schema file.
			$schema_file = $this->content_dir . '/_schema/' . $basename . '.sql';
			if ( ! file_exists( $schema_file ) ) {
				// No schema file — skip this table (data without schema is useless).
				continue;
			}

			$schema_sql = trim( file_get_contents( $schema_file ) );

			// Schema files may contain multiple statements (CREATE + ALTER).
			// Split on semicolons and execute each statement individually.
			$statements = $this->split_sql( $schema_sql );
			$table_created = false;

			foreach ( $statements as $stmt ) {
				$stmt = trim( $stmt );
				if ( empty( $stmt ) ) {
					continue;
				}

				// Try the driver first (MySQL syntax). This creates the table
				// AND populates the information schema, which is required for
				// the MySQL→SQLite query translator to work correctly.
				try {
					$this->driver->query( $stmt );
					$table_created = true;
				} catch ( \Throwable $e ) {
					// Driver failed — likely SQLite syntax from a legacy schema
					// file. Fall back to raw PDO exec.
					try {
						$pdo->exec( $stmt );
						$table_created        = true;
						$needs_schema_rebuild = true;
					} catch ( \Throwable $e2 ) {
						error_log( "Markdown DB: Failed to execute schema for {$basename}: " . $e2->getMessage() );
					}
				}
			}

			if ( ! $table_created ) {
				continue;
			}

			// Load the data.
			$this->load_table_from_json( $basename );
		}

		// When tables were created via raw PDO (SQLite syntax fallback), the
		// information schema is out of sync — the translator doesn't know about
		// these tables and will fail on INSERT/UPDATE queries with "table not
		// found" errors. Trigger a full information schema reconstruction.
		if ( $needs_schema_rebuild ) {
			$this->rebuild_information_schema();
		}

		$this->timings['load_plugin_tables'] = microtime( true ) - $start;
	}

	/**
	 * Rebuild the information schema for tables created via raw PDO.
	 *
	 * The WP_SQLite_Information_Schema_Reconstructor compares actual SQLite
	 * tables against the information schema and reconstructs any missing
	 * entries. This is necessary when tables are created outside the driver
	 * (e.g., via raw PDO with SQLite syntax).
	 */
	private function rebuild_information_schema(): void {
		if ( ! class_exists( 'WP_SQLite_Information_Schema_Reconstructor' )
			|| ! class_exists( 'WP_SQLite_Information_Schema_Builder' )
			|| ! class_exists( 'WP_PDO_MySQL_On_SQLite' )
		) {
			return;
		}

		try {
			// Build a new schema builder on the same connection and prefix.
			// This operates on the same information schema tables the driver
			// already created — the table names are deterministic from the
			// reserved prefix.
			$connection     = $this->driver->get_connection();
			$schema_builder = new \WP_SQLite_Information_Schema_Builder(
				\WP_PDO_MySQL_On_SQLite::RESERVED_PREFIX,
				$connection
			);

			// The reconstructor needs the WP_PDO_MySQL_On_SQLite instance
			// (for execute_sqlite_query and create_parser). Access it via
			// the Closure binding pattern used by WP_SQLite_Driver itself.
			// We bind to WP_SQLite_Driver scope explicitly because the
			// property is private on the parent class.
			$get_driver   = \Closure::bind(
				function () {
					return $this->mysql_on_sqlite_driver;
				},
				$this->driver,
				\WP_SQLite_Driver::class
			);
			$mysql_driver = $get_driver();

			$reconstructor = new \WP_SQLite_Information_Schema_Reconstructor(
				$mysql_driver,
				$schema_builder
			);
			$reconstructor->ensure_correct_information_schema();
		} catch ( \Throwable $e ) {
			error_log( 'Markdown DB: Failed to rebuild information schema: ' . $e->getMessage() );
		}
	}

	/**
	 * Split a SQL file into individual statements.
	 *
	 * @param string $sql Multi-statement SQL.
	 * @return string[]
	 */
	private function split_sql( string $sql ): array {
		// Simple split on semicolons at line endings.
		return preg_split( '/;\s*$/m', $sql, -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Get timing data for debugging.
	 *
	 * @return array
	 */
	public function get_timings(): array {
		return $this->timings;
	}
}
