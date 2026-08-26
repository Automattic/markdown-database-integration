# Markdown Database Integration

File-backed, reconstructable WordPress database state: Markdown for content,
JSON for WordPress and plugin table rows, SQL for plugin schemas, and SQLite
as a rebuildable query engine and index.

## What This Does

MDI has two SQLite-backed operating modes:

- **`mirror`** (the default) keeps the SQLite database authoritative and mirrors
  Markdown-backed post writes to files.
- **`primary`** persists the state needed to reconstruct WordPress to ordinary
  files. SQLite remains the runtime query engine and index, but a cold boot can
  recreate it from the content and state trees.

## SQLite Integration API

MDI requires a SQLite Database Integration release that includes the canonical
PDO-compatible `WP_MySQL_On_SQLite` API, introduced by
WordPress/sqlite-database-integration#449. MDI no longer uses the deprecated
`WP_SQLite_Driver` compatibility layer. The runtime constructs the canonical
driver with its `mysql-on-sqlite:` DSN and consumes query results as
`PDOStatement` objects.

In primary mode, a typical single-root store looks like this:

```
wp-content/markdown/
  post/
    hello-markdown-world.md
    gutenberg-block-test.md
  page/
    about.md
    contact.md
  wiki/                         # Custom post type.
    woocommerce-pricing.md
  _options/
    siteurl.json
  _tables/
    users.json
    comments.json
    my_plugin_jobs.json
  _schema/
    my_plugin_jobs.sql
```

Post types get their own directories. Each Markdown-backed post file has YAML
frontmatter for its row data, post meta, and terms, with stored `post_content`
bytes as its body:

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

MDI does not decide whether that body is Markdown, block markup, or HTML. It
stores whatever the caller or content-format layer writes to `post_content`.

MDI manages the frontmatter shape automatically. Markdown files use portable, WordPress-compatible metadata: broadly useful concept fields stay at the top level, while WordPress round-trip fields live under `wordpress`. Existing MDI files are rewritten to the current shape by the one-time frontmatter migration during upgrade.

### Primary-Mode Persistence

Primary mode persists the following state so SQLite can be reconstructed:

- Post rows and content in `post/*.md`, `page/*.md`, and custom-post-type
  directories; their post meta and terms are in frontmatter.
- Options as individual `_options/*.json` files.
- Users and usermeta, taxonomy tables, comments and commentmeta, links, and
  non-Markdown posts as `_tables/*.json` snapshots.
- Arbitrary plugin-table rows as `_tables/*.json` snapshots and their schemas
  as `_schema/*.sql` files.

The driver persists every detected `INSERT`, `UPDATE`, `DELETE`, and `REPLACE`
unless its table is explicitly excluded through `MARKDOWN_DB_EPHEMERAL_TABLES`
or the `markdown_db_ephemeral_tables` filter. The write engine also honors an
explicit `markdown_db_table_persistence_policy` exclusion. Do not assume that
caches, sessions, or other plugin tables are ephemeral by default; configure
the exclusion for tables a site does not want persisted.

## Storage Boundary

MDI is a storage and persistence layer:

- It persists database state to files and rebuilds or synchronizes the SQLite
  index from those files in primary mode.
- It stores `post_content` bytes exactly as received.
- It does not render markdown to HTML.
- It does not convert editor block markup to markdown during normal writes.
- It does not register render, REST, editor, or write-engine conversion hooks.

Content-format policy belongs to the application layer above MDI. A site can
choose block markup, HTML, Markdown, or another format for `post_content`; MDI
persists those bytes and database state without interpreting them.

Import/export is the explicit content-format boundary. The `markdown-db import`
and `markdown-db export` commands and abilities use Block Format Bridge to
round-trip between markdown files and serialized block content by default:

- Import: `markdown` → `blocks`
- Export: `blocks` → `markdown`
- Raw byte preservation: pass `--no-convert` or set `no_convert` in the ability input.
- Custom conversion: pass `--from=<format> --to=<format>`.
- Policy override: filter `markdown_db_content_format_conversion`.
- Layout selection: pass `--layout-profile=<id>` or configure
  `MARKDOWN_DB_CONTENT_LAYOUT_PROFILE`. External layouts register their complete
  `enumerate`, `map_source`, and `path_for_post` contract from the bootstrap
  file named by `MARKDOWN_DB_CONTENT_LAYOUT_PROFILE_BOOTSTRAP`, which is loaded
  before the `db.php` primary runtime starts.

Custom layout writes stage a temporary file in a verified destination directory.
MDI rejects symlinked path segments and compares the directory device/inode before
and after staging and immediately before rename. A detected directory replacement
aborts and removes the staged file. This is PHP's strongest portable filesystem
guarantee without an `openat(2)` descriptor API; deployments that permit an
attacker to replace directories between the final identity check and `rename()`
must protect the content root with filesystem ownership and permissions.

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

## Why

File-backed primary state makes a WordPress site reconstructable instead of
depending solely on one SQLite file. That supports:

- Portability between machines or fresh WordPress installations.
- Git review, history, and replication for content and any state roots a site
  chooses to version.
- Direct inspection and editing of content by people, local tools, and AI
  agents.
- Backups and recovery by rebuilding a disposable SQLite runtime from the
  persisted files.
- Disposable local or test runtimes that can be recreated from the same trees.

## Architecture

```
WordPress Core ($wpdb)
        │
        v
WP_Markdown_Driver -------------------- runtime queries ----> SQLite index
        │                                                        ^
        │ successful writes                                      │ rebuild/sync
        v                                                        │
Persisted files ---------------------- cold/warm boot --> MDI loader
        │
        ├── MARKDOWN_DB_CONTENT_DIR
        │     post/*.md, page/*.md, {type}/*.md
        │
        └── MARKDOWN_DB_STATE_DIR
              _options/*.json, _tables/*.json, _schema/*.sql,
              markdown-index.sqlite
```

### Durable Reconciliation Operations

Bounded reconciliation can use `WP_Markdown_Durable_Reconciliation_Operations`
to coordinate mutations that cross the WordPress and canonical-filesystem
durability domains. Operations bind a plan and continuation, canonical root,
normalized resource identity, direction and kind, and exact normalized before
and after identities. The lifecycle is explicit:

```
planned -> claimed -> effect_observed -> completed
                  \-> ambiguous -> reconciliation_required
```

`WP_Markdown_Reconciliation_Adapter` keeps WordPress, SQLite, MySQL, and file
observation/mutation details behind the owning adapter. Recovery of an ordinary
operation is observation-only and completes only when every named domain exactly
proves the intended after identity. A separately prepared WordPress commit may
continue its canonical effect only after its exact commit checkpoint is proven.
Missing, indeterminate, or divergent evidence is persisted as a structured
`reconciliation_required` conflict.

Because only an owning backend can make resource fencing atomic with its effect,
an adapter installs each claimed fence and must atomically reject any token that
is no longer current for the normalized resource. The generic store also rejects
expired-lease transitions; together these prevent a stale worker from publishing
progress or mutating after a replacement owner has fenced it.

The supplied filesystem operation store uses revision/state/fence
compare-and-set transitions, expiring ownership leases, and monotonically
increasing fencing tokens. Its HMAC-authenticated, atomically replaced journal
has caller-configured record and byte bounds, retains terminal identities to
prevent stale intent from being planned again, and rejects placement within or
above a managed canonical root. Operations are accepted only for roots authorized
when the store is constructed, and at least one root must be declared. Deployments
should derive its authentication key from server-only secret material and place
it in a server-owned runtime directory. This journal is runtime coordination
state, not canonical content; it has no Git or publication requirement.

### Three-Way Content Reconciliation

`WP_Markdown_Reconciliation_Service` is the backend-neutral planning and apply
contract shared by PHP callers, backend content adapters, WP-CLI, and the
`markdown-db/reconcile` ability. It compares the current normalized canonical
post identity, current normalized WordPress post identity, and the last common
baseline recorded for that canonical root. Plans contain deterministic arrays
for `created`, `updated_from_file`, `written_from_wordpress`,
`deleted_from_file`, `deleted_from_wordpress`, `moved`, `unchanged`, and
`conflicts`.

The production adapter keeps the secret-free last-common identity in both post
metadata and a root-keyed WordPress option so the baseline survives deletion of
the WordPress row. Normalized content identities intentionally exclude the
backend-assigned post ID; the resource ID binds ownership separately.

Public entries contain only SHA-256 identities, canonical paths, and resource
IDs. Conflict entries include all three compared identities and do not mutate
by default. MDI performs no Git operation, remote merge, branch update, or
publication decision; those remain caller policy.

Every applied resource is an operation in the durable store described above.
Apply first validates the reviewed source and plan without effects, then
enumerates original incomplete operation IDs and recovers or
executes under the production database/filesystem ownership adapters. Exact
before and after identities, the plan, source snapshot, resource, root, and
continuation are bound into the durable operation ID. An ambiguous effect is
reported as `reconciliation_required`, never inferred as success.

Deletion is disabled unless `deletion_policy=managed`, and even then requires a
last-common baseline proving that the resource was managed by the selected
canonical root. Batch sizes are bounded from 1 through 1000. Continuations are
opaque, stable objects bound to the same plan and complete source snapshot.

With one root, `MARKDOWN_DB_STATE_DIR` defaults to
`MARKDOWN_DB_CONTENT_DIR`. When they are split, the content root owns
Markdown-backed posts while the state root owns JSON snapshots, plugin schemas,
and the SQLite index. On a cold primary boot, MDI creates core tables; loads
options, users, taxonomy, Markdown posts and their frontmatter meta and terms,
remaining core rows, and plugin schemas and tables; then saves manifests for
incremental warm synchronization.

In mirror mode, SQLite remains authoritative and files are mirrors rather than a
reconstruction source. In either mode, live content conversion remains above
MDI, and import/export conversion is limited to the explicit CLI or ability
boundary.

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

This order also applies to WP-CLI: an installed MDI `db.php` runtime is active
before normal plugin activation and is not removed by
`--skip-plugins=markdown-database-integration`. Canonical persistence registers
its own native PHP shutdown callback only after a tracked mutation. Read-only
commands therefore have a clean, zero-work flush; dirty requests publish their
bounded dirty subset atomically before exit. Subscribe to
`markdown_database_integration_persistence_diagnostics` to attribute a flush
to its tables, post IDs, partition resources, and canonical paths.

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

// Tables to exclude from file persistence (comma-separated table suffixes).
// No tables are excluded by default.
define( 'MARKDOWN_DB_EPHEMERAL_TABLES', 'my_session_table' );

// Operating mode. 'mirror' (default) or 'primary' — see Modes below.
define( 'MARKDOWN_DB_MODE', 'mirror' );
```

### Modes

- **`mirror`** (default): SQLite on disk is authoritative. MDI mirrors
  Markdown-backed posts to files, and WordPress reads from SQLite.
- **`primary`**: MDI persists reconstructable WordPress state to Markdown,
  JSON, and plugin-schema SQL. SQLite is a runtime index and query engine,
  rebuilt on cold boot and incrementally synchronized on warm boot. The default
  index path is `wp-content/markdown-index.sqlite`.

Primary mode trades cold-boot work for reconstructable persisted state. To
reconstruct the complete configured state, retain both the content tree and the
state tree when they are split.

### Native MySQL/MariaDB Backends

MDI also has two explicit native-database backends:

- `mysql-content` runs as a normal plugin without `db.php`. It makes the post
  types listed in `MARKDOWN_DB_MANAGED_POST_TYPES` content-primary, including
  post rows, meta, terms, hierarchy, reconciliation, explicit flush receipts,
  and reconstruction of those managed posts.
- `mysql-full` uses the MDI `db.php` boundary to preserve stock `wpdb` behavior
  while capturing supported DML and DDL into a transactional InnoDB outbox.
  Successful mutations are planned immediately into stable semantic envelopes,
  committed through the outbox, and published to
  the existing Markdown, JSON, and schema layouts. Failed publication remains
  in the outbox for retry, and `wp_markdown_mysql_full_flush()` provides an
  explicit durability boundary with changed-path receipts.

Configure one backend in `wp-config.php`:

```php
define( 'MARKDOWN_DB_BACKEND', 'mysql-content' );
define( 'MARKDOWN_DB_MANAGED_POST_TYPES', 'post,page,wiki' );

// Or, with the MDI db.php installed:
define( 'MARKDOWN_DB_BACKEND', 'mysql-full' );
```

For multisite `mysql-full`, site-local canonical roots default to
`sites/{blog_id}` below the configured content and state roots. Network-global
tables remain in the base state root. `markdown_db_mysql_full_roots` can supply
deployment-owned roots for each captured scope. Changed-path receipts qualify
site-local paths with `sites/{blog_id}/` so equal paths from different sites do
not collide.

`mysql-full` currently publishes canonical state but does not yet reconstruct
an empty MySQL/MariaDB database from those files. Direct `mysqli` writes,
separate connections, server-side writers, multi-statements, stored-routine
internal statements, and XA transactions remain outside the captured `wpdb`
boundary and are reported by diagnostics.

### Query Compatibility Corpus

The `mdi-native` query-runtime program uses a versioned, backend-neutral corpus
to preserve caller-visible `wpdb` behavior without making MySQL part of the
future engine. `WP_Markdown_Query_Compatibility_Recorder` wraps one query at a
time, returns the backend result unchanged, and records ordered rows, column
metadata, errors, insert IDs, affected-row counts, and transaction transitions.
Callers provide explicit replacements for site URLs, paths, credentials, and
scenario-specific content; UUIDs and SQL timestamps are normalized by default.

The committed standalone contract runs with:

```bash
php tests/smoke-query-compatibility-corpus.php
```

To emit reviewer-resolvable evidence from a disposable native MariaDB WordPress
runtime, run the following command and retain its JSON output:

```bash
wp eval-file wp-content/plugins/markdown-database-integration/tests/probe-native-mariadb-query-corpus.php
```

The native probe uses a dedicated database connection, creates one uniquely
named InnoDB table, asserts and records read, write, schema, failure, rollback,
and transaction behavior, and removes the table in `finally`.
Recording is tooling-only and has no effect unless a caller explicitly invokes
the recorder.

### Native Shadow Verification

An existing SQLite or `mysql-full` runtime can replay authoritative WordPress
reads through `mdi-native` without changing query results. Enable this only in a
disposable verification runtime:

```php
define( 'MARKDOWN_DB_NATIVE_SHADOW', true );
define( 'MARKDOWN_DB_NATIVE_SHADOW_MAX', 1000 ); // Optional bounded query count.
```

After WordPress boot, emit the report with:

```bash
wp eval-file wp-content/plugins/markdown-database-integration/tests/probe-native-shadow-report.php
```

The report counts compatible, unsupported, mismatched, ignored, verifier-failed,
and dropped observations. Its first blocker contains only a SHA-256 query identity,
a literal-free query template, structured diagnostic codes, and structural
mismatch paths. SQL literal values and result rows are not retained. The
authoritative backend remains the sole source of caller-visible behavior, and
shadow failures do not fail the query. The SHA-256 identity covers the sanitized
template, not the literal-bearing source query.

### Native Cutover Boundary

SQLite remains MDI's supported default runtime while `mdi-native` expands behind
explicit configuration and shadow verification. Native becomes eligible to
replace SQLite only after generic SQL and `wpdb` contracts pass installation,
activation, schema migration, CRUD, transaction, cron, REST, WP-CLI, admin, and
front-end workflows across representative arbitrary WordPress plugins.

Data Machine and WooCommerce are compatibility evidence, not implementation
targets. Compatibility belongs in reusable grammar, typed AST, planning, schema,
storage, transaction, and result-state primitives. Plugin names, plugin table
names, and plugin-specific query branches are outside the native engine contract.

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

### Storage-Only Primary Runtime

Constrained callers can bootstrap MDI's primary loader, driver, and write engine
around a caller-owned disposable SQLite cache. The cache is a query index only:
canonical Markdown and JSON remain the durable state. `flush()` is explicit and
returns sorted paths relative to the canonical content or state root, grouped as
`created`, `changed`, and `deleted`.

```php
$runtime = WP_Markdown_Primary_Storage_Runtime::bootstrap(
    array(
    'content_root' => '/srv/site/content',
    'state_root'   => '/srv/site/state',
    ),
    $sqlite_connection,
    'wordpress',
    null,
    true, // Cold cache: hydrate it from canonical Markdown/JSON.
);

// Use normal WordPress/MDI SQL mutations. The existing driver tracks them.
$driver = $runtime->get_driver();
$driver->query( "UPDATE `wp_posts` SET post_title = 'Updated' WHERE ID = 12" );
$driver->query( "UPDATE `wp_options` SET option_value = 'https://example.test' WHERE option_name = 'siteurl'" );

$changes = $runtime->flush(); // Does not require process shutdown.
$identity = $runtime->get_identity(); // Persist this beside the disposable cache.
```

For a warm cache, pass its prior `$identity` and `false` as the fourth and fifth
arguments; an identity is required and MDI verifies it before synchronizing the
cache. Deleting the SQLite cache and bootstrapping with `null, true`
reconstructs it solely from the canonical files. The runtime delegates path
moves, Markdown serialization, option filenames, ephemeral-option filtering,
and writes to MDI's existing
storage, loader, driver, and write engine. Cloudflare, R2, Durable Objects, and
WP Codebox remain outside MDI.

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
- `markdown-db/reconcile`

### Reconciliation

Plan first. Dry-run does not acquire resource ownership or mutate files or the
database:

```bash
wp markdown-db reconcile --dry-run \
  --managed-scope=post,page \
  --direction=bidirectional \
  --deletion-policy=none \
  --conflict-policy=none \
  --batch-size=100 \
  --format=json
```

Apply the exact reviewed snapshot by passing the returned identities:

```bash
wp markdown-db reconcile \
  --managed-scope=post,page \
  --direction=bidirectional \
  --deletion-policy=none \
  --conflict-policy=none \
  --batch-size=100 \
  --plan-id=<plan_id> \
  --source-identity=<source_identity> \
  --format=json
```

For another dry-run or apply page, pass the returned continuation object as JSON
with `--continuation='<json>'` while retaining the same reviewed plan and source
identities. Each continuation authenticates the still-unprocessed suffix, so
mutations completed by earlier pages do not invalidate later pages. Any change
to an unprocessed resource makes continuation fail closed. Set
`--deletion-policy=managed` only when deletion propagation is intended. The
same keys use underscores in the ability/PHP input (`dry_run`,
`canonical_root`, `managed_scope`, `deletion_policy`, `conflict_policy`,
`batch_size`, `plan_id`, and `source_identity`).

External PHP callers use the same facade as the public transports:

```php
$plan = WP_Markdown_CLI::reconcile(
    array(
        'dry_run'         => true,
        'canonical_root'  => '/srv/site/content',
        'managed_scope'   => array( 'post', 'page' ),
        'direction'       => 'bidirectional',
        'deletion_policy' => 'none',
        'conflict_policy' => 'none',
        'batch_size'      => 100,
    )
);
```

Backend integrations can instead construct `WP_Markdown_Reconciliation_Service`
with a `WP_Markdown_Reconciliation_Content_Adapter`. The adapter enumerates
normalized resource snapshots and returns the #190 owning adapter for each
operation; it must not create another journal.

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

- **Primary reconstruction** → cold boot creates core tables and reloads
  Markdown content, frontmatter meta and terms, JSON-backed core state, plugin
  schemas, and plugin table rows; warm boot synchronizes changed files
- **Creating posts** via WP-CLI, REST API, or the admin → `.md` file created
- **Updating posts** → `.md` file updated (title, content, metadata)
- **Deleting posts** → `.md` file removed
- **Gutenberg blocks** → stored exactly as `post_content` unless another layer converts them first
- **Pages** → stored in `page/` subdirectory
- **Custom post types** → each type gets its own subdirectory
- **WordPress admin** → works normally, no changes visible
- **JSON state** → options, users, taxonomy, comments, links, non-Markdown
  posts, and persisted plugin tables are reloaded from state files
- **Plugin schemas** → non-core table DDL is snapshotted and loaded before its
  JSON table rows
- **Round-trip** → file body and `post_content` stay byte-identical for storage-managed post types

## License

GPL v2 or later.
