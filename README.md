# Markdown Database Integration

MDI is a native, pure-PHP SQL engine for WordPress backed by canonical files:
Markdown for content, JSON for WordPress and plugin-table rows, and SQL for
plugin schemas. Its native `wpdb` implementation serves those files directly;
no SQLite extension, SQLite Database Integration install, or MySQL server is
required for the default runtime.

The priorities are **full MySQL-visible SQL and `wpdb` parity for arbitrary
WordPress plugins and workloads first**, then **performance and scalability
beyond SQLite and MySQL**, with real WP-CLI workflows as the primary measurement
path. Both are goals still under development. See [current evidence](#native-goals-and-current-evidence)
and the [CLI-first benchmark requirements](tests/bench/README.md#priorities-and-cli-acceptance).

## What This Does

MDI selects its backend in `wp-content/db.php` before plugins load:

- An explicit `MARKDOWN_DB_BACKEND` configuration always wins.
- Without one, an existing SQLite database (`FQDB`, when it names a file, or
  `wp-content/database/.ht.sqlite`) keeps the SQLite backend so an upgrade does
  not change an operating site's query engine.
- Otherwise, MDI selects **`mdi-native`**, where canonical Markdown and JSON
  files are the database.

`sqlite`, `mysql-content`, and `mysql-full` remain supported explicit
operational backends. Their constraints and setup are documented below; they
are not the default architecture.

## SQLite Integration API (Explicit `sqlite` Backend)

SQLite Database Integration is optional. It is required only by the `sqlite`
backend; the default `mdi-native` backend serves WordPress directly from the
canonical Markdown and JSON files and never loads it.

When the `sqlite` backend is selected, MDI requires a SQLite Database
Integration release that includes the canonical
PDO-compatible `WP_MySQL_On_SQLite` API, introduced by
WordPress/sqlite-database-integration#449. MDI no longer uses the deprecated
`WP_SQLite_Driver` compatibility layer. The runtime constructs the canonical
driver with its `mysql-on-sqlite:` DSN and consumes query results as
`PDOStatement` objects.

## Canonical Storage

The native runtime and SQLite `primary` mode share canonical storage layouts.
A typical single-root store looks like this:

```
wp-content/db/
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

### SQLite Primary-Mode Persistence

Primary mode persists the following state so SQLite can be reconstructed:

- Post rows and content in `post/*.md`, `page/*.md`, and custom-post-type
  directories; their post meta and terms are in frontmatter.
- Options as individual `_options/*.json` files.
- Users and usermeta, taxonomy tables, comments and commentmeta, links, and
  non-Markdown posts as `_tables/*.json` snapshots.
- Arbitrary plugin-table rows as `_tables/*.json` snapshots and their schemas
  as `_schema/*.sql` files.

The `MARKDOWN_DB_TABLE_DURABILITY_POLICY` `wp-config.php` array and
`markdown_db_table_durability_policy` filter classify each table as
`canonical`, `reconstructible`, or `ephemeral`. Canonical tables persist their
schema and complete rows. Reconstructible tables may persist a bounded
`projection` (`query`, `limit`, or partition settings), while their owner may
recreate the runtime table when canonical state is absent. Ephemeral tables are
excluded from mutation capture, schema and row snapshots, native registration,
and cold hydration. Tables remain canonical by default.

The constant makes the policy available during drop-in cold reconstruction:

```php
define( 'MARKDOWN_DB_TABLE_DURABILITY_POLICY', array(
	'runtime_events' => array( 'durability' => 'reconstructible', 'projection' => array( 'limit' => 100 ) ),
	'runtime_locks'  => 'ephemeral',
) );
```

The filter receives that normalized policy, unprefixed table name, and full
table name, and may override it after hooks are available. For example:

```php
add_filter( 'markdown_db_table_durability_policy', function ( $policy, $suffix ) {
	if ( 'runtime_events' === $suffix ) {
		return array( 'durability' => 'reconstructible', 'projection' => array( 'limit' => 100 ) );
	}
	if ( 'runtime_locks' === $suffix ) {
		return array( 'durability' => 'ephemeral' );
	}
	return $policy;
}, 10, 2 );
```

`MARKDOWN_DB_EPHEMERAL_TABLES`, `markdown_db_ephemeral_tables`,
`markdown_db_table_persistence_policy`, `markdown_db_persistent_table_query`,
and `markdown_db_persistent_table_rows` remain compatibility inputs to this
resolver. Sites can migrate table classifications to the unified filter while
retaining the query and row filters for existing projections.

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
and `markdown-db export` commands and abilities use the configured Blocks Engine
PHP Transformer conversion path to round-trip between Markdown files and
serialized block content by default:

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
SQLITE BACKEND WRITE (native writes canonical files directly):

  WordPress caller writes post_content
       │
       ▼
  SQLite stores post_content bytes
       │
       ▼  MDI write engine
  .md file stores the same bytes


SQLITE PRIMARY READ (native queries canonical files directly):

  .md file body
       │
       ▼  loaded AS-IS into in-memory SQLite
  post_content has the same bytes


IMPORT / EXPORT:

  .md file body or post_content
       │
       ▼  explicit import/export conversion, unless disabled
  target file body or post_content


RENDER / EDITOR / API:

  Handled by the application/content-format layer, not MDI.
```

### Dependencies

MDI requires its Blocks Engine PHP Transformer dependency for default
import/export conversion. The drop-in and live write engine remain byte-preserving;
the transformer is not used by
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

## Native Architecture

```
WordPress Core ($wpdb)
        │
        v
WP_Markdown_Native_WPDB
        │
        v
Native PHP SQL parser, planner, and executor
        │
        v
Canonical files
        │
        ├── MARKDOWN_DB_CONTENT_DIR
        │     post/*.md, page/*.md, {type}/*.md
        │
        └── MARKDOWN_DB_STATE_DIR
               _options/*.json, _tables/*.json, _schema/*.sql
```

The native runtime reads and writes the canonical store directly. SQLite's
rebuildable index is specific to the explicit `sqlite` backend and its
`primary` mode.

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
- PHP 8.1+
- Composer

The default `mdi-native` runtime uses the bundled `db.php` drop-in and the
canonical filesystem store. MySQL/MariaDB is required only for the explicit
MySQL backends or for import/export against a normal MySQL/MariaDB WordPress
installation. SQLite Database Integration is required only for the explicit
`sqlite` backend.

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

# Install the MDI db.php drop-in for the native default runtime, or for an
# explicit sqlite or mysql-full backend.
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
SQLite `primary` install can report `install_fallback` while WordPress
completes its first installation.

This order also applies to WP-CLI: an installed MDI `db.php` runtime is active
before normal plugin activation and is not removed by
`--skip-plugins=markdown-database-integration`. Canonical persistence registers
its own native PHP shutdown callback only after a tracked mutation. Read-only
commands therefore have a clean, zero-work flush; dirty requests publish their
bounded dirty subset atomically before exit. Subscribe to
`markdown_database_integration_persistence_diagnostics` to attribute a flush
to its tables, post IDs, partition resources, and canonical paths.

On a normal MySQL/MariaDB WordPress site, activate the plugin without copying
the `db.php` drop-in when using only import/export or `mysql-content`. Install
the drop-in for `mysql-full`. Use the import/export commands or abilities to
move content between the active database and `MARKDOWN_DB_CONTENT_DIR`.

## Configuration

Add to `wp-config.php`:

```php
// Where Markdown-backed posts and post-type hierarchy are stored.
// Default: wp-content/db/. An existing wp-content/markdown/ store is still used
// when wp-content/db/ is absent.
define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/db' );

// Or customize the storage root for a plugin or repo-backed app:
define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/plugins/my-world/content' );

// Optional local root for non-post runtime state. When omitted, this defaults
// to MARKDOWN_DB_CONTENT_DIR and preserves the existing single-root layout.
define( 'MARKDOWN_DB_STATE_DIR', WP_CONTENT_DIR . '/markdown-state' );

// Post types to exclude from Markdown storage (comma-separated). The default
// excludes revision, auto-draft, nav_menu_item, customize_changeset,
// oembed_cache, wp_navigation, wp_global_styles, wp_template, and
// wp_template_part.
define( 'MARKDOWN_DB_EXCLUDED_TYPES', 'attachment,nav_menu_item' );

// Tables to exclude from file persistence (comma-separated table suffixes).
// No tables are excluded by default.
define( 'MARKDOWN_DB_EPHEMERAL_TABLES', 'my_session_table' );

// Select an operational backend only when overriding automatic selection.
// Unconfigured installs use mdi-native unless an existing SQLite database is
// detected. Valid values: mdi-native, sqlite, mysql-content, mysql-full.
define( 'MARKDOWN_DB_BACKEND', 'mdi-native' );
```

### SQLite Modes (Explicit `sqlite` Backend)

- **`mirror`** (the SQLite-mode default): SQLite on disk is authoritative. MDI mirrors
  Markdown-backed posts to files, and WordPress reads from SQLite.
- **`primary`**: MDI persists reconstructable WordPress state to Markdown,
  JSON, and plugin-schema SQL. SQLite is a runtime index and query engine,
  rebuilt on cold boot and incrementally synchronized on warm boot. The default
  index path is `wp-content/markdown-index.sqlite`.

SQLite primary mode trades cold-boot work for reconstructable persisted state. To
reconstruct the complete configured state, retain both the content tree and the
state tree when they are split.

### MySQL/MariaDB Operational Backends

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

Native SQL reads and writes acquire canonical-root admission across processes;
explicit transactions retain it through commit or rollback. Independent SQL
readers cannot observe uncommitted canonical changes, and subsequent admission
refreshes stale snapshots. This is bounded coarse serialization, not MVCC.
Five-second lock waits can return contention failures, and direct filesystem
writers bypassing the protocol are outside this contract. Runtime instances
for the same root in one PHP process share one logical transaction owner;
independent same-process connection isolation is not supported.

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

The committed `artifacts/native-mysql-coverage.json` report records the
WordPress and raw MySQL surfaces currently answered by `mdi-native`. Regenerate
it with `php tests/run-native-mysql-coverage.php` using WP Codebox 0.26.3 or
newer.

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

### Native Goals and Current Evidence

Status reviewed September 12, 2026. `mdi-native` is the default for installations
without an existing SQLite database; removing shipped SQLite support remains
subject to explicit acceptance gates. Its first goal is
full MySQL-visible SQL and `wpdb` parity for arbitrary WordPress plugins and
workloads through generic engine primitives. Representative corpora, including
Data Machine and WooCommerce, are evidence rather than product scope; plugin
names, table names, and plugin-specific query branches do not belong in the
engine.

The second goal, after parity, is to be faster and more scalable than both
SQLite and MySQL. That requires real end-to-end WP-CLI measurements of cold
process/bootstrap/query/persistence shutdown, warm filesystem versus warm
process behavior, growth, memory, concurrency, and tail latency. Profiling can
guide parity work, but compatibility is not traded for benchmark results.

Neither goal is achieved by this documentation or the currently cited tests.
The historical benchmark at `21267e1` is not current evidence and does not
establish a performance gate.

Current merged evidence includes:

- [#384](https://github.com/Automattic/markdown-database-integration/pull/384):
  45 SQL and 26 WordPress operations, plus WooCommerce lifecycle, multisite,
  and `dbDelta` coverage.
- [#399](https://github.com/Automattic/markdown-database-integration/pull/399)
  and [#400](https://github.com/Automattic/markdown-database-integration/pull/400):
  journaled post writes and multisite journals.
- [#403](https://github.com/Automattic/markdown-database-integration/pull/403):
  cross-process read/write admission and bounded write serialization. This is
  not MVCC: waits are bounded at five seconds, and direct filesystem writers
  remain outside the protocol.
- [#404](https://github.com/Automattic/markdown-database-integration/pull/404),
  [#405](https://github.com/Automattic/markdown-database-integration/pull/405),
  and [#406](https://github.com/Automattic/markdown-database-integration/pull/406):
  modification ordering, hierarchical canonical paths, and non-ASCII body
  `LIKE` matching. The latter is not full Unicode collation support.
- The paired DME 1053 run and companion Data Machine adapter recorded native
  `1040 passed, 4 failed, 9 skipped`; the MySQL control recorded
  `1046 passed, 0 failed, 7 skipped`. Four tests still require a physical
  `mysqli` connection, and two known table-`REPLACE` smoke failures remain.

The active native work trackers are [#232](https://github.com/Automattic/markdown-database-integration/issues/232)
and [#377](https://github.com/Automattic/markdown-database-integration/issues/377).
The former optimization draft #370 is merged. SQLite removal still requires
verified native-only install, site workflows, backup restoration, cold restart,
and a supported transition for existing SQLite-backed installations. Keep
development differential references separate from shipped runtime dependencies.

With only `MARKDOWN_DB_CONTENT_DIR` configured, SQLite `primary` mode keeps the existing
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

### Storage-Only SQLite Primary Runtime

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
wp markdown-db import --content-dir=/path/to/markdown
```

Export current posts, pages, and custom post types to markdown:

```bash
wp markdown-db export --dry-run
wp markdown-db export
wp markdown-db export --content-dir=/path/to/markdown
```

Both commands default to `MARKDOWN_DB_CONTENT_DIR`. Pass
`--content-dir=/path/to/markdown` to read from or write to a different root.
`--path` remains the WP-CLI global option for selecting the WordPress installation.
Export accepts `--post-type=post,page,wiki` to limit the post types.

### WP-CLI Option Migration

`--path` was previously documented as the Markdown content-root option for
`markdown-db import` and `markdown-db export`. It is now reserved exclusively
for WP-CLI's WordPress-installation selector. Replace the old invocations:

```bash
wp markdown-db import --path=/path/to/markdown
wp markdown-db export --path=/path/to/markdown
```

with:

```bash
wp markdown-db import --content-dir=/path/to/markdown
wp markdown-db export --content-dir=/path/to/markdown
```

Select a WordPress installation and a Markdown content root independently:

```bash
wp --path=/path/to/wordpress markdown-db import --content-dir=/path/to/markdown --dry-run
wp --path=/path/to/wordpress markdown-db export --content-dir=/path/to/markdown --dry-run
```

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
meta, and frontmatter terms. By default, import converts Markdown to blocks and
export converts blocks to Markdown through the explicit conversion boundary;
pass `--no-convert` to preserve raw body bytes.

### Import/export content transforms

MDI stays storage-only in its runtime paths, but import/export exposes filter
seams before its explicit default conversion. Filters can customize how file
bodies map to WordPress `post_content` and back; `--no-convert` disables the
default conversion and preserves raw body bytes.

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

## SQLite Primary Operational Coverage

The following SQLite `primary`-mode behavior is separately covered on
WordPress 6.9 with local and Playground-style runtimes:

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
## Native SQL Mode Defaults

The native WordPress connection starts with an empty SQL mode, matching its
existing non-strict `SHOW VARIABLES` contract. Generic table inserts use MySQL
implicit defaults for supported numeric, string and temporal columns when an
explicit default is absent. Nullable columns remain NULL; explicit defaults
take precedence. Missing implicit defaults produce bounded `SHOW WARNINGS`
records and an exact `@@warning_count`.

`SET [SESSION] sql_mode` and `SELECT @@[SESSION.]sql_mode` support the empty
mode, `STRICT_TRANS_TABLES`, `STRICT_ALL_TABLES` and `NO_ENGINE_SUBSTITUTION`.
Strict mode rejects an omitted required column with error 1364. Modes whose
additional semantics are not implemented are rejected without changing the
session. This is not a claim of complete MySQL coercion or SQL-mode coverage.
The mode is connection-local, survives blog switches and resets on logical
close; it is not persisted in canonical storage.
