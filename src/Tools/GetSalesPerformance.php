<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tools;

/**
 * Tool 1: get_sales_performance
 *
 * Targets wp_wc_order_stats (pre-aggregated HPOS analytical table).
 * Supports four interval modes: summary | hourly | daily | monthly.
 */
final class GetSalesPerformance {

    use ToolHelpers;

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function execute(array $args): array|\WP_Error {
        global $wpdb;

        $interval   = in_array($args['interval'] ?? '', ['summary', 'hourly', 'daily', 'monthly'], true)
                        ? $args['interval']
                        : 'summary';

        $start_date = $this->sanitize_date($args['start_date'] ?? null) ?? date('Y-m-d', strtotime('-30 days'));
        $end_date   = $this->sanitize_date($args['end_date']   ?? null) ?? date('Y-m-d');

        $table   = $wpdb->prefix . 'wc_order_stats';
        $statuses = $this->order_status_list();

        if ($interval === 'summary') {
            $sql = $wpdb->prepare(
                "SELECT
                    COUNT(order_id)                               AS total_orders,
                    COALESCE(SUM(total_sales), 0)                AS gross_revenue,
                    COALESCE(SUM(shipping_total), 0)             AS shipping_collected,
                    COALESCE(SUM(tax_total), 0)                  AS taxes_collected,
                    COALESCE(SUM(net_total), 0)                  AS true_net_revenue,
                    ROUND(COALESCE(SUM(total_sales) / NULLIF(COUNT(order_id), 0), 0), 2) AS avg_order_value
                FROM {$table}
                WHERE status IN ({$statuses})
                  AND date_created BETWEEN %s AND %s",
                $start_date . ' 00:00:00',
                $end_date   . ' 23:59:59'
            );
        } else {
            $group_expr = match ($interval) {
                'hourly'  => "DATE_FORMAT(date_created, '%Y-%m-%d %H:00:00')",
                'monthly' => "DATE_FORMAT(date_created, '%Y-%m-01')",
                default   => 'DATE(date_created)',
            };

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare(
                "SELECT
                    {$group_expr}                                 AS time_bucket,
                    COUNT(order_id)                               AS orders,
                    COALESCE(SUM(total_sales), 0)                AS gross_revenue,
                    COALESCE(SUM(net_total), 0)                  AS net_revenue
                FROM {$table}
                WHERE status IN ({$statuses})
                  AND date_created BETWEEN %s AND %s
                GROUP BY time_bucket
                ORDER BY time_bucket ASC",
                $start_date . ' 00:00:00',
                $end_date   . ' 23:59:59'
            );
        }

        $rows = $this->run_query($sql);

        if (is_wp_error($rows)) {
            return $rows;
        }

        return $this->cast_columns(
            $rows,
            int_cols:   ['total_orders', 'orders'],
            float_cols: ['gross_revenue', 'shipping_collected', 'taxes_collected', 'true_net_revenue', 'avg_order_value', 'net_revenue']
        );
    }
}
