# REPLACE Reference Parity

Tracker: [#377](https://github.com/Automattic/markdown-database-integration/issues/377).
Verified September 12, 2026 against a disposable Docker MariaDB 11.4.12 service.

## Findings

The two failures previously recorded in `smoke-native-table-replace.php` were
stale non-strict expectations. With empty SQL mode, MariaDB accepts the omitted
required string column, supplies `''`, emits warning 1364, and leaves three rows
in the fixture. `STRICT_TRANS_TABLES` rejects the omission with error 1364 and
leaves the rows unchanged. Native matched both behaviors.

The differential exposed an actual native gap: session-variable SELECTs did not
accept column aliases. The repair shares the SQL tokenizer for alias parsing,
including doubled-backtick identifiers, rather than adding table-specific query
branches. Warning-count reads retain diagnostics; SQL-mode reads preserve their
existing reset behavior. Bare clause keywords and trailing SQL remain rejected.

## Verification

`mdi-377-replace-reference-docker-v9` completed through Homeboy on Lab:

- 30 statement observations matched stock WordPress `wpdb` against native `wpdb`:
  15 for empty SQL mode and 15 for `STRICT_TRANS_TABLES`.
- Zero differences in typed returns, rows, column metadata, errors, insert IDs,
  affected rows, warning counts, and warnings.
- Primary/unique conflicts, optional `INTO`, omitted defaults, rollback, and
  savepoint rewind were exercised.
- A fresh PHP request read persisted native and reference rows with zero differences.
- The report recorded `passed: true`, `mismatch_count: 0`, and empty cleanup failures.
- The standalone native test verified canonical rows from a distinct OS process.
- Session/default checks (14), corrected REPLACE checks (10), and upsert checks
  (14) passed on Lab.

Playground reuses an OS PID. The recipe explicitly labels its persistence
boundary `fresh_php_request`; the separate native CLI subprocess supplies the
OS-process persistence check. Neither is described as a crash-kill experiment.

## Reproduce

Run the reference workload on a disposable Lab host with Docker and WP Codebox:

```sh
php tests/smoke-native-sql-mode-defaults.php
php tests/smoke-native-table-replace.php
php tests/smoke-native-table-upsert.php
wp-codebox recipe build template --options tests/recipes/mdi-377-replace-parity.options.json --output mdi-377-replace-parity.recipe.json
wp-codebox recipe validate --recipe mdi-377-replace-parity.recipe.json --json
wp-codebox recipe-run --recipe mdi-377-replace-parity.recipe.json --artifacts artifacts/mdi-377-replace-parity --timeout 20m --json
```

Emit the recipe at the repository root so its relative source package resolves
to this checkout. WP Codebox owns database provisioning and teardown; it injects
generated credentials. The script refuses pre-existing fixture tables and stale
phase state. It includes the staged source-package digest in the report.

Operator-retained evidence (not publicly hosted artifact links):

- Homeboy run: `mdi-377-replace-reference-docker-v9`
- Runner job: `5430d366-f3f2-4fd1-b9a0-84e7a9245aac`
- Recipe artifact: `runner-exec-77a19d72e43b4bcd4957b519b04271aa35d238c9df95be70adff3ae45bcba2ff`
- Result bundle: `16a59de8-d6a6-4b86-87c6-98d30163b7b5`
- Typed report: `files/runtime-evidence/typed-artifacts/replace-parity-report-2.json`
- Source-package SHA-256: `12af3b0414e72bc0017a682418b6e3a6e4fb679922073cb8deef5e1f7eee9554`
- Candidate base: `a92914009e7b4341562999b22b64d6b2d3048b6d`, plus this change.

## Remaining Boundaries

The broader query-parser smoke still fails its existing unsupported-aggregate
assertion on both the candidate and unchanged baseline `df206ec`. Baseline run:
`mdi-377-parser-baseline`. The new quoted-identifier check passed. This is not a
claim that the complete native test suite is green.

Consumer run `16fa8154-e33f-4177-808e-7d815dae2c5a` is excluded from parity evidence:
the executing Data Machine dependency was not the requested pinned revision,
and the extension result parser failed to resolve its shared library. Its counts
cannot be compared with the historical paired 1,053-test result. A valid pinned
native/MySQL consumer pair remains required.

This focused result does not close full SQL parity, performance acceptance,
SQLite removal, or production adoption.
