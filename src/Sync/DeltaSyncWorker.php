<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Sync;

/**
 * Handles the daily scheduled delta sync for Cloud Sync mode (Option A).
 *
 * Complements the real-time WebhookDispatcher by catching any orders that
 * were missed by hooks (3rd-party imports, direct DB writes, gateway quirks).
 *
 * Strategy:
 *  - Runs once per day at a merchant-configurable hour (default: 2 AM site time).
 *  - Fetches orders modified since wc_mcp_last_sync_timestamp.
 *  - Processes in 250-row pages to keep memory usage low.
 *  - Reschedules itself for the next day at the same hour after each run.
 */
final class DeltaSyncWorker {

    private const ACTION_HOOK  = 'wc_mcp_delta_sync';
    private const GROUP        = 'wc-mcp';
    private const BATCH_SIZE   = 250;
    private const DEFAULT_HOUR = 2; // 2 AM site timezone

    public static function register_hooks(): void {
        add_action(self::ACTION_HOOK, [self::class, 'run']);
    }

    /**
     * Enqueue an immediate (async) delta sync.
     * Used by the "Sync Now" button in the admin UI.
     * Distinct from trigger_force_sync — does NOT reset the backfill cursor.
     */
    public static function enqueue_immediate(): void {
        if (!function_exists('as_enqueue_async_action')) {
            return;
        }
        as_enqueue_async_action(self::ACTION_HOOK, [], self::GROUP);
        self::log('Manual delta sync queued.', 'info');
    }

    /**
     * Schedule the first delta sync if none is pending.
     * Called on plugin activation and when sync hour changes.
     *
     * @param int|null $hour Hour of day in site timezone (0–23). Null = read from wp_options.
     */
    public static function maybe_schedule(int $hour = null): void {
        if (!function_exists('as_has_scheduled_action')) {
            return;
        }

        $hour = $hour ?? (int) get_option('wc_mcp_sync_hour', self::DEFAULT_HOUR);

        // If already scheduled, skip.
        if (as_has_scheduled_action(self::ACTION_HOOK, [], self::GROUP)) {
            return;
        }

        as_schedule_single_action(
            self::next_occurrence_timestamp($hour),
            self::ACTION_HOOK,
            [],
            self::GROUP
        );
    }

    /**
     * Cancel any pending delta sync jobs and reschedule at the new hour.
     * Called when the user changes the sync hour in settings.
     */
    public static function reschedule(int $hour): void {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions(self::ACTION_HOOK, [], self::GROUP);

        as_schedule_single_action(
            self::next_occurrence_timestamp($hour),
            self::ACTION_HOOK,
            [],
            self::GROUP
        );
    }

    /**
     * Action Scheduler callback — runs the delta sync, then schedules next day's run.
     */
    public static function run(): void {
        if (get_option('wc_mcp_connection_mode', 'cloud_sync') !== 'cloud_sync') {
            return;
        }

        $tenant_id  = get_option('wc_mcp_tenant_id');
        $auth_token = get_option('wc_mcp_auth_token');

        if (!$tenant_id || !$auth_token) {
            self::log('Delta sync skipped — store not connected.', 'warning');
            self::schedule_next();
            return;
        }

        $since  = self::since_timestamp();
        $offset = 0;
        $total  = 0;

        self::log("Delta sync starting. Fetching orders modified since {$since}.", 'info');

        $dispatcher = new WebhookDispatcher();

        do {
            $orders = self::fetch_orders($since, $offset);
            if (empty($orders)) {
                break;
            }
            self::attach_line_items($orders);
            self::attach_order_meta($orders);
            self::attach_coupons($orders);
            $dispatcher->dispatch_batch($orders);
            $offset += count($orders);
            $total  += count($orders);
        } while (count($orders) === self::BATCH_SIZE);

        update_option('wc_mcp_last_sync_timestamp', time());
        self::log("Delta sync complete. {$total} orders pushed.", 'info');

        // Schedule next day's run at the same configured hour.
        self::schedule_next();
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the Unix timestamp for the next occurrence of $hour in site timezone.
     */
    private static function next_occurrence_timestamp(int $hour): int {
        $tz   = wp_timezone();
        $now  = new \DateTime('now', $tz);
        $next = new \DateTime('today', $tz);
        $next->setTime($hour, 0, 0);

        // If the target time has already passed today, roll to tomorrow.
        if ($next <= $now) {
            $next->modify('+1 day');
        }

        return $next->getTimestamp();
    }

    /**
     * Returns the ISO-8601 datetime string to use as the "modified since" filter.
     * Uses last_sync_timestamp if available, otherwise 24 hours ago as a safe fallback.
     */
    private static function since_timestamp(): string {
        $ts = (int) get_option('wc_mcp_last_sync_timestamp', 0);
        if ($ts > 0) {
            return gmdate('Y-m-d H:i:s', $ts);
        }
        return gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    }

    /**
     * Fetch a page of orders modified since $since from HPOS tables.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetch_orders(string $since, int $offset): array {
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
                        THEN ba.email
                        ELSE NULL
                    END                              AS billing_email_raw
                FROM {$wpdb->prefix}wc_orders o
                LEFT JOIN {$wpdb->prefix}wc_order_stats os ON o.id = os.order_id
                LEFT JOIN {$wpdb->prefix}wc_order_addresses ba
                    ON o.id = ba.order_id AND ba.address_type = 'billing'
                LEFT JOIN {$wpdb->prefix}wc_order_addresses sa
                    ON o.id = sa.order_id AND sa.address_type = 'shipping'
                WHERE o.type = 'shop_order'
                  AND o.status IN ('wc-completed','wc-processing','wc-shipped','wc-refunded','wc-cancelled')
                  AND o.date_updated_gmt >= %s
                ORDER BY o.date_updated_gmt ASC
                LIMIT %d OFFSET %d",
                $since,
                self::BATCH_SIZE,
                $offset
            ),
            ARRAY_A
        );
        // phpcs:enable

        // Compute HMAC-SHA256 email hashes in PHP (cannot use SQL — needs auth token as key)
        if ($rows) {
            $auth_token = get_option('wc_mcp_auth_token', '');
            foreach ($rows as &$row) {
                if (!empty($row['billing_email_raw']) && $auth_token) {
                    $row['billing_email_hash'] = hash_hmac(
                        'sha256',
                        strtolower(trim($row['billing_email_raw'])),
                        $auth_token
                    );
                } else {
                    $row['billing_email_hash'] = null;
                }
                unset($row['billing_email_raw']);
            }
            unset($row);
        }

        return $rows ?: [];
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
     * Schedule next day's delta sync at the same configured hour.
     */
    private static function schedule_next(): void {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }

        $hour      = (int) get_option('wc_mcp_sync_hour', self::DEFAULT_HOUR);
        $tz        = wp_timezone();
        $tomorrow  = new \DateTime('tomorrow', $tz);
        $tomorrow->setTime($hour, 0, 0);

        as_schedule_single_action(
            $tomorrow->getTimestamp(),
            self::ACTION_HOOK,
            [],
            self::GROUP
        );
    }

    private static function log(string $message, string $level = 'info'): void {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'wc-analytics-mcp-delta']);
        }
    }
}
