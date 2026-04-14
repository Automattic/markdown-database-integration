<?php
/**
 * Markdown Storage Engine
 *
 * Reads and writes WordPress posts as markdown files with YAML frontmatter.
 * Each post becomes a file at: {content_dir}/{post_type}/{slug}.md
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
 *   parent: 0
 *   menu_order: 0
 *   comment_status: open
 *   ping_status: open
 *   excerpt: "A short excerpt..."
 *   meta:
 *     custom_field: "value"
 *     another_field: "value"
 *   terms:
 *     category:
 *       - uncategorized
 *     post_tag:
 *       - ai
 *       - wordpress
 *   ---
 *
 *   The post content goes here as markdown.
 *
 *   ## Subheading
 *
 *   More content...
 *
 * @package Markdown_Database_Integration
 * @since 0.1.0
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
	 * Post types that should be stored as markdown.
	 *
	 * @var string[]
	 */
	private $post_types;

	/**
	 * In-memory index of post ID → file path.
	 * Populated on first access, updated on writes.
	 *
	 * @var array<int, string>|null
	 */
	private $index = null;

	/**
	 * Constructor.
	 *
	 * @param string   $content_dir Base directory for markdown files.
	 * @param string[] $post_types  Post types to store as markdown.
	 */
	public function __construct( string $content_dir, array $post_types = array( 'post', 'page' ) ) {
		$this->content_dir = rtrim( $content_dir, '/' );
		$this->post_types  = $post_types;
	}

	/**
	 * Write a post to a markdown file.
	 *
	 * Accepts a row object (as returned by $wpdb) and writes it as a .md file
	 * with YAML frontmatter.
	 *
	 * @param object $post A post row object with WordPress column names.
	 * @return string|false The file path written, or false on failure.
	 */
	public function write_post( object $post ): string|false {
		$post_type = $post->post_type ?? 'post';

		// Only write configured post types.
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			return false;
		}

		// Skip revisions and auto-drafts.
		if ( 'revision' === $post_type ) {
			return false;
		}
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

		// Build the directory path: {content_dir}/{post_type}/
		$dir = $this->content_dir . '/' . $this->sanitize_path( $post_type );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		$file_path = $dir . '/' . $this->sanitize_path( $slug ) . '.md';

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
				// Remove old path if slug changed.
				if ( isset( $this->index[ $id ] ) && $this->index[ $id ] !== $file_path ) {
					@unlink( $this->index[ $id ] );
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
	 * @param int $post_id The post ID.
	 * @return bool True if deleted, false if not found.
	 */
	public function delete_post( int $post_id ): bool {
		$file_path = $this->find_file_by_id( $post_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		$result = @unlink( $file_path );

		if ( $result && null !== $this->index ) {
			unset( $this->index[ $post_id ] );
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
			$this->remove_directory_contents( $dir );
		} else {
			foreach ( $this->post_types as $type ) {
				$dir = $this->content_dir . '/' . $this->sanitize_path( $type );
				$this->remove_directory_contents( $dir );
			}
		}
		$this->index = null;
	}

	/**
	 * Get all markdown files as post objects.
	 *
	 * Used for rebuilding the SQLite index from markdown (Phase 2).
	 *
	 * @return object[] Array of post-like objects.
	 */
	public function get_all_posts(): array {
		$posts = array();

		foreach ( $this->post_types as $post_type ) {
			$dir = $this->content_dir . '/' . $this->sanitize_path( $post_type );
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = glob( $dir . '/*.md' );
			if ( ! $files ) {
				continue;
			}

			foreach ( $files as $file ) {
				$post = $this->parse_file( $file );
				if ( $post ) {
					$posts[] = $post;
				}
			}
		}

		return $posts;
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
	 * Rebuild the in-memory index by scanning all markdown files.
	 */
	private function rebuild_index(): void {
		$this->index = array();

		foreach ( $this->post_types as $post_type ) {
			$dir = $this->content_dir . '/' . $this->sanitize_path( $post_type );
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = glob( $dir . '/*.md' );
			if ( ! $files ) {
				continue;
			}

			foreach ( $files as $file ) {
				$id = $this->extract_id_from_file( $file );
				if ( $id ) {
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
		$post->post_parent           = (int) ( $frontmatter['parent'] ?? 0 );
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

		return $post;
	}

	/**
	 * Build the YAML frontmatter array from a post object.
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
	 * Remove all files in a directory (non-recursive).
	 *
	 * @param string $dir Directory path.
	 */
	private function remove_directory_contents( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( $dir . '/*.md' );
		if ( $files ) {
			foreach ( $files as $file ) {
				@unlink( $file );
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
	 * Get the configured post types.
	 *
	 * @return string[]
	 */
	public function get_post_types(): array {
		return $this->post_types;
	}

	/**
	 * Check if a post type should be stored as markdown.
	 *
	 * @param string $post_type The post type slug.
	 * @return bool
	 */
	public function is_markdown_type( string $post_type ): bool {
		return in_array( $post_type, $this->post_types, true );
	}
}
