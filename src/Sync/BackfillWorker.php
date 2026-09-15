<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Sync;

/**
 * Handles historical order backfill via Action Scheduler.
 *
 * Strategy:
 *  - Processes HPOS orders in pages of 250.
 *  - Checks PHP memory headroom before each batch to prevent OOM crashes.
 *  - Stores a cursor offset in wp_options so batches can resume after failures.
 *  - Only active when connection_mode = 'option_a' (warehouse sync).
 */
final class BackfillWorker {

    private const BATCH_SIZE       = 50;
    private const ACTION_HOOK      = 'wc_mcp_backfill_batch';
    private const FORCE_SYNC_HOOK  = 'wc_mcp_force_sync';
    private const GROUP            = 'wc-mcp';
    private const MEMORY_HEADROOM  = 0.80; // Stop if memory usage exceeds 80%.

    public static function register_hooks(): void {
        add_action(self::ACTION_HOOK,     [self::class, 'process_batch']);
        add_action(self::FORCE_SYNC_HOOK, [self::class, 'trigger_force_sync']);
    }

    /**
     * Schedule the initial backfill job if none is pending.
     * Called on plugin activation.
     */
    public static function maybe_schedule_backfill(): void {
        if (!function_exists('as_has_scheduled_action')) {
            return;
        }

        if (as_has_scheduled_action(self::ACTION_HOOK, [], self::GROUP)) {
            return;
        }

        // Reset cursor.
        update_option('wc_mcp_backfill_offset', 0);

        as_enqueue_async_action(self::ACTION_HOOK, [], self::GROUP);
    }

    /**
     * Action Scheduler callback: processes one page of orders and schedules the next.
     */
    public static function process_batch(): void {
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return; // Local Bridge does not need warehouse sync.
        }

        if (!self::memory_ok()) {
            self::log('Memory headroom exhausted — requeueing batch for later.', 'warning');
            as_schedule_single_action(time() + 300, self::ACTION_HOOK, [], self::GROUP);
            return;
        }

        $offset   = (int) get_option('wc_mcp_backfill_offset', 0);
        $range    = (int) get_option('wc_mcp_backfill_range', 12);
        $since    = date('Y-m-d H:i:s', strtotime("-{$range} months"));

        // Free (controlled-backfill) stores are limited to a trailing retention
        // window (wc_mcp_retention_days, set from /v1/stores/me — 30 days on the
        // free tier). Clamp $since forward to that floor so no older history is
        // synced to the warehouse. Paid plans have no cap (option absent → 0).
        $retention_days = (int) get_option('wc_mcp_retention_days', 0);
        if ($retention_days > 0) {
            $floor = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
            if ($floor > $since) {
                $since = $floor;
            }
        }

        // Count total orders on first batch for progress tracking
        $backfill_total = null;
        if ($offset === 0) {
            $backfill_total = self::count_orders($since);
            update_option('wc_mcp_backfill_total', $backfill_total);
        }

        $orders = self::fetch_orders($offset, $since);

        if (empty($orders)) {
            // Backfill complete. Send a completion event to SaaS API.
            $dispatcher = new WebhookDispatcher();
            $dispatcher->dispatch_batch([], $offset, 'backfill_complete');

            delete_option('wc_mcp_backfill_offset');
            update_option('wc_mcp_last_sync_timestamp', time());
            update_option('wc_mcp_backfill_complete', time()); // Timestamp for UI display.
            self::log('Historical backfill complete.', 'info');
            return;
        }

        self::attach_line_items($orders);
        self::attach_order_meta($orders);
        self::attach_coupons($orders);

        $dispatcher = new WebhookDispatcher();
        $result     = $dispatcher->dispatch_batch($orders, $offset, 'backfill_batch', $backfill_total);

        // If the API acknowledged a cursor, use it; otherwise advance locally.
        $new_offset = is_array($result) && isset($result['cursor'])
            ? (int) $result['cursor']
            : $offset + count($orders);

        // Advance cursor.
        update_option('wc_mcp_backfill_offset', $new_offset);
        update_option('wc_mcp_last_sync_timestamp', time());

        // Schedule next page immediately.
        as_enqueue_async_action(self::ACTION_HOOK, [], self::GROUP);
    }

    /**
     * Force sync: resets the cursor and reschedules a full backfill.
     */
    public static function trigger_force_sync(): void {
        update_option('wc_mcp_backfill_offset', 0);
        as_enqueue_async_action(self::ACTION_HOOK, [], self::GROUP);
        self::log('Force sync triggered.', 'info');
    }

    /**
     * Fetch a page of orders from HPOS tables using $wpdb directly.
     * Avoids loading full WC_Order objects to keep memory footprint minimal.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetch_orders(int $offset, string $since): array {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    o.id,
                    o.status,
                    o.date_created_gmt,
                    o.date_updated_gmt,
                    o.total_amount,
                    o.customer_id,
                    o.payment_method,
                    o.payment_method_title,
                    o.transaction_id,
                    os.total_sales,
                    os.shipping_total,
                    os.tax_total,
                    os.net_total,
                    os.returning_customer,
                    os.num_items_sold,
                    COALESCE(sa.city, ba.city)       AS shipping_city,
                    COALESCE(sa.country, ba.country) AS shipping_country,
                    CASE
                        WHEN ba.email IS NOT NULL AND ba.email != ''
                        THEN SHA2(LOWER(TRIM(ba.email)), 256)
                        ELSE NULL
                    END                              AS billing_email_hash
                FROM {$wpdb->prefix}wc_orders o
                LEFT JOIN {$wpdb->prefix}wc_order_stats os ON o.id = os.order_id
                LEFT JOIN {$wpdb->prefix}wc_order_addresses ba
                    ON o.id = ba.order_id AND ba.address_type = 'billing'
                LEFT JOIN {$wpdb->prefix}wc_order_addresses sa
                    ON o.id = sa.order_id AND sa.address_type = 'shipping'
                WHERE o.type = 'shop_order'
                  AND o.status IN ('wc-completed','wc-processing','wc-shipped','wc-refunded','wc-cancelled')
                  AND o.date_created_gmt >= %s
                ORDER BY o.id ASC
                LIMIT %d OFFSET %d",
                $since,
                self::BATCH_SIZE,
                $offset
            ),
            ARRAY_A
        );
        // phpcs:enable

        return $rows ?: [];
    }

    /**
     * Count total orders matching backfill criteria (for progress tracking).
     *
     * @return int Total number of orders to backfill.
     */
    private static function count_orders(string $since): int {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$wpdb->prefix}wc_orders o
                WHERE o.type = 'shop_order'
                  AND o.status IN ('wc-completed','wc-processing','wc-shipped','wc-refunded','wc-cancelled')
                  AND o.date_created_gmt >= %s",
                $since
            )
        );
        // phpcs:enable

        return (int) ($count ?: 0);
    }

    /**
     * Fetch and attach line items for a batch of orders in a single optimized query.
     *
     * @param array<int, array<string, mixed>> $orders Reference to the array of order associative arrays.
     */
    private static function attach_line_items(array &$orders): void {
        if (empty($orders)) {
            return;
        }

        global $wpdb;

        $order_ids = array_map(function($order) {
            return (int) $order['id'];
        }, $orders);

        $order_ids_csv = implode(',', $order_ids);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT 
                oi.order_id,
                oi.order_item_id,
                oi.order_item_name,
                oim.meta_key,
                oim.meta_value
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            WHERE oi.order_id IN ($order_ids_csv)
              AND oi.order_item_type = 'line_item'",
            ARRAY_A
        );
        // phpcs:enable

        $items_by_id = [];
        foreach ($rows as $row) {
            $item_id = (int) $row['order_item_id'];
            if (!isset($items_by_id[$item_id])) {
                $items_by_id[$item_id] = [
                    'order_id'       => (int) $row['order_id'],
                    'product_name'   => $row['order_item_name'],
                    'product_id'     => 0,
                    'variation_id'   => 0,
                    'variation_name' => '',
                    'quantity'       => 0,
                    'total'          => 0.0,
                    'attributes'     => [],
                ];
            }

            $key = $row['meta_key'];
            $val = $row['meta_value'];

            if ($key === '_product_id') {
                $items_by_id[$item_id]['product_id'] = (int) $val;
            } elseif ($key === '_variation_id') {
                $items_by_id[$item_id]['variation_id'] = (int) $val;
            } elseif ($key === '_qty') {
                $items_by_id[$item_id]['quantity'] = (int) $val;
            } elseif ($key === '_line_total') {
                $items_by_id[$item_id]['total'] = (float) $val;
            } elseif (str_starts_with($key, '_') || in_array($key, ['_line_subtotal', '_line_subtotal_tax', '_line_tax', '_tax_class'])) {
                // Skip private/internal keys
            } else {
                $clean_key = str_replace('pa_', '', $key);
                $items_by_id[$item_id]['attributes'][] = ucfirst($clean_key) . ': ' . $val;
            }
        }

        // Resolve parent product names for variation line items
        foreach ($items_by_id as $item_id => &$item_data) {
            if ($item_data['variation_id'] > 0 && $item_data['product_id'] > 0) {
                $parent_product = wc_get_product($item_data['product_id']);
                if ($parent_product) {
                    $item_data['product_name'] = $parent_product->get_name();
                }
            }
        }
        unset($item_data);

        $items_by_order = [];
        foreach ($items_by_id as $item_id => $item_data) {
            $order_id = $item_data['order_id'];
            if (!isset($items_by_order[$order_id])) {
                $items_by_order[$order_id] = [];
            }
            
            $var_name = !empty($item_data['attributes']) ? implode(', ', $item_data['attributes']) : null;

            $items_by_order[$order_id][] = [
                'product_id'     => $item_data['product_id'],
                'variation_id'   => $item_data['variation_id'],
                'product_name'   => $item_data['product_name'],
                'variation_name' => $var_name,
                'quantity'       => $item_data['quantity'],
                'total'          => $item_data['total'],
            ];
        }

        foreach ($orders as &$order) {
            $id = (int) $order['id'];
            $order['line_items'] = $items_by_order[$id] ?? [];
        }
    }

    /**
     * Fetch and attach order attribution metadata for a batch of orders in a single optimized query.
     */
    private static function attach_order_meta(array &$orders): void {
        if (empty($orders)) {
            return;
        }

        global $wpdb;

        $order_ids = array_map(function($order) {
            return (int) $order['id'];
        }, $orders);

        $order_ids_csv = implode(',', $order_ids);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT order_id, meta_key, meta_value
             FROM {$wpdb->prefix}wc_orders_meta
             WHERE order_id IN ($order_ids_csv)
               AND meta_key IN (
                   '_wc_order_attribution_utm_source',
                   '_wc_order_attribution_utm_medium',
                   '_wc_order_attribution_utm_campaign',
                   '_wc_order_attribution_referrer',
                   '_wc_order_attribution_user_agent'
               )",
            ARRAY_A
        );
        // phpcs:enable

        $meta_by_order = [];
        foreach ($rows as $row) {
            $order_id = (int) $row['order_id'];
            if (!isset($meta_by_order[$order_id])) {
                $meta_by_order[$order_id] = [
                    'utm_source'   => null,
                    'utm_medium'   => null,
                    'utm_campaign' => null,
                    'referrer'     => null,
                    'user_agent'   => null,
                ];
            }

            $key = $row['meta_key'];
            $val = $row['meta_value'];

            if ($key === '_wc_order_attribution_utm_source') {
                $meta_by_order[$order_id]['utm_source'] = $val;
            } elseif ($key === '_wc_order_attribution_utm_medium') {
                $meta_by_order[$order_id]['utm_medium'] = $val;
            } elseif ($key === '_wc_order_attribution_utm_campaign') {
                $meta_by_order[$order_id]['utm_campaign'] = $val;
            } elseif ($key === '_wc_order_attribution_referrer') {
                $meta_by_order[$order_id]['referrer'] = $val;
            } elseif ($key === '_wc_order_attribution_user_agent') {
                $meta_by_order[$order_id]['user_agent'] = $val;
            }
        }

        foreach ($orders as &$order) {
            $id = (int) $order['id'];
            $meta = $meta_by_order[$id] ?? [
                'utm_source'   => null,
                'utm_medium'   => null,
                'utm_campaign' => null,
                'referrer'     => null,
                'user_agent'   => null,
            ];
            $order['utm_source']   = $meta['utm_source'];
            $order['utm_medium']   = $meta['utm_medium'];
            $order['utm_campaign'] = $meta['utm_campaign'];
            $order['referrer']     = $meta['referrer'];
            $order['user_agent']   = $meta['user_agent'];
        }
    }

    /**
     * Fetch and attach applied coupons for a batch of orders in a single optimized query.
     */
    private static function attach_coupons(array &$orders): void {
        if (empty($orders)) {
            return;
        }

        global $wpdb;

        $order_ids = array_map(function($order) {
            return (int) $order['id'];
        }, $orders);

        $order_ids_csv = implode(',', $order_ids);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT l.order_id, p.post_title AS coupon_code, l.discount_amount
             FROM {$wpdb->prefix}wc_order_coupon_lookup l
             JOIN {$wpdb->posts} p ON l.coupon_id = p.ID
             WHERE l.order_id IN ($order_ids_csv)",
            ARRAY_A
        );
        // phpcs:enable

        $coupons_by_order = [];
        foreach ($rows as $row) {
            $order_id = (int) $row['order_id'];
            if (!isset($coupons_by_order[$order_id])) {
                $coupons_by_order[$order_id] = [];
            }
            $coupons_by_order[$order_id][] = [
                'coupon_code'     => $row['coupon_code'],
                'discount_amount' => (float) $row['discount_amount'],
            ];
        }

        foreach ($orders as &$order) {
            $id = (int) $order['id'];
            $order['coupons'] = $coupons_by_order[$id] ?? [];
        }
    }

    /**
     * Returns true if PHP memory usage is below the safety threshold.
     */
    private static function memory_ok(): bool {
        $limit = self::parse_memory_limit(ini_get('memory_limit'));
        if ($limit <= 0) {
            return true; // No limit set — proceed.
        }
        return (memory_get_usage(true) / $limit) < self::MEMORY_HEADROOM;
    }

    private static function parse_memory_limit(string $val): int {
        $val  = strtolower(trim($val));
        $last = $val[-1] ?? '';
        $num  = (int) $val;

        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    private static function log(string $message, string $level = 'info'): void {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'wc-analytics-mcp']);
        }
    }
}
