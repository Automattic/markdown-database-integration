# Native benchmark plan

See [the benchmark guide](README.md) and `rigs/mdi-native/rig.json` for the
supported native workloads. MariaDB remains the compatibility oracle.

Measure corpus sizes 100, 1,000, and 10,000 using identical deterministic seeds.
Separate fresh-process cold boot, filesystem-warm boot, and warm-process query
measurements. Include persistence shutdown, peak memory, concurrent writers,
lock contention, restart recovery, and p50/p95/p99 latency. Record fixture and
runtime identities. Do not infer parity or crash durability from throughput.

No retired SQLite runtime or comparison matrix is required by this plan.
