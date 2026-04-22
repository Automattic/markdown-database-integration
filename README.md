# Markdown Database Integration

WordPress database integration that stores content as markdown files. SQLite for machinery, markdown for knowledge.

## What This Does

Every time you create, update, or delete a post in WordPress, the content is converted to **clean markdown** and written to a `.md` file on disk:

```
wp-content/markdown/
  post/
    hello-markdown-world.md
    gutenberg-block-test.md
  page/
    about.md
    contact.md
  wiki/
    woocommerce-pricing.md
```

Each file has YAML frontmatter with metadata and the post content as clean markdown:

```markdown
---
id: 7
title: Gutenberg Block Test
status: publish
type: post
author: 1
date: "2026-04-14 03:14:35"
modified: "2026-04-14 03:14:49"
slug: gutenberg-block-test
---

## This is a heading block

Content goes here with **bold** and *italic* text.

- List item one
- List item two

> A blockquote for good measure.
```

No block comments. No HTML. Just markdown. WordPress keeps working normally — the block editor, plugins, everything. The conversion between markdown and blocks happens automatically.

## Why

Matt Mullenweg, April 2026:

> "like if we exported all p2s to md files? or talked to mysql directly instead of APIs?"
>
> "I don't care which p2s I'm a part of or not, or my wordpress.com account, I want all of Automattic's intelligence that's part of our intranet in one super-fast place"

This plugin makes that happen. Content is files. Files are:

- **AI-native** — any agent reads them directly. No API, no auth, just `grep`.
- **Git-syncable** — `git push` to share knowledge across machines and people.
- **Instant search** — `grep -r "woocommerce" wp-content/markdown/` is faster than any API.
- **Human-readable** — open in any text editor, any IDE, any markdown viewer.
- **LLM Wiki ready** — this is the Karpathy LLM Wiki pattern running on WordPress.

## Conversion Pipeline

Content is stored as raw markdown. Conversion happens at write time (blocks → markdown) and render time (markdown → HTML/blocks):

```
WRITE (post saved in editor):

  Gutenberg Blocks
       │
       ▼  serialize_blocks()                    [WordPress core]
  Block HTML (<!-- wp:paragraph --><p>...</p><!-- /wp:paragraph -->)
       │
       ▼  strip_block_comments()                [WP_Markdown_Converter]
       ▼  unwrap_block_elements()               [WP_Markdown_Converter]
  Clean HTML (<p>...</p>)
       │
       ▼  league/html-to-markdown (+ TableConverter)
  Clean Markdown (stored in .md file)


READ (site boots):

  Markdown (.md file on disk)
       │
       ▼  loaded AS-IS into in-memory SQLite
  post_content is raw markdown


RENDER (display time):

  Frontend:
    post_content (markdown) → the_content filter (p1) → league/commonmark → HTML → browser

  Editor:
    post_content (markdown) → REST filter (p5) → HTML → html-to-blocks-converter REST filter (p10) → blocks → editor

  CLI/API:
    post_content (markdown) → returned as-is
```

### Dependencies

| Package | Direction | Role |
|---------|-----------|------|
| [league/commonmark](https://commonmark.thephpleague.com/) | Markdown → HTML | GFM-flavored parser (tables, strikethrough, autolinks) |
| [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) | HTML → Markdown | Converts clean HTML back to markdown |
| [html-to-blocks-converter](https://github.com/chubes4/html-to-blocks-converter) | HTML → Blocks | PHP port of Gutenberg's `rawHandler` (optional) |

The `html-to-blocks-converter` plugin is optional. Without it, clean HTML is inserted directly into SQLite — WordPress handles this gracefully, and the block editor converts to blocks on first open.

## Architecture

```
WordPress Core ($wpdb)
        │
    WP_Markdown_DB (extends WP_SQLite_DB)
        │
    WP_Markdown_Driver (extends WP_SQLite_Driver)
        │
    ┌───────────────────────────────────────┐
    │  query() override:                    │
    │    1. Execute via SQLite (parent)      │
    │    2. If wp_posts write:               │
    │       convert blocks → markdown        │
    │       write to .md file                │
    └───────────────────────────────────────┘
        │                    │
    SQLite (all tables)    Markdown files
    wp_options             wp-content/markdown/
    wp_users                 post/*.md
    wp_terms                 page/*.md
    transients               wiki/*.md
    plugin tables

    WP_Markdown_Converter
    ├── blocks_to_markdown()    — write path (unwrap_block_elements, TableConverter)
    ├── markdown_to_html()      — render path (the_content filter, REST filter)
    └── strip_block_comments()  — internal to blocks_to_markdown pipeline
```

**SQLite** handles: options, users, terms, transients, sessions, plugin tables — the machinery that WordPress hammers thousands of times per page load.

**Markdown** handles: posts, pages, custom post types — the content that humans and AI agents want to read, search, and sync.

## Render-Time Conversion

`post_content` in SQLite stores raw markdown. Conversion to HTML or blocks happens at render time through WordPress filters:

| Context | Filter | Priority | Output |
|---------|--------|----------|--------|
| Frontend (theme) | `the_content` | 1 | league/commonmark → HTML for the browser |
| REST API / editor | REST `prepare_value` | 5 | league/commonmark → HTML for the REST response |
| Block editor | REST `prepare_value` | 10 | html-to-blocks-converter turns the HTML into block markup |
| WP-CLI / abilities | — | — | Raw markdown returned as-is |

This means WordPress is effectively **markdown-native**. The database holds markdown, and the conversion to whatever format the consumer needs happens on the way out. CLI tools and AI agents skip conversion entirely — they get the markdown directly.

## Requirements

- WordPress 6.9+
- [SQLite Database Integration](https://github.com/WordPress/sqlite-database-integration) plugin (installed as mu-plugin)
- PHP 7.4+
- Composer (for league/commonmark and league/html-to-markdown)

## Installation

```bash
# Clone the plugin
git clone https://github.com/Automattic/markdown-database-integration.git \
  wp-content/plugins/markdown-database-integration

# Install PHP dependencies
cd wp-content/plugins/markdown-database-integration
composer install --no-dev

# Back up the existing db.php
cp wp-content/db.php wp-content/db.php.backup

# Install our db.php drop-in
cp wp-content/plugins/markdown-database-integration/db.php wp-content/db.php
```

Optional: install the [HTML to Blocks Converter](https://github.com/chubes4/html-to-blocks-converter) plugin for full block markup round-trips.

## Configuration

Add to `wp-config.php`:

```php
// Where markdown files are stored (default: wp-content/markdown/)
define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/markdown' );

// Post types to exclude from markdown storage (comma-separated).
// Default: all types stored as markdown. Override if you want certain
// types (e.g. attachments) to live only in SQLite.
define( 'MARKDOWN_DB_EXCLUDED_TYPES', 'attachment,nav_menu_item' );

// Operating mode. 'mirror' (default) or 'primary' — see Modes below.
define( 'MARKDOWN_DB_MODE', 'mirror' );
```

### Modes

- **`mirror`** (default): SQLite on disk is authoritative. Markdown files are mirrored on every write. WordPress reads from SQLite. AI agents read from markdown. Safe, conservative — SQLite on disk survives even if a `.md` file is lost.
- **`primary`**: Markdown files are the sole source of truth. SQLite is rebuilt in-memory from the files on every boot (backed by `wp-content/markdown-index.sqlite`). Writes go to markdown first. Anything without a `.md` file is ephemeral — non-markdown tables (options, users, transients, etc.) are snapshot to `wp-content/markdown/_tables/*.json` and reloaded on boot.

`primary` mode is in production use on personal intelligence sites. It trades a minor boot cost (rebuild from markdown) for a much stronger guarantee: your content is files, not database rows. `git clone` the markdown tree and a fresh WordPress install reconstructs the exact same site.

## Extension Points

### `markdown_db_frontmatter` filter

Extensions that want to contribute additional YAML fields to every `.md` file can hook the `markdown_db_frontmatter` filter. MDI assembles its core fields (post columns, meta, terms) and then passes the array through the filter before writing. Fields added via the filter travel with the file — useful for provenance, domain metadata, or anything that should survive export to git.

```php
add_filter( 'markdown_db_frontmatter', function ( array $fm, $post ) {
    if ( 'wiki' === ( $post->post_type ?? '' ) ) {
        // Nest under a namespace key to avoid collisions with future
        // MDI core fields.
        $fm['my_extension'] = array(
            'custom_attribution' => 'value derived from post meta',
        );
    }
    return $fm;
}, 10, 2 );
```

MDI's own fields (`id`, `title`, `status`, `type`, `slug`, `parent`, etc.) are required for round-trip read/write — removing or mutating them is unsupported and will corrupt the files.

## What Works

Tested on WordPress 6.9 with Studio (SQLite + PHP WASM):

- **Creating posts** via WP-CLI, REST API, or the admin → `.md` file created
- **Updating posts** → `.md` file updated (title, content, metadata)
- **Deleting posts** → `.md` file removed
- **Gutenberg blocks** → converted to clean markdown automatically
- **Pages** → stored in `page/` subdirectory
- **Custom post types** → each type gets its own subdirectory
- **WordPress admin** → works normally, no changes visible
- **Plugins** → work normally, no compatibility issues observed
- **Round-trip** → markdown → HTML → blocks → HTML → markdown preserves content

## Part of Intelligence

This plugin is part of the [Intelligence](https://github.com/Automattic/intelligence) project — a personal AI agent for WordPress. The markdown files are the knowledge layer that Intelligence agents read directly.

## License

GPL v2 or later.
