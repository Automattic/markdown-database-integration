<?php
/**
 * Post-run integrity audit.
 *
 * After a workload finishes, this audit walks the WordPress side of the
 * substrate (wp_posts) and the on-disk side (markdown files, where
 * applicable) and reports drift:
 *
 *   - expected_posts vs actual_posts: row count vs file count
 *   - orphan_files:  .md files with no matching wp_posts row
 *   - orphan_rows:   wp_posts rows with no .md file (primary mode is the
 *                    hot spot — primary's invariant is "every authoritative
 *                    row has a file")
 *   - meta_drift:    posts where _markdown_file_index disagrees with the
 *                    real file path
 *
 * The audit is intentionally read-only: it never repairs drift, only
 * reports. That keeps it safe to run mid-cell when triaging a regression.
 *
 * Substrate awareness:
 *   - SDI:           file-side checks are skipped (no markdown directory)
 *   - MDI mirror:    both sides checked; orphan_files indicates failed
 *                    mirror writes; orphan_rows indicates rows the mirror
 *                    couldn't materialize
 *   - MDI primary:   both sides checked; orphan_files indicates rebuilt-
 *                    index-misses-row; orphan_rows is the durability
 *                    smoking gun (#70 / #47 class)
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

if (!function_exists('mdi_bench_integrity_audit')) {

    /**
     * Run the audit and return a structured report.
     *
     * @param array $opts {
     *     @type string $markdown_dir Absolute path to the markdown directory.
     *                                 Default: WP_CONTENT_DIR/markdown.
     *     @type string $post_type    Post type to audit. Default 'post'.
     * }
     * @return array Report with keys: expected_posts, actual_posts,
     *               orphan_files, orphan_rows, meta_drift_count, errors.
     */
    function mdi_bench_integrity_audit(array $opts = []): array {
        $markdown_dir = $opts['markdown_dir'] ?? (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/markdown' : '');
        $post_type    = $opts['post_type'] ?? 'post';

        $report = [
            'expected_posts'    => 0,
            'actual_posts'      => 0,
            'orphan_files'      => 0,
            'orphan_rows'       => 0,
            'meta_drift_count'  => 0,
            'errors'            => 0,
            'markdown_dir'      => $markdown_dir,
            'markdown_dir_exists' => is_dir($markdown_dir),
        ];

        // wp_posts side. Use a direct $wpdb query instead of get_posts so
        // we get every published+draft+pending row in one shot — get_posts
        // applies pagination defaults that hide rows past the 5th page in
        // 10k corpora.
        global $wpdb;
        if (!isset($wpdb)) {
            $report['errors']++;
            return $report;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_name, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'trash')",
            $post_type
        ));
        $report['actual_posts'] = is_array($rows) ? count($rows) : 0;

        if (!$report['markdown_dir_exists']) {
            // SDI substrate or pre-write state. Set expected = actual so
            // there's no false drift signal; orphan_* stays 0.
            $report['expected_posts'] = $report['actual_posts'];
            return $report;
        }

        // File side. Scan recursively under markdown_dir/$post_type/. MDI's
        // on-disk layout is wp-content/markdown/<post_type>/<slug>.md.
        $type_dir = $markdown_dir . '/' . $post_type;
        $files = [];
        if (is_dir($type_dir)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($type_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iter as $file) {
                if ($file->isFile() && $file->getExtension() === 'md') {
                    $files[$file->getBasename('.md')] = $file->getPathname();
                }
            }
        }
        $report['expected_posts'] = count($files);

        // Cross-check.
        $row_slugs = [];
        foreach ($rows as $row) {
            $slug = $row->post_name;
            if ($slug === '') {
                continue;
            }
            $row_slugs[$slug] = (int) $row->ID;

            // Meta drift: _markdown_file_index should resolve to a file
            // that exists. Only checked when markdown_dir is populated
            // (i.e. MDI substrates).
            $idx = get_post_meta((int) $row->ID, '_markdown_file_index', true);
            if ($idx !== '' && !file_exists($idx)) {
                $report['meta_drift_count']++;
            }
        }

        foreach ($files as $slug => $path) {
            if (!isset($row_slugs[$slug])) {
                $report['orphan_files']++;
            }
        }
        foreach ($row_slugs as $slug => $id) {
            if (!isset($files[$slug])) {
                $report['orphan_rows']++;
            }
        }

        return $report;
    }
}
