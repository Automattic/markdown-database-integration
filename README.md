# Markdown Database Integration

WordPress database integration that stores content as markdown files. SQLite for machinery, markdown for knowledge.

## What This Does

Every time you create, update, or delete a post in WordPress, the content is written to a `.md` file on disk:

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

Each file has YAML frontmatter with metadata and the post content as the body:

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

<!-- wp:heading -->
<h2 class="wp-block-heading">This is a heading block</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Content goes here.</p>
<!-- /wp:paragraph -->
```

WordPress keeps working normally. The block editor works. Plugins work. Everything that reads from `$wpdb` gets the same data it always did — because SQLite is still the query engine. The markdown files are a sync layer on top.

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

## Architecture

```
WordPress Core ($wpdb)
        |
    WP_Markdown_DB (extends WP_SQLite_DB)
        |
    WP_Markdown_Driver (extends WP_SQLite_Driver)
        |
    +-----------------------------------+
    |  query() override:                |
    |    1. Execute via SQLite (parent)  |
    |    2. If wp_posts write:           |
    |       sync to .md file             |
    +-----------------------------------+
        |                    |
    SQLite (all tables)    Markdown files
    wp_options             wp-content/markdown/
    wp_users                 post/*.md
    wp_terms                 page/*.md
    transients               wiki/*.md
    plugin tables
```

**SQLite** handles: options, users, terms, transients, sessions, plugin tables — the machinery that WordPress hammers thousands of times per page load.

**Markdown** handles: posts, pages, custom post types — the content that humans and AI agents want to read, search, and sync.

## Requirements

- WordPress 6.9+
- [SQLite Database Integration](https://github.com/WordPress/sqlite-database-integration) plugin (installed as mu-plugin)
- PHP 7.4+

## Installation

1. Clone or copy this plugin to `wp-content/plugins/markdown-database-integration/`
2. Copy `db.php` to `wp-content/db.php` (replacing the SQLite integration's version)
3. That's it. New posts will appear as markdown files in `wp-content/markdown/`

```bash
# Clone the plugin
git clone https://github.com/chubes4/markdown-database-integration.git \
  wp-content/plugins/markdown-database-integration

# Back up the existing db.php
cp wp-content/db.php wp-content/db.php.backup

# Install our db.php drop-in
cp wp-content/plugins/markdown-database-integration/db.php wp-content/db.php
```

## Configuration

Add to `wp-config.php`:

```php
// Where markdown files are stored (default: wp-content/markdown/)
define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/markdown' );

// Which post types to store as markdown (default: post,page)
define( 'MARKDOWN_DB_POST_TYPES', 'post,page,wiki' );

// Operating mode (default: mirror)
define( 'MARKDOWN_DB_MODE', 'mirror' );
```

### Modes

- **`mirror`** (Phase 1): SQLite is the primary database. Markdown files are synced on every write. WordPress reads from SQLite. AI agents read from markdown.
- **`primary`** (Phase 2, coming soon): Markdown files are the primary source of truth. SQLite is an index rebuilt from the files. Writes go to markdown first.

## What Works

Tested on WordPress 6.9 with Studio (SQLite + PHP WASM):

- **Creating posts** via WP-CLI, REST API, or the admin → `.md` file created
- **Updating posts** → `.md` file updated (title, content, metadata)
- **Deleting posts** → `.md` file removed
- **Gutenberg blocks** → block markup preserved in the markdown file
- **Pages** → stored in `page/` subdirectory
- **Custom post types** → each type gets its own subdirectory
- **WordPress admin** → works normally, no changes visible
- **Plugins** → work normally, no compatibility issues observed

## File Format

```yaml
---
id: 42
title: "Post Title"
status: publish
type: post
author: 1
date: "2026-04-13 21:17:48"
date_gmt: "2026-04-13 21:17:48"
modified: "2026-04-14 00:33:50"
modified_gmt: "2026-04-14 00:33:50"
slug: post-title
parent: 0
menu_order: 0
comment_status: open
ping_status: open
guid: "http://localhost:8881/2026/04/13/post-title/"
comment_count: 0
excerpt: "Optional excerpt"
---

Post content goes here as markdown (or Gutenberg block markup).
```

## Roadmap

- [ ] `wp markdown sync` — one-time sync of existing posts to markdown files
- [ ] `wp markdown rebuild` — rebuild SQLite index from markdown files (Phase 2)
- [ ] `wp markdown export` — export all content as a git-ready markdown directory
- [ ] `wp_postmeta` as frontmatter — custom fields in the YAML
- [ ] `wp_terms` as frontmatter tags — categories and tags in the YAML
- [ ] File watcher — detect external edits to `.md` files and sync back to SQLite
- [ ] Git integration — auto-commit on post save
- [ ] Block-to-markdown conversion — strip Gutenberg comments, output clean markdown
- [ ] Transfer to `Automattic/markdown-database-integration`

## Part of Intelligence

This plugin is part of the [Intelligence](https://github.com/Automattic/intelligence) project — a personal AI agent for WordPress. The markdown files are the knowledge layer that Intelligence agents read directly.

## License

GPL v2 or later.
