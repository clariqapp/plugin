<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tools;

/**
 * Tool: get_repurchase_clock
 *
 * Forensic query that computes the natural repurchase interval for repeat
 * buyers. Uses a CTE with the LAG() window function to calculate exact days
 * between sequential checkouts per customer, then aggregates the distribution.
 *
 * Requires MySQL 8.0+ (window function support). WordPress's minimum MySQL
 * requirement is 5.7, but this plugin's constraints specify 8.0.
 */
final class GetRepurchaseClock {

    use ToolHelpers;

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function execute(array $args): array|\WP_Error {
        global $wpdb;

        $start_date = $this->sanitize_date($args['start_date'] ?? null) ?? date('Y-m-d', strtotime('-365 days'));
        $end_date   = $this->sanitize_date($args['end_date']   ?? null) ?? date('Y-m-d');

        $stats_table = $wpdb->prefix . 'wc_order_stats';
        $statuses    = $this->order_status_list();

        // CTE computes per-customer inter-order deltas using the LAG window function.
        // Outer query aggregates across all customers who have ordered more than once.
        $sql = $wpdb->prepare(
            "WITH customer_order_deltas AS (
                SELECT
                    customer_id,
                    date_created,
                    LAG(date_created) OVER (
                        PARTITION BY customer_id
                        ORDER BY date_created ASC
                    ) AS previous_order_date
                FROM {$stats_table}
                WHERE status IN ({$statuses})
                  AND customer_id > 0
                  AND date_created_gmt BETWEEN %s AND %s
            )
            SELECT
                COUNT(DISTINCT customer_id)                             AS loyal_repeat_buyers,
                ROUND(AVG(DATEDIFF(date_created, previous_order_date)), 1)
                                                                        AS avg_days_between_repurchases,
                MIN(DATEDIFF(date_created, previous_order_date))        AS fastest_return_days,
                MAX(DATEDIFF(date_created, previous_order_date))        AS longest_return_days
            FROM customer_order_deltas
            WHERE previous_order_date IS NOT NULL",
            $start_date . ' 00:00:00',
            $end_date   . ' 23:59:59'
        );

        $rows = $this->run_query($sql);
        if (is_wp_error($rows)) {
            return $rows;
        }

        return $this->cast_columns(
            $rows,
            int_cols:   ['loyal_repeat_buyers'],
            float_cols: ['avg_days_between_repurchases', 'fastest_return_days', 'longest_return_days']
        );
    }
}
