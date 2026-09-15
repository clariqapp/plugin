<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tools;

/**
 * Tool 4: get_customer_insights
 *
 * Geo-level demographic analysis, retention cohorts, and repurchase cadence.
 * The 'repurchase_intervals' dimension folds in the former get_repurchase_clock
 * tool (repeat-buyer inter-order timing) so all customer-retention views live
 * under a single tool.
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

        $dimension  = in_array($args['dimension'] ?? '', ['geography', 'retention_cohorts', 'repurchase_intervals'], true)
                        ? $args['dimension']
                        : 'geography';

        // Repurchase cadence looks over a longer horizon by default (a customer's
        // natural reorder cycle is often quarterly+), so it defaults to 365 days;
        // the other views keep the 30-day default.
        $default_lookback = $dimension === 'repurchase_intervals' ? '-365 days' : '-30 days';

        $start_date = $this->sanitize_date($args['start_date'] ?? null) ?? date('Y-m-d', strtotime($default_lookback));
        $end_date   = $this->sanitize_date($args['end_date']   ?? null) ?? date('Y-m-d');
        $limit      = min(max((int) ($args['limit'] ?? 20), 1), 200);

        if ($dimension === 'repurchase_intervals') {
            return $this->repurchase_intervals($start_date, $end_date);
        }

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
        // Only registered customers can be tracked across orders. Guest orders
        // all carry customer_id = 0, so including them would collapse every
        // guest into a single synthetic "customer" and badly skew the split —
        // hence the customer_id > 0 filter (matches GetRepurchaseClock).
        $sql = $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN cs.order_count = 1 THEN 1 ELSE 0 END)  AS first_time_buyers,
                SUM(CASE WHEN cs.order_count > 1 THEN 1 ELSE 0 END)  AS repeat_customers,
                COUNT(*)                                               AS total_unique_customers
            FROM (
                SELECT o.customer_id, COUNT(o.id) AS order_count
                FROM {$orders_table} o
                WHERE o.status IN ({$statuses})
                  AND o.customer_id > 0
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

    /**
     * 'repurchase_intervals' dimension — repeat-buyer reorder cadence.
     *
     * Forensic query that computes the natural repurchase interval for repeat
     * buyers. Uses a CTE with the LAG() window function to calculate exact days
     * between sequential checkouts per customer, then aggregates the distribution.
     *
     * Requires MySQL 8.0+ (window function support). WordPress's minimum MySQL
     * requirement is 5.7, but this plugin's constraints specify 8.0. (Folded in
     * from the former standalone get_repurchase_clock tool.)
     *
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    private function repurchase_intervals(string $start_date, string $end_date): array|\WP_Error {
        global $wpdb;

        // LAG()/CTE require MySQL 8.0+ or MariaDB 10.2+. Guard so older MySQL 5.7
        // installs get a clear message instead of an opaque SQL syntax error.
        // Fail open: only block when we positively identify pre-8.0 MySQL, so
        // MariaDB and unidentifiable servers are never wrongly rejected.
        $server_info = strtolower((string) $wpdb->db_server_info());
        $db_version  = $wpdb->db_version();
        $is_mariadb  = strpos($server_info, 'mariadb') !== false;
        if (!$is_mariadb && $db_version && version_compare($db_version, '8.0', '<')) {
            return new \WP_Error(
                'mcp_unsupported_db',
                "get_customer_insights dimension 'repurchase_intervals' requires MySQL 8.0+ or MariaDB 10.2+ (window functions).",
                ['status' => 501]
            );
        }

        $stats_table = $wpdb->prefix . 'wc_order_stats';
        $statuses    = $this->order_status_list();

        // CTE computes per-customer inter-order deltas using the LAG window function.
        // Outer query aggregates across all customers who have ordered more than once.
        // Guest orders all carry customer_id = 0, so the customer_id > 0 filter keeps
        // them from collapsing into one synthetic "customer" (matches retention_cohorts).
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
