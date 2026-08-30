<?php
/**
 * Expose a benchmark-only read endpoint with backend and result assertions.
 *
 * @package Markdown_Database_Integration\Tests\Bench
 */

add_action(
    'rest_api_init',
    static function (): void {
        register_rest_route(
            'mdi-bench/v1',
            '/concurrent-read',
            [
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => static function () {
                    global $wpdb;

                    $expected_class = defined('MDI_BENCH_EXPECTED_WPDB_CLASS')
                        ? (string) MDI_BENCH_EXPECTED_WPDB_CLASS
                        : '';
                    $actual_class = is_object($wpdb) ? get_class($wpdb) : gettype($wpdb);
                    if ('' === $expected_class || $expected_class !== $actual_class) {
                        return new WP_Error(
                            'mdi_bench_backend_mismatch',
                            sprintf('Expected %s, received %s.', $expected_class, $actual_class),
                            ['status' => 500]
                        );
                    }

                    $users = $wpdb->get_results(
                        "SELECT ID, user_login FROM {$wpdb->users} ORDER BY user_login ASC LIMIT 10",
                        ARRAY_A
                    );
                    if ('' !== (string) $wpdb->last_error || !is_array($users) || [] === $users) {
                        return new WP_Error(
                            'mdi_bench_query_failed',
                            'The concurrent read query did not return the installed WordPress user.',
                            ['status' => 500]
                        );
                    }

                    foreach ($users as $user) {
                        if (!isset($user['ID'], $user['user_login']) || (int) $user['ID'] < 1 || '' === (string) $user['user_login']) {
                            return new WP_Error(
                                'mdi_bench_invalid_result',
                                'The concurrent read query returned an invalid user row.',
                                ['status' => 500]
                            );
                        }
                    }

                    return new WP_REST_Response(
                        [
                            'backend' => $actual_class,
                            'rows'    => count($users),
                        ],
                        200
                    );
                },
            ]
        );
    }
);
