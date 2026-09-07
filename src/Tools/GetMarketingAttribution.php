<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tools;

/**
 * Tool 2: get_marketing_attribution
 *
 * Joins wp_wc_orders with wp_wc_orders_meta to surface UTM attribution data.
 * Supports three dimension modes: source_cleaned | campaign_leaderboard | attribution_coverage.
 */
final class GetMarketingAttribution {

    use ToolHelpers;

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function execute(array $args): array|\WP_Error {
        global $wpdb;

        $dimension  = in_array($args['dimension'] ?? '', ['source_cleaned', 'campaign_leaderboard', 'attribution_coverage'], true)
                        ? $args['dimension']
                        : 'source_cleaned';

        $start_date = $this->sanitize_date($args['start_date'] ?? null) ?? date('Y-m-d', strtotime('-30 days'));
        $end_date   = $this->sanitize_date($args['end_date']   ?? null) ?? date('Y-m-d');

        $orders_table = $wpdb->prefix . 'wc_orders';
        $meta_table   = $wpdb->prefix . 'wc_orders_meta';
        $statuses     = $this->order_status_list();

        if ($dimension === 'campaign_leaderboard') {
            $sql = $wpdb->prepare(
                "SELECT
                    c.meta_value                                          AS campaign_id,
                    COUNT(DISTINCT o.id)                                  AS orders,
                    COALESCE(SUM(o.total_amount), 0)                     AS revenue,
                    ROUND(COALESCE(AVG(o.total_amount), 0), 2)           AS aov
                FROM {$orders_table} o
                JOIN {$meta_table} c ON o.id = c.order_id
                WHERE c.meta_key  = '_wc_order_attribution_utm_campaign'
                  AND o.status    IN ({$statuses})
                  AND o.date_created_gmt BETWEEN %s AND %s
                GROUP BY c.meta_value
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

        if ($dimension === 'source_cleaned') {
            $sql = $wpdb->prepare(
                "SELECT
                    CASE
                        WHEN m.meta_value LIKE '%instagram%' OR m.meta_value = 'ig' THEN 'Instagram'
                        WHEN m.meta_value LIKE '%facebook%'  OR m.meta_value = 'fb' THEN 'Facebook'
                        WHEN m.meta_value LIKE '%tiktok%'                            THEN 'TikTok'
                        WHEN m.meta_value LIKE '%google%'                            THEN 'Google'
                        WHEN m.meta_value = '(direct)'                               THEN 'Direct'
                        ELSE 'Other'
                    END                                                   AS clean_source,
                    COUNT(DISTINCT o.id)                                  AS orders,
                    COALESCE(SUM(o.total_amount), 0)                     AS revenue
                FROM {$orders_table} o
                JOIN {$meta_table} m ON o.id = m.order_id
                WHERE m.meta_key  = '_wc_order_attribution_utm_source'
                  AND o.status    IN ({$statuses})
                  AND o.date_created_gmt BETWEEN %s AND %s
                GROUP BY clean_source
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

        // attribution_coverage: what % of orders have UTM data at all.
        $sql = $wpdb->prepare(
            "SELECT
                COUNT(DISTINCT o.id)                                                        AS total_orders,
                COUNT(DISTINCT CASE WHEN m.meta_key = '_wc_order_attribution_utm_source'
                                     AND m.meta_value IS NOT NULL
                                     AND m.meta_value != '' THEN o.id END)                  AS attributed_orders,
                ROUND(
                    COUNT(DISTINCT CASE WHEN m.meta_key = '_wc_order_attribution_utm_source'
                                         AND m.meta_value IS NOT NULL
                                         AND m.meta_value != '' THEN o.id END)
                    / NULLIF(COUNT(DISTINCT o.id), 0) * 100, 2
                )                                                                            AS attribution_rate_pct
            FROM {$orders_table} o
            LEFT JOIN {$meta_table} m ON o.id = m.order_id
              AND m.meta_key = '_wc_order_attribution_utm_source'
            WHERE o.status IN ({$statuses})
              AND o.date_created_gmt BETWEEN %s AND %s",
            $start_date . ' 00:00:00',
            $end_date   . ' 23:59:59'
        );

        $rows = $this->run_query($sql);
        if (is_wp_error($rows)) {
            return $rows;
        }

        return $this->cast_columns($rows, int_cols: ['total_orders', 'attributed_orders'], float_cols: ['attribution_rate_pct']);
    }
}
