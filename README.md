# Markdown Database Integration

MDI is a native, pure-PHP SQL engine for WordPress backed by canonical files:
Markdown for content, JSON for WordPress and plugin-table rows, and SQL for
plugin schemas. Its native `wpdb` implementation serves those files directly.
No SQLite extension, SQLite Database Integration install, or MySQL server is
required for the native runtime.

## Backend selection

`wp-content/db.php` always selects **`mdi-native`** by default. An explicit
`MARKDOWN_DB_BACKEND` constant or environment variable may select only
`mdi-native`. Any other identifier fails closed with
`markdown_db_unknown_backend`; there is no fallback engine.

Leftover SQLite databases and managed `db-path.php` files are never discovered,
loaded, migrated, or modified. SQLite mirror/primary runtimes, recovery commands,
shadow verification, and native-vs-SQLite differential harnesses are removed.

## Upgrading from SQLite

Back up the complete installation, canonical content and state roots, and any
SQLite database **before upgrading**. Keep the old version available for rollback.

- **Former primary installations:** native boots directly from the existing
  canonical Markdown, JSON, and schema files. Preserve both roots when content
  and state are split. Install the new bundled `db.php`; remove an explicit
  `MARKDOWN_DB_BACKEND=sqlite` setting or change it to `mdi-native`.
- **Former mirror installations:** SQLite was authoritative. On the **old
  version**, export the authoritative database to a complete canonical store
  and verify that all required options, users, taxonomy, plugin tables, and
  schemas are present, not just post Markdown. Finish this before installing the
  native-only release. Do not boot native against an incomplete mirror tree.
  If necessary, restore the old version and backup to complete export.
- Remove retired `MARKDOWN_DB_MODE`, `MARKDOWN_DB_INDEX_PATH`, and
  `MARKDOWN_DB_NATIVE_SHADOW*` configuration. Remove obsolete SQLite discovery
  settings from your deployment configuration; MDI no longer reads them.

There is no automatic SQLite conversion or safety-net fallback. Leftover
`.ht.sqlite`, `.ht.sqlite.php`, and `markdown-index.sqlite` files may remain for
manual backup/rollback but do not affect selection. Validate native site and
WP-CLI workflows against the backup before deleting old files.

## Requirements and installation

- WordPress 6.9+
- PHP 8.2+
- Composer

```bash
git clone https://github.com/Automattic/markdown-database-integration.git \
  wp-content/plugins/markdown-database-integration
cd wp-content/plugins/markdown-database-integration
composer install --no-dev
wp plugin activate markdown-database-integration
wp markdown-db doctor
wp markdown-db doctor --repair
```

An existing unrelated `wp-content/db.php` is not overwritten without
`--repair --force`; that operation preserves a deterministic
`db.php.markdown-db-backup`. The bundled `@studio-keep` marker protects the MDI
drop-in from Studio refreshes. Restart PHP/WordPress after repair: the drop-in
loads before plugins, including when WP-CLI skips this plugin.

For import/export on an ordinary MySQL/MariaDB WordPress installation, activate
only the plugin, without installing MDI's drop-in. MySQL/MariaDB is an external
import/export target and compatibility oracle, not an MDI backend.

## Configuration

```php
// Defaults to wp-content/db; existing wp-content/markdown is used if db is absent.
define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/db' );
// Optional separate root for non-post state; defaults to the content root.
define( 'MARKDOWN_DB_STATE_DIR', WP_CONTENT_DIR . '/markdown-state' );
// Optional; native is the only supported identifier and is already the default.
define( 'MARKDOWN_DB_BACKEND', 'mdi-native' );
define( 'MARKDOWN_DB_EPHEMERAL_TABLES', 'my_session_table' );
define( 'MARKDOWN_DB_EXCLUDED_TYPES', 'attachment,nav_menu_item' );
```

The default excluded post types are revision, auto-draft, nav_menu_item,
customize_changeset, oembed_cache, wp_navigation, wp_global_styles, wp_template,
and wp_template_part. Non-Markdown post types use canonical JSON fallback.

## Canonical storage

```text
content/
  post/*.md
  page/*.md
  custom-post-type/*.md
state/
  _options/*.json
  _tables/*.json
  _schema/*.sql
```

With a single root these directories coexist. Backups must preserve both roots
when split. Markdown frontmatter carries post fields, metadata, and terms;
post bodies preserve `post_content` bytes as received. Options have individual
files; users, taxonomy, comments, links, non-Markdown posts, and plugin-table
rows use JSON snapshots or configured row partitions. Plugin schemas use SQL.

MDI does not render Markdown, convert editor blocks during live writes, or
register content-rendering policy. Applications own content format. Conversion
is confined to explicit import/export.

### Table durability

Tables are canonical by default. `MARKDOWN_DB_TABLE_DURABILITY_POLICY` and
`markdown_db_table_durability_policy` classify unprefixed table names as
`canonical`, `reconstructible`, or `ephemeral`. Canonical tables persist complete
rows and schemas. Reconstructible tables may retain bounded projections;
ephemeral tables are excluded from canonical persistence and native registration.

```php
define( 'MARKDOWN_DB_TABLE_DURABILITY_POLICY', array(
    'runtime_events' => array(
        'durability' => 'reconstructible',
        'projection' => array( 'limit' => 100 ),
    ),
    'runtime_locks' => 'ephemeral',
) );
```

`markdown_db_ephemeral_tables`, `markdown_db_table_persistence_policy`,
`markdown_db_persistent_table_query`, and `markdown_db_persistent_table_rows`
remain shared policy inputs. Row partitions support bounded identity-scoped
updates and complete-generation publication for unscoped changes.

## Native architecture

Native parser, planner, executor, and `wpdb` adapter operate on canonical state.
Canonical persistence and storage remain separate from query execution. Native
SQL reads and writes acquire cross-process canonical-root admission; explicit
transactions retain it through commit/rollback. This is coarse serialization,
not MVCC. Lock waits are bounded at five seconds. Direct filesystem writers
outside the protocol and independent same-process connection isolation are not
covered. Runtime instances sharing a root in one process share a transaction owner.

Canonical persistence registers its PHP shutdown callback only after a tracked
mutation. Read-only commands have a zero-work flush; dirty requests publish their
bounded dirty subset atomically. The
`markdown_database_integration_persistence_diagnostics` action attributes flushes
to tables, post IDs, resource partitions, and paths.

### Reconciliation

Shared three-way reconciliation compares canonical and WordPress state against
the last synchronized identity. Durable operations retain journals, leases,
fencing, recovery, and receipts. Filesystem, native, PDO, and WPDB reconciliation
adapters remain available to their respective consumers; PDO/MySQL support here
is not an operational backend. CLI and ability surfaces support dry-run plans,
conflict/deletion policy, bounded batches, and resumable continuations.

Custom layout profiles register `enumerate`, `map_source`, and `path_for_post`
through `MARKDOWN_DB_CONTENT_LAYOUT_PROFILE_BOOTSTRAP`; select them using
`MARKDOWN_DB_CONTENT_LAYOUT_PROFILE` or `--layout-profile=<id>`.
Writes stage within verified destination directories and reject symlink path
segments. Protect roots with filesystem permissions: PHP cannot guarantee an
`openat(2)`-style defense against a concurrent directory replacement immediately
before rename.

## MySQL/MariaDB import and export

Use `wp markdown-db import` and `wp markdown-db export` (or their abilities) to
move content between the active WordPress database and a canonical store.
Use `--content-dir=<directory>` to select a root. Default conversion uses the
Blocks Engine PHP Transformer: Markdown to blocks on import, blocks to Markdown
on export. Pass `--no-convert` to preserve bytes or `--from=<format> --to=<format>`
for explicit formats. `markdown_db_content_format_conversion` controls policy.
Consult `wp help markdown-db` for command options and reconciliation operations.

## Query compatibility corpus

The backend-neutral recorder retains ordered rows, column metadata, errors,
insert IDs, affected counts, and transaction transitions without altering query
results. Callers must supply replacements for sensitive URLs, paths, credentials,
and content; UUIDs and SQL timestamps normalize by default.

```bash
php tests/smoke-query-compatibility-corpus.php
# On a disposable MariaDB WordPress reference installation:
wp eval-file wp-content/plugins/markdown-database-integration/tests/probe-native-mariadb-query-corpus.php
```

MariaDB remains the native compatibility oracle. Its probe uses a dedicated
connection, a uniquely named InnoDB table, and cleanup in `finally`.
`artifacts/native-mysql-coverage.json` records current coverage; regenerate with
`php tests/run-native-mysql-coverage.php` using WP Codebox 0.26.3 or newer.

## Native goals and current evidence

Full MySQL-visible SQL and `wpdb` parity for arbitrary plugins comes first;
performance and scalability come second. Representative plugin corpora are
samples, not hardcoded engine scope. Native-only shipping does not establish
complete parity or a performance advantage. Real end-to-end WP-CLI measurements
must include process/bootstrap/query/shutdown, cold/warm behavior, growth,
memory, concurrency, and tail latency. See the [benchmark guide](tests/bench/README.md)
and [MariaDB REPLACE evidence](tests/REPLACE_PARITY.md).

## Development

```bash
composer install --no-interaction --prefer-dist
find inc tests -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/smoke-*.php; do php "$test" || exit; done
homeboy review lint markdown-database-integration --path .
```

Drop-in selection tests create nonempty read-only legacy databases and a managed
path file to prove native boots without loading or mutating them. Shared
canonical tests use semantic operations rather than a retired SQL adapter.

## License

GPL-2.0-or-later.
