<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tools;

/**
 * Tool 3: get_product_analytics
 *
 * Leverages wp_wc_order_product_lookup (the HPOS analytical join table)
 * for variant-level revenue velocity and basket affinity analysis.
 */
final class GetProductAnalytics {

    use ToolHelpers;

    /**
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function execute(array $args): array|\WP_Error {
        global $wpdb;

        $mode = in_array($args['mode'] ?? '', ['catalog_dictionary', 'top_selling_items', 'product_affinities'], true)
                    ? $args['mode']
                    : 'top_selling_items';

        $start_date = $this->sanitize_date($args['start_date'] ?? null) ?? date('Y-m-d', strtotime('-30 days'));
        $end_date   = $this->sanitize_date($args['end_date']   ?? null) ?? date('Y-m-d');
        $limit      = min(max((int) ($args['limit'] ?? 25), 1), 200);

        $lookup_table  = $wpdb->prefix . 'wc_order_product_lookup';
        $orders_table  = $wpdb->prefix . 'wc_orders';
        $posts_table   = $wpdb->prefix . 'posts';
        $statuses      = $this->order_status_list();

        if ($mode === 'top_selling_items') {
            $sql = $wpdb->prepare(
                "SELECT
                    p.product_id,
                    p.variation_id,
                    post.post_title                        AS item_name,
                    SUM(p.product_qty)                    AS total_units_sold,
                    COALESCE(SUM(p.product_net_revenue), 0) AS total_net_revenue
                FROM {$lookup_table} p
                JOIN {$orders_table} o   ON p.order_id = o.id
                JOIN {$posts_table}  post ON (
                    CASE WHEN p.variation_id > 0 THEN p.variation_id ELSE p.product_id END
                ) = post.ID
                WHERE o.status IN ({$statuses})
                  AND o.date_created_gmt BETWEEN %s AND %s
                GROUP BY p.product_id, p.variation_id, post.post_title
                ORDER BY total_net_revenue DESC
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
                int_cols:   ['product_id', 'variation_id', 'total_units_sold'],
                float_cols: ['total_net_revenue']
            );
        }

        if ($mode === 'product_affinities') {
            // Note: no date filter — affinity scores are calculated across all history.
            $sql = $wpdb->prepare(
                "SELECT
                    p1.product_id                  AS item_a_id,
                    post1.post_title               AS item_a_name,
                    p2.product_id                  AS item_b_id,
                    post2.post_title               AS item_b_name,
                    COUNT(*)                       AS times_bought_together
                FROM {$lookup_table} p1
                JOIN {$lookup_table} p2
                    ON p1.order_id = p2.order_id
                   AND p1.product_id < p2.product_id
                JOIN {$posts_table} post1 ON p1.product_id = post1.ID
                JOIN {$posts_table} post2 ON p2.product_id = post2.ID
                GROUP BY item_a_id, item_b_id, item_a_name, item_b_name
                ORDER BY times_bought_together DESC
                LIMIT %d",
                $limit
            );

            $rows = $this->run_query($sql);
            if (is_wp_error($rows)) {
                return $rows;
            }

            return $this->cast_columns(
                $rows,
                int_cols: ['item_a_id', 'item_b_id', 'times_bought_together']
            );
        }

        // catalog_dictionary: enumerate all active products with IDs.
        $sql = $wpdb->prepare(
            "SELECT
                ID                 AS product_id,
                post_title         AS product_name,
                post_type,
                post_status
            FROM {$posts_table}
            WHERE post_type   IN ('product', 'product_variation')
              AND post_status  = 'publish'
            ORDER BY post_title ASC
            LIMIT %d",
            $limit
        );

        $rows = $this->run_query($sql);
        if (is_wp_error($rows)) {
            return $rows;
        }

        return $this->cast_columns($rows, int_cols: ['product_id']);
    }
}
