# Native Query Composition Review

## Architecture

Boolean WHERE terms are represented as disjunctions of conjunctions in the shared typed AST and lowered plan. Subquery terms now live in that same node beside indexed and scalar predicates. Uncorrelated subqueries execute through the same plan executor as top-level queries, so supported joins, derived sources, scalar projections, aggregates, and UNIONs retain their normal semantics before membership is evaluated. Correlated bounded predicates retain the existing keyed matcher. Both paths evaluate SQL truth at the original boolean position after the bounded source read, preserving `IN`, `NOT IN`, and `EXISTS` NULL behavior.

UNION plans now retain a separate combined-result ORDER BY/LIMIT stage. Unparenthesized trailing clauses are moved from the syntactic final branch to that stage. Branches accumulate with each UNION/ALL boundary's DISTINCT behavior, later branch output is aligned by position to first-branch names, then the combined result is globally ordered and sliced. Parenthesized derived UNION branches retain their local plan clauses.

## Adversarial Coverage

- `tests/smoke-native-subquery-union.php` covers scalar `DATE()` plus `IN`, nested scalar/EXISTS/NOT IN disjunctions, NULL-bearing membership sources, correlation against base and joined aliases with hidden correlation-column loading, joined and aggregate `IN` subquery plans, mixed UNION/ALL accumulation, global descending LIMIT, scalar output aliases, and ordinal global ordering.
- The disposable MariaDB reference on Lab returned `1`, `1`, `3`, and `2024-01-03` for the matching scalar/subquery, nested boolean, global UNION, and ordinal-alias UNION probes. The native regression asserts the same rows.
- The Codebox corpus includes `select.subquery.scalar.in` and `select.union.global.order.limit`; both completed on the final candidate.

## Remaining Limits

- Correlated subqueries remain bounded single-table plans with one qualified equality correlation. A correlated predicate may reference any available outer JOIN alias. Uncorrelated subqueries may use any shape supported by the shared plan executor.
- UNION branches require equal projection counts and compatible native column types. Global ORDER BY accepts first-branch output names or positive ordinals, not arbitrary expressions.
- Parenthesized SELECTs are supported as derived sources; arbitrary parenthesized top-level UNION syntax remains outside the bounded grammar.
