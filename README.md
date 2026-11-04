# Markdown Database Integration

> **Experimental software:** MDI is for local experimentation, testing, and
> knowledge bases. It is not meant for production sites as a database replacement.

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

After a plugin upgrade, the plugin replaces an installed MDI drop-in that
differs from its bundled `db.php`; the new copy serves the next request. An
unrelated or absent `db.php` is left alone. If `wp-content` is not writable,
`wp markdown-db doctor` reports `dropin_outdated`.

For import/export on an ordinary MySQL/MariaDB WordPress installation, activate
only the plugin, without installing MDI's drop-in. MySQL/MariaDB is an external
import/export target and compatibility oracle, not an MDI backend.

## Securing the store

Canonical files can contain password hashes, private posts, and sensitive options.
Never allow anonymous HTTP access to the content or state root. On bootstrap,
MDI adds `.htaccess` (`Require all denied`) and `index.php` to existing roots inside
`ABSPATH`, without overwriting existing files or creating directories.
`wp markdown-db doctor --repair` recreates missing guards.

Servers that ignore `.htaccess`, including nginx, PHP's built-in server, and
Playground/Studio, need separate protection. For nginx's default store paths:

```nginx
location ~ ^/wp-content/(db|markdown)/ { deny all; }
```

Adapt the rule for custom paths and separate state roots. The index file does
not block direct file requests. `wp markdown-db doctor` checks an existing
canonical content file anonymously over HTTP: a 200 response reports
`store_publicly_readable`; 403/404 are blocked. Unreachable probes are
indeterminate, and roots outside `WP_CONTENT_DIR` or without files are skipped.
Neither result proves the store is secure, and separate state roots need checking too.

For servers without reliable deny rules, move both roots outside the document
root and set `MARKDOWN_DB_CONTENT_DIR` / `MARKDOWN_DB_STATE_DIR` in `wp-config.php`
to their absolute paths. MDI does not write guard files outside `ABSPATH`.

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
rows use JSON snapshots. Plugin schemas use SQL.

MDI does not render Markdown, convert editor blocks during live writes, or
register content-rendering policy. Applications own content format. Conversion
is confined to explicit import/export.

### Table durability

Tables persist to canonical state by default. Mark a table `ephemeral` to keep
it out of canonical state and native table registration, using
`MARKDOWN_DB_TABLE_DURABILITY_POLICY`, `MARKDOWN_DB_EPHEMERAL_TABLES`,
`markdown_db_ephemeral_tables`, or `markdown_db_table_durability_policy`
(unprefixed table names; durability is `canonical` or `ephemeral`).

```php
define( 'MARKDOWN_DB_TABLE_DURABILITY_POLICY', array(
    'runtime_locks' => 'ephemeral',
) );
```

Row-partitioned tables written by earlier MDI releases
(`_tables/<table>/.mdi-partition.json`) fold into `_tables/<table>.json` on the
first bootstrap that finds them. Columns added since the rows were written take
their declared default. A partition that does not fit its schema stays in place,
the table is not registered, and the reason goes to the PHP error log.

## Native architecture

Native parser, planner, executor, and `wpdb` adapter operate on canonical state.
Storage remains separate from query execution. Native
SQL reads and writes acquire cross-process canonical-root admission; explicit
transactions retain it through commit/rollback. This is coarse serialization,
not MVCC. Lock waits are bounded at five seconds. Direct filesystem writers
outside the protocol and independent same-process connection isolation are not
covered. Runtime instances sharing a root in one process share a transaction owner.
Because explicit transactions commit and roll back, persisted tables report
`ENGINE` `InnoDB` in `information_schema.TABLES`, and `ALTER TABLE … ENGINE=InnoDB`
is a no-op. Other engines are rejected.

### Known limits

- No physical MySQL connection: code that needs a real `mysqli` handle is not supported.
- Title lookups support ASCII case-insensitive, trailing-space-padded `=` and
  `IN`; non-ASCII values fail closed (no full Unicode collation). An uncached
  title lookup fails after 1,024 canonical source files.
- Correlated subqueries are bounded to 10,000 distinct outer bindings per
  statement. Correlated aggregate inputs and nested correlated subqueries fail
  closed with `unsupported_subquery_correlation`.
- UNION branches need equal projection counts and compatible column types.
  Global ORDER BY accepts first-branch output names or positive ordinals.

### Import results

CLI imports and the `markdown-db/import` ability report `create_count`,
`update_count`, `unchanged_count`, and `skipped_count`, with corresponding
`created`, `updated`, `unchanged`, and `skipped` lists. Each `unchanged` entry
contains the stored post `id` and source `path`, in both dry-run and apply.
Existing posts whose final filtered post fields and managed source metadata
already match are unchanged: no post write, revision, or modified-date bump
occurs. Only dates supplied in the final post array are compared; dateless
sources leave stored dates alone. Dry-run reports changes without writing.

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

### Frontmatter profiles

Select a built-in or registered frontmatter profile with `--profile=<id>`.
`native` preserves WordPress fields; `okf` writes portable Open Knowledge Format
frontmatter. Custom profiles register with
`markdown_db_register_frontmatter_profile( $id, $profile )` and can provide
`import_post_data( array $frontmatter, string $body, array $context )`,
`export_frontmatter( object $post, array $context )`, and
`export_body( object $post, array $context )` callbacks.

The `frontmatter` key accepts `required` (the default) or `optional`. Optional
profiles receive an empty array and the Markdown body for files without YAML
frontmatter (or with an empty block). Required profiles report these files in
the import result's `skipped` list. Returning an empty array from
`export_frontmatter` omits the YAML block entirely; export adds one final LF.

The opt-in `markdown-h1-title` profile imports the first `# ` line as the title,
removes that line and one following blank line, and defaults status to `publish`.
Export writes `# {title}` followed by a blank line and the body, without YAML.
Combine it with a layout profile to derive type, slug, parent and stable source
identity from nested repo paths such as `plugins/foo/docs/user/page.md`. Identity
is retained in WordPress source metadata, not injected into Markdown. Files in
this H1/blank-line format with LF endings and a final newline round-trip
byte-identically; other heading spacing or newline conventions are normalized.

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
path file to prove native boots without loading or mutating them. Query
compatibility helpers used by tests and probes live in `tests/support/`.

## License

GPL-2.0-or-later.
