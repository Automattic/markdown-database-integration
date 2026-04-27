<?php
/**
 * Obsidian-bursty workload — the target use case.
 *
 * Mix matches PLAN.md: 70% update / 20% create / 5% rename / 3% reparent
 * / 2% delete on a small corpus. Each iteration runs `ops_per_iter`
 * mixed operations against the same persistent corpus, so the dispatcher's
 * per-iteration metric is "wall time for one burst of edits."
 *
 * Why this shape matters:
 *
 *   - Updates dominate (70%). MDI's mirror write-path (HTML → markdown
 *     → file) is the per-edit cost. p50 directly measures it.
 *   - Renames (5%) and reparents (3%) exercise the parent-promotion +
 *     file-rename code that issue #70 fixed. The crash-kill workload
 *     is the durability counterpart; this one is the steady-state cost.
 *   - Creates (20%) keep the corpus growing — by iter K, the corpus is
 *     larger than it was at iter 0, which mirrors actual vault growth.
 *   - Deletes (2%) trim the tail without dominating.
 *
 * Corpus management: seeded once on the first iteration, then mutated in
 * place across iterations. We track the live ID set in shared state when
 * available, fall back to a per-process static otherwise. Static fallback
 * means single-instance runs work; the shared-state path is only needed
 * for concurrent variants (which use concurrent-writers.php anyway, not
 * this workload).
 *
 * Iterations are NOT independent. Iteration K's corpus is iteration K-1
 * plus 20% creates minus 2% deletes. That's intentional — burst-on-burst
 * is the realistic shape. The warmup iteration the dispatcher discards
 * also seeds the corpus, so timing iterations all run against an already-
 * populated state.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

require_once __DIR__ . '/../bench-lib/shared-helpers.php';

return function (): array {
    static $live_ids = null;
    static $next_create = 0;
    static $ops_per_iter = 50; // 50 mixed ops per dispatcher iteration

    if ($live_ids === null) {
        // First call — seed the corpus.
        mdi_bench_seed();
        $live_ids = mdi_bench_seed_corpus(mdi_bench_corpus_size());
        $next_create = mdi_bench_corpus_size();
        // Re-seed AFTER corpus generation so the operation stream is
        // independent of the corpus stream (otherwise a different corpus
        // size would shift every operation choice).
        mdi_bench_seed(MDI_BENCH_DEFAULT_SEED + 1);
    }

    // Bail cleanly when the corpus is empty so substrate failures
    // surface as "skipped: empty_corpus" instead of 50 silent no-ops
    // that look like a successful but suspiciously fast iteration.
    if ($skip = mdi_bench_corpus_check($live_ids, 'obsidian-bursty')) {
        return $skip;
    }

    $counts = ['update' => 0, 'create' => 0, 'rename' => 0, 'reparent' => 0, 'delete' => 0];

    for ($i = 0; $i < $ops_per_iter; $i++) {
        $roll = mt_rand(0, 99);
        if ($roll < 70) {
            // 70% update
            $id = mdi_bench_pick($live_ids);
            if ($id) {
                $existing = get_post($id);
                if ($existing) {
                    wp_update_post([
                        'ID'           => $id,
                        'post_content' => mdi_bench_make_body($id + $i),
                    ]);
                    $counts['update']++;
                }
            }
        } elseif ($roll < 90) {
            // 20% create
            $new_id = wp_insert_post([
                'post_title'   => mdi_bench_make_title($next_create),
                'post_content' => mdi_bench_make_body($next_create),
                'post_status'  => 'publish',
                'post_type'    => 'post',
                'post_name'    => 'bursty-' . $next_create,
            ], true);
            if (!is_wp_error($new_id) && $new_id) {
                $live_ids[] = (int) $new_id;
                $counts['create']++;
            }
            $next_create++;
        } elseif ($roll < 95) {
            // 5% rename (slug change → file rename in MDI)
            $id = mdi_bench_pick($live_ids);
            if ($id) {
                wp_update_post([
                    'ID'        => $id,
                    'post_name' => 'bursty-' . $id . '-r' . mt_rand(1000, 9999),
                ]);
                $counts['rename']++;
            }
        } elseif ($roll < 98) {
            // 3% reparent (post_parent change — exercises the #70 path)
            $id = mdi_bench_pick($live_ids);
            $parent_id = mdi_bench_pick($live_ids);
            if ($id && $parent_id && $id !== $parent_id) {
                wp_update_post([
                    'ID'          => $id,
                    'post_parent' => $parent_id,
                ]);
                $counts['reparent']++;
            }
        } else {
            // 2% delete
            $id = mdi_bench_pick($live_ids);
            if ($id) {
                wp_delete_post($id, true);
                $live_ids = array_values(array_filter($live_ids, function ($x) use ($id) { return $x !== $id; }));
                $counts['delete']++;
            }
        }
    }

    return [
        'kind'      => 'obsidian-bursty',
        'ops'       => $ops_per_iter,
        'live_size' => count($live_ids),
        'mix'       => $counts,
    ];
};
