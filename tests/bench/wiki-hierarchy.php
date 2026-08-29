<?php
/**
 * Large hierarchical wiki scan with postmeta exclusion predicates.
 *
 * Mirrors Intelligence's wiki-tree query: project only hierarchy columns,
 * order for rendering, and exclude observation/calendar scaffolding with
 * correlated NOT EXISTS checks.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

return function (): array {
    static $seeded = false;

    global $wpdb;

    $root_slug = 'bench-wiki-hierarchy';
    $root_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s LIMIT 1",
            'wiki',
            $root_slug
        )
    );

    if (!$seeded && $root_id <= 0) {
        $root_id = (int) wp_insert_post([
            'post_type'   => 'wiki',
            'post_status' => 'publish',
            'post_title'  => 'Bench Wiki Hierarchy',
            'post_name'   => $root_slug,
        ]);

        for ($topic = 0; $topic < 20; $topic++) {
            $topic_id = (int) wp_insert_post([
                'post_type'   => 'wiki',
                'post_status' => 'publish',
                'post_parent' => $root_id,
                'post_title'  => sprintf('Bench Wiki Topic %02d', $topic),
                'post_name'   => sprintf('bench-wiki-topic-%02d', $topic),
                'menu_order'  => $topic,
            ]);

            for ($article = 0; $article < 49; $article++) {
                $article_id = (int) wp_insert_post([
                    'post_type'    => 'wiki',
                    'post_status'  => 'publish',
                    'post_parent'  => $topic_id,
                    'post_title'   => sprintf('Bench Wiki Topic %02d Article %02d', $topic, $article),
                    'post_name'    => sprintf('bench-wiki-topic-%02d-article-%02d', $topic, $article),
                    'post_content' => 'Synthetic wiki article for hierarchy query benchmarking.',
                    'menu_order'   => $article,
                ]);

                if (0 === $article % 20) {
                    add_post_meta($article_id, '_intelligence_wiki_observation', '1', true);
                } elseif (1 === $article % 20) {
                    add_post_meta($article_id, '_intelligence_wiki_calendar_parent', '1', true);
                }
            }
        }
    }
    $seeded = true;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.ID, p.post_parent, p.post_name, p.post_title
            FROM {$wpdb->posts} p
            WHERE p.post_type = %s
            AND p.post_status = %s
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} observation_meta
                WHERE observation_meta.post_id = p.ID AND observation_meta.meta_key = %s
            )
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} calendar_meta
                WHERE calendar_meta.post_id = p.ID AND calendar_meta.meta_key = %s
            )
            ORDER BY p.menu_order ASC, p.post_title ASC",
            'wiki',
            'publish',
            '_intelligence_wiki_observation',
            '_intelligence_wiki_calendar_parent'
        )
    );

    if (!is_array($rows) || '' !== (string) $wpdb->last_error) {
        throw new RuntimeException('Wiki hierarchy query failed: ' . (string) $wpdb->last_error);
    }

    return [
        'metrics' => [
            'rows_returned' => count($rows),
            'seeded_posts'   => 1001,
            'excluded_rows'  => 100,
        ],
        'metadata' => [
            'query_shape' => 'posts hierarchy ordered with two correlated postmeta NOT EXISTS predicates',
        ],
    ];
};
