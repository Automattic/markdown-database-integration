# Native WordPress benchmarks

The only MDI runtime rig is `rigs/mdi-native/rig.json`. It installs the native
`db.php`, mounts canonical fixture state, and checks that
`WP_Markdown_Native_WPDB` owns the active boundary. There is no SQLite comparison
cell or shadow/differential runner.

## Priorities and CLI acceptance

MySQL-visible SQL and `wpdb` compatibility comes before optimization. Use the
MariaDB query corpus as the native oracle; representative plugin workloads do
not define engine scope. Claims of performance improvement require real
end-to-end WP-CLI timings including cold process/bootstrap, query execution,
persistence shutdown, memory, growth, concurrency, and tail latency. A warmed
PHP callable benchmark is not proof of faster WordPress bootstrap or complete
compatibility.

## Workloads

The native rig retains these public-WordPress-API workloads:

- `bulk-import.php`: import throughput from a clean corpus.
- `obsidian-bursty.php`: deterministic mixed writes and deletes.
- `read-heavy.php`: ID, path, date, taxonomy, and search queries.
- `wiki-hierarchy.php`: hierarchy projections.
- `plugin-table-inventory.php`: plugin table inventory.
- `transaction-heavy.php`: transaction durability and rollback.

`concurrent-writers.php` and `crash-kill.php` are reusable API workloads for
explicit shared-state experiments. `exit()` runs PHP shutdown handlers and is
not equivalent to an OS-level kill; do not treat that workload as proof of
mid-write crash durability. `tests/bench-fixtures/concurrent-read-endpoint.php`
is the native rig's HTTP concurrency endpoint.

Shared helpers live in `tests/bench-lib/shared-helpers.php`. Set
`BENCH_CORPUS_SIZE` to vary growth (default 100); deterministic seeds preserve
repeatability. Record runtime identity, corpus size, warmup policy,
p50/p95/p99, peak memory, and host/tool versions with each result.

The former SDI/mirror/primary matrix, direct SQLite loader boot-timing runner,
and SQLite index audit have been removed. To measure native boot, use fresh
WP-CLI processes against an isolated native installation, not the retired
loader API. Back up fixtures before destructive workloads.
