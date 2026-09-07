<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tools;

/**
 * Shared helpers for all MCP tool handlers.
 */
trait ToolHelpers {

    /**
     * Validate and sanitise a date string (YYYY-MM-DD).
     * Returns null if invalid so the caller can substitute a safe default.
     */
    protected function sanitize_date(?string $value): ?string {
        if (empty($value)) {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $value);
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    /**
     * Returns the ORDER BY-safe status IN clause.
     */
    protected function order_status_list(): string {
        return "'wc-completed','wc-processing','wc-shipped'";
    }

    /**
     * Executes a prepared $wpdb query and returns results as an array of assoc arrays.
     *
     * @param string               $sql   Already-prepared SQL string.
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    protected function run_query(string $sql): array|\WP_Error {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($sql, ARRAY_A);

        if ($wpdb->last_error) {
            return new \WP_Error(
                'mcp_db_error',
                'Database query failed. Check WooCommerce logs for details.',
                ['status' => 500]
            );
        }

        return $results ?: [];
    }

    /**
     * Cast numeric strings returned by MySQL to proper PHP number types.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string[]                         $int_cols
     * @param string[]                         $float_cols
     * @return array<int, array<string, mixed>>
     */
    protected function cast_columns(array $rows, array $int_cols = [], array $float_cols = []): array {
        foreach ($rows as &$row) {
            foreach ($int_cols as $col) {
                if (array_key_exists($col, $row)) {
                    $row[$col] = (int) $row[$col];
                }
            }
            foreach ($float_cols as $col) {
                if (array_key_exists($col, $row)) {
                    $row[$col] = (float) $row[$col];
                }
            }
        }
        return $rows;
    }
}
