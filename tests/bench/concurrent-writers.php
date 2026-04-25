<?php
/**
 * Concurrent-writers workload — surfaces #47 / #70 lock contention.
 *
 * Reads HOMEBOY_BENCH_INSTANCE_ID and HOMEBOY_BENCH_CONCURRENCY (set by
 * the homeboy bench dispatcher when --concurrency > 1). Each instance
 * writes its own deterministic stream of posts under a per-instance slug
 * prefix so two instances never target the same row by ID, but they do
 * race on shared resources:
 *
 *   - SDI:           one .ht.sqlite file with WAL mode → SQLite handles
 *                    serialization; lock contention shows as p95/p99
 *                    spread, not data loss.
 *   - MDI mirror:    .ht.sqlite + per-write .md mirror. The .md write
 *                    path goes through file_put_contents — concurrent
 *                    writes to the SAME slug are the hazard, but slug
 *                    namespacing avoids that. Cross-process meta writes
 *                    on _markdown_file_index are the remaining risk.
 *   - MDI primary:   the hot spot. Concurrent boot rebuild + concurrent
 *                    in-memory SQLite + concurrent .md writes. Issue
 *                    #47 (UNIQUE constraint on wp_posts.ID) is the
 *                    canonical reproducer. If two instances each call
 *                    `wp_insert_post` and the rebuild path assigns
 *                    overlapping IDs, this workload surfaces it.
 *
 * Per-instance scoping:
 *
 *   - Each instance writes posts with slug `concurrent-i<N>-<seq>`.
 *   - Each instance writes to its own counter file under
 *     <shared-state>/instance-<N>/counter.log so the audit can verify
 *     "instance N wrote K posts" without DB round-trip.
 *   - Single-instance fallback (concurrency=1, no shared state) still
 *     runs but is meaningless — counter ends up at zero parallel
 *     contention. Operators should run this workload with
 *     --concurrency=2 or higher.
 *
 * Single-iteration shape: each invocation of the callable writes
 * `posts_per_iter` posts. With dispatcher iterations=10, each instance
 * makes 10 bursts; combined with concurrency=4 that's 40 bursts of
 * `posts_per_iter` posts racing across 4 processes.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

require_once __DIR__ . '/../bench-lib/shared-helpers.php';

return function (): array {
    static $iter_count = 0;
    static $posts_per_iter = 25;

    $instance = defined('HOMEBOY_BENCH_INSTANCE_ID') ? (int) HOMEBOY_BENCH_INSTANCE_ID : 0;
    $concurrency = defined('HOMEBOY_BENCH_CONCURRENCY') ? (int) HOMEBOY_BENCH_CONCURRENCY : 1;

    mdi_bench_seed(MDI_BENCH_DEFAULT_SEED + 100 + $instance);

    $written = 0;
    $errors = 0;
    $base = $iter_count * $posts_per_iter;

    for ($i = 0; $i < $posts_per_iter; $i++) {
        $seq = $base + $i;
        $id = wp_insert_post([
            'post_title'   => mdi_bench_make_title($seq),
            'post_content' => mdi_bench_make_body($seq),
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_name'    => 'concurrent-i' . $instance . '-' . $seq,
        ], true);
        if (is_wp_error($id) || !$id) {
            $errors++;
        } else {
            $written++;
        }
    }

    // Track per-instance progress in shared state. Lets the audit reconcile
    // "instance N intended to write K posts" against "actual rows in
    // wp_posts with concurrent-iN-* slugs" — gap = lost writes (#47/#70).
    $scratch = mdi_bench_instance_scratch();
    if ($scratch !== '') {
        $log_path = $scratch . '/concurrent-writes.log';
        $line = sprintf(
            "iter=%d instance=%d concurrency=%d written=%d errors=%d ts=%s\n",
            $iter_count,
            $instance,
            $concurrency,
            $written,
            $errors,
            microtime(true)
        );
        file_put_contents($log_path, $line, FILE_APPEND | LOCK_EX);
    }

    $iter_count++;

    return [
        'kind'         => 'concurrent-writers',
        'instance'     => $instance,
        'concurrency'  => $concurrency,
        'written'      => $written,
        'errors'       => $errors,
        'iter'         => $iter_count - 1,
    ];
};
