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
# homeboy core issue #1604 tracks restoring those CLI flags; until then,
# single-instance shared-state runs can export HOMEBOY_BENCH_SHARED_STATE.
HOMEBOY_BENCH_SHARED_STATE=/tmp/mdi-bench/primary \
  homeboy --output /tmp/primary-shared.json bench markdown-database-integration \
    --iterations 5 \
    --setting-json wp_config_defines='{"MARKDOWN_DB_MODE":"primary"}'

# Issue #44: cold vs warm MDI loader timings.
bash tests/bench/run-boot-timing.sh --iterations 5 --corpus-size 1000
```

Results land at `tests/bench/results/<YYYY-MM-DD>/<substrate>.json`.

`run-boot-timing.sh` writes a focused summary to
`tests/bench/results/<YYYY-MM-DD>/boot-timing-summary.json` and raw
per-iteration observations to the run's shared-state directory.

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
    return ['kind' => 'my-workload', /* ... metadata ... */];
};
```

The dispatcher discovers each file, runs the callable
`HOMEBOY_BENCH_ITERATIONS` times (plus one warmup, discarded), and emits
p50/p95/p99/mean/min/max in the BenchResults envelope. Each iteration is
a fresh PHP-WASM boot — there is no cross-iteration WordPress state. The
shared-state file IS persistent across iterations within a run.

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
- **Focused boot timing uses `BENCH_ONLY=boot-timing` as a local filter.** The WordPress bench runner currently discovers and executes every workload under `tests/bench/`. The unrelated workloads return immediate no-ops under `BENCH_ONLY`, but they still appear in the `BenchResults` envelope. Tracked upstream in [Extra-Chill/homeboy-extensions#266](https://github.com/Extra-Chill/homeboy-extensions/issues/266).
- **Detailed loader metrics are sidecar JSON for now.** The WordPress bench runner ignores callable return payloads, so loader phase stats and lazy content counts are written to `boot-timing-observations.jsonl` and summarized by the driver. Tracked upstream in [Extra-Chill/homeboy-extensions#265](https://github.com/Extra-Chill/homeboy-extensions/issues/265).
- **The driver exports `HOMEBOY_BENCH_SHARED_STATE` directly.** homeboy core still has shared-state/concurrency workflow plumbing, but the installed CLI no longer exposes the flags. Tracked upstream in [Extra-Chill/homeboy#1604](https://github.com/Extra-Chill/homeboy/issues/1604).
- **`run-matrix.sh` does not forward `--shared-state` / `--concurrency`** to the cells automatically. Concurrency-shape variants need direct `homeboy bench` invocation today; the matrix driver is single-instance per cell.
- **No 10k corpus committed result set** ships in the initial PR. Run `BENCH_CORPUS_SIZE=10000 bash tests/bench/run-matrix.sh --iterations 10` locally for the power-user-vault numbers — wall time is single-digit hours.
- **Iteration noise.** At small iteration counts (<5) and small corpus sizes (<100), substrate ranking can invert run-over-run. Read p99 spread before drawing directionality conclusions; one-shot results are signal-poor.

## Related upstream PRs (this cook)

The bench harness drove three changes upstream rather than papering over them:

- [Extra-Chill/homeboy-extensions#248](https://github.com/Extra-Chill/homeboy-extensions/pull/248) — `wp_config_defines` setting for per-component wp-config additions (released as wordpress-v2.17.0). Without it, each substrate would have shipped a custom `db.php` to vary one constant.
- [Extra-Chill/homeboy-extensions#249](https://github.com/Extra-Chill/homeboy-extensions/pull/249) — `PLUGIN_SLUG` honors `HOMEBOY_COMPONENT_ID` (released as wordpress-v2.17.1). Without it, running `homeboy bench` from a git-worktree directory mounted the plugin at the wrong path and broke MDI's internal class probes.
- [Extra-Chill/homeboy-extensions#250](https://github.com/Extra-Chill/homeboy-extensions/pull/250) — `bench_env` setting forwards host-shell env vars into Playground PHP-WASM (released as wordpress-v2.18.0). Without it, `BENCH_CORPUS_SIZE=10000` from the parent shell never reached `getenv()` inside workloads — every "10k corpus" run was actually corpus=100. The matrix driver now threads workload knobs through `bench_env` in `HOMEBOY_SETTINGS_JSON`.
- [Extra-Chill/homeboy#1532](https://github.com/Extra-Chill/homeboy/issues/1532) — `--output` post-subcommand position silently swallowed by trailing-arg capture. Workaround applied in `run-matrix.sh`: use the documented global position (`homeboy --output ... bench ...`).

The harness also surfaced [Extra-Chill/homeboy#1526](https://github.com/Extra-Chill/homeboy/issues/1526) (workload slug collision in subdirs) — not blocking, harness is structured to avoid the trap (no subdirs in `tests/bench/`).
