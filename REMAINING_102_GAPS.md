# Remaining Native Consumer Gaps

The exact full DME1053 pair at candidate `99e032be6c5cf65b85a64ce863eb0e0065084682` recorded native 942 passed, 70 failures, 32 errors, and 9 skipped. The MySQL control recorded 1,022 passed, 24 errors, and 7 skipped.

## Raw-Diagnostic Classification

- The MySQL control errors are WP_CLI bootstrap failures and must remain separate from native engine parity.
- Native errors include the same missing WP_CLI class plus three physical `mysqli` root-access failures in `VenueProfileMutationsTest`; neither proves a native SQL mismatch.
- Native assertion failures include harness/application state differences such as user initialization and the physical-`mysqli` expectation in `WordPressLifecycleTest`.
- A repeated native query symptom is empty event candidate sets in `EventDateQueryAbilitiesTest` and duplicate/upsert paths. The posts schema did not classify exact `post_title` predicates as lookups. This branch supports ASCII case-insensitive, trailing-space-padded `=` and `IN` comparisons, with non-ASCII values failing closed. A title lookup without a reusable scoped snapshot explicitly fails after 1,024 canonical source files; ASCII validation is a collation constraint, not a scan-cost bound.

## Still Unresolved

- Full Unicode MySQL collation semantics for title lookups remain unsupported.
- Title lookups over larger uncached canonical corpora require a reusable source index before they can execute without the explicit 1,024-file work limit.
- Physical `mysqli` and WP_CLI-dependent tests require separate Codebox/bootstrap ownership.
- Transaction semantics require a dedicated end-to-end framework repair; reporting an InnoDB engine string alone would not supply them.
- The remaining native assertions need paired, per-test diagnosis after this focused repair; aggregate full-suite counts are not parity evidence.
