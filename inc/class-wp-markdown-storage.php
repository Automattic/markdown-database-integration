<?php
/**
 * Markdown Storage Engine
 *
 * Reads and writes WordPress posts as markdown files with YAML frontmatter.
 * Posts are stored hierarchically: directory structure matches the post
 * parent-child hierarchy. Parent posts that have children become directories
 * with their content in _index.md (Hugo convention).
 *
 * File layout:
 *   {content_dir}/{post_type}/slug.md              — leaf post (no children)
 *   {content_dir}/{post_type}/parent-slug/_index.md — parent post (has children)
 *   {content_dir}/{post_type}/parent-slug/child.md  — child post
 *
 * The directory structure IS the hierarchy — no `parent` field in frontmatter.
 * The loader derives post_parent from the filesystem path.
 *
 * File format:
 *   ---
 *   id: 42
 *   title: "My Post Title"
 *   status: publish
 *   type: post
 *   author: 1
 *   date: "2026-04-13 21:17:48"
 *   modified: "2026-04-14 00:33:50"
 *   slug: my-post-title
 *   menu_order: 0
 *   comment_status: open
 *   ping_status: open
 *   ---
 *
 *   The post content goes here as markdown.
 *
 * Ref: GitHub issue #14
 *
 * @package Markdown_Database_Integration
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Markdown_Storage {

	/**
	 * Base directory for markdown files.
	 *
	 * @var string
	 */
	private $content_dir;

	/**
	 * Post types explicitly excluded from markdown storage.
	 *
	 * @var string[]
	 */
	private $excluded_types = array();

	/**
	 * In-memory index of post ID → file path.
	 * Populated on first access, updated on writes.
	 *
	 * @var array<int, string>|null
	 */
	private $index = null;

	/**
	 * Callback to resolve a post's slug and parent ID by post ID.
	 * Set by the write engine so we can build hierarchical paths.
	 *
	 * Returns: (object) { post_name: string, post_parent: int, post_type: string }
	 *
	 * @var callable|null
	 */
	private $post_resolver = null;

	/**
	 * Callback to resolve a post's meta by post ID.
	 * Returns: array of { meta_key: string, meta_value: string }
	 *
	 * @var callable|null
	 */
	private $meta_resolver = null;

	/**
	 * Callback to resolve a post's terms by post ID.
	 * Returns: array of { taxonomy: string, slug: string }
	 *
	 * @var callable|null
	 */
	private $terms_resolver = null;

	/**
	 * Constructor.
	 *
	 * All post types are stored as markdown by default.
	 * Pass $excluded_types to opt specific types out.
	 *
	 * @param string   $content_dir    Base directory for markdown files.
	 * @param string[] $excluded_types Post types to exclude from markdown storage.
	 */
	public function __construct( string $content_dir, array $excluded_types = array() ) {
		$this->content_dir    = rtrim( $content_dir, '/' );
		$this->excluded_types = $excluded_types;
	}

	/**
	 * Set the post resolver callback.
	 *
	 * The resolver takes a post ID and returns an object with at minimum:
	 *   post_name (string), post_parent (int), post_type (string)
	 *
	 * @param callable $resolver
	 */
	public function set_post_resolver( callable $resolver ): void {
		$this->post_resolver = $resolver;
	}

	/**
	 * Set the meta resolver callback.
	 *
	 * @param callable $resolver Takes post ID, returns array of {meta_key, meta_value}.
	 */
	public function set_meta_resolver( callable $resolver ): void {
		$this->meta_resolver = $resolver;
	}

	/**
	 * Set the terms resolver callback.
	 *
	 * @param callable $resolver Takes post ID, returns array of {taxonomy, slug}.
	 */
	public function set_terms_resolver( callable $resolver ): void {
		$this->terms_resolver = $resolver;
	}

	/**
	 * Write a post to a markdown file.
	 *
	 * Builds a hierarchical directory path based on the post's ancestry.
	 * If the post has a parent, it's written inside the parent's directory.
	 * If a parent post gains its first child, the parent's leaf file is
	 * promoted to a directory with _index.md.
	 *
	 * @param object $post A post row object with WordPress column names.
	 * @return string|false The file path written, or false on failure.
	 */
	public function write_post( object $post ): string|false {
		$post_type = $post->post_type ?? 'post';

		// Skip excluded post types.
		if ( in_array( $post_type, $this->excluded_types, true ) ) {
			return false;
		}

		// Skip auto-drafts — they're ephemeral.
		$status = $post->post_status ?? '';
		if ( 'auto-draft' === $status ) {
			return false;
		}

		$slug = $post->post_name ?? '';
		$id   = (int) ( $post->ID ?? 0 );

		// Use ID as filename fallback if no slug.
		if ( empty( $slug ) ) {
			$slug = $id ? (string) $id : 'untitled-' . time();
		}

		$parent_id = (int) ( $post->post_parent ?? 0 );

		// Build the hierarchical directory path.
		$type_dir = $this->content_dir . '/' . $this->sanitize_path( $post_type );
		$parent_dir = $this->resolve_parent_dir( $type_dir, $parent_id );

		if ( ! is_dir( $parent_dir ) ) {
			mkdir( $parent_dir, 0755, true );
		}

		$file_path = $parent_dir . '/' . $this->sanitize_path( $slug ) . '.md';

		// Build frontmatter.
		$frontmatter = $this->build_frontmatter( $post );

		// Build content.
		$content = $post->post_content ?? '';

		// Assemble the file.
		$output = "---\n";
		$output .= $this->encode_yaml( $frontmatter );
		$output .= "---\n\n";
		$output .= $content . "\n";

		$result = file_put_contents( $file_path, $output, LOCK_EX );

		if ( false !== $result ) {
			// Update the in-memory index.
			if ( $id && null !== $this->index ) {
				// Remove old file if path changed (slug change or reparent).
				if ( isset( $this->index[ $id ] ) && $this->index[ $id ] !== $file_path ) {
					$old_path = $this->index[ $id ];
					@unlink( $old_path );
					$this->cleanup_empty_dirs( dirname( $old_path ), $type_dir );
				}
				$this->index[ $id ] = $file_path;
			}
			return $file_path;
		}

		return false;
	}

	/**
	 * Read a post from a markdown file by ID.
	 *
	 * @param int $post_id The post ID.
	 * @return object|null A post-like object, or null if not found.
	 */
	public function read_post( int $post_id ): ?object {
		$file_path = $this->find_file_by_id( $post_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return null;
		}

		return $this->parse_file( $file_path );
	}

	/**
	 * Delete a post's markdown file.
	 *
	 * If the post is a parent (has a directory), and the directory
	 * still has children, only the _index.md is removed. If the
	 * directory becomes empty, it's cleaned up.
	 *
	 * @param int $post_id The post ID.
	 * @return bool True if deleted, false if not found.
	 */
	public function delete_post( int $post_id ): bool {
		$file_path = $this->find_file_by_id( $post_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		$result = @unlink( $file_path );

		if ( $result ) {
			// Clean up empty directories up to the post type dir.
			$dir = dirname( $file_path );
			$type_dir = $this->guess_type_dir( $file_path );
			$this->cleanup_empty_dirs( $dir, $type_dir );

			if ( null !== $this->index ) {
				unset( $this->index[ $post_id ] );
			}
		}

		return $result;
	}

	/**
	 * Delete all markdown files (truncate).
	 *
	 * @param string $post_type Optional. Truncate only a specific post type directory.
	 */
	public function truncate( string $post_type = '' ): void {
		if ( ! empty( $post_type ) ) {
			$dir = $this->content_dir . '/' . $this->sanitize_path( $post_type );
			$this->remove_directory_recursive( $dir );
		} else {
			// Truncate all post type directories.
			$dirs = glob( $this->content_dir . '/*', GLOB_ONLYDIR );
			if ( $dirs ) {
				foreach ( $dirs as $dir ) {
					if ( ! str_starts_with( basename( $dir ), '_' ) ) {
						$this->remove_directory_recursive( $dir );
					}
				}
			}
		}
		$this->index = null;
	}

	/**
	 * Get all markdown files as post objects.
	 *
	 * Recursively scans each post type directory. Derives post_parent
	 * from the directory structure: _index.md files are parents, files
	 * inside a directory are children of that directory's _index.md.
	 *
	 * Deduplicates by post ID. See GitHub issue #9.
	 *
	 * @return object[] Array of post-like objects (unique by ID).
	 */
	public function get_all_posts(): array {
		if ( ! is_dir( $this->content_dir ) ) {
			return array();
		}

		// Scan all subdirectories — each one is a post type.
		$dirs = glob( $this->content_dir . '/*', GLOB_ONLYDIR );
		if ( ! $dirs ) {
			return array();
		}

		// Collect all posts, keyed by ID for dedup.
		$posts_by_id = array();
		$paths_by_id = array();
		$conflicts   = array();

		foreach ( $dirs as $type_dir ) {
			$dirname = basename( $type_dir );

			// Skip internal directories (prefixed with underscore).
			if ( str_starts_with( $dirname, '_' ) ) {
				continue;
			}

			// Skip excluded types.
			if ( in_array( $dirname, $this->excluded_types, true ) ) {
				continue;
			}

			// Recursively scan this post type directory.
			// First pass: collect all posts and build an ID → post map.
			$all_files = $this->scan_directory_recursive( $type_dir );

			// Second pass: resolve parent IDs from directory structure.
			// An _index.md file IS the parent for all siblings in its directory.
			// We need to find the _index.md's ID for each directory.
			$dir_parent_ids = array();

			// First, find all _index.md files and record their directory → ID mapping.
			foreach ( $all_files as $file ) {
				if ( basename( $file ) === '_index.md' ) {
					$id = $this->extract_id_from_file( $file );
					if ( $id ) {
						$dir_parent_ids[ dirname( $file ) ] = $id;
					}
				}
			}

			// Now parse all files and set post_parent from directory structure.
			foreach ( $all_files as $file ) {
				$post = $this->parse_file( $file );
				if ( ! $post ) {
					continue;
				}

				// Derive post_parent from the directory hierarchy.
				// For hierarchical layouts (_index.md / subdirs), the directory
				// structure is authoritative. For flat layouts (all files at the
				// type root), fall back to the frontmatter `parent` field so
				// pre-migration files retain their hierarchy.
				$file_dir = dirname( $file );

				if ( basename( $file ) === '_index.md' ) {
					// This is a parent post. Its parent is the _index.md of
					// the directory one level up (if it exists).
					$parent_dir = dirname( $file_dir );
					$post->post_parent = $dir_parent_ids[ $parent_dir ] ?? 0;
				} else {
					// Regular file. Its parent is the _index.md in its directory
					// (if one exists and we're not at the post type root).
					if ( $file_dir === $type_dir ) {
						// Top-level file in the post type dir.
						// Keep the frontmatter parent value (set by parse_file)
						// as a fallback for flat/pre-migration layouts.
						// post_parent is already set from frontmatter; don't zero it.
					} else {
						$post->post_parent = $dir_parent_ids[ $file_dir ] ?? 0;
					}
				}

				$id = (int) $post->ID;

				// No ID — include it but it won't collide.
				if ( 0 === $id ) {
					$posts_by_id[] = $post;
					continue;
				}

				if ( ! isset( $posts_by_id[ $id ] ) ) {
					$posts_by_id[ $id ] = $post;
					$paths_by_id[ $id ] = $file;
					continue;
				}

				// Duplicate ID — resolve by file modification time (newest wins).
				$existing_mtime = filemtime( $paths_by_id[ $id ] );
				$new_mtime      = filemtime( $file );

				if ( $new_mtime > $existing_mtime ) {
					$loser_file  = $paths_by_id[ $id ];
					$loser_post  = $posts_by_id[ $id ];
					$winner_file = $file;

					$posts_by_id[ $id ] = $post;
					$paths_by_id[ $id ] = $file;
				} else {
					$loser_file = $file;
					$loser_post = $post;
					$winner_file = $paths_by_id[ $id ];
				}

				$conflicts[] = sprintf(
					'ID %d: kept %s (newer), skipped %s "%s" at %s',
					$id,
					$this->relative_path( $winner_file ),
					$loser_post->post_type ?? 'post',
					$loser_post->post_title ?? '(untitled)',
					$this->relative_path( $loser_file )
				);
			}
		}

		// Log conflicts.
		if ( ! empty( $conflicts ) ) {
			error_log( 'Markdown DB: Resolved ' . count( $conflicts ) . ' duplicate post ID(s) during scan:' );
			foreach ( $conflicts as $msg ) {
				error_log( '  - ' . $msg );
			}
		}

		return array_values( $posts_by_id );
	}

	/**
	 * Resolve the parent directory for a post's file.
	 *
	 * Walks up the ancestor chain using the post resolver to build
	 * the full hierarchical path. If a parent post is currently stored
	 * as a leaf file (slug.md), promotes it to a directory (slug/_index.md).
	 *
	 * @param string $type_dir  The post type root directory.
	 * @param int    $parent_id The post_parent ID (0 = top level).
	 * @return string The directory to write the file in.
	 */
	private function resolve_parent_dir( string $type_dir, int $parent_id ): string {
		if ( 0 === $parent_id || null === $this->post_resolver ) {
			return $type_dir;
		}

		// Build the ancestor chain (bottom-up, then reverse).
		$ancestors = array();
		$current_id = $parent_id;
		$seen = array(); // Guard against infinite loops.

		while ( $current_id > 0 && ! isset( $seen[ $current_id ] ) ) {
			$seen[ $current_id ] = true;

			$parent_post = call_user_func( $this->post_resolver, $current_id );
			if ( ! $parent_post ) {
				break;
			}

			$slug = $parent_post->post_name ?? '';
			if ( empty( $slug ) ) {
				$slug = (string) $current_id;
			}

			$ancestors[] = $this->sanitize_path( $slug );
			$current_id  = (int) ( $parent_post->post_parent ?? 0 );
		}

		// Reverse so we go from root → leaf.
		$ancestors = array_reverse( $ancestors );

		// Build the directory path, promoting leaf files along the way.
		$current_dir = $type_dir;
		foreach ( $ancestors as $slug ) {
			$target_dir  = $current_dir . '/' . $slug;
			$leaf_file   = $current_dir . '/' . $slug . '.md';

			if ( file_exists( $leaf_file ) && ! is_dir( $target_dir ) ) {
				// Promote leaf file to directory with _index.md.
				mkdir( $target_dir, 0755, true );
				rename( $leaf_file, $target_dir . '/_index.md' );

				// Update the index if we have one.
				if ( null !== $this->index ) {
					$promoted_id = $this->extract_id_from_file( $target_dir . '/_index.md' );
					if ( $promoted_id ) {
						$this->index[ $promoted_id ] = $target_dir . '/_index.md';
					}
				}
			} elseif ( ! is_dir( $target_dir ) ) {
				mkdir( $target_dir, 0755, true );
			}

			$current_dir = $target_dir;
		}

		return $current_dir;
	}

	/**
	 * Clean up empty directories after a file is deleted or moved.
	 *
	 * Walks up from $dir to $stop_dir, removing empty directories.
	 *
	 * @param string $dir      The directory to start cleaning from.
	 * @param string $stop_dir Stop when reaching this directory (don't delete it).
	 */
	private function cleanup_empty_dirs( string $dir, string $stop_dir ): void {
		while ( $dir !== $stop_dir && str_starts_with( $dir, $stop_dir ) ) {
			// Check if directory is empty (no files, no subdirs).
			$entries = @scandir( $dir );
			if ( false === $entries ) {
				break;
			}

			// Filter out . and ..
			$entries = array_diff( $entries, array( '.', '..' ) );

			if ( ! empty( $entries ) ) {
				break; // Directory has contents, stop.
			}

			@rmdir( $dir );
			$dir = dirname( $dir );
		}
	}

	/**
	 * Guess the post type directory from a file path.
	 *
	 * The post type dir is the first directory after content_dir.
	 *
	 * @param string $file_path
	 * @return string
	 */
	private function guess_type_dir( string $file_path ): string {
		$relative = $this->relative_path( $file_path );
		$parts    = explode( '/', $relative );
		if ( ! empty( $parts[0] ) ) {
			return $this->content_dir . '/' . $parts[0];
		}
		return $this->content_dir;
	}

	/**
	 * Recursively scan a directory for .md files.
	 *
	 * @param string $dir Directory to scan.
	 * @return string[] Array of file paths.
	 */
	private function scan_directory_recursive( string $dir ): array {
		$files = array();

		if ( ! is_dir( $dir ) ) {
			return $files;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return $files;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) ) {
				// Skip internal directories (e.g. _tables, _schema).
				if ( str_starts_with( $entry, '_' ) ) {
					continue;
				}
				// Recurse into subdirectories (these are parent post directories).
				$files = array_merge( $files, $this->scan_directory_recursive( $path ) );
			} elseif ( str_ends_with( $entry, '.md' ) ) {
				$files[] = $path;
			}
		}

		return $files;
	}

	/**
	 * Get a file path relative to the content directory (for logging).
	 *
	 * @param string $path Absolute file path.
	 * @return string Relative path.
	 */
	private function relative_path( string $path ): string {
		if ( str_starts_with( $path, $this->content_dir . '/' ) ) {
			return substr( $path, strlen( $this->content_dir ) + 1 );
		}
		return $path;
	}

	/**
	 * Find a markdown file path by post ID.
	 *
	 * Uses the in-memory index for fast lookups, rebuilds it on first access.
	 *
	 * @param int $post_id The post ID.
	 * @return string|null File path, or null if not found.
	 */
	private function find_file_by_id( int $post_id ): ?string {
		if ( null === $this->index ) {
			$this->rebuild_index();
		}

		return $this->index[ $post_id ] ?? null;
	}

	/**
	 * Rebuild the in-memory index by scanning all markdown files recursively.
	 *
	 * Deduplicates by ID: when two files share the same ID,
	 * the most recently modified file wins. See GitHub issue #9.
	 */
	private function rebuild_index(): void {
		$this->index = array();

		if ( ! is_dir( $this->content_dir ) ) {
			return;
		}

		$dirs = glob( $this->content_dir . '/*', GLOB_ONLYDIR );
		if ( ! $dirs ) {
			return;
		}

		foreach ( $dirs as $dir ) {
			$dirname = basename( $dir );

			// Skip internal directories.
			if ( str_starts_with( $dirname, '_' ) ) {
				continue;
			}

			$files = $this->scan_directory_recursive( $dir );

			foreach ( $files as $file ) {
				$id = $this->extract_id_from_file( $file );
				if ( ! $id ) {
					continue;
				}

				// Dedup: prefer the most recently modified file.
				if ( isset( $this->index[ $id ] ) ) {
					$existing_mtime = filemtime( $this->index[ $id ] );
					$new_mtime      = filemtime( $file );

					if ( $new_mtime > $existing_mtime ) {
						$this->index[ $id ] = $file;
					}
				} else {
					$this->index[ $id ] = $file;
				}
			}
		}
	}

	/**
	 * Extract the post ID from a markdown file's frontmatter.
	 *
	 * Uses a fast regex scan instead of full YAML parsing.
	 *
	 * @param string $file_path Path to the markdown file.
	 * @return int|null The post ID, or null.
	 */
	private function extract_id_from_file( string $file_path ): ?int {
		// Read just the first ~500 bytes — the ID is always near the top.
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return null;
		}

		$header = fread( $handle, 512 );
		fclose( $handle );

		if ( preg_match( '/^id:\s*(\d+)\s*$/m', $header, $m ) ) {
			return (int) $m[1];
		}

		return null;
	}

	/**
	 * Parse a markdown file into a post-like object.
	 *
	 * Note: post_parent is NOT set here — it's derived from the directory
	 * structure by get_all_posts(). The caller is responsible for setting it.
	 *
	 * @param string $file_path Path to the markdown file.
	 * @return object|null A post object, or null on parse failure.
	 */
	private function parse_file( string $file_path ): ?object {
		$raw = file_get_contents( $file_path );
		if ( false === $raw ) {
			return null;
		}

		// Split frontmatter and content.
		if ( ! preg_match( '/^---\n(.+?)\n---\n\n?(.*)/s', $raw, $m ) ) {
			return null;
		}

		$frontmatter = $this->decode_yaml( $m[1] );
		$content     = rtrim( $m[2] );

		if ( ! $frontmatter ) {
			return null;
		}

		// Map frontmatter back to WordPress column names.
		$post = new \stdClass();

		$post->ID                    = (int) ( $frontmatter['id'] ?? 0 );
		$post->post_title            = $frontmatter['title'] ?? '';
		$post->post_status           = $frontmatter['status'] ?? 'draft';
		$post->post_type             = $frontmatter['type'] ?? 'post';
		$post->post_author           = (int) ( $frontmatter['author'] ?? 0 );
		$post->post_date             = $frontmatter['date'] ?? '0000-00-00 00:00:00';
		$post->post_date_gmt         = $frontmatter['date_gmt'] ?? $post->post_date;
		$post->post_modified         = $frontmatter['modified'] ?? $post->post_date;
		$post->post_modified_gmt     = $frontmatter['modified_gmt'] ?? $post->post_modified;
		$post->post_name             = $frontmatter['slug'] ?? '';
		$post->post_parent           = (int) ( $frontmatter['parent'] ?? 0 ); // Frontmatter fallback; overridden by directory structure in get_all_posts().
		$post->menu_order            = (int) ( $frontmatter['menu_order'] ?? 0 );
		$post->comment_status        = $frontmatter['comment_status'] ?? 'open';
		$post->ping_status           = $frontmatter['ping_status'] ?? 'open';
		$post->post_excerpt          = $frontmatter['excerpt'] ?? '';
		$post->post_content          = $content;
		$post->post_content_filtered = '';
		$post->post_mime_type        = $frontmatter['mime_type'] ?? '';
		$post->post_password         = $frontmatter['password'] ?? '';
		$post->to_ping               = '';
		$post->pinged                = '';
		$post->guid                  = $frontmatter['guid'] ?? '';
		$post->comment_count         = (int) ( $frontmatter['comment_count'] ?? 0 );
		$post->filter                = 'raw';

		// Extract meta from frontmatter (key-value pairs).
		// These will be INSERTed into wp_postmeta by the loader.
		$post->_frontmatter_meta = array();
		if ( isset( $frontmatter['meta'] ) && is_array( $frontmatter['meta'] ) ) {
			$post->_frontmatter_meta = $frontmatter['meta'];
		}

		// Extract terms from frontmatter (taxonomy → [slugs]).
		// These will be resolved and INSERTed into wp_term_relationships by the loader.
		$post->_frontmatter_terms = array();
		if ( isset( $frontmatter['terms'] ) && is_array( $frontmatter['terms'] ) ) {
			$post->_frontmatter_terms = $frontmatter['terms'];
		}

		return $post;
	}

	/**
	 * Build the YAML frontmatter array from a post object.
	 *
	 * Does NOT include `parent` — hierarchy is expressed by directory
	 * structure, not frontmatter. See GitHub issue #14.
	 *
	 * @param object $post A WordPress post row.
	 * @return array Frontmatter key-value pairs.
	 */
	private function build_frontmatter( object $post ): array {
		$fm = array();

		$fm['id']             = (int) ( $post->ID ?? 0 );
		$fm['title']          = $post->post_title ?? '';
		$fm['status']         = $post->post_status ?? 'draft';
		$fm['type']           = $post->post_type ?? 'post';
		$fm['author']         = (int) ( $post->post_author ?? 0 );
		$fm['date']           = $post->post_date ?? '';
		$fm['date_gmt']       = $post->post_date_gmt ?? '';
		$fm['modified']       = $post->post_modified ?? '';
		$fm['modified_gmt']   = $post->post_modified_gmt ?? '';
		$fm['slug']           = $post->post_name ?? '';
		$fm['parent']         = (int) ( $post->post_parent ?? 0 );
		$fm['menu_order']     = (int) ( $post->menu_order ?? 0 );
		$fm['comment_status'] = $post->comment_status ?? 'open';
		$fm['ping_status']    = $post->ping_status ?? 'open';
		$fm['guid']           = $post->guid ?? '';
		$fm['comment_count']  = (int) ( $post->comment_count ?? 0 );

		// Excerpt — only include if non-empty.
		$excerpt = $post->post_excerpt ?? '';
		if ( ! empty( $excerpt ) ) {
			$fm['excerpt'] = $excerpt;
		}

		// Password — only include if set.
		$password = $post->post_password ?? '';
		if ( ! empty( $password ) ) {
			$fm['password'] = $password;
		}

		// MIME type — only include if set (attachments).
		$mime = $post->post_mime_type ?? '';
		if ( ! empty( $mime ) ) {
			$fm['mime_type'] = $mime;
		}

		$id = (int) ( $post->ID ?? 0 );

		// Post meta — fetch from SQLite and include in frontmatter.
		// Each post's .md file is self-contained. See GitHub issue #6.
		if ( $id > 0 && null !== $this->meta_resolver ) {
			$meta_rows = call_user_func( $this->meta_resolver, $id );
			if ( ! empty( $meta_rows ) ) {
				$meta = array();
				foreach ( $meta_rows as $row ) {
					$key   = $row->meta_key ?? '';
					$value = $row->meta_value ?? '';

					// Skip internal/WordPress meta that's not useful in frontmatter.
					if ( empty( $key ) || str_starts_with( $key, '_' ) ) {
						continue;
					}

					$meta[ $key ] = $value;
				}
				if ( ! empty( $meta ) ) {
					$fm['meta'] = $meta;
				}
			}
		}

		// Terms — fetch from SQLite and group by taxonomy.
		if ( $id > 0 && null !== $this->terms_resolver ) {
			$term_rows = call_user_func( $this->terms_resolver, $id );
			if ( ! empty( $term_rows ) ) {
				$terms = array();
				foreach ( $term_rows as $row ) {
					$taxonomy = $row->taxonomy ?? '';
					$slug     = $row->slug ?? '';
					if ( ! empty( $taxonomy ) && ! empty( $slug ) ) {
						$terms[ $taxonomy ][] = $slug;
					}
				}
				if ( ! empty( $terms ) ) {
					$fm['terms'] = $terms;
				}
			}
		}

		return $fm;
	}

	/**
	 * Encode an array as simple YAML.
	 *
	 * Minimal YAML encoder — handles the subset we need for frontmatter.
	 * No dependency on symfony/yaml or any external library.
	 *
	 * @param array $data Key-value pairs.
	 * @return string YAML string.
	 */
	private function encode_yaml( array $data ): string {
		$lines = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$lines[] = $key . ':';
				foreach ( $value as $sub_key => $sub_value ) {
					if ( is_array( $sub_value ) ) {
						$lines[] = '  ' . $sub_key . ':';
						foreach ( $sub_value as $item ) {
							$lines[] = '    - ' . $this->yaml_scalar( $item );
						}
					} else {
						$lines[] = '  ' . $sub_key . ': ' . $this->yaml_scalar( $sub_value );
					}
				}
			} else {
				$lines[] = $key . ': ' . $this->yaml_scalar( $value );
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Encode a scalar value for YAML.
	 *
	 * @param mixed $value The value to encode.
	 * @return string YAML representation.
	 */
	private function yaml_scalar( $value ): string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_null( $value ) ) {
			return '""';
		}

		$str = (string) $value;

		// Quote strings that could be misinterpreted.
		if (
			'' === $str
			|| is_numeric( $str )
			|| preg_match( '/^(true|false|null|yes|no|on|off)$/i', $str )
			|| preg_match( '/[:#\[\]{}|>!&*?]/', $str )
			|| str_starts_with( $str, ' ' )
			|| str_ends_with( $str, ' ' )
			|| str_contains( $str, "\n" )
		) {
			// Use double quotes and escape.
			$str = str_replace( '\\', '\\\\', $str );
			$str = str_replace( '"', '\\"', $str );
			$str = str_replace( "\n", '\\n', $str );
			return '"' . $str . '"';
		}

		return $str;
	}

	/**
	 * Decode simple YAML frontmatter into an array.
	 *
	 * Handles the subset we produce — not a general YAML parser.
	 *
	 * @param string $yaml The YAML string.
	 * @return array|null Decoded array, or null on failure.
	 */
	private function decode_yaml( string $yaml ): ?array {
		$result  = array();
		$lines   = explode( "\n", $yaml );
		$current_key    = null;
		$current_subkey = null;

		foreach ( $lines as $line ) {
			// Skip empty lines.
			if ( '' === trim( $line ) ) {
				continue;
			}

			// Array item: "    - value" (nested under a subkey).
			if ( preg_match( '/^    - (.+)$/', $line, $m ) ) {
				if ( $current_key && $current_subkey ) {
					$result[ $current_key ][ $current_subkey ][] = $this->yaml_decode_scalar( $m[1] );
				}
				continue;
			}

			// Subkey: "  key: value" (nested under a top-level key).
			if ( preg_match( '/^  (\w+):\s*(.*)$/', $line, $m ) ) {
				$current_subkey = $m[1];
				$value          = trim( $m[2] );
				if ( '' === $value ) {
					// This subkey has array children.
					if ( $current_key ) {
						$result[ $current_key ][ $current_subkey ] = array();
					}
				} else {
					if ( $current_key ) {
						$result[ $current_key ][ $current_subkey ] = $this->yaml_decode_scalar( $value );
					}
				}
				continue;
			}

			// Top-level key with no value (has nested children).
			if ( preg_match( '/^(\w+):\s*$/', $line, $m ) ) {
				$current_key    = $m[1];
				$current_subkey = null;
				$result[ $current_key ] = array();
				continue;
			}

			// Top-level key: value.
			if ( preg_match( '/^(\w+):\s+(.+)$/', $line, $m ) ) {
				$current_key    = null;
				$current_subkey = null;
				$result[ $m[1] ] = $this->yaml_decode_scalar( $m[2] );
				continue;
			}
		}

		return empty( $result ) ? null : $result;
	}

	/**
	 * Decode a YAML scalar value.
	 *
	 * @param string $value Raw YAML scalar.
	 * @return mixed Decoded value.
	 */
	private function yaml_decode_scalar( string $value ) {
		$value = trim( $value );

		// Quoted string.
		if ( preg_match( '/^"(.*)"$/s', $value, $m ) ) {
			$str = $m[1];
			$str = str_replace( '\\"', '"', $str );
			$str = str_replace( '\\\\', '\\', $str );
			$str = str_replace( '\\n', "\n", $str );
			return $str;
		}
		if ( preg_match( "/^'(.*)'$/s", $value, $m ) ) {
			return str_replace( "''", "'", $m[1] );
		}

		// Boolean.
		if ( 'true' === strtolower( $value ) ) {
			return true;
		}
		if ( 'false' === strtolower( $value ) ) {
			return false;
		}

		// Null.
		if ( 'null' === strtolower( $value ) || '~' === $value ) {
			return null;
		}

		// Integer.
		if ( preg_match( '/^-?\d+$/', $value ) ) {
			return (int) $value;
		}

		// Float.
		if ( preg_match( '/^-?\d+\.\d+$/', $value ) ) {
			return (float) $value;
		}

		return $value;
	}

	/**
	 * Sanitize a string for use as a filesystem path component.
	 *
	 * @param string $name The name to sanitize.
	 * @return string Sanitized name.
	 */
	private function sanitize_path( string $name ): string {
		// Remove anything that's not alphanumeric, dash, or underscore.
		$name = preg_replace( '/[^a-zA-Z0-9_-]/', '-', $name );
		$name = trim( $name, '-' );
		return $name ?: 'unnamed';
	}

	/**
	 * Remove all files and subdirectories in a directory (recursive).
	 *
	 * @param string $dir Directory path.
	 */
	private function remove_directory_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_directory_recursive( $path );
				@rmdir( $path );
			} else {
				@unlink( $path );
			}
		}
	}

	/**
	 * Get the content directory path.
	 *
	 * @return string
	 */
	public function get_content_dir(): string {
		return $this->content_dir;
	}

	/**
	 * Get the excluded post types.
	 *
	 * @return string[]
	 */
	public function get_excluded_types(): array {
		return $this->excluded_types;
	}

	/**
	 * Check if a post type should be stored as markdown.
	 *
	 * All types are stored unless explicitly excluded.
	 *
	 * @param string $post_type The post type slug.
	 * @return bool
	 */
	public function is_markdown_type( string $post_type ): bool {
		return ! in_array( $post_type, $this->excluded_types, true );
	}
}
