<?php
/**
 * Boot timing workload — captures MDI primary cold/warm loader timings.
 *
 * This workload is intentionally paired with run-boot-timing.sh. The script
 * prepares the markdown corpus before WordPress boots, then invokes homeboy
 * bench once per phase against the same shared-state directory.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

require_once __DIR__ . '/../bench-lib/shared-helpers.php';

return function (): array {
    static $iteration = 0;

    $phase = getenv('BENCH_BOOT_PHASE');
    if ($phase === false || trim($phase) === '') {
        return [
            'kind'    => 'boot-timing',
            'skipped' => true,
            'reason'  => 'missing_bench_boot_phase',
        ];
    }

    $shared = mdi_bench_shared_state_path();
    if ($shared === '') {
        return [
            'kind'    => 'boot-timing',
            'skipped' => true,
            'reason'  => 'no_shared_state',
        ];
    }

    $content_dir = getenv('BENCH_BOOT_CONTENT_DIR');
    $content_dir = $content_dir === false || $content_dir === '' ? $shared . '/boot-content' : $content_dir;
    $index_path = getenv('BENCH_BOOT_INDEX_PATH');
    $index_path = $index_path === false || $index_path === '' ? $shared . '/boot-markdown-index.sqlite' : $index_path;

    if (!is_dir($content_dir)) {
        return [
            'kind'        => 'boot-timing',
            'skipped'     => true,
            'reason'      => 'missing_content_dir',
            'content_dir' => $content_dir,
        ];
    }

    if ($phase === 'cold') {
        mdi_bench_unlink_index($index_path);
    }

    $primed = false;
    if ($phase !== 'cold' && !file_exists($index_path)) {
        mdi_bench_run_loader_phase($content_dir, $index_path, 'cold');
        $primed = true;
    }

    if ($phase === 'warm-one-file') {
        mdi_bench_mutate_one_markdown_file($content_dir, $iteration);
    }

    [$loader, $driver, $loader_action] = mdi_bench_run_loader_phase(
        $content_dir,
        $index_path,
        $phase === 'cold' ? 'cold' : 'warm'
    );

    $timings = [];
    foreach ($loader->get_timings() as $name => $seconds) {
        $timings[$name . '_ms'] = round((float) $seconds * 1000, 6);
    }

    $lazy_sample_size = getenv('BENCH_LAZY_SAMPLE_SIZE');
    $lazy_sample_size = $lazy_sample_size === false ? 10 : max(0, (int) $lazy_sample_size);

    $lazy_start = hrtime(true);
    $rows = [];
    if ($lazy_sample_size > 0) {
        $rows = $driver->query(
            "SELECT ID, post_content FROM `wptests_posts` WHERE post_type = 'post' ORDER BY ID ASC LIMIT " . (int) $lazy_sample_size
        );
    }
    $lazy_elapsed_ms = (hrtime(true) - $lazy_start) / 1_000_000;

    $content_bytes = 0;
    foreach ($rows as $row) {
        $content_bytes += strlen((string) ($row->post_content ?? ''));
    }

    $metrics = $timings;
    foreach ($loader->get_stats() as $name => $value) {
        if (is_numeric($value)) {
            $metrics[$name] = (float) $value;
        }
    }

    $metrics['lazy_content_ms'] = round($lazy_elapsed_ms, 6);
    $metrics['lazy_content_rows'] = count($rows);
    $metrics['lazy_content_bytes'] = $content_bytes;
    $metrics['markdown_file_count'] = mdi_bench_count_markdown_files($content_dir);
    $metrics['index_size_bytes'] = $index_path !== '' && file_exists($index_path) ? filesize($index_path) : 0;

    $iteration++;

    return [
        'metrics'  => $metrics,
        'metadata' => [
            'kind'          => 'boot-timing',
            'phase'         => (string) $phase,
            'loader_action' => $loader_action,
            'primed'        => $primed,
            'content_dir'   => $content_dir,
            'index_path'    => $index_path,
            'index_exists'  => $index_path !== '' && file_exists($index_path),
        ],
    ];
};

/**
 * Run the real MDI loader against an isolated index path.
 *
 * @param string $content_dir Markdown content directory.
 * @param string $index_path  SQLite index path.
 * @param string $phase       "cold" or "warm".
 * @return array{WP_Markdown_Loader,WP_Markdown_Driver,string}
 */
function mdi_bench_run_loader_phase(string $content_dir, string $index_path, string $phase): array {
    if (!is_dir(dirname($index_path))) {
        @mkdir(dirname($index_path), 0755, true);
    }

    $storage = new WP_Markdown_Storage($content_dir, []);
    $connection = new WP_SQLite_Connection([
        'path' => $index_path,
    ]);
    $driver = new WP_Markdown_Driver($connection, 'wordpress', $storage);
    $loader = new WP_Markdown_Loader($content_dir, $driver, $storage, 'wptests_');

    if ($phase === 'cold') {
        $loader->load_all();
        return [$loader, $driver, 'load_all'];
    }

    $loader->sync_incremental();
    return [$loader, $driver, 'sync_incremental'];
}

/**
 * Delete a SQLite index and its sidecar files.
 *
 * @param string $index_path SQLite path.
 */
function mdi_bench_unlink_index(string $index_path): void {
    @unlink($index_path);
    @unlink($index_path . '-wal');
    @unlink($index_path . '-shm');
    @unlink($index_path . '-journal');
}

/**
 * Mutate one markdown file so the next warm sync sees exactly one change.
 *
 * @param string $content_dir Markdown content directory.
 * @param int    $iteration   Current workload iteration.
 */
function mdi_bench_mutate_one_markdown_file(string $content_dir, int $iteration): void {
    $file = $content_dir . '/post/bench-1.md';
    if (!file_exists($file)) {
        return;
    }
    file_put_contents(
        $file,
        "\nChanged for warm-one-file iteration {$iteration} at " . sprintf('%.6F', microtime(true)) . ".\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Count markdown files under a content directory.
 *
 * @param string $content_dir Markdown content directory.
 * @return int Number of .md files found.
 */
function mdi_bench_count_markdown_files(string $content_dir): int {
    if ($content_dir === '' || !is_dir($content_dir)) {
        return 0;
    }

    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($content_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
            $count++;
        }
    }
    return $count;
}
