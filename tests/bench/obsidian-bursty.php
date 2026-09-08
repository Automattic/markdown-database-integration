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
 * place across iterations. The dispatcher retains this workload callable in
 * one PHP process, so its per-process static live-ID set spans warmup and
 * measured iterations. Concurrent variants use concurrent-writers.php.
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
    static $run_sequence = 0;
    static $operation_seed = null;
    static $operation_random_sequence = 0;

    $runtime = mdi_bench_runtime();
    global $wpdb;

    if ($operation_seed === null) {
        $instance = defined('HOMEBOY_BENCH_INSTANCE_ID') ? (int) HOMEBOY_BENCH_INSTANCE_ID : 0;
        $operation_seed = MDI_BENCH_DEFAULT_SEED + 1 + $instance * 1000;
    }
    $next_random = static function (int $min, int $max) use (&$operation_random_sequence, $operation_seed): int {
        $value = unpack('N', hash('sha256', $operation_seed . ':' . $operation_random_sequence++, true))[1];
        return $min + ($value % ($max - $min + 1));
    };

    if ($live_ids === null) {
        // First call — seed the corpus.
        $live_ids = [];
        for ($i = 0; $i < mdi_bench_corpus_size(); $i++) {
            // Reset before each generated post so WordPress insertion cannot
            // perturb the next corpus document's deterministic content.
            mdi_bench_seed(MDI_BENCH_DEFAULT_SEED + $i);
            $post_id = wp_insert_post([
                'post_title'   => mdi_bench_make_title($i),
                'post_content' => mdi_bench_make_body($i),
                'post_status'  => 'publish',
                'post_type'    => 'post',
                'post_name'    => 'bench-' . $i,
            ], true);
            if (is_wp_error($post_id) || !$post_id) {
                throw new RuntimeException('Obsidian-bursty seed failed: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : $wpdb->last_error));
            }
            $live_ids[] = (int) $post_id;
        }
        if (count($live_ids) !== mdi_bench_corpus_size() || count($live_ids) !== count(array_unique($live_ids))) {
            throw new RuntimeException('Obsidian-bursty seed did not create the requested unique corpus.');
        }
        $next_create = mdi_bench_corpus_size();
    }

    if (count($live_ids) !== count(array_unique($live_ids))) {
        throw new RuntimeException('Obsidian-bursty live post IDs are not unique.');
    }

    $initial_live_size = count($live_ids);
    $initial_stored_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post'");
    if ('' !== (string) $wpdb->last_error) {
        throw new RuntimeException('Obsidian-bursty initial row count failed: ' . $wpdb->last_error);
    }

    $counts = ['update' => 0, 'create' => 0, 'rename' => 0, 'reparent' => 0, 'delete' => 0];
    $operation_ns = array_fill_keys(array_keys($counts), 0);
    $plan = hash_init('sha256');
    $profile = '1' === getenv('BENCH_PROFILE');
    if ($profile) {
        if (!class_exists('WP_Markdown_Operation_Profile')) {
            throw new RuntimeException('Requested MDI operation profiling is unavailable.');
        }
        WP_Markdown_Operation_Profile::start();
    }

    try {
        for ($i = 0; $i < $ops_per_iter; $i++) {
            $roll = $next_random(0, 99);
            if ($roll < 70) {
                // 70% update
                $id_index = $next_random(0, count($live_ids) - 1);
                $id = $live_ids[$id_index];
                hash_update($plan, $i . ':update:' . $id_index . "\n");
                $started = hrtime(true);
                $existing = get_post($id);
                $updated = $existing ? wp_update_post([
                    'ID'           => $id,
                    'post_content' => mdi_bench_make_body($id + $i),
                ], true) : new WP_Error('missing_post', 'The selected update post is unavailable.');
                $operation_ns['update'] += hrtime(true) - $started;
                if (is_wp_error($updated) || (int) $updated !== $id) {
                    throw new RuntimeException('Obsidian-bursty update failed: ' . (is_wp_error($updated) ? $updated->get_error_message() : $wpdb->last_error));
                }
                $counts['update']++;
            } elseif ($roll < 90) {
                // 20% create
                hash_update($plan, $i . ':create:' . $next_create . "\n");
                mdi_bench_seed(MDI_BENCH_DEFAULT_SEED + 1000000 + $next_create);
                $started = hrtime(true);
                $new_id = wp_insert_post([
                    'post_title'   => mdi_bench_make_title($next_create),
                    'post_content' => mdi_bench_make_body($next_create),
                    'post_status'  => 'publish',
                    'post_type'    => 'post',
                    'post_name'    => 'bursty-' . $next_create,
                ], true);
                $operation_ns['create'] += hrtime(true) - $started;
                if (is_wp_error($new_id) || !$new_id || in_array((int) $new_id, $live_ids, true)) {
                    throw new RuntimeException('Obsidian-bursty create failed: ' . (is_wp_error($new_id) ? $new_id->get_error_message() : $wpdb->last_error));
                }
                $live_ids[] = (int) $new_id;
                $counts['create']++;
                $next_create++;
            } elseif ($roll < 95) {
                // 5% rename (slug change → file rename in MDI)
                $id_index = $next_random(0, count($live_ids) - 1);
                $id = $live_ids[$id_index];
                $rename_suffix = $next_random(1000, 9999);
                hash_update($plan, $i . ':rename:' . $id_index . ':' . $rename_suffix . "\n");
                $started = hrtime(true);
                $renamed = wp_update_post([
                    'ID'        => $id,
                    'post_name' => 'bursty-' . $id . '-r' . $rename_suffix,
                ], true);
                $operation_ns['rename'] += hrtime(true) - $started;
                if (is_wp_error($renamed) || (int) $renamed !== $id) {
                    throw new RuntimeException('Obsidian-bursty rename failed: ' . (is_wp_error($renamed) ? $renamed->get_error_message() : $wpdb->last_error));
                }
                $counts['rename']++;
            } elseif ($roll < 98) {
                // 3% reparent (post_parent change — exercises the #70 path)
                $id_index = $next_random(0, count($live_ids) - 1);
                $parent_index = $next_random(0, count($live_ids) - 1);
                $id = $live_ids[$id_index];
                $parent_id = $live_ids[$parent_index];
                if ($parent_id === $id || in_array($id, get_post_ancestors($parent_id), true)) {
                    foreach ($live_ids as $candidate_index => $candidate) {
                        if ($candidate !== $id && !in_array($id, get_post_ancestors($candidate), true)) {
                            $parent_id = $candidate;
                            $parent_index = $candidate_index;
                            break;
                        }
                    }
                }
                if ($parent_id === $id || in_array($id, get_post_ancestors($parent_id), true)) {
                    throw new RuntimeException('Obsidian-bursty could not select a valid reparent target.');
                }
                hash_update($plan, $i . ':reparent:' . $id_index . ':' . $parent_index . "\n");
                $started = hrtime(true);
                $reparented = wp_update_post([
                    'ID'          => $id,
                    'post_parent' => $parent_id,
                ], true);
                $operation_ns['reparent'] += hrtime(true) - $started;
                if (is_wp_error($reparented) || (int) $reparented !== $id) {
                    throw new RuntimeException('Obsidian-bursty reparent failed: ' . (is_wp_error($reparented) ? $reparented->get_error_message() : $wpdb->last_error));
                }
                $counts['reparent']++;
            } else {
                // 2% delete
                $id_index = $next_random(0, count($live_ids) - 1);
                $id = $live_ids[$id_index];
                hash_update($plan, $i . ':delete:' . $id_index . "\n");
                $started = hrtime(true);
                $deleted = wp_delete_post($id, true);
                $operation_ns['delete'] += hrtime(true) - $started;
                if (!is_object($deleted) || (int) $deleted->ID !== $id) {
                    throw new RuntimeException('Obsidian-bursty delete failed: ' . $wpdb->last_error);
                }
                $live_ids = array_values(array_filter($live_ids, static fn($x) => $x !== $id));
                $counts['delete']++;
            }
        }
    } finally {
        $operations = $profile ? WP_Markdown_Operation_Profile::stop() : [];
    }

    if ($ops_per_iter !== array_sum($counts)) {
        throw new RuntimeException('Obsidian-bursty did not complete every logical mutation attempt.');
    }
    if (count($live_ids) !== count(array_unique($live_ids))) {
        throw new RuntimeException('Obsidian-bursty mutations produced duplicate live post IDs.');
    }

    $final_stored_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post'");
    $expected_stored_posts = $initial_stored_posts + $counts['create'] - $counts['delete'];
    if ('' !== (string) $wpdb->last_error || $final_stored_posts !== $expected_stored_posts) {
        throw new RuntimeException('Obsidian-bursty persisted post count does not match successful mutations.');
    }

    return [
        'metrics' => array_merge([
            'ops' => $ops_per_iter,
            'initial_live_size' => $initial_live_size,
            'final_live_size' => count($live_ids),
            'initial_stored_posts' => $initial_stored_posts,
            'final_stored_posts' => $final_stored_posts,
            'mix_update' => $counts['update'],
            'mix_create' => $counts['create'],
            'mix_rename' => $counts['rename'],
            'mix_reparent' => $counts['reparent'],
            'mix_delete' => $counts['delete'],
            'update_ms' => $operation_ns['update'] / 1e6,
            'create_ms' => $operation_ns['create'] / 1e6,
            'rename_ms' => $operation_ns['rename'] / 1e6,
            'reparent_ms' => $operation_ns['reparent'] / 1e6,
            'delete_ms' => $operation_ns['delete'] / 1e6,
        ], $operations),
        'metadata' => array_merge($runtime, [
            'kind' => 'obsidian-bursty',
            'run_sequence' => ++$run_sequence,
            'mix' => $counts,
            'initial_live_size' => $initial_live_size,
            'final_live_size' => count($live_ids),
            'operation_profile_enabled' => $profile,
            'operation_profile_scope' => 'mixed mutation loop only; inclusive durations overlap',
            'operation_plan_checksum' => hash_final($plan),
            'query_shapes' => $profile ? WP_Markdown_Operation_Profile::query_shapes() : [],
        ]),
    ];
};
