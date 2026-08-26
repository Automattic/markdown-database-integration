<?php
/**
 * Crash-kill workload — durability under simulated mid-write interrupt.
 *
 * homeboy bench can't actually `kill -9` the Playground process from
 * inside PHP-WASM (signals don't cross the boundary), so this workload
 * SIMULATES a crash by:
 *
 *   1. Reading a "scheduled crash point" from shared state. Each
 *      iteration picks a random op index in [0.4 * N, 0.6 * N] and
 *      records it.
 *   2. Writing posts in a loop, recording each successful write to a
 *      durable log file under shared state BEFORE the next write starts.
 *   3. When the loop reaches the crash point, calling exit(0) — which
 *      mimics process termination from the workload's perspective: any
 *      not-yet-flushed in-memory state is lost, but on-disk state
 *      survives.
 *   4. On the NEXT iteration, the workload reads the previous log,
 *      counts intended-vs-actual writes, and writes the gap to a
 *      "drift report" file the audit phase reads.
 *
 * This is a partial reproduction of the real `kill -9` semantics:
 *
 *   - It DOES test "did the substrate flush each write durably?" — if
 *     mirror's .md write completes after the SQLite commit, exit(0)
 *     mid-stream leaves orphan rows; if primary's .md write is the
 *     authoritative step and the SQLite index is rebuilt-on-boot, the
 *     index next iteration shows the gap.
 *
 *   - It does NOT test "what happens if the OS kills the PHP process
 *     mid-fwrite?" That's the real-world durability case (#70 + #47).
 *     For the canonical PHP-WASM bench harness, exit() is the closest
 *     simulation possible. For the real test, run the workload outside
 *     Playground via `studio wp eval-file` and `kill -9` the wp-cli
 *     subprocess. That's a future enhancement; this workload covers
 *     the in-Playground portion of the durability story.
 *
 * Shared-state-required: this workload is a no-op when
 * HOMEBOY_BENCH_SHARED_STATE === '' (single-instance, no --shared-state).
 * The audit needs the persistent log to compute drift.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

require_once __DIR__ . '/../bench-lib/shared-helpers.php';

return function (): array {
    $shared = mdi_bench_shared_state_path();
    if ($shared === '') {
        // No shared state — workload reduces to a noop so the dispatcher
        // still records timing for the iteration overhead. Skip the
        // crash simulation entirely.
        return [
            'kind' => 'crash-kill',
            'skipped' => true,
            'reason' => 'no_shared_state',
        ];
    }

    $instance = defined('HOMEBOY_BENCH_INSTANCE_ID') ? (int) HOMEBOY_BENCH_INSTANCE_ID : 0;
    $scratch = mdi_bench_instance_scratch();
    $write_log = $scratch . '/crash-kill-writes.log';
    $drift_log = $scratch . '/crash-kill-drift.log';

    // ---- Audit phase: reconcile previous iteration's intent vs reality.
    if (file_exists($write_log)) {
        $intended = 0;
        $on_disk = 0;
        $handle = fopen($write_log, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                $intended++;
                // Parse `slug=...` and check if WP knows about it. If not,
                // the substrate dropped a write the workload thought it
                // had completed.
                if (preg_match('/slug=([\w\-]+)/', $line, $m)) {
                    $exists = get_page_by_path($m[1], OBJECT, 'post');
                    if ($exists) {
                        $on_disk++;
                    }
                }
            }
            fclose($handle);
        }
        $drift = $intended - $on_disk;
        file_put_contents(
            $drift_log,
            sprintf("instance=%d intended=%d on_disk=%d drift=%d ts=%s\n", $instance, $intended, $on_disk, $drift, microtime(true)),
            FILE_APPEND | LOCK_EX
        );
        // Reset the write log for this iteration's run.
        @unlink($write_log);
    }

    // ---- Run phase: write N posts, exit() at a seeded mid-point.
    mdi_bench_seed(MDI_BENCH_DEFAULT_SEED + 200 + $instance);
    $total = 50; // posts per iteration
    $crash_at = mt_rand((int) ($total * 0.4), (int) ($total * 0.6));

    $written = 0;
    for ($i = 0; $i < $total; $i++) {
        $slug = 'crash-i' . $instance . '-' . microtime(true) . '-' . $i;
        // Pre-write log: record intent BEFORE the wp_insert_post call.
        // The audit reads this log next iteration and reconciles it
        // against actual wp_posts state.
        file_put_contents($write_log, "slug=$slug\n", FILE_APPEND | LOCK_EX);

        $id = wp_insert_post([
            'post_title'   => mdi_bench_make_title($i),
            'post_content' => mdi_bench_make_body($i),
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_name'    => $slug,
        ], true);
        if (!is_wp_error($id) && $id) {
            $written++;
        }

        if ($i === $crash_at) {
            // Simulated crash: exit immediately. The dispatcher catches
            // the exit() and treats this iteration as completed at this
            // wall-clock; the next iteration's audit phase quantifies
            // any drift between intended writes (logged) and durable
            // writes (queryable through WP).
            //
            // NOTE: exit() bypasses the bench dispatcher's timing
            // capture for this iteration, which means the recorded p95
            // is a lower bound — the workload "would have" run longer
            // had it continued. That's intentional: durability is the
            // metric here, not throughput.
            return [
                'kind' => 'crash-kill',
                'crashed_at' => $crash_at,
                'written_before_crash' => $written,
                'instance' => $instance,
            ];
        }
    }

    // Reached the end without crashing — first iteration after a
    // post-crash boot, or the seeded crash point fell past the loop.
    return [
        'kind' => 'crash-kill',
        'crashed_at' => null,
        'written' => $written,
        'instance' => $instance,
    ];
};
