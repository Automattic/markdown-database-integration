# MDI Stress Bench — Plan

> **Status:** Phase 1+2+3 shipped. Three substrates × five workloads run
> end-to-end via `bash tests/bench/run-matrix.sh`. See `README.md` for
> usage; this file documents the design + open questions.

## Why

Markdown Database Integration (MDI) is positioned as a substrate that lets
a local WordPress site act as a second brain — the Obsidian-replacement
use case. That promise only holds if MDI can compete with stock SQLite
Database Integration (SDI) on **speed**, **scale**, and **durability**
across realistic vault sizes.

This bench harness exists to produce numbers, not vibes. It compares three
substrates on the same workloads and emits reproducible JSON results
committed into the repo (under `results/<date>/`).

## What this is not

- **Not a PHPUnit test suite.** PHPUnit is pass/fail. Benchmarks are
  timing + percentile distributions. Different shape.
- **Not a replacement for `tests/smoke-*.php`.** Those are correctness
  smokes. The bench assumes correctness and measures cost.

## Substrates under test

```
substrate          authoritative store       boot cost      mode select
───────────────────────────────────────────────────────────────────────
SDI (control)      .ht.sqlite                O(1) open      n/a — db.php parked
MDI mirror         .ht.sqlite + .md mirror   O(1) open      MARKDOWN_DB_MODE='mirror'
MDI primary        .md files                 O(n) rebuild   MARKDOWN_DB_MODE='primary'
```

SDI is the control. MDI-mirror isolates the cost of the markdown side
effect on writes. MDI-primary isolates the cost of making markdown
authoritative (boot rebuild, in-memory DB).

The substrate selection mechanism is the major design decision in this
harness — see "Architecture" below.

## Corpus sizes

Matches real Obsidian vault distribution:

| Size  | Profile                          |
|-------|----------------------------------|
| 100   | Casual note-taker                |
| 1,000 | Active vault, ~1 year of daily use |
| 10,000 | Power user / long-running vault |

Corpora are generated deterministically from a fixed seed (RNG seed plus
instance offset for parallel runs) so runs are comparable across
substrates and across time. Generator produces:

- Slug: `bench-<i>` (or workload-prefixed: `bulk-<i>`, `bursty-<i>`, etc.)
- Title: 3–8 seeded words from a 24-word pool
- Body: 2–5 Gutenberg paragraph + heading blocks, 2–6 sentences each
- Status: `publish`
- Tags: a quarter of the corpus is tagged from a 5-tag pool (read-heavy seeds the taxonomy)

Read `BENCH_CORPUS_SIZE` env var (default 100) — exposed via
`mdi_bench_corpus_size()`.

## Workloads (5)

All five live at `tests/bench/*.php`. Each `return`s a closure the
dispatcher invokes per iteration.

### 1. `obsidian-bursty.php` — target use case

Single writer, persistent corpus mutated in place across iterations.
Each iteration runs 50 mixed ops:

- 70% update (re-save with body change)
- 20% create (new post, added to live set)
- 5% rename (slug change → exercises file-rename / parent-promotion)
- 3% reparent (post_parent change — same #70-class path)
- 2% delete

First iteration seeds the corpus; later iterations operate against the
mutated state. Iterations are NOT independent — burst-on-burst is the
realistic shape.

### 2. `bulk-import.php` — switching-from-Obsidian moment

Each iteration starts from an empty `wp_posts` table and inserts
`BENCH_CORPUS_SIZE` posts in a tight loop. The dispatcher's per-iteration
metric is "wall time to import the full corpus from zero." Each iteration
calls `DELETE FROM wp_posts WHERE post_type = 'post'` first to reset.

### 3. `concurrent-writers.php` — surfaces #47/#70 lock contention

Reads `HOMEBOY_BENCH_INSTANCE_ID` and `HOMEBOY_BENCH_CONCURRENCY`. Each
instance writes its own deterministic stream under per-instance slug
prefixes (`concurrent-i<N>-<seq>`) so two instances don't target the same
row by ID, but they DO race on shared resources (file locks, .md write
contention, primary-mode boot rebuilds). Per-instance counters land in
`<shared-state>/instance-<N>/concurrent-writes.log`.

Single-instance runs without `--shared-state` still execute but are
meaningless — the workload should be invoked with `--concurrency=2` or
higher.

### 4. `crash-kill.php` — durability under simulated mid-write interrupt

Shared-state-required (no-op without `--shared-state`). Each iteration
writes posts in a loop, recording each intended write to a durable log
under shared state BEFORE the next write starts. At a seeded mid-point
the workload calls `exit(0)` — simulating process termination.

The next iteration's audit phase reads the previous iteration's log and
reconciles intended-vs-actual writes against the now-fresh WordPress
state. **Limitation: each Playground iteration is a fresh PHP-WASM boot,
so `on_disk` always shows 0** — the WordPress SQLite from iteration K
does not survive to iteration K+1. This workload still exercises the
shared-state contract and the workload's pre-write logging path; a true
cross-boot durability test requires a persistent-WordPress harness
(future enhancement, not Playground).

### 5. `read-heavy.php` — query mix on a populated corpus

100 mixed reads per iteration against a once-seeded 100/1k/10k corpus:

- 50% `get_post()` by ID
- 20% `get_page_by_path()` by slug
- 15% `WP_Query` by date range
- 10% `WP_Query` by tag
- 5% `WP_Query` `s=` (full-text-ish)

Reads do not mutate. Iteration K and K+1 hit identical data — variance
is purely query engine + cache behaviour.

## Metrics captured per scenario

The homeboy bench dispatcher emits the canonical `BenchResults` envelope:

```json
{
  "component_id": "markdown-database-integration",
  "iterations": 5,
  "scenarios": [
    {
      "id": "bulk-import",
      "file": "tests/bench/bulk-import.php",
      "iterations": 5,
      "metrics": {
        "mean_ms": 5076.03,
        "p50_ms": 5076.03,
        "p95_ms": 5841.04,
        "p99_ms": 5933.42,
        "min_ms": 4982.10,
        "max_ms": 5945.87
      },
      "memory": { "peak_bytes": 52953088 }
    }
  ]
}
```

Wrapped in homeboy's `--output` envelope under `data.results`.

## Architecture

The major design decision is **substrate axis = Playground boot
configuration, not on-disk components**. The first cut of this harness
constructed three on-disk substrate components, each with its own plugin
entry, `db.php` proxy, `homeboy.json`, and registration. That was
indirection that didn't carry weight — every substrate was just "MDI with
a different config."

Current shape:

```
markdown-database-integration/      ← one component
├── db.php                          ← Playground-aware (probes /internal/shared/sqlite-database-integration)
├── markdown-database-integration.php
├── inc/
└── tests/
    ├── bench/                      ← workloads + matrix driver
    │   ├── *.php                   ← 5 workloads
    │   ├── run-matrix.sh           ← orchestrates 3 cells
    │   └── results/
    └── bench-lib/                  ← helpers (sibling, NOT child of bench/)
```

`run-matrix.sh` invokes `homeboy bench markdown-database-integration` 3 times:

1. **SDI cell:** rename `db.php` → `db.php.bench-parked`, run, rename back. Playground falls back to bundled SDI mu-plugin.
2. **Mirror cell:** `HOMEBOY_SETTINGS_JSON='{"wp_config_defines":{"MARKDOWN_DB_MODE":"mirror"}}'`. MDI's drop-in loads.
3. **Primary cell:** same as mirror with `'primary'`.

`HOMEBOY_SETTINGS_JSON` is the env var homeboy core normally populates from
component-side `homeboy.json` settings; we synthesize it directly here
because the matrix is the orchestration, not a per-component declaration.

When [homeboy#1525 (rig matrix)](https://github.com/Extra-Chill/homeboy/issues/1525)
lands, the matrix declaration moves into a rig spec. `run-matrix.sh`
drops then.

## Why three Playground instances and not one with substrate-switching

Playground PHP-WASM is single-process, single-WP-VFS per invocation.
Switching `db.php` mid-process (e.g. via filter) would require deep
WordPress internals — `$wpdb` swap, schema rebuild, cache invalidation —
and would still measure the swap, not the steady-state substrate. Three
clean boots, one per substrate, is the only honest measurement.

Cross-cell parallelism is possible (three Playground processes are
independent), but the matrix today runs them serially because:
1. Per-iteration cold-boot + install cost is already the dominant cost
   for short workloads; parallelism would mask substrate variance.
2. SDI cell renames the on-disk `db.php` — concurrent SDI + mirror would
   race on that rename.

If wall time becomes a constraint (10k corpus, 10+ iterations, 3
substrates, full matrix taking hours), parallelize SDI separately from
mirror+primary. Not worth complicating the driver until then.

## Open questions

- **Object cache:** Playground default is in-memory only (no persistent
  object cache). Do we flush between workloads or leave warm?
  Currently no explicit flush — workload K's cache state carries into
  K+1. For the read-heavy mix this is realistic; for bulk-import the
  per-iteration `DELETE FROM wp_posts` already invalidates everything
  meaningful.
- **PHP OPcache:** Playground default is on. Leaving it on (realistic).
  Note in results metadata if we ever add an OPcache-off variant.
- **Gutenberg block serialization cost:** MDI round-trips blocks through
  HTML via `html-to-blocks-converter`. This is a write-path cost not
  present in SDI. Workload results show it; no special handling needed.
- **Primary-mode JSON fallback tables:** `_tables/*.json` is read on
  boot and written on non-markdown-type post writes. Its cost shows in
  the boot curve naturally. We should capture `_tables/` footprint
  separately as a resource metric — TODO, not in v1.
- **Concurrent-writer count ceiling:** what N is realistic for a local
  Studio site? Studio is single-user by nature, but admin + cron +
  background jobs make N=2–4 plausible. N=8 is the stress case. Not
  asking the bench to solve distributed — just surface the lock-
  contention curve.
- **Iteration count noise floor.** At small iter counts (<5) on small
  corpora (<100), substrate ranking inverts across runs. Surface this
  in result interpretation; recommend `--iterations 10+` for any
  conclusion claim.
- **Crash-kill cross-boot durability** (see workload doc): needs a
  persistent-WordPress harness instead of Playground. Future
  enhancement; not in v1.

## Phase status

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Skeleton + SDI baseline + bulk-import end-to-end | ✅ shipped |
| 2 | Mirror + primary substrates driven by same runner | ✅ shipped |
| 3 | All five workloads runnable across all three substrates | ✅ shipped |
| 4 | Regression tracking (`--baseline`, `--ratchet`, `--regression-threshold`) | ✅ shipped via homeboy bench primitives — no harness work needed |
| 5 (out of v1) | CI hook for 100-size cells on PR | future |
| 5 (out of v1) | 10k corpus committed result set | future |
| 5 (out of v1) | Cross-boot durability harness for crash-kill | future |

## Homeboy fit

The bench WAS scoped to live as plain shell + PHP because earlier
homeboy-wordpress had three blockers: (1) it owned `db.php`, (2) it ran
ephemeral wp-phpunit with no persistence, (3) it had no bench primitive.

All three are now resolved:

- **Bench primitive shipped** in homeboy core (`homeboy bench`,
  Extra-Chill/homeboy#1385, v0.86).
- **Playground bench dispatcher shipped** (homeboy-extensions #238,
  wordpress-v2.13.0+) with cold-boot per iteration matching the test
  runner's boot path.
- **`--shared-state` + `--concurrency`** shipped (homeboy core #1512,
  v0.94.0; wordpress dispatcher #243, wordpress-v2.16.0+).
- **`wp_config_defines` setting** shipped during this cook
  (homeboy-extensions #248, wordpress-v2.17.0). Lets components vary
  wp-config constants declaratively. This is the keystone — without it
  every substrate would have shipped a custom `db.php`.
- **`PLUGIN_SLUG` honors `HOMEBOY_COMPONENT_ID`** shipped during this
  cook (homeboy-extensions #249, wordpress-v2.17.1). Without it the
  bench couldn't be invoked from a git-worktree directory.

So the harness machinery the original PLAN scoped (`run-bench.sh`,
`run-matrix.sh` with full custom orchestration, `lib/measure.php`,
`lib/results-writer.php`) collapsed to homeboy's responsibility — MDI
ships only the workloads + helpers + thin matrix driver.

## Open issues during this cook

- **homeboy#1526** — workload slug collision in subdirs. Not blocking;
  harness avoids subdirs.
- **homeboy#1532** — `--output` post-subcommand position silently
  swallowed. `run-matrix.sh` uses the documented-correct global
  position; consumers should too until the dispatcher fixes the
  trailing-arg capture.

## Result interpretation

See `README.md` for jq snippets. Note the iteration-noise caveat — at
small iter counts (<5), substrate ranking can invert. The committed
results in `results/<date>/` should always be from `--iterations >= 5`
(ideally 10+) with `BENCH_CORPUS_SIZE >= 100`.
