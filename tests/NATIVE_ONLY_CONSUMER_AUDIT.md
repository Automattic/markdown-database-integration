# Native-only consumer audit (#451)

Part of #449. This is a deletion boundary, not a claim of full native parity.

## Removed consumers and owners

- `db.php`: SQLite integration discovery, database/header/path discovery,
  managed path-file loading, primary/mirror branching, install fallback,
  primary-index evidence, and shadow bootstrap. Only native runtime creation
  remains; named alternatives fail with the existing unknown-backend diagnostic.
- `inc/sqlite/`: operations, runtime adapter, and recovery/ability hooks.
- Retired SQLite facades: DB, driver, backend adapter, loader, write engine,
  primary storage runtime/index health, search, mutation impact, and query
  observer boundary. Repository consumers were SQLite bootstrap, loader,
  recovery, or shadow paths; shared persistence is not owned by those facades.
- Native shadow verifier, authoritative SQL snapshot runtime, trace producers,
  report probe, native-vs-SQLite fuzz/bench definitions, comparison rigs, and
  associated tests. Native temporary-table shadowing is unrelated and retained.
- SQLite-only bootstrap, driver compatibility, cold/warm loader/index,
  synchronization, recovery, and search-adapter tests. The legacy SDI/mirror/
  primary bench matrix, direct loader boot runner, and index audit were removed;
  native rig references were updated before removing their targets.

## Retained and migrated consumers

- Native parser/planner/executor, runtime factory, schema and mutation engine,
  `wpdb`, and native query/lifecycle/transaction/concurrency smoke suites.
- Canonical persistence, storage, YAML, layout/frontmatter profiles, option
  policy, durability, and path helpers. Atomic writes, bounded table policy,
  partition receipts/migration/deletion, temp reclamation, constrained-memory
  shutdown streaming, and ephemeral-option tests now use semantic operations
  rather than a SQLite driver. Native provider tests retain hydration coverage.
- Shared reconciliation journals, coordinator, filesystem/PDO/WPDB adapters,
  WordPress reconciliation, CLI, and abilities. Protocol fixtures replace
  SQLite fixtures in shared tests; live MariaDB probes remain the real-engine
  oracle. MySQL import/export-only installations retain fence-table setup.
- MariaDB reference corpus, recorder, coverage runner and committed reports.
- API-only benchmark workloads and the native rig. Native runtime identity is
  the helper default. No benchmark requires a retired query adapter.

## Regression and residual references

`smoke-dropin-backend-selection.php` runs subprocesses through a copied drop-in
and the real plugin runtime. Nonempty read-only `.ht.sqlite`, `.ht.sqlite.php`,
and `markdown-index.sqlite` files retain hash, size and mtime; a throwing managed
`db-path.php` never executes. No retired path/mode constants appear. Explicit
retired/unknown backends fail without publishing a `wpdb` fallback. Missing
plugin and stock `wpdb` prerequisites also fail rather than selecting SQLite.

Remaining SQLite mentions are upgrade/changelog history, negative regression
fixtures, native SQL compatibility assertions, environment capability reporting,
and documentation of a third-party WP-CLI partial-bootstrap call path. None
loads a SQLite runtime. Remaining native `shadow()` methods implement SQL
TEMPORARY table name shadowing, not differential execution.

## Verification

- Composer install: passed.
- PHP syntax checks over tracked PHP files: passed.
- All 132 `tests/smoke-*.php` scripts: passed.
- `git diff --check HEAD`: passed.
- `homeboy review lint markdown-database-integration --path .`: failed with
  10,280 PHPCS errors, 6,341 warnings, and 354 PHPStan findings. The untouched
  HEAD also fails: 11,058 PHPCS errors, 7,010 warnings, and 568 PHPStan findings.
  These repository-wide findings were not hidden or auto-fixed across unrelated
  engine code. Lower totals do not imply every changed line is lint-clean.
