<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tools;

/**
 * Tool: get_inventory_runway
 *
 * Evaluates current stock levels against 30-day sales velocity to surface how
 * many days of runway each SKU has before a stockout.
 *
 * days_of_runway_remaining is null when a product has had zero sales in the
 * last 30 days (no velocity to compute from), float otherwise.
 */
final class GetInventoryRunway {

    use ToolHelpers;

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function execute(array $args): array|\WP_Error {
        global $wpdb;

        $limit = min(max((int) ($args['limit'] ?? 20), 1), 100);

        $lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
        $orders_table = $wpdb->prefix . 'wc_orders';
        $posts_table  = $wpdb->prefix . 'posts';
        $meta_table   = $wpdb->prefix . 'wc_product_meta_lookup';
        $statuses     = $this->order_status_list();

        // Effective product ID: prefer variation_id when set, else use product_id.
        $effective_id = "CASE WHEN p.variation_id > 0 THEN p.variation_id ELSE p.product_id END";

        $sql = $wpdb->prepare(
            "SELECT
                p.product_id,
                p.variation_id,
                post.post_title                             AS item_name,
                look.stock_quantity                         AS current_stock_level,
                SUM(p.product_qty)                          AS units_sold_last_30_days,
                ROUND(SUM(p.product_qty) / 30, 2)          AS daily_sales_velocity,
                CASE
                    WHEN SUM(p.product_qty) = 0 THEN NULL
                    ELSE ROUND(look.stock_quantity / (SUM(p.product_qty) / 30), 1)
                END                                         AS days_of_runway_remaining
            FROM {$lookup_table} p
            JOIN {$orders_table} o
                ON p.order_id = o.id
            JOIN {$posts_table} post
                ON ({$effective_id}) = post.ID
            JOIN {$meta_table} look
                ON ({$effective_id}) = look.product_id
            WHERE o.status IN ({$statuses})
              AND o.date_created_gmt >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY p.product_id, p.variation_id, post.post_title, look.stock_quantity
            ORDER BY daily_sales_velocity DESC
            LIMIT %d",
            $limit
        );

        $rows = $this->run_query($sql);
        if (is_wp_error($rows)) {
            return $rows;
        }

        return $this->cast_columns(
            $rows,
            int_cols:   ['product_id', 'variation_id', 'current_stock_level', 'units_sold_last_30_days'],
            float_cols: ['daily_sales_velocity', 'days_of_runway_remaining']
        );
    }
}
