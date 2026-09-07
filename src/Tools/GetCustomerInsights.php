<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tools;

/**
 * Tool 4: get_customer_insights
 *
 * Geo-level demographic analysis and retention cohort breakdown.
 * PII guardrail: city + country only — no street addresses or personal identifiers.
 */
final class GetCustomerInsights {

    use ToolHelpers;

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function execute(array $args): array|\WP_Error {
        global $wpdb;

        $dimension  = in_array($args['dimension'] ?? '', ['geography', 'retention_cohorts'], true)
                        ? $args['dimension']
                        : 'geography';

        $start_date = $this->sanitize_date($args['start_date'] ?? null) ?? date('Y-m-d', strtotime('-30 days'));
        $end_date   = $this->sanitize_date($args['end_date']   ?? null) ?? date('Y-m-d');
        $limit      = min(max((int) ($args['limit'] ?? 20), 1), 200);

        $orders_table    = $wpdb->prefix . 'wc_orders';
        $addresses_table = $wpdb->prefix . 'wc_order_addresses';
        $statuses        = $this->order_status_list();

        if ($dimension === 'geography') {
            $sql = $wpdb->prepare(
                "SELECT
                    a.city,
                    a.country,
                    COUNT(DISTINCT o.id)                  AS orders,
                    COALESCE(SUM(o.total_amount), 0)     AS revenue,
                    ROUND(COALESCE(AVG(o.total_amount), 0), 2) AS aov
                FROM {$orders_table} o
                JOIN {$addresses_table} a ON o.id = a.order_id
                WHERE a.address_type = 'shipping'
                  AND o.status IN ({$statuses})
                  AND o.date_created_gmt BETWEEN %s AND %s
                GROUP BY a.city, a.country
                ORDER BY revenue DESC
                LIMIT %d",
                $start_date . ' 00:00:00',
                $end_date   . ' 23:59:59',
                $limit
            );

            $rows = $this->run_query($sql);
            if (is_wp_error($rows)) {
                return $rows;
            }

            return $this->cast_columns($rows, int_cols: ['orders'], float_cols: ['revenue', 'aov']);
        }

        // retention_cohorts
        $sql = $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN cs.order_count = 1 THEN 1 ELSE 0 END)  AS first_time_buyers,
                SUM(CASE WHEN cs.order_count > 1 THEN 1 ELSE 0 END)  AS repeat_customers,
                COUNT(*)                                               AS total_unique_customers
            FROM (
                SELECT o.customer_id, COUNT(o.id) AS order_count
                FROM {$orders_table} o
                WHERE o.status IN ({$statuses})
                  AND o.date_created_gmt BETWEEN %s AND %s
                GROUP BY o.customer_id
            ) AS cs",
            $start_date . ' 00:00:00',
            $end_date   . ' 23:59:59'
        );

        $rows = $this->run_query($sql);
        if (is_wp_error($rows)) {
            return $rows;
        }

        return $this->cast_columns($rows, int_cols: ['first_time_buyers', 'repeat_customers', 'total_unique_customers']);
    }
}
