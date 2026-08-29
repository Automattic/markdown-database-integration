<?php
/**
 * Dynamic plugin-table inventory reads and lifecycle upserts.
 *
 * Mirrors Data Machine Code's persisted worktree inventory without loading
 * that plugin: full ordered inventory, repository filtering, exact task
 * lookup, and one REPLACE mutation per iteration.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

return function (): array {
    static $seeded = false;

    global $wpdb;

    $table = $wpdb->prefix . 'bench_worktree_inventory';
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            handle varchar(191) NOT NULL,
            repo varchar(191) NOT NULL DEFAULT '',
            lifecycle_state varchar(64) DEFAULT NULL,
            task_url text DEFAULT NULL,
            owner_run_ref varchar(191) DEFAULT NULL,
            missing_path tinyint(1) NOT NULL DEFAULT 0,
            metadata longtext DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY handle (handle),
            KEY repo (repo),
            KEY lifecycle_state (lifecycle_state),
            KEY missing_path (missing_path)
        )"
    );

    if (!$seeded) {
        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        for ($i = $existing; $i < 500; $i++) {
            $wpdb->insert($table, [
                'handle'          => sprintf('repo-%02d@branch-%04d', $i % 25, $i),
                'repo'            => sprintf('repo-%02d', $i % 25),
                'lifecycle_state' => 0 === $i % 3 ? 'cleanup_eligible' : 'active',
                'task_url'        => 'https://example.com/issues/' . ($i % 100),
                'owner_run_ref'   => 'bench-run-' . $i,
                'missing_path'    => 0 === $i % 10 ? 1 : 0,
                'metadata'        => wp_json_encode(['sequence' => $i, 'source' => 'benchmark']),
                'updated_at'      => '2026-08-29 00:00:00',
            ]);
        }
        $seeded = true;
    }

    $all_rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY handle ASC", ARRAY_A);
    $repo_rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$table} WHERE repo = %s ORDER BY handle ASC", 'repo-07'),
        ARRAY_A
    );
    $task_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE task_url = %s OR LOWER(owner_run_ref) = LOWER(%s) ORDER BY handle ASC LIMIT %d",
            'https://example.com/issues/42',
            'bench-run-42',
            201
        ),
        ARRAY_A
    );

    $sequence = count($all_rows);
    $replaced = $wpdb->replace($table, [
        'handle'          => 'repo-00@benchmark-current',
        'repo'            => 'repo-00',
        'lifecycle_state' => 'active',
        'task_url'        => 'https://example.com/issues/current',
        'owner_run_ref'   => 'bench-current-' . $sequence,
        'missing_path'    => 0,
        'metadata'        => wp_json_encode(['sequence' => $sequence, 'source' => 'benchmark']),
        'updated_at'      => '2026-08-29 00:00:00',
    ]);

    if (!is_array($all_rows) || !is_array($repo_rows) || !is_array($task_rows) || false === $replaced || '' !== (string) $wpdb->last_error) {
        throw new RuntimeException('Plugin-table inventory workload failed: ' . (string) $wpdb->last_error);
    }

    return [
        'metrics' => [
            'inventory_rows' => count($all_rows),
            'repo_rows'      => count($repo_rows),
            'task_rows'      => count($task_rows),
            'replace_result' => (int) $replaced,
        ],
        'metadata' => [
            'query_shape' => 'dynamic plugin table ordered scans, filtered lookup, and REPLACE upsert',
        ],
    ];
};
