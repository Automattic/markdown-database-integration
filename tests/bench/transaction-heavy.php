<?php
/**
 * Transaction-heavy plugin-table writes with commit and rollback assertions.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

require_once __DIR__ . '/../bench-lib/shared-helpers.php';

return function (): array {
    static $run_id = 0;

    global $wpdb;
    $runtime = mdi_bench_runtime();
    $table = $wpdb->prefix . 'bench_transactions';

    $schema_started = hrtime(true);
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            transaction_id bigint(20) unsigned NOT NULL,
            sequence_no int(11) unsigned NOT NULL,
            state varchar(32) NOT NULL DEFAULT 'inserted',
            payload longtext DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY operation (run_id, transaction_id, sequence_no),
            KEY run_id (run_id)
        )"
    );
    if ('' !== (string) $wpdb->last_error) {
        throw new RuntimeException('Transaction-heavy schema setup failed: ' . (string) $wpdb->last_error);
    }
    $schema_duration_ns = hrtime(true) - $schema_started;

    $run_id++;
    $transactions = 20;
    $writes_per_transaction = 6;
    $committed_transactions = 0;
    $rolled_back_transactions = 0;
    $transaction_duration_ns = 0;
    $insert_duration_ns = 0;
    $update_duration_ns = 0;

    for ($transaction_id = 0; $transaction_id < $transactions; $transaction_id++) {
        $transaction_started = hrtime(true);
        if (false === $wpdb->query('START TRANSACTION')) {
            throw new RuntimeException('Transaction-heavy workload could not start a transaction: ' . (string) $wpdb->last_error);
        }
        $transaction_duration_ns += hrtime(true) - $transaction_started;

        for ($sequence = 0; $sequence < $writes_per_transaction; $sequence++) {
            $insert_started = hrtime(true);
            $inserted = $wpdb->insert($table, [
                'run_id'         => $run_id,
                'transaction_id' => $transaction_id,
                'sequence_no'    => $sequence,
                'state'          => 'inserted',
                'payload'        => wp_json_encode([
                    'run'         => $run_id,
                    'transaction' => $transaction_id,
                    'sequence'    => $sequence,
                ]),
            ]);
            $insert_duration_ns += hrtime(true) - $insert_started;
            if (false === $inserted) {
                $wpdb->query('ROLLBACK');
                throw new RuntimeException('Transaction-heavy insert failed: ' . (string) $wpdb->last_error);
            }
        }

        $update_started = hrtime(true);
        $updated = $wpdb->update(
            $table,
            ['state' => 'updated'],
            ['run_id' => $run_id, 'transaction_id' => $transaction_id, 'sequence_no' => 0]
        );
        $update_duration_ns += hrtime(true) - $update_started;
        if (1 !== $updated) {
            $wpdb->query('ROLLBACK');
            throw new RuntimeException('Transaction-heavy update did not affect exactly one row: ' . (string) $wpdb->last_error);
        }

        if (0 === $transaction_id % 4) {
            $transaction_started = hrtime(true);
            if (false === $wpdb->query('ROLLBACK')) {
                throw new RuntimeException('Transaction-heavy rollback failed: ' . (string) $wpdb->last_error);
            }
            $transaction_duration_ns += hrtime(true) - $transaction_started;
            $rolled_back_transactions++;
        } else {
            $transaction_started = hrtime(true);
            if (false === $wpdb->query('COMMIT')) {
                throw new RuntimeException('Transaction-heavy commit failed: ' . (string) $wpdb->last_error);
            }
            $transaction_duration_ns += hrtime(true) - $transaction_started;
            $committed_transactions++;
        }
    }

    $verification_started = hrtime(true);
    $committed_rows = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE run_id = %d", $run_id)
    );
    $updated_rows = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE run_id = %d AND state = %s", $run_id, 'updated')
    );
    $rolled_back_rows = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE run_id = %d AND transaction_id = %d", $run_id, 0)
    );
    $verification_duration_ns = hrtime(true) - $verification_started;
    $expected_rows = $committed_transactions * $writes_per_transaction;

    if ('' !== (string) $wpdb->last_error
        || $expected_rows !== $committed_rows
        || $committed_transactions !== $updated_rows
        || 0 !== $rolled_back_rows
    ) {
        throw new RuntimeException('Transaction-heavy workload returned incorrect commit or rollback state.');
    }

    return [
        'metrics' => [
            'transactions'             => $transactions,
            'writes'                   => $transactions * $writes_per_transaction,
            'committed_transactions'   => $committed_transactions,
            'rolled_back_transactions' => $rolled_back_transactions,
            'committed_rows'           => $committed_rows,
            'updated_rows'             => $updated_rows,
            'schema_duration_ms'       => $schema_duration_ns / 1e6,
            'transaction_duration_ms'  => $transaction_duration_ns / 1e6,
            'insert_duration_ms'       => $insert_duration_ns / 1e6,
            'update_duration_ms'       => $update_duration_ns / 1e6,
            'verification_duration_ms' => $verification_duration_ns / 1e6,
        ],
        'metadata' => [
            'query_shape' => 'plugin-table transactions with inserts, updates, commits, rollbacks, and verified final state',
            'backend'     => $runtime['backend'],
            'wpdb_class'  => $runtime['wpdb_class'],
        ],
    ];
};
