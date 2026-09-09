<?php
/**
 * Bulk import workload — sustained write throughput from empty.
 *
 * The "switching from Obsidian" moment. Each iteration starts from a
 * cleaned-out posts table and inserts BENCH_CORPUS_SIZE posts in a tight
 * `wp_insert_post` loop. The dispatcher's per-iteration p50/p95/p99 are
 * therefore "wall time to import the full corpus" — substrate cost shows
 * up directly as iteration time.
 *
 * Why empty between iterations: bulk-import semantics ("from zero to N
 * posts") are meaningless if iteration K+1 starts on the corpus iteration
 * K left behind. Without the reset, every substrate gradually picks up
 * an O(N²) curve from accumulated rows.
 *
 * What this surfaces:
 *
 *   - Throughput curve: degradation as corpus grows is visible in
 *     p99 vs p50 (the last few inserts at size N tail-spike).
 *   - Storage mirror cost: MDI writes each post row to SQLite and mirrors
 *     post_content bytes to disk. p95 widening on MDI vs SDI quantifies
 *     the persistence overhead.
 *   - Disk-write pressure: not measured directly here (homeboy bench
 *     reports peak_bytes only). Read off `du -sh` via the harness when
 *     it matters.
 *
 * Iteration count: respect HOMEBOY_BENCH_ITERATIONS (default 10). Two
 * caveats:
 *   - At BENCH_CORPUS_SIZE=10000 and iterations=10, the cell wall-clocks
 *     to roughly N * iters * single-insert time. Sub-second per insert
 *     on SDI means tens of minutes per cell. Drop --iterations for big
 *     corpora.
 *   - The first iteration is the warmup the dispatcher discards. That's
 *     useful for autoload + OPcache, less so for the empty-table reset
 *     cost — that runs every iteration.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

require_once __DIR__ . '/../bench-lib/shared-helpers.php';

return function (): array {
    $runtime = mdi_bench_runtime();

    $size = mdi_bench_corpus_size();

    // Reset the posts table so iteration-K starts with the same blank
    // slate as iteration-0. Use $wpdb->query to avoid the per-row
    // wp_delete_post cost — bulk import measures inserts, not deletes.
    global $wpdb;
    $reset_started = hrtime(true);
    foreach ([
        "DELETE FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post')",
        "DELETE FROM {$wpdb->posts} WHERE post_type = 'post'",
    ] as $sql) {
        if (false === $wpdb->query($sql)) {
            throw new RuntimeException('Bulk import reset failed: ' . $wpdb->last_error);
        }
    }
    $reset_ms = (hrtime(true) - $reset_started) / 1e6;

    mdi_bench_seed();
    $imported = 0;
    $generation_ns = 0;
    $insert_ns = 0;
    $profile = '1' === getenv('BENCH_PROFILE');
    if ($profile) {
        if (!class_exists('WP_Markdown_Operation_Profile')) {
            throw new RuntimeException('Requested MDI operation profiling is unavailable.');
        }
        WP_Markdown_Operation_Profile::start();
    }
    try {
        for ($i = 0; $i < $size; $i++) {
            $started = hrtime(true);
            $post = [
                'post_title'   => mdi_bench_make_title($i),
                'post_content' => mdi_bench_make_body($i),
                'post_status'  => 'publish',
                'post_type'    => 'post',
                'post_name'    => 'bulk-' . $i,
            ];
            $generation_ns += hrtime(true) - $started;
            $started = hrtime(true);
            $id = wp_insert_post($post, true);
            $insert_ns += hrtime(true) - $started;
            if (is_wp_error($id) || !$id) {
                throw new RuntimeException('Bulk import INSERT failed: ' . (is_wp_error($id) ? $id->get_error_message() : $wpdb->last_error));
            }
            $imported++;
        }
    } finally {
        $operations = $profile ? WP_Markdown_Operation_Profile::stop() : [];
    }

    $started = hrtime(true);
    $stored = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post'");
    if ('' !== (string) $wpdb->last_error || $stored !== $size || $imported !== $size) {
        throw new RuntimeException('Bulk import did not persist the requested corpus.');
    }
    $verification_ms = (hrtime(true) - $started) / 1e6;

    return [
        'metrics' => array_merge([
            'corpus_size' => $size,
            'imported' => $imported,
            'stored_posts' => $stored,
            'reset_ms' => $reset_ms,
            'generation_ms' => $generation_ns / 1e6,
            'insert_ms' => $insert_ns / 1e6,
            'verification_ms' => $verification_ms,
        ], $operations),
        'metadata' => array_merge($runtime, [
            'operation_profile_enabled' => $profile,
            'query_shapes' => $profile ? WP_Markdown_Operation_Profile::query_shapes() : [],
            'operation_profile_scope' => 'insert loop only; inclusive durations overlap; manifest advance excludes consumer work',
        ]),
    ];
};
