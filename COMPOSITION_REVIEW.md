# Native Query Composition Review

## Architecture

Boolean WHERE terms are represented as disjunctions of conjunctions in the shared typed AST and lowered plan. Subquery terms now live in that same node beside indexed and scalar predicates. Uncorrelated subqueries execute through the same plan executor as top-level queries, so supported joins, derived sources, scalar projections, aggregates, and UNIONs retain their normal semantics before membership is evaluated. Direct correlations bind lexically enclosing aliases after child-local aliases across predicate and scalar expression trees, preserve a NULL comparison as a false boolean leaf, and surface child failures to the enclosing statement. Both paths evaluate SQL truth at the original boolean position after the bounded source read, preserving `IN`, `NOT IN`, and `EXISTS` NULL behavior.

UNION plans now retain a separate combined-result ORDER BY/LIMIT stage. Unparenthesized trailing clauses are moved from the syntactic final branch to that stage. Branches accumulate with each UNION/ALL boundary's DISTINCT behavior, later branch output is aligned by position to first-branch names, then the combined result is globally ordered and sliced. Parenthesized derived UNION branches retain their local plan clauses.

## Adversarial Coverage

- `tests/smoke-native-subquery-union.php` covers scalar `DATE()` plus `IN`, nested scalar/EXISTS/NOT IN disjunctions, correlation against base and joined aliases with hidden correlation-column loading, `LOWER(inner.name) = LOWER(outer.name)`, scalar-only OR correlation, NULL outer scalar OR branches, child alias shadowing, child failure propagation, and exact correlated-work bounds.
- The disposable MariaDB reference on Lab returned `1,3`, `1,3`, and `1,2,3` for scalar-AND, scalar-only OR, and NULL-scalar OR probes. The native regression asserts the same rows.
- The Codebox corpus includes `select.subquery.scalar.in` and `select.union.global.order.limit`; both completed on the final candidate.

## Remaining Limits

- Correlated subqueries are bounded to 10,000 distinct outer bindings per statement and support qualified predicate and scalar-expression references against enclosing aliases. Correlated aggregate/HAVING expressions and nested correlated subqueries fail closed with `unsupported_subquery_correlation`; uncorrelated subqueries may use any shape supported by the shared plan executor.
- UNION branches require equal projection counts and compatible native column types. Global ORDER BY accepts first-branch output names or positive ordinals, not arbitrary expressions.
- Parenthesized bounded SELECT and UNION query expressions are supported both as derived sources and as top-level statements; each operand must still use the supported bounded SELECT grammar.
