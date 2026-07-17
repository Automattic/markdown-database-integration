# Markdown Database Integration

WordPress database integration that persists WordPress database rows as markdown and JSON files. SQLite for machinery, files for knowledge.

## What This Does

Every time you create, update, or delete a post in WordPress, the post row is mirrored to a `.md` file on disk:

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

Each file has YAML frontmatter with metadata and the stored `post_content` bytes as the body:

```markdown
---
type: document
title: Gutenberg Block Test
description: A Gutenberg block editor smoke page.
resource: https://example.test/gutenberg-block-test
tags: [gutenberg, blocks]
timestamp: "2026-04-14T03:14:35+00:00"
wordpress:
  id: 7
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

MDI does not decide whether that body is markdown, block markup, or HTML. It stores whatever the caller/content-format layer writes to `post_content`.

MDI manages the frontmatter shape automatically. Markdown files use portable, WordPress-compatible metadata: broadly useful concept fields stay at the top level, while WordPress round-trip fields live under `wordpress`. Existing MDI files are rewritten to the current shape by the one-time frontmatter migration during upgrade.

## Why

WordPress stores content in database rows. That is great for runtime queries,
but awkward for source control, local editing, AI agents, backups, and review.

MDI keeps WordPress running on SQLite while making durable content available as
plain files. Files are:

- **AI-native** — any agent reads them directly. No API, no auth, just `grep`.
- **Git-syncable** — `git push` to share knowledge across machines and people.
- **Instant search** — `grep -r "woocommerce" wp-content/markdown/` is faster than any API.
- **Human-readable** — open in any text editor or IDE.
- **Agent/wiki ready** — content can be read directly by local tools and agents.

## Storage Boundary

MDI's live storage path is storage/persistence only:

- It mirrors WordPress DB rows to files.
- It rebuilds the SQLite index from files in primary mode.
- It stores `post_content` bytes exactly as received.
- It does not render markdown to HTML.
- It does not convert editor block markup to markdown during normal writes.
- It does not register render, REST, editor, or write-engine conversion hooks.

Content-format policy belongs to the application layer above MDI. A site can
choose to store block markup, HTML, markdown, or another format in
`post_content`; MDI persists those bytes without interpreting them.

Import/export is the explicit content-format boundary. The `markdown-db import`
and `markdown-db export` commands and abilities use Block Format Bridge to
round-trip between markdown files and serialized block content by default:

- Import: `markdown` → `blocks`
- Export: `blocks` → `markdown`
- Raw byte preservation: pass `--no-convert` or set `no_convert` in the ability input.
- Custom conversion: pass `--from=<format> --to=<format>`.
- Policy override: filter `markdown_db_content_format_conversion`.

```
WRITE:

  WordPress caller writes post_content
       │
       ▼
  SQLite stores post_content bytes
       │
       ▼  MDI write engine
  .md file stores the same bytes


READ (site boots):

  .md file body
       │
       ▼  loaded AS-IS into in-memory SQLite
  post_content has the same bytes


IMPORT / EXPORT:

  .md file body or post_content
       │
       ▼  BFB conversion, unless disabled
  target file body or post_content


RENDER / EDITOR / API:

  Handled by the application/content-format layer, not MDI.
```

### Dependencies

MDI requires Block Format Bridge for self-contained import/export conversion.
The drop-in and live write engine remain byte-preserving; BFB is not used by
the runtime render, REST, editor, or DB write paths.

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
    │       write row bytes to .md file      │
    └───────────────────────────────────────┘
        │                    │
    SQLite (all tables)    Markdown files
    wp_options             wp-content/markdown/
    wp_users                 post/*.md
    wp_terms                 page/*.md
    transients               wiki/*.md
    plugin tables

    Live content conversion lives above MDI.
    Import/export conversion lives at the explicit MDI CLI/ability boundary.
```

**SQLite** handles: options, users, terms, transients, sessions, plugin tables — the machinery that WordPress hammers thousands of times per page load.

**Markdown files** handle: posts, pages, custom post types — the content rows that humans and AI agents want to read, search, and sync.

## Content Formats

MDI does not make WordPress markdown-native by itself. It makes WordPress content file-backed.

If a site wants wiki posts stored as markdown and rendered as HTML, the site/application layer should declare that policy and handle conversion at write, render, REST, and editor edges. MDI then persists the resulting `post_content` bytes without knowing which layer produced them. For repository import/export workflows, MDI can convert through BFB at the command/ability boundary without changing live storage behavior.

## Requirements

- WordPress 6.9+
- A normal WordPress database. MySQL/MariaDB works for import/export commands; the bundled `db.php` drop-in additionally supports SQLite-backed mirror/primary modes.
- PHP 8.1+
- Composer

## Installation

```bash
# Clone the plugin
git clone https://github.com/Automattic/markdown-database-integration.git \
  wp-content/plugins/markdown-database-integration

# Install PHP dependencies.
cd wp-content/plugins/markdown-database-integration
composer install --no-dev

# Activate the plugin. A MARKDOWN_DB_MODE constant alone does not activate MDI.
wp plugin activate markdown-database-integration

# For SQLite-backed mirror or primary mode, inspect and install the MDI drop-in.
wp markdown-db doctor
wp markdown-db doctor --repair
```

The bundled drop-in includes the `@studio-keep` marker so WordPress Studio
preserves it during SQLite integration refreshes.

If `wp-content/db.php` belongs to another integration, `--repair` refuses to
replace it. Inspect that integration first; only use `--repair --force` when
you approve a deterministic backup at `wp-content/db.php.markdown-db-backup`.
Restart PHP or WordPress after an install or repair because WordPress loads
`db.php` before regular plugins. A healthy install reports `healthy`; a fresh
primary install can report `install_fallback` while WordPress completes its
first installation.

On a normal MySQL/MariaDB WordPress site, activate the plugin without copying
the `db.php` drop-in. Use the import/export commands or abilities to move
content between the active database and `MARKDOWN_DB_CONTENT_DIR`.

## Configuration

Add to `wp-config.php`:

```php
// Where Markdown-backed posts and post-type hierarchy are stored.
// Default: wp-content/markdown/
define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/markdown' );

// Or customize the storage root for a plugin or repo-backed app:
define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/plugins/my-world/content' );

// Optional local root for non-post runtime state. When omitted, this defaults
// to MARKDOWN_DB_CONTENT_DIR and preserves the existing single-root layout.
define( 'MARKDOWN_DB_STATE_DIR', WP_CONTENT_DIR . '/markdown-state' );

// Post types to exclude from markdown storage (comma-separated).
// Default: all types stored as markdown. Override if you want certain
// types (e.g. attachments) to live only in SQLite.
define( 'MARKDOWN_DB_EXCLUDED_TYPES', 'attachment,nav_menu_item' );

// Operating mode. 'mirror' (default) or 'primary' — see Modes below.
define( 'MARKDOWN_DB_MODE', 'mirror' );
```

### Modes

- **`mirror`** (default): SQLite on disk is authoritative. Markdown files are mirrored on every write. WordPress reads from SQLite. AI agents read from markdown. Safe, conservative — SQLite on disk survives even if a `.md` file is lost.
- **`primary`**: Markdown files are the sole source of truth for posts. SQLite is rebuilt from the files on cold boot and incrementally synchronized on warm boot (backed by `wp-content/markdown-index.sqlite` in the default layout). Writes go to markdown first. Non-markdown tables (options, users, plugin tables, etc.) are snapshotted as JSON and reloaded on boot.

`primary` mode trades a minor boot cost (rebuild from markdown) for a much
stronger guarantee: your content is files, not database rows. `git clone` the
markdown tree and a fresh WordPress install can reconstruct the same content.

With only `MARKDOWN_DB_CONTENT_DIR` configured, primary mode keeps the existing
single-root layout:

```
wp-content/
  markdown-index.sqlite
  markdown/
    post/*.md
    page/*.md
    _options/*.json
    _tables/*.json
    _schema/*.sql
```

For a Git-backed post-only repository, configure a separate local state root:

```php
define( 'MARKDOWN_DB_MODE', 'primary' );
define( 'MARKDOWN_DB_CONTENT_DIR', '/path/to/git/content' );
define( 'MARKDOWN_DB_STATE_DIR', WP_CONTENT_DIR . '/markdown-state' );
```

This routes storage by ownership:

```
/path/to/git/content/              # safe to version as post content
  post/*.md
  page/*.md
  wiki/*.md

wp-content/markdown-state/         # local WordPress runtime state
  markdown-index.sqlite
  _options/*.json
  _tables/*.json
  _schema/*.sql
```

Installed-site detection reads `siteurl` from the state root, so the content
repository does not need machine-specific options. Cold and warm primary boots
load Markdown posts from the content root and all non-post state from the state
root. `MARKDOWN_DB_INDEX_PATH` can still override the primary index path.

## MySQL/MariaDB Import and Export

MDI exposes generic import/export operations through both WP-CLI and the
WordPress Abilities API. These operations use the same service path and work
against the current WordPress database, whether that database is MySQL,
MariaDB, or SQLite.

Import markdown files into the current database:

```bash
wp markdown-db import --dry-run
wp markdown-db import
```

Export current posts, pages, and custom post types to markdown:

```bash
wp markdown-db export --dry-run
wp markdown-db export
```

Both commands default to `MARKDOWN_DB_CONTENT_DIR`. Pass `--path=/path/to/markdown`
to read from or write to a different root. Export accepts `--post-type=post,page,wiki`
to limit the post types.

The same operations are available to agents through abilities:

- `markdown-db/import`
- `markdown-db/export`

The import path upserts posts instead of duplicating them. It records
`_markdown_source_path` and `_markdown_source_hash` post meta so repeat imports
can update the same database rows even when the runtime is not using the SQLite
drop-in. MDI imports the fields already represented by its storage parser:
post hierarchy, slugs, post type, status, dates, content bytes, frontmatter
meta, and frontmatter terms. It does not convert markdown, block markup, or
HTML between formats.

### Import/export content transforms

MDI stays storage-only, but import/export exposes filter seams so downstream
plugins can decide how file bodies map to WordPress `post_content` and back.
When no filters are registered, content is imported and exported unchanged.

Available filters:

- `markdown_db_import_post_content`: filters the parsed file body before `wp_insert_post()` receives `post_content`.
- `markdown_db_import_post_data`: filters the complete `wp_insert_post()` array before insert/update.
- `markdown_db_export_post_content`: filters a post object's `post_content` before MDI writes the markdown body.
- `markdown_db_export_post_object`: filters the post-like object before storage writes it.

Each filter receives a context array with fields such as `operation`,
`post_type`, `source_path`, `content_dir`, `source_format`, `stored_format`,
`dry_run`, and parsed `frontmatter` when import has it available. Import
contexts also include `write_operation` with `create` or `update`.

Example downstream policy:

```php
add_filter(
    'markdown_db_import_post_content',
    function ( string $content, array $context ): string {
        if ( 'wiki' !== $context['post_type'] ) {
            return $content;
        }

        return my_site_convert_markdown_to_editor_content( $content );
    },
    10,
    2
);

add_filter(
    'markdown_db_export_post_content',
    function ( string $content, array $context ): string {
        if ( 'wiki' !== $context['post_type'] ) {
            return $content;
        }

        return my_site_convert_editor_content_to_markdown( $content );
    },
    10,
    2
);
```

Those conversion functions are intentionally application-owned. MDI provides
the storage and context; downstream plugins provide format conversion policy
and dependencies.

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

Tested on WordPress 6.9 with SQLite-backed local and Playground-style runtimes:

- **Creating posts** via WP-CLI, REST API, or the admin → `.md` file created
- **Updating posts** → `.md` file updated (title, content, metadata)
- **Deleting posts** → `.md` file removed
- **Gutenberg blocks** → stored exactly as `post_content` unless another layer converts them first
- **Pages** → stored in `page/` subdirectory
- **Custom post types** → each type gets its own subdirectory
- **WordPress admin** → works normally, no changes visible
- **Plugins** → work normally, no compatibility issues observed
- **Round-trip** → file body and `post_content` stay byte-identical for storage-managed post types

## License

GPL v2 or later.
