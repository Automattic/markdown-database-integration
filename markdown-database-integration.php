<?php
/**
 * Plugin Name: Markdown Database Integration
 * Plugin URI: https://github.com/chubes4/markdown-database-integration
 * Description: WordPress database integration that stores content as markdown files. SQLite for machinery, markdown for knowledge. AI-native WordPress storage layer.
 * Version: 0.1.0
 * Author: Chris Huber
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: markdown-database-integration
 * Requires at least: 6.9
 * Requires PHP: 7.4
 *
 * Requires the SQLite Database Integration plugin (sqlite-database-integration).
 *
 * Modes:
 *   - 'mirror'  (Phase 1): SQLite is primary. Markdown files are mirrored on every write.
 *                           WordPress reads from SQLite. AI agents read from markdown.
 *   - 'primary' (Phase 2): Markdown is primary. SQLite is an index rebuilt from .md files.
 *                           WordPress reads are served from the index. Writes go to markdown first.
 *
 * The markdown files are the knowledge layer:
 *   - AI agents read them directly (no API, no query, just grep)
 *   - Git syncs them across machines and people
 *   - Every post is a file, every file is searchable
 *   - WordPress keeps working normally
 *
 * @package Markdown_Database_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MARKDOWN_DB_VERSION', '0.2.0' );
define( 'MARKDOWN_DB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * The content directory where markdown files are stored.
 * Override in wp-config.php: define( 'MARKDOWN_DB_CONTENT_DIR', '/path/to/wiki' );
 */
if ( ! defined( 'MARKDOWN_DB_CONTENT_DIR' ) ) {
	define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/markdown' );
}

/**
 * Operating mode: 'mirror' or 'primary'.
 * Override in wp-config.php: define( 'MARKDOWN_DB_MODE', 'primary' );
 */
if ( ! defined( 'MARKDOWN_DB_MODE' ) ) {
	define( 'MARKDOWN_DB_MODE', 'mirror' );
}

/**
 * Post types to store as markdown. Comma-separated.
 * Override in wp-config.php: define( 'MARKDOWN_DB_POST_TYPES', 'post,page,wiki' );
 */
if ( ! defined( 'MARKDOWN_DB_POST_TYPES' ) ) {
	define( 'MARKDOWN_DB_POST_TYPES', 'post,page' );
}
