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
 *   - Block serialization cost: MDI round-trips Gutenberg blocks through
 *     html-to-blocks-converter on every write. p95 widening on MDI vs
 *     SDI quantifies that cost.
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
    if ($skip = mdi_bench_skip_if_not_selected('bulk-import')) {
        return $skip;
    }

    $size = mdi_bench_corpus_size();

    // Reset the posts table so iteration-K starts with the same blank
    // slate as iteration-0. Use $wpdb->query to avoid the per-row
    // wp_delete_post cost — bulk import measures inserts, not deletes.
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post')");
    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'post'");

    mdi_bench_seed();
    $imported = 0;
    for ($i = 0; $i < $size; $i++) {
        $id = wp_insert_post([
            'post_title'   => mdi_bench_make_title($i),
            'post_content' => mdi_bench_make_body($i),
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_name'    => 'bulk-' . $i,
        ], true);
        if (!is_wp_error($id) && $id) {
            $imported++;
        }
    }

    return [
        'kind'        => 'bulk-import',
        'corpus_size' => $size,
        'imported'    => $imported,
    ];
};
