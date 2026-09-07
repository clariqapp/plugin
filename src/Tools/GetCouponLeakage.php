<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tools;

/**
 * Tool: get_coupon_leakage
 *
 * Maps active promotional codes to their discount volumes to surface margin
 * cannibalization. Joins WooCommerce's native coupon analytics tables against
 * order stats to compute the true net revenue impact per coupon.
 */
final class GetCouponLeakage {

    use ToolHelpers;

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function execute(array $args): array|\WP_Error {
        global $wpdb;

        $start_date = $this->sanitize_date($args['start_date'] ?? null) ?? date('Y-m-d', strtotime('-30 days'));
        $end_date   = $this->sanitize_date($args['end_date']   ?? null) ?? date('Y-m-d');
        $limit      = min(max((int) ($args['limit'] ?? 25), 1), 200);

        $items_table  = $wpdb->prefix . 'woocommerce_order_items';
        $coupon_table = $wpdb->prefix . 'wc_order_coupon_lookup';
        $stats_table  = $wpdb->prefix . 'wc_order_stats';
        $statuses     = $this->order_status_list();

        $sql = $wpdb->prepare(
            "SELECT
                cp.order_item_name                                          AS coupon_code,
                COUNT(DISTINCT stats.order_id)                              AS total_usages,
                SUM(cplook.discount_amount)                                 AS total_discount_given,
                SUM(stats.total_sales)                                      AS gross_revenue_generated,
                SUM(stats.net_total)                                        AS true_net_product_revenue,
                ROUND(
                    (SUM(cplook.discount_amount) / NULLIF(SUM(stats.total_sales), 0)) * 100,
                    2
                )                                                           AS margin_drain_percentage
            FROM {$items_table} cp
            JOIN {$coupon_table} cplook
                ON cp.order_id = cplook.order_id
            JOIN {$stats_table} stats
                ON cp.order_id = stats.order_id
            WHERE cp.order_item_type = 'coupon'
              AND stats.status IN ({$statuses})
              AND stats.date_created_gmt BETWEEN %s AND %s
            GROUP BY cp.order_item_name
            ORDER BY total_discount_given DESC
            LIMIT %d",
            $start_date . ' 00:00:00',
            $end_date   . ' 23:59:59',
            $limit
        );

        $rows = $this->run_query($sql);
        if (is_wp_error($rows)) {
            return $rows;
        }

        return $this->cast_columns(
            $rows,
            int_cols:   ['total_usages'],
            float_cols: ['total_discount_given', 'gross_revenue_generated', 'true_net_product_revenue', 'margin_drain_percentage']
        );
    }
}
