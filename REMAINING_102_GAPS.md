# Remaining Native Consumer Gaps

## Current Status (September 12, 2026)

This filename and the diagnostic below describe a historical run, not the
current failure count. Full MySQL-visible SQL and `wpdb` parity for arbitrary
WordPress plugins and workloads is the first priority. Faster and more scalable
execution than SQLite and MySQL follows, measured primarily through complete
WP-CLI invocations. Representative suites establish evidence, not a restricted
plugin support target. See [#232](https://github.com/Automattic/markdown-database-integration/issues/232)
and [#377](https://github.com/Automattic/markdown-database-integration/issues/377).

[#399](https://github.com/Automattic/markdown-database-integration/pull/399)
journaled post mutations; [#400](https://github.com/Automattic/markdown-database-integration/pull/400)
shared multisite journals; [#403](https://github.com/Automattic/markdown-database-integration/pull/403)
added cross-process read/write admission, snapshot refresh, transactional-table
capability checks, and independent-process isolation and recovery verification.
The latter records this paired 1,053-test consumer run with the same companion
consumer adapter on both backends:

| Backend | Passed | Failed/Error | Skipped |
|---|---:|---:|---:|
| Native | 1,040 | 4 | 9 |
| MySQL | 1,046 | 0 | 7 |

The four remaining failures require physical `mysqli` connections or handles.
They need explicit consumer/harness ownership; the native engine does not
provide a physical MySQL connection. The different skips still require
classification. #403 also records two pre-existing failures in
`tests/smoke-native-table-replace.php`. These are recorded PR results, not a
fresh execution at the current checkout.

Full Unicode collation semantics and uncached title lookups beyond the
1,024-source-file budget remain limitations. [#406](https://github.com/Automattic/markdown-database-integration/pull/406)
fixes LIKE matching against non-ASCII bodies, not complete Unicode collation.
Transactions now have bounded coarse serialization, not MVCC or independent
same-process connection isolation. Direct filesystem writers bypassing native
locking remain outside that contract. Complete site lifecycle, restoration,
concurrency, SQL parity, and current-head performance acceptance remain open.

## Historical DME1053 Diagnosis

The exact full DME1053 pair at candidate `99e032be6c5cf65b85a64ce863eb0e0065084682` recorded native 942 passed, 70 failures, 32 errors, and 9 skipped. The MySQL control recorded 1,022 passed, 24 errors, and 7 skipped.

## Raw-Diagnostic Classification

- The MySQL control errors are WP_CLI bootstrap failures and must remain separate from native engine parity.
- Native errors include the same missing WP_CLI class plus three physical `mysqli` root-access failures in `VenueProfileMutationsTest`; neither proves a native SQL mismatch.
- Native assertion failures include harness/application state differences such as user initialization and the physical-`mysqli` expectation in `WordPressLifecycleTest`.
- A repeated native query symptom is empty event candidate sets in `EventDateQueryAbilitiesTest` and duplicate/upsert paths. The posts schema did not classify exact `post_title` predicates as lookups. This branch supports ASCII case-insensitive, trailing-space-padded `=` and `IN` comparisons, with non-ASCII values failing closed. A title lookup without a reusable scoped snapshot explicitly fails after 1,024 canonical source files; ASCII validation is a collation constraint, not a scan-cost bound.

## Unresolved At That Revision

- Full Unicode MySQL collation semantics for title lookups remain unsupported.
- Title lookups over larger uncached canonical corpora require a reusable source index before they can execute without the explicit 1,024-file work limit.
- Physical `mysqli` and WP_CLI-dependent tests require separate Codebox/bootstrap ownership.
- Transaction semantics require a dedicated end-to-end framework repair; reporting an InnoDB engine string alone would not supply them.
- The remaining native assertions need paired, per-test diagnosis after this focused repair; aggregate full-suite counts are not parity evidence.
