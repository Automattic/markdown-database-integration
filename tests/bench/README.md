# MDI Stress Bench

Compare three WordPress DB substrates on identical workloads at realistic
Obsidian-vault scale: stock SDI (control), MDI mirror, MDI primary.

The goal is numbers, not vibes. Every cell produces a `BenchResults` JSON
envelope with p50/p95/p99 timings per workload, peak memory, and the
substrate context that produced it.

## TL;DR — run the smoke matrix

```bash
# Quick smoke (corpus 100 posts, 5 iterations per workload)
bash tests/bench/run-matrix.sh --iterations 5

# Realistic vault size (1000 posts)
BENCH_CORPUS_SIZE=1000 bash tests/bench/run-matrix.sh --iterations 10

# Power-user vault (10000 posts) — single-digit hours wall time
BENCH_CORPUS_SIZE=10000 bash tests/bench/run-matrix.sh --iterations 10

# Concurrent-writer + crash-kill cells need shared state + concurrency.
homeboy --output /tmp/primary-concurrent.json bench markdown-database-integration \
  --iterations 5 \
  --shared-state /tmp/mdi-bench/primary \
  --concurrency 4 \
  --setting-json wp_config_defines='{"MARKDOWN_DB_MODE":"primary"}'

# Issue #44: cold vs warm MDI loader timings.
bash tests/bench/run-boot-timing.sh --iterations 5 --corpus-size 1000
```

Results land at `tests/bench/results/<YYYY-MM-DD>/<substrate>.json`.

## SQLite vs native decision rigs

### Native cutover evidence

The native-only target is tracked in #232; the current optimization candidate
is draft PR #370. SQLite remains the comparison reference. The final source
revision is `21267e1` (`b2cafd2` is the runtime change under review): it
removes retain-first branches while preserving `requires_complete_scope`
full-freshness traversal.

Final-head local correctness evidence on `b2cafd2`: PHP 8.5.5 passed all 73
smoke-native scripts. The optional canonical usermeta check is intentionally
skipped at `tests/smoke-native-usermeta-query.php:165` because it is not
configured. The 240-case differential suite and storage-index freshness checks
passed.

#### Final-head decision matrix

Homeboy 0.370.0 ran the `decision` profile at `21267e1`, with one run, five
measured iterations, one warmup, `BENCH_CORPUS_SIZE=1000`, and unprofiled
workloads. Both cells passed: SQLite `b28d11dd-e33d-40cf-8944-683aacb5fbc3`
and native `2cc3b041-9291-4e8b-823f-e05b92376607`. These operator run IDs are
supplementary; the command and revision below are the reproducible evidence.

| Workload | Native mean (ms) | SQLite mean (ms) | Verified result |
|---|---:|---:|---|
| bulk-import | 24627.2434754 | 24654.0637768 | 1,000 imported and 1,000 stored |
| obsidian-bursty | 7078.3977238 | 1220.025412 | Signal only; no strict equal-result assertion |
| read-heavy | 116.9425662 | 28.4982226 | Signal only; no strict equal-result assertion |
| wiki-hierarchy | 92.886808 | 3.616162 | 881 rows returned |
| plugin-table-inventory | 5.7952898 | 5.0626114 | 501 inventory, 20 repository, and 5 task rows |
| transaction-heavy | 107.566288 | 84.0940222 | 90 committed rows, 15 commits, 5 rollbacks, and 120 writes |

The seventh decision-profile entry, `boot-timing`, was skipped because
`BENCH_BOOT_PHASE` was absent. It is excluded from performance results, not a
boot result. The plugin workload accepts either non-false `REPLACE` result:
native returned 2 and SQLite returned 1, a backend difference that the
workload intentionally permits.

Reproduce the representative comparison from this checkout:

```sh
homeboy bench markdown-database-integration --profile decision --runs 1 \
  --iterations 5 --warmup 1 \
  --setting-json 'bench_env={"BENCH_CORPUS_SIZE":"1000","BENCH_PROFILE":"0"}' \
  --rig mdi-sqlite,mdi-native --runner homeboy-lab --path "$PWD"
```

#### Final-head transaction scaling

Homeboy 0.370.0 ran `transaction-heavy` at `21267e1`, with one run, 50
measured iterations, and one warmup. Both cells passed: SQLite
`e98e356b-197f-48f9-9000-9c9950ced9a1` and native
`1334ecdc-f7b2-4c8b-9a5b-34f18025ebf4` (operator records only).

| Metric | Native (ms) | SQLite (ms) |
|---|---:|---:|
| Mean total duration | 426.07837956 | 90.88239834 |
| INSERT duration | 234.6008429 | 62.38926462 |
| UPDATE duration | 173.8366598 | 11.15693886 |
| Transaction control duration | 11.64906446 | 14.29955532 |

Total-duration samples ranged from 94.371189 to 822.249571 ms for native and
83.963205 to 103.349132 ms for SQLite. The recorded distributions are sorted,
so their endpoints are ranges, not first-to-last chronological trends.

Each invocation performs 20 transactions and 6 writes per transaction, with
15 commits, 5 rollbacks, and 90 committed rows. The table accumulates across
invocations, but verification checks only the current invocation's rows. The
50 measured iterations therefore add 4,500 committed rows excluding warmup;
that theoretical accumulation was not separately asserted. `BENCH_CORPUS_SIZE`
does not size this table.

Reproduce the scaling comparison:

```sh
homeboy bench markdown-database-integration --scenario transaction-heavy \
  --runs 1 --iterations 50 --warmup 1 \
  --setting-json 'bench_env={"BENCH_CORPUS_SIZE":"1000","BENCH_PROFILE":"0"}' \
  --rig mdi-sqlite,mdi-native --runner homeboy-lab --path "$PWD"
```

#### Decision

The execution gates are complete: both final-head matrix cells and both
scaling cells passed. Performance acceptance is not complete: no explicit
regression budgets exist, and there is no same-head pre-simplification
baseline that attributes the slower native results to a new regression. The
results show a native scaling weakness, not causal profiling. This PR is not
declared ready to land; review must either accept the known experimental
boundary and performance evidence or prioritize repairing the demonstrated
gaps.

#### Corrected bursty A/B diagnostic

Direct Lab A/B profiling used the same corrected benchmark hash
`e456e89dc0e350e10fbc4d6b3a088a9013505934efbd3af90c43321dd3fda86e`,
`BENCH_CORPUS_SIZE=1000`, one run, five measured iterations, one warmup, and
`BENCH_PROFILE=0`. Baseline `c89490a` (run
`9ea77ddd-b18b-4fc3-809a-c1afafe3d9ce`, job
`7f38f96e-1b9d-43ce-be55-4aeea8811894`) averaged 3854.9958552 ms
(3608.71965-4088.386191); the candidate runtime averaged 3734.1098676 ms
(3467.557242-3889.180371), 3.136% lower. The ranges overlap and this one
sequential pair is diagnostic evidence, not a statistical performance claim.

Both runs completed all 50 operations per iteration with the same verified
plan checksum, `9a11101c424387b131b497548a6a01cc73725e1fecc885ffea30927029851d3a`.
The observed mix was 37 updates, 10 creates, 2 reparents, and 1 delete. The
historical bursty matrix result (7.08 ms versus 1.22 ms) used process-global
RNG and different mixes, so it is not a controlled runtime-halving comparison.

#### Predicate-normalization candidate

The predicate-normalization candidate atop `57e459a` ran in Lab job
`8f8eb80c-81e1-457a-8d80-1b3bd8169205`, run
`6311b125-6012-47bd-bf96-06ea4e11b99f`, with the same corrected benchmark hash
`e456e89dc0e350e10fbc4d6b3a088a9013505934efbd3af90c43321dd3fda86e`, five
measured iterations, one warmup, corpus size 1,000, and `BENCH_PROFILE=0`.
Its mean was 3575.981257 ms (3333.300395-3780.202275), versus 3734.1098676 ms
(3467.557242-3889.180371) for the prior runtime measurement: 4.23% lower mean.
The ranges overlap, and this single sequential comparison is diagnostic only,
not causal or statistical performance proof. All five candidate iterations
completed 50 operations; corpus progression was 1013, 1019, 1026, 1039, and
1044, with the same final plan checksum above.

Native post mutations remain a separate, fail-closed compatibility boundary:
an active native transaction rejects the mutation before Markdown is written,
because its journal does not record canonical Markdown posts. The bounded merge
decision is whether reviewers accept that unsupported case; it is not native
post transaction rollback or crash-recovery support.

SQLite removal and production cutover remain separate. They require actual
native post transaction rollback and crash recovery, then an accepted-site
rehearsal with backups, workers, and compatibility verification.

### Operation profiling

Set `--setting-json 'bench_env={"BENCH_CORPUS_SIZE":"1000","BENCH_PROFILE":"1"}'`
on the Lab benchmark command to enable request-local MDI operation measurements.
Profiling is opt-in and adds measurement overhead; use unprofiled runs for final
performance comparisons.

Bulk import reports `reset_ms`, `generation_ms`, `insert_ms`, and `verification_ms`,
plus effective `corpus_size`, successful `imported`, and verified `stored_posts`.
A failed reset, insert, or final row-count check fails the workload. Metadata
records the active backend and wpdb class; Homeboy records candidate provenance.

Profile measurements cover only the insert loop. `identity_allocation_ms`,
`post_write_ms`, `metadata_parse_ms`, and `body_parse_ms` are inclusive and may
overlap: do not add them as independent phases. `manifest_advance_ms` measures
generator advancement, excluding its consumer. Each operation reports `_calls`;
`manifest_scans`, `manifest_files`, and `post_parse_reuse` report counts. Missing
operation keys mean no calls were observed, not an unavailable backend-wide timer.
The WordPress insert timer also includes work outside MDI, while reset and final
verification are reported separately from the operation profile.

Native `query_select_ms`, `query_insert_ms`, `query_update_ms`, `query_delete_ms`,
`query_replace_ms`, and `query_other_ms` cover runtime dispatch through result
construction, grouped by statement verb without retaining SQL or content.
`select_parse_ms` and `select_execute_ms` subdivide SELECT processing;
`catalogue_publish_ms` measures durable post catalogue publication. These are
inclusive spans, not additional independent costs. Nested execution can overlap.

Native SELECT shape attribution appears in `metadata.query_shapes`, sorted by
total duration for that iteration. SQL string and numeric literals are replaced
with `?`; identifiers remain visible. Each shape includes calls, inclusive time,
exact repeats, and distinct queries tracked using internal SHA-256 hashes.
Tracking is bounded to 64 shapes and 4096 exact queries per iteration; overflow
is reported in metrics. Shape tokenization itself adds profiling overhead and is
outside query timers but inside the WordPress insert timer. Metadata is not a
cross-iteration aggregate; use a single-iteration diagnostic for attribution.

The repository ships `mdi-sqlite`, `mdi-primary`, and `mdi-native` rigs for an
isolated, repeatable backend comparison. Install them from this checkout and
point them at the same MDI worktree:

```bash
homeboy rig install --all .

export HOMEBOY_RIG_COMPONENT_PATH__MDI_SQLITE__MARKDOWN_DATABASE_INTEGRATION="$PWD"
export HOMEBOY_RIG_COMPONENT_PATH__MDI_PRIMARY__MARKDOWN_DATABASE_INTEGRATION="$PWD"
export HOMEBOY_RIG_COMPONENT_PATH__MDI_NATIVE__MARKDOWN_DATABASE_INTEGRATION="$PWD"

homeboy bench markdown-database-integration \
  --rig mdi-sqlite,mdi-native \
  --profile decision \
  --runs 5 \
  --iterations 30 \
  --warmup 5 \
  --report side-by-side \
  --run-id mdi-backend-comparison
```

Both cells use the same corpus, warmup, checkout, and workload profile. The
SQLite rig exercises the supported mirror-mode default and the native rig sets
`MARKDOWN_DB_BACKEND=mdi-native`.

### Primary-mode cell

`mdi-primary` sets `MARKDOWN_DB_MODE=primary` against the same canonical
fixture so primary mode can be measured beside the other two. Add it to the
`--rig` list once primary mode boots against that fixture:

```bash
homeboy bench markdown-database-integration \
  --rig mdi-sqlite,mdi-primary,mdi-native \
  --profile decision \
  --report side-by-side
```

That cell currently produces no measurement. Primary mode fails during
WordPress bootstrap with `Error establishing a database connection` against the
shared fixture, with both the default bootstrap deadline and a 30 s deadline.
The rig itself passes `homeboy rig lint`, so the blocker is primary-mode
startup rather than the rig definition.

`run-boot-timing.sh` writes a focused summary to
`tests/bench/results/<YYYY-MM-DD>/boot-timing-summary.json`. Detailed loader
stats, file counts, lazy-content counts, and phase metadata are emitted in the
normal `boot-timing` BenchResults scenario metrics.

## How it works

The harness invokes `homeboy bench markdown-database-integration` three times
— once per substrate — using one component (MDI itself), with the substrate
selected by varying boot configuration:

| Cell    | Mechanism                                                        |
|---------|------------------------------------------------------------------|
| sdi     | MDI's `db.php` is renamed to `db.php.bench-parked` for the cell. Playground falls back to its bundled SDI mu-plugin (which has a `if (file_exists('/wordpress/wp-content/db.php')) return;` guard at the top — when MDI's drop-in is missing, the mu-plugin owns `$wpdb`). MDI's plugin code still loads, but its hot-path filters (`the_content`, `rest_prepare_*`) never fire on the workload paths (`wp_insert_post`, `WP_Query`, `get_post`), so this isolates the SDI substrate cleanly. |
| mirror  | `MARKDOWN_DB_MODE='mirror'` injected via `HOMEBOY_SETTINGS_JSON` → `wp_config_defines` (homeboy-extensions wordpress v2.17.0+). MDI's drop-in loads normally; markdown files mirrored on writes; SQLite authoritative. |
| primary | Same shape as mirror with `MARKDOWN_DB_MODE='primary'`. MDI's drop-in loads normally; markdown files authoritative; in-memory SQLite rebuilt from `.md` on cold boot. |

Three Playground instances, three result envelopes, no on-disk substrate
components, no proxy `db.php` files — the substrate axis is orchestrated
from outside the dispatcher because it's fundamentally a "how do we boot
WordPress" axis, not a "which component is under test" axis.

When [homeboy#1525 (rig matrix)](https://github.com/Extra-Chill/homeboy/issues/1525)
lands, the matrix declaration moves into a rig spec and homeboy core
orchestrates the three cells natively — `run-matrix.sh` drops then.

## File layout

```
tests/bench/
├── PLAN.md                  ← detailed plan / open questions / homeboy-fit story
├── README.md                ← this file
├── run-matrix.sh            ← matrix driver (parks db.php, varies env, invokes homeboy bench 3x)
├── run-boot-timing.sh       ← issue #44 driver (cold / warm-noop / warm-one-file)
├── boot-timing.php          ← workload: direct WP_Markdown_Loader cold/warm timing
├── bulk-import.php          ← workload: empty → N posts via wp_insert_post
├── concurrent-writers.php   ← workload: per-instance write streams, surfaces #47/#70 contention
├── crash-kill.php           ← workload: simulated mid-write interrupt, shared-state-required
├── obsidian-bursty.php      ← workload: 70%U / 20%C / 5%R / 3%P / 2%D against persistent corpus
├── read-heavy.php           ← workload: get_post / by_slug / date / tax / search mix
├── wiki-hierarchy.php       ← workload: ordered hierarchy scan with postmeta exclusions
├── plugin-table-inventory.php ← workload: dynamic plugin-table scans and lifecycle upserts
└── results/                 ← gitignored output dir, .gitkeep retained

tests/bench-lib/
├── shared-helpers.php       ← seeded RNG, deterministic corpus generator, instance scratch
└── integrity-audit.php      ← post-run drift audit (file-vs-row reconciliation)
```

`tests/bench-lib/` is sibling to `tests/bench/` (not a child) because the
homeboy bench dispatcher recursively scans `tests/bench/` for workloads
and treats every `.php` file under it as a callable workload. Helpers
sitting under `tests/bench/lib/` would get loaded as no-op "workloads" and
contaminate the BenchResults envelope. The sibling directory keeps helpers
out of the discovery path.

## Workload contract

Each workload file under `tests/bench/*.php` `return`s a callable:

```php
require_once __DIR__ . '/../bench-lib/shared-helpers.php';

return function (): array {
    // ... measurable work ...
    return [
        'metrics' => ['rows' => 100],
        'metadata' => ['phase' => 'warm'],
    ];
};
```

The dispatcher discovers each file, retains its callable in one PHP process, and
runs it `HOMEBOY_BENCH_ITERATIONS` times plus one discarded warmup. Workloads
may intentionally retain static state across those invocations; for example,
`obsidian-bursty` seeds during warmup and mutates the same corpus in subsequent
iterations. Numeric values returned under `metrics` are aggregated into the
same scenario metrics object; the latest returned `metadata` payload is attached
to that scenario. The shared-state file is also persistent across iterations
within a run.

## Constants the workloads read

| Constant                       | Always defined? | Default          | Purpose                                           |
|--------------------------------|-----------------|------------------|---------------------------------------------------|
| `HOMEBOY_BENCH_SHARED_STATE`   | yes             | `''`             | Absolute path to shared dir, or `''` if `--shared-state` was not passed. |
| `HOMEBOY_BENCH_INSTANCE_ID`    | yes             | `0`              | `0..N-1` for parallel runs.                       |
| `HOMEBOY_BENCH_CONCURRENCY`    | yes             | `1`              | Total instance count.                             |
| `MARKDOWN_DB_MODE`             | substrate-dep   | n/a              | Set by `wp_config_defines` for mirror/primary cells; undefined for SDI cell. |
| `BENCH_CORPUS_SIZE` (env var)  | yes (env)       | `100`            | Workload-controlled corpus size; read via `mdi_bench_corpus_size()`. |

## Reading results

Each `<substrate>.json` is a homeboy `--output` envelope wrapping the
BenchResults shape:

```bash
jq -r '.data.results.scenarios | map([.id, "p50=" + (.metrics.p50_ms|tostring) + "ms"] | join("  ")) | .[]' \
  results/2026-04-25/mirror.json
```

Compare cells:

```bash
DATE=2026-04-25
for s in sdi mirror primary; do
  echo "=== $s ===";
  jq -r '.data.results.scenarios | map([.id, "p50=" + (.metrics.p50_ms|tostring) + "ms"] | join("  ")) | .[]' \
    results/$DATE/$s.json
done
```

## Known harness limitations

- **`crash-kill` cross-boot durability is not reproducible inside Playground.** Each Playground iteration is a fresh PHP-WASM boot; the WordPress SQLite from iteration K does not survive into iteration K+1. The crash-kill workload's "audit phase" therefore always sees `on_disk=0` for the previous iteration's writes — a true cross-boot durability test would require a persistent-WordPress harness, not Playground. Filed as a future enhancement; the current workload still exercises the shared-state contract end-to-end and surfaces the dispatcher seam working correctly.
- **`run-boot-timing.sh` measures the loader directly, not the full drop-in boot path.** The WordPress Playground bench runner always runs wp-phpunit's install stage, which mutates persisted state and prevents a true installed-site warm boot. The boot-timing workload therefore instantiates `WP_Markdown_Loader` against an isolated shared-state markdown/index directory. This still exercises the real `load_all()` / `sync_incremental()` / lazy content paths, but excludes the surrounding `WP_Markdown_DB::db_connect()` and wp-phpunit install costs. Tracked upstream in [Extra-Chill/homeboy-extensions#267](https://github.com/Extra-Chill/homeboy-extensions/issues/267).
- **`run-matrix.sh` does not forward `--shared-state` / `--concurrency`** to the cells automatically. Concurrency-shape variants need direct `homeboy bench` invocation today; the matrix driver is single-instance per cell.
- **No 10k corpus committed result set** ships in the initial PR. Run `BENCH_CORPUS_SIZE=10000 bash tests/bench/run-matrix.sh --iterations 10` locally for the power-user-vault numbers — wall time is single-digit hours.
- **Iteration noise.** At small iteration counts (<5) and small corpus sizes (<100), substrate ranking can invert run-over-run. Read p99 spread before drawing directionality conclusions; one-shot results are signal-poor.

## Related upstream PRs (this cook)

The bench harness drove three changes upstream rather than papering over them:

- [Extra-Chill/homeboy-extensions#248](https://github.com/Extra-Chill/homeboy-extensions/pull/248) — `wp_config_defines` setting for per-component wp-config additions (released as wordpress-v2.17.0). Without it, each substrate would have shipped a custom `db.php` to vary one constant.
- [Extra-Chill/homeboy-extensions#249](https://github.com/Extra-Chill/homeboy-extensions/pull/249) — `PLUGIN_SLUG` honors `HOMEBOY_COMPONENT_ID` (released as wordpress-v2.17.1). Without it, running `homeboy bench` from a git-worktree directory mounted the plugin at the wrong path and broke MDI's internal class probes.
- [Extra-Chill/homeboy-extensions#250](https://github.com/Extra-Chill/homeboy-extensions/pull/250) — `bench_env` setting forwards host-shell env vars into Playground PHP-WASM (released as wordpress-v2.18.0). Without it, `BENCH_CORPUS_SIZE=10000` from the parent shell never reached `getenv()` inside workloads — every "10k corpus" run was actually corpus=100. The matrix driver now threads workload knobs through `bench_env` in `HOMEBOY_SETTINGS_JSON`.
- [Extra-Chill/homeboy-extensions#271](https://github.com/Extra-Chill/homeboy-extensions/pull/271) — workload return values can contribute custom numeric `metrics` and scenario `metadata` to BenchResults. Without it, issue #44 loader stats had to live in sidecar JSONL outside homeboy's baseline/reporting path.
- [Extra-Chill/homeboy#1532](https://github.com/Extra-Chill/homeboy/issues/1532) — `--output` post-subcommand position silently swallowed by trailing-arg capture. Workaround applied in `run-matrix.sh`: use the documented global position (`homeboy --output ... bench ...`).

The harness also surfaced [Extra-Chill/homeboy#1526](https://github.com/Extra-Chill/homeboy/issues/1526) (workload slug collision in subdirs) — not blocking, harness is structured to avoid the trap (no subdirs in `tests/bench/`).
