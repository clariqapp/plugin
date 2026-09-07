<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tools;

/**
 * Tool 5: get_technical_analytics
 *
 * Parses _wc_order_attribution_user_agent metadata to surface device/OS breakdowns
 * and cross-examines OS vs. payment method conversion patterns.
 */
final class GetTechnicalAnalytics {

    use ToolHelpers;

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function execute(array $args): array|\WP_Error {
        global $wpdb;

        $breakdown  = in_array($args['breakdown'] ?? '', ['device_types', 'os_types', 'os_x_payment'], true)
                        ? $args['breakdown']
                        : 'device_types';

        $start_date = $this->sanitize_date($args['start_date'] ?? null) ?? date('Y-m-d', strtotime('-30 days'));
        $end_date   = $this->sanitize_date($args['end_date']   ?? null) ?? date('Y-m-d');

        $orders_table = $wpdb->prefix . 'wc_orders';
        $meta_table   = $wpdb->prefix . 'wc_orders_meta';
        $statuses     = $this->order_status_list();

        // Shared OS CASE expression.
        $os_case = "CASE
            WHEN m.meta_value LIKE '%Windows%'                             THEN 'Windows'
            WHEN m.meta_value LIKE '%Mac OS%' OR m.meta_value LIKE '%Macintosh%' THEN 'MacOS'
            WHEN m.meta_value LIKE '%Android%'                             THEN 'Android'
            WHEN m.meta_value LIKE '%iPhone%' OR m.meta_value LIKE '%iOS%' THEN 'iOS'
            ELSE 'Other'
        END";

        // Shared device CASE expression (mobile vs. desktop heuristic).
        $device_case = "CASE
            WHEN m.meta_value LIKE '%Mobi%' OR m.meta_value LIKE '%Android%' OR m.meta_value LIKE '%iPhone%' THEN 'Mobile'
            WHEN m.meta_value LIKE '%Tablet%' OR m.meta_value LIKE '%iPad%'  THEN 'Tablet'
            ELSE 'Desktop'
        END";

        if ($breakdown === 'os_x_payment') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare(
                "SELECT
                    {$os_case}                            AS os_type,
                    o.payment_method,
                    o.payment_method_title,
                    COUNT(DISTINCT o.id)                  AS orders,
                    COALESCE(SUM(o.total_amount), 0)     AS revenue
                FROM {$orders_table} o
                JOIN {$meta_table} m ON o.id = m.order_id
                WHERE m.meta_key = '_wc_order_attribution_user_agent'
                  AND o.status IN ({$statuses})
                  AND o.date_created_gmt BETWEEN %s AND %s
                GROUP BY os_type, o.payment_method, o.payment_method_title
                ORDER BY revenue DESC",
                $start_date . ' 00:00:00',
                $end_date   . ' 23:59:59'
            );

            $rows = $this->run_query($sql);
            if (is_wp_error($rows)) {
                return $rows;
            }

            return $this->cast_columns($rows, int_cols: ['orders'], float_cols: ['revenue']);
        }

        $group_case = $breakdown === 'os_types' ? $os_case : $device_case;
        $alias      = $breakdown === 'os_types' ? 'os_type' : 'device_type';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT
                {$group_case}                         AS {$alias},
                COUNT(DISTINCT o.id)                  AS orders,
                COALESCE(SUM(o.total_amount), 0)     AS revenue,
                ROUND(COALESCE(AVG(o.total_amount), 0), 2) AS aov
            FROM {$orders_table} o
            JOIN {$meta_table} m ON o.id = m.order_id
            WHERE m.meta_key = '_wc_order_attribution_user_agent'
              AND o.status IN ({$statuses})
              AND o.date_created_gmt BETWEEN %s AND %s
            GROUP BY {$alias}
            ORDER BY revenue DESC",
            $start_date . ' 00:00:00',
            $end_date   . ' 23:59:59'
        );

        $rows = $this->run_query($sql);
        if (is_wp_error($rows)) {
            return $rows;
        }

        return $this->cast_columns($rows, int_cols: ['orders'], float_cols: ['revenue', 'aov']);
    }
}
