<?php
/**
 * Read-heavy workload — query mix on a populated corpus.
 *
 * Mix matches PLAN.md: 50% get_post, 20% get_page_by_path, 15% date-range
 * WP_Query, 10% taxonomy WP_Query, 5% full-text-ish (`s=`).
 *
 * Each iteration runs `ops_per_iter` mixed reads against a corpus seeded
 * once on the first iteration. Reads do not mutate the corpus, so iteration
 * K and iteration K+1 hit identical data — read variance is purely query
 * engine + cache behaviour.
 *
 * What this surfaces:
 *
 *   - get_post p50/p95: ID-keyed lookup is the cheapest WP read. SDI sets
 *     the floor; MDI subclassing should add measurable but small cost.
 *   - get_page_by_path: slug index efficiency. MDI primary's in-memory
 *     SQLite has different index characteristics than SDI's on-disk
 *     SQLite — this op type quantifies that.
 *   - Date-range WP_Query: range scan over post_date. p99 widening here
 *     suggests index pressure.
 *   - Taxonomy WP_Query: term + term_relationships join. Both substrates
 *     use the same SQLite engine for taxonomy in primary mode (the JSON
 *     fallback tables); mirror keeps SQLite-native taxonomy.
 *   - Full-text-ish: WP's `s=` lowers to LIKE %term% — MDI primary's
 *     PHP grep backend (#43) is the regression risk. If primary's
 *     full-text p99 is much worse than mirror, the grep backend isn't
 *     scaling.
 *
 * Important: read-heavy DOES NOT seed inside the iteration. Seeding is
 * one-time on the first call (the warmup iteration). That keeps the
 * timing measurement focused on reads, not on a hidden write phase.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

require_once __DIR__ . '/../bench-lib/shared-helpers.php';

return function (): array {
    static $ids = null;
    static $tag_term_ids = null;
    static $ops_per_iter = 100;

    if ($ids === null) {
        mdi_bench_seed();
        $ids = mdi_bench_seed_corpus(mdi_bench_corpus_size());

        // Add a few tags so taxonomy queries have something to match.
        $tag_pool = ['note', 'project', 'idea', 'review', 'todo'];
        $tag_term_ids = [];
        foreach ($tag_pool as $name) {
            $term = wp_insert_term($name, 'post_tag');
            if (!is_wp_error($term)) {
                $tag_term_ids[] = (int) $term['term_id'];
            } else {
                $existing = get_term_by('name', $name, 'post_tag');
                if ($existing) {
                    $tag_term_ids[] = (int) $existing->term_id;
                }
            }
        }
        // Tag a quarter of posts deterministically.
        for ($i = 0; $i < count($ids); $i += 4) {
            wp_set_post_terms($ids[$i], [$tag_pool[$i % count($tag_pool)]], 'post_tag', false);
        }

        mdi_bench_seed(MDI_BENCH_DEFAULT_SEED + 2);
    }

    $counts = ['get_post' => 0, 'by_slug' => 0, 'date_range' => 0, 'taxonomy' => 0, 'search' => 0];

    for ($i = 0; $i < $ops_per_iter; $i++) {
        $roll = mt_rand(0, 99);
        if ($roll < 50) {
            // 50% get_post by ID
            $id = mdi_bench_pick($ids);
            if ($id) {
                get_post($id);
                $counts['get_post']++;
            }
        } elseif ($roll < 70) {
            // 20% by-slug
            $idx = mt_rand(0, count($ids) - 1);
            get_page_by_path('bench-' . $idx, OBJECT, 'post');
            $counts['by_slug']++;
        } elseif ($roll < 85) {
            // 15% date range
            $q = new WP_Query([
                'post_type'      => 'post',
                'posts_per_page' => 20,
                'date_query'     => [['after' => '1 year ago']],
                'no_found_rows'  => true,
            ]);
            wp_reset_postdata();
            $counts['date_range']++;
            unset($q);
        } elseif ($roll < 95) {
            // 10% taxonomy
            $tag = ['note', 'project', 'idea', 'review', 'todo'][mt_rand(0, 4)];
            $q = new WP_Query([
                'post_type'      => 'post',
                'posts_per_page' => 20,
                'tag'            => $tag,
                'no_found_rows'  => true,
            ]);
            wp_reset_postdata();
            $counts['taxonomy']++;
            unset($q);
        } else {
            // 5% search
            $q = new WP_Query([
                'post_type'      => 'post',
                'posts_per_page' => 20,
                's'              => 'note',
                'no_found_rows'  => true,
            ]);
            wp_reset_postdata();
            $counts['search']++;
            unset($q);
        }
    }

    return [
        'kind'        => 'read-heavy',
        'ops'         => $ops_per_iter,
        'corpus_size' => count($ids),
        'mix'         => $counts,
    ];
};
