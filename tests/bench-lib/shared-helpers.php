<?php
/**
 * Shared helpers for MDI bench workloads.
 *
 * These helpers are loaded by every workload via `require_once` (the
 * Playground bench dispatcher executes each workload file in a fresh
 * `require` so the autoload-once cost is per-workload, not per-iteration).
 *
 * Conventions every workload follows:
 *
 *   - Determinism. All randomness is seeded via mdi_bench_seed(). The
 *     same seed produces the same corpus and the same operation stream
 *     across substrates, which is the whole point of the harness.
 *
 *   - Substrate-agnostic. Workloads NEVER touch markdown files directly,
 *     never query SQLite directly, never `define()` MDI mode. Substrate
 *     selection is the component's db.php concern; workloads only call
 *     WordPress public APIs (wp_insert_post / wp_update_post / etc).
 *
 *   - HOMEBOY_BENCH_* constants. The Playground bench dispatcher defines
 *     these as PHP constants when --shared-state / --concurrency are
 *     passed. Workloads MUST tolerate the no-shared-state case
 *     (HOMEBOY_BENCH_SHARED_STATE === '') — the smoke matrix invokes
 *     workloads without shared state for read-heavy cells.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

if (defined('MDI_BENCH_SHARED_HELPERS_LOADED')) {
    return;
}
define('MDI_BENCH_SHARED_HELPERS_LOADED', true);

/**
 * Default RNG seed. Override per-workload by passing $seed to mdi_bench_seed().
 *
 * Why a constant default instead of mt_srand(0): cross-workload comparisons
 * are clearer when every workload starts from the same arithmetic surface.
 */
if (!defined('MDI_BENCH_DEFAULT_SEED')) {
    define('MDI_BENCH_DEFAULT_SEED', 42);
}

/**
 * Default corpus size when BENCH_CORPUS_SIZE env var is unset.
 *
 * 100 matches the smallest tier in the PLAN.md size table — the smoke
 * matrix runs at this size. Operators bump it via `BENCH_CORPUS_SIZE=10000
 * homeboy bench mdi-bench-primary` for full-vault numbers.
 */
if (!defined('MDI_BENCH_DEFAULT_CORPUS_SIZE')) {
    define('MDI_BENCH_DEFAULT_CORPUS_SIZE', 100);
}

/**
 * Read BENCH_CORPUS_SIZE from environment with safe fallback.
 *
 * Playground passes through the parent shell's env, so
 * `BENCH_CORPUS_SIZE=1000 homeboy bench ...` propagates here. Returns the
 * default constant if unset, malformed, or non-positive.
 */
function mdi_bench_corpus_size(): int {
    $raw = getenv('BENCH_CORPUS_SIZE');
    if ($raw === false || $raw === '') {
        return MDI_BENCH_DEFAULT_CORPUS_SIZE;
    }
    $n = (int) $raw;
    return $n > 0 ? $n : MDI_BENCH_DEFAULT_CORPUS_SIZE;
}

/**
 * Seed mt_rand for deterministic corpus + workload streams.
 *
 * Combines the base seed with the bench instance id so concurrent
 * instances each get a distinct-but-reproducible stream. Without the
 * instance offset, N parallel writers would all generate the same posts
 * and step on each other's IDs.
 */
function mdi_bench_seed(int $seed = MDI_BENCH_DEFAULT_SEED): void {
    $instance = defined('HOMEBOY_BENCH_INSTANCE_ID') ? (int) HOMEBOY_BENCH_INSTANCE_ID : 0;
    mt_srand($seed + $instance * 1000);
}

/**
 * Whether shared-state is mounted for this run.
 *
 * The dispatcher always defines HOMEBOY_BENCH_SHARED_STATE — it's '' when
 * --shared-state wasn't passed, an absolute guest path otherwise. This
 * helper centralizes the empty-string check so workloads aren't repeating
 * the dance.
 */
function mdi_bench_shared_state_path(): string {
    return defined('HOMEBOY_BENCH_SHARED_STATE') ? HOMEBOY_BENCH_SHARED_STATE : '';
}

/**
 * Per-instance scratch directory under shared state.
 *
 * Concurrent-writer + crash-kill workloads need a per-instance subdirectory
 * inside HOMEBOY_BENCH_SHARED_STATE so two instances don't trample each
 * other's tracking files. Returns '' when shared state isn't mounted.
 *
 * Created on demand. Caller is responsible for honouring the empty-string
 * fallback ("no shared state, skip the persistent step").
 */
function mdi_bench_instance_scratch(): string {
    $shared = mdi_bench_shared_state_path();
    if ($shared === '') {
        return '';
    }
    $instance = defined('HOMEBOY_BENCH_INSTANCE_ID') ? (int) HOMEBOY_BENCH_INSTANCE_ID : 0;
    $dir = $shared . '/instance-' . $instance;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Generate a deterministic corpus of N posts using wp_insert_post.
 *
 * Used by workloads that need a populated starting state (obsidian-bursty,
 * read-heavy). Bulk-import is the inverse: it measures generation, so it
 * doesn't pre-seed.
 *
 * Returns the array of post IDs in insertion order. The caller can re-seed
 * mdi_bench_seed() afterwards if it needs to derive operations from a
 * separate stream.
 */
function mdi_bench_seed_corpus(int $size, int $seed = MDI_BENCH_DEFAULT_SEED): array {
    mdi_bench_seed($seed);
    $ids = [];
    for ($i = 0; $i < $size; $i++) {
        $post_id = wp_insert_post([
            'post_title'   => mdi_bench_make_title($i),
            'post_content' => mdi_bench_make_body($i),
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_name'    => 'bench-' . $i,
        ], true);
        if (!is_wp_error($post_id) && $post_id) {
            $ids[] = (int) $post_id;
        }
    }
    return $ids;
}

/**
 * Generate a seeded title (3-8 words from a fixed pool).
 *
 * Pool size is intentionally small (24 words) so the seeded sequence
 * touches every word multiple times across a 100-post corpus. That's
 * realistic for taxonomy/full-text tests where a small vocabulary
 * stresses the index more than a large one.
 */
function mdi_bench_make_title(int $i): string {
    static $pool = [
        'note', 'thought', 'idea', 'plan', 'sketch', 'draft',
        'review', 'log', 'journal', 'meeting', 'todo', 'reading',
        'project', 'system', 'pattern', 'concept', 'reflection', 'analysis',
        'summary', 'outline', 'feedback', 'snippet', 'reference', 'archive',
    ];
    $word_count = 3 + ($i % 6);
    $words = [];
    for ($w = 0; $w < $word_count; $w++) {
        $words[] = $pool[mt_rand(0, count($pool) - 1)];
    }
    $words[0] = ucfirst($words[0]);
    return implode(' ', $words);
}

/**
 * Generate a seeded Gutenberg-block body.
 *
 * Mix of paragraph + heading blocks at varied lengths. The block
 * serialization matters specifically for MDI: writes round-trip through
 * `html-to-blocks-converter` on disk, which is a write-path cost that
 * SDI doesn't pay. Workload results surface this naturally.
 */
function mdi_bench_make_body(int $i): string {
    $paragraph_count = 2 + ($i % 4);
    $blocks = [];

    for ($p = 0; $p < $paragraph_count; $p++) {
        if ($p > 0 && $p % 2 === 0) {
            $blocks[] = "<!-- wp:heading -->\n<h2>Section " . ($p / 2) . "</h2>\n<!-- /wp:heading -->";
        }
        $sentence_count = 2 + mt_rand(0, 4);
        $sentences = [];
        for ($s = 0; $s < $sentence_count; $s++) {
            $sentences[] = mdi_bench_make_sentence();
        }
        $text = implode(' ', $sentences);
        $blocks[] = "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
    }

    return implode("\n\n", $blocks);
}

/**
 * Cheap seeded sentence generator.
 *
 * Returns 6-14 words from a fixed vocabulary. Not Lorem Ipsum — short
 * everyday words so taxonomy and full-text matching exercise multiple
 * hits per query. Punctuation is intentional (period termination only)
 * so MDI's markdown round-trip doesn't introduce escape variance.
 */
function mdi_bench_make_sentence(): string {
    static $vocab = [
        'the', 'note', 'said', 'about', 'system', 'pattern', 'idea',
        'design', 'project', 'morning', 'evening', 'review', 'long',
        'short', 'simple', 'complex', 'every', 'some', 'many',
        'with', 'without', 'inside', 'between', 'over', 'under',
        'plan', 'next', 'action', 'item', 'context', 'detail',
    ];
    $len = 6 + mt_rand(0, 8);
    $words = [];
    for ($i = 0; $i < $len; $i++) {
        $words[] = $vocab[mt_rand(0, count($vocab) - 1)];
    }
    $words[0] = ucfirst($words[0]);
    return implode(' ', $words) . '.';
}

/**
 * Pick a random ID from an array (deterministic given the seed).
 *
 * Used by workloads that select a post to update/delete out of an existing
 * corpus. Returns 0 if the array is empty (callers should treat 0 as
 * "skip this op" rather than fault).
 */
function mdi_bench_pick(array $ids): int {
    $n = count($ids);
    return $n === 0 ? 0 : (int) $ids[mt_rand(0, $n - 1)];
}
