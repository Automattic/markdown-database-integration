# Markdown Database Integration

Markdown Database Integration (MDI) is a pure-PHP WordPress database runtime backed by canonical files. Markdown stores managed post content, JSON stores options and table snapshots, SQL files describe plugin schemas, and row partitions provide bounded access to large tables.

## Runtime

Install the plugin and copy its `db.php` to `wp-content/db.php`. The drop-in uses `mdi-native` by default and does not require a database extension or external database process.

```php
define( 'MARKDOWN_DB_STATE_DIR', WP_CONTENT_DIR . '/markdown-state' );
define( 'MARKDOWN_DB_CONTENT_DIR', WP_CONTENT_DIR . '/markdown' );
```

The native runtime currently executes the bounded SQL surface represented by its compatibility corpus. Unsupported SQL fails closed through structured `wpdb` diagnostics; it never falls back to another database.

Canonical storage:

```text
markdown/
  post/
  page/

markdown-state/
  _options/
  _schema/
  _tables/
```

## MySQL Backends

MDI also supports explicit MySQL integration modes for importing, exporting, and publishing canonical state:

```php
define( 'MARKDOWN_DB_BACKEND', 'mysql-content' );
```

`mysql-content` uses stock WordPress `wpdb` and does not use the MDI drop-in. `mysql-full` installs the MDI-owned `wpdb` boundary for durable mutation capture:

```php
define( 'MARKDOWN_DB_BACKEND', 'mysql-full' );
```

## Verification

Run all smoke tests:

```sh
for test in tests/smoke-*.php; do php "$test" || exit 1; done
```

The authoritative runtime gate boots stock WordPress in a disposable WP Codebox Playground with `mdi-native` as `db.php` and classifies every observed query.

## Development

The pure-PHP engine is tracked in [issue #232](https://github.com/Automattic/markdown-database-integration/issues/232). Compatibility expands from deterministic WordPress and plugin query evidence through the tokenizer, typed AST, planner, schema catalog, canonical providers, and executor.
