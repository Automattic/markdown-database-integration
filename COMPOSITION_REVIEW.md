# Native Query Composition Review

## Architecture

Boolean WHERE terms are represented as disjunctions of conjunctions in the shared typed AST and lowered plan. Subquery terms now live in that same node beside indexed and scalar predicates. The executor materializes each bounded subquery once, then evaluates its SQL truth value at its original boolean position after the bounded source read. This preserves OR grouping and the existing NULL behavior for `IN`, `NOT IN`, and correlated `EXISTS`.

UNION plans now retain a separate combined-result ORDER BY/LIMIT stage. Unparenthesized trailing clauses are moved from the syntactic final branch to that stage. Branches accumulate with each UNION/ALL boundary's DISTINCT behavior, later branch output is aligned by position to first-branch names, then the combined result is globally ordered and sliced. Parenthesized derived UNION branches retain their local plan clauses.

## Adversarial Coverage

- `tests/smoke-native-subquery-union.php` covers scalar `DATE()` plus `IN`, nested scalar/EXISTS/NOT IN disjunctions, NULL-bearing membership sources, mixed UNION/ALL accumulation, global descending LIMIT, scalar output aliases, and ordinal global ordering.
- The disposable MariaDB reference on Lab returned `1`, `1`, `3`, and `2024-01-03` for the matching scalar/subquery, nested boolean, global UNION, and ordinal-alias UNION probes. The native regression asserts the same rows.
- The Codebox corpus includes `select.subquery.scalar.in` and `select.union.global.order.limit`; both completed on the final candidate.

## Remaining Limits

- Subqueries remain bounded single-table plans with one qualified equality correlation. JOIN subquery predicates currently require the outer reference to be the base JOIN source.
- UNION branches require equal projection counts and compatible native column types. Global ORDER BY accepts first-branch output names or positive ordinals, not arbitrary expressions.
- Parenthesized SELECTs are supported as derived sources; arbitrary parenthesized top-level UNION syntax remains outside the bounded grammar.
