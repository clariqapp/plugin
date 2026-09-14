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

        $coupon_table = $wpdb->prefix . 'wc_order_coupon_lookup';
        $stats_table  = $wpdb->prefix . 'wc_order_stats';
        $posts_table  = $wpdb->prefix . 'posts';
        $statuses     = $this->order_status_list();

        // The coupon-usage lookup already holds exactly one row per (order,
        // coupon), so we join it straight to order stats and resolve the coupon
        // code from the coupon post title. We deliberately do NOT join
        // wp_woocommerce_order_items: an order can carry several coupons, and
        // joining item rows to lookup rows on order_id alone yields an N*N
        // cartesian product that double-counts discounts and inflates revenue.
        $sql = $wpdb->prepare(
            "SELECT
                pc.post_title                                               AS coupon_code,
                COUNT(DISTINCT cplook.order_id)                             AS total_usages,
                SUM(cplook.discount_amount)                                 AS total_discount_given,
                SUM(stats.total_sales)                                      AS gross_revenue_generated,
                SUM(stats.net_total)                                        AS true_net_product_revenue,
                ROUND(
                    (SUM(cplook.discount_amount) / NULLIF(SUM(stats.total_sales), 0)) * 100,
                    2
                )                                                           AS margin_drain_percentage
            FROM {$coupon_table} cplook
            JOIN {$posts_table} pc
                ON pc.ID = cplook.coupon_id
               AND pc.post_type = 'shop_coupon'
            JOIN {$stats_table} stats
                ON stats.order_id = cplook.order_id
            WHERE stats.status IN ({$statuses})
              AND stats.date_created_gmt BETWEEN %s AND %s
            GROUP BY pc.post_title
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
