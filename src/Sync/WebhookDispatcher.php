<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Sync;

/**
 * Dispatches order mutation events to the Clariq cloud warehouse (cloud_sync mode).
 *
 * Hooks into HPOS-compatible order lifecycle actions so every create/update/delete
 * is pushed to the SaaS ingest endpoint in near-real time.
 *
 * Retry policy for dispatch_batch():
 *   - Up to 3 attempts with exponential back-off: 1 s, 4 s, 16 s.
 *   - On 429: honour the Retry-After header before re-attempting.
 *   - On 401: stop syncing immediately — credentials are invalid.
 */
final class WebhookDispatcher {

    private const REQUEST_TIMEOUT  = 10;
    // Batch ingest is a background bulk operation: a full page of orders means
    // dozens of upserts against a (possibly remote) Postgres, which can take well
    // over the 10s used for single fire-and-forget webhooks. A tight timeout here
    // caused the plugin to disconnect mid-request, stranding the server-side
    // transaction with its row locks held — which then blocked every following
    // batch and exhausted the API's connection pool. Give batches real headroom.
    private const BATCH_REQUEST_TIMEOUT = 60;
    private const MAX_RETRIES      = 3;
    private const BASE_BACKOFF     = 1; // seconds

    private static function ingest_url(): string {
        return rtrim(WC_MCP_CLARIQ_INTERNAL_URL, '/') . '/v1/orders/ingest';
    }

    public static function register_hooks(): void {
        // HPOS-compatible order lifecycle hooks.
        add_action('woocommerce_new_order',            [self::class, 'on_order_created'], 10, 2);
        add_action('woocommerce_update_order',         [self::class, 'on_order_updated'], 10, 2);
        add_action('woocommerce_order_status_changed', [self::class, 'on_status_changed'], 10, 4);
        add_action('woocommerce_before_delete_order',  [self::class, 'on_order_deleted'], 10, 1);
    }

    // -----------------------------------------------------------------------
    // Real-time single-order hooks (fire-and-forget)
    // -----------------------------------------------------------------------

    /** @param int $order_id @param \WC_Order|null $order */
    public static function on_order_created(int $order_id, ?\WC_Order $order = null): void {
        self::push_order($order_id, 'created', $order);
    }

    public static function on_order_updated(int $order_id, ?\WC_Order $order = null): void {
        self::push_order($order_id, 'updated', $order);
    }

    /** @param int $order_id @param string $from @param string $to @param \WC_Order $order */
    public static function on_status_changed(int $order_id, string $from, string $to, \WC_Order $order): void {
        self::push_order($order_id, 'status_changed', $order, ['from' => $from, 'to' => $to]);
    }

    public static function on_order_deleted(int $order_id): void {
        self::push_order($order_id, 'deleted');
    }

    // -----------------------------------------------------------------------
    // Batch dispatch (BackfillWorker / DeltaSync)
    // -----------------------------------------------------------------------

    /**
     * Dispatches a batch of raw order rows to POST /v1/orders/ingest/batch.
     * Retries up to MAX_RETRIES times on transient failures.
     *
     * @param array<int, array<string, mixed>> $orders
     * @param int                              $cursor Progress count (orders sent so far) sent to the API.
     * @param string                           $event  The event type name.
     * @param int|null                         $backfill_total Window order count (progress denominator).
     * @param string|null                      $window_start Locked window floor (Y-m-d H:i:s) — the run's
     *                                          immutable date lower bound, sent so the server can derive
     *                                          progress as COUNT(orders in window) / target.
     * @return array<string, mixed>|null  Decoded API response body, or null on total failure.
     */
    public function dispatch_batch(array $orders, int $cursor = 0, string $event = 'backfill_batch', ?int $backfill_total = null, ?string $window_start = null): ?array {
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return null;
        }

        $tenant_id  = get_option('wc_mcp_tenant_id');
        $auth_token = get_option('wc_mcp_auth_token');

        if (!$tenant_id || !$auth_token) {
            return null;
        }

        $payload = [
            'orders' => $orders,
            'event'  => $event,
            'cursor' => $cursor,
        ];
        if ($backfill_total !== null) {
            $payload['backfill_total'] = $backfill_total;
        }
        if ($window_start !== null) {
            $payload['window_start'] = $window_start;
        }

        $body = wp_json_encode($payload);

        // Content-hash idempotency (Phase 6): derive the key from the run token +
        // batch content instead of a fresh UUID per attempt. Retrying the same
        // batch (e.g. after a lost response) yields the SAME key, so the server
        // returns its cached result and re-does no work. Scoping by the per-run
        // token (wc_mcp_backfill_run_id) ensures a later re-import of identical
        // orders is NOT masked by a stale cached response — a new run = new keys.
        // Falls back to a random UUID if no run token is set (e.g. delta sync).
        $run_id = (string) get_option('wc_mcp_backfill_run_id', '');
        $idempotency_key = $run_id !== ''
            ? hash('sha256', $run_id . '|' . $body)
            : wp_generate_uuid4();

        $headers = array_merge(
            self::build_headers($auth_token, $tenant_id),
            ['X-Idempotency-Key' => $idempotency_key]
        );

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = wp_remote_post(self::ingest_url() . '/batch', [
                'timeout' => self::BATCH_REQUEST_TIMEOUT,
                'headers' => $headers,
                'body'    => $body,
            ]);

            if (is_wp_error($response)) {
                // Network / timeout error — retry with back-off.
                self::log(
                    sprintf('Batch dispatch attempt %d failed (network): %s', $attempt, $response->get_error_message()),
                    'warning'
                );
                if ($attempt < self::MAX_RETRIES) {
                    self::wait($attempt);
                }
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);

            if ($status_code === 401) {
                // Invalid credentials — do not retry; surface in admin UI.
                self::log('Batch dispatch failed: 401 Unauthorized — disabling sync.', 'error');
                update_option('wc_mcp_sync_error', '401_unauthorized');
                return null;
            }

            if ($status_code === 429) {
                // Server backpressure (pool saturated). Don't burn in-process
                // retries sleeping — return a rate-limited marker so the caller
                // (BackfillWorker) reschedules via Action Scheduler after the
                // server's Retry-After, freeing this PHP worker immediately. This
                // is NOT a failure and must not count toward the batch's failure
                // budget.
                $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
                $sleep       = max(1, $retry_after ?: (int) (self::BASE_BACKOFF ** $attempt));
                self::log(sprintf('Rate limited (429) — backing off %d s (server backpressure).', $sleep), 'warning');
                return ['rate_limited' => true, 'retry_after' => $sleep];
            }

            if ($status_code >= 500) {
                // Server error — retry with back-off.
                self::log(sprintf('Batch dispatch attempt %d failed: HTTP %d', $attempt, $status_code), 'warning');
                if ($attempt < self::MAX_RETRIES) {
                    self::wait($attempt);
                }
                continue;
            }

            if ($status_code >= 200 && $status_code < 300) {
                // Success — decode and return the API response.
                $decoded = json_decode(wp_remote_retrieve_body($response), true);
                update_option('wc_mcp_last_sync_timestamp', time());
                delete_option('wc_mcp_sync_error'); // Clear any previous error.
                return is_array($decoded) ? $decoded : [];
            }

            // 4xx (other than 401/429) — do not retry.
            self::log(sprintf('Batch dispatch failed with HTTP %d — not retrying.', $status_code), 'error');
            return null;
        }

        self::log('Batch dispatch exhausted all retries.', 'error');
        return null;
    }

    /**
     * Ask the SaaS API which of a page of WooCommerce order ids it has NOT
     * persisted (Phase 5 auto re-enqueue). Only the plugin can enumerate WC-side
     * ids, so this diff is what lets the reconciliation sweep recompute the
     * *exact* missing orders and re-send them, rather than only recording a
     * count shortfall.
     *
     * @param array<int, int> $candidate_ids Order ids the plugin believes are in-window.
     * @return array<int, int>|null  Missing ids (subset of candidates), or null on failure.
     */
    public function reconcile_batch(array $candidate_ids): ?array {
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return null;
        }

        $tenant_id  = get_option('wc_mcp_tenant_id');
        $auth_token = get_option('wc_mcp_auth_token');
        if (!$tenant_id || !$auth_token) {
            return null;
        }
        if (empty($candidate_ids)) {
            return [];
        }

        $body = wp_json_encode(['candidate_ids' => array_values(array_map('intval', $candidate_ids))]);

        $response = wp_remote_post(self::ingest_url() . '/reconcile', [
            'timeout' => self::BATCH_REQUEST_TIMEOUT,
            'headers' => self::build_headers($auth_token, $tenant_id),
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            self::log('Reconcile request failed (network): ' . $response->get_error_message(), 'warning');
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            self::log(sprintf('Reconcile request returned HTTP %d.', $code), 'warning');
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (is_array($decoded) && isset($decoded['missing_ids']) && is_array($decoded['missing_ids'])) {
            return array_map('intval', $decoded['missing_ids']);
        }
        return [];
    }

    /**
     * Report a permanently-failed batch to the SaaS API so the gap is recorded
     * in dead_letter_events (Phase 5) instead of being silently lost when the
     * run pauses. Best-effort and single-shot: if the API is the reason the
     * batch failed, this will also fail — that's fine, the plugin still surfaces
     * wc_mcp_sync_error locally. No retries here (we're already giving up).
     *
     * @param int    $from_id First order id in the failed page.
     * @param int    $to_id   Last order id in the failed page.
     * @param string $reason  Short machine reason (e.g. 'backfill_batch_failed').
     * @return bool           True if the API accepted the dead-letter report.
     */
    public function dispatch_dead_letter(int $from_id, int $to_id, string $reason): bool {
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return false;
        }

        $tenant_id  = get_option('wc_mcp_tenant_id');
        $auth_token = get_option('wc_mcp_auth_token');
        if (!$tenant_id || !$auth_token) {
            return false;
        }

        $body = wp_json_encode([
            'orders'         => [],
            'event'          => 'backfill_dead_letter',
            'failed_from_id' => $from_id,
            'failed_to_id'   => $to_id,
            'error_reason'   => $reason,
        ]);

        $headers = array_merge(
            self::build_headers($auth_token, $tenant_id),
            ['X-Idempotency-Key' => wp_generate_uuid4()]
        );

        $response = wp_remote_post(self::ingest_url() . '/batch', [
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => $headers,
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            self::log('Dead-letter report failed (network): ' . $response->get_error_message(), 'warning');
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        return $status_code >= 200 && $status_code < 300;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /** @param array<string, mixed>|null $extra */
    private static function push_order(
        int $order_id,
        string $event,
        ?\WC_Order $order = null,
        ?array $extra = null
    ): void {
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return;
        }

        $tenant_id  = get_option('wc_mcp_tenant_id');
        $auth_token = get_option('wc_mcp_auth_token');

        if (!$tenant_id || !$auth_token) {
            return;
        }

        $order_data = null;
        if ($order instanceof \WC_Order) {
            $order_data = self::serialize_order($order);
        } else {
            $order_data = ['id' => $order_id];
        }

        $payload = [
            'event' => $event,
            'order' => $order_data,
        ];
        if ($extra) {
            $payload['meta'] = $extra;
        }

        wp_remote_post(self::ingest_url(), [
            'timeout'   => self::REQUEST_TIMEOUT,
            'blocking'  => false, // Fire-and-forget.
            'headers'   => self::build_headers($auth_token, $tenant_id),
            'body'      => wp_json_encode($payload),
        ]);

        update_option('wc_mcp_last_sync_timestamp', time());
    }

    /**
     * Serializes a WC_Order into the flat structure the ingest API expects.
     * PII fields (names, raw emails, address lines) are intentionally excluded.
     * Only the SHA-256 hash of the billing email is sent.
     *
     * @return array<string, mixed>
     */
    private static function serialize_order(\WC_Order $order): array {
        // City/country: prefer shipping address, fall back to billing (covers
        // the common case where the customer ships to their billing address and
        // WooCommerce leaves the shipping fields empty).
        $shipping_city    = $order->get_shipping_city()    ?: $order->get_billing_city();
        $shipping_country = $order->get_shipping_country() ?: $order->get_billing_country();

        // Email hash: HMAC-SHA-256 of normalised billing email, salted with the
        // store auth token. Raw email is never sent. Each store's hashes are unique.
        $billing_email      = $order->get_billing_email();
        $auth_token         = get_option('wc_mcp_auth_token', '');
        $billing_email_hash = ($billing_email && $auth_token)
            ? hash_hmac('sha256', strtolower(trim($billing_email)), $auth_token)
            : null;

        return [
            'id'                  => $order->get_id(),
            'status'              => 'wc-' . $order->get_status(),
            'date_created_gmt'    => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
            'date_updated_gmt'    => $order->get_date_modified() ? $order->get_date_modified()->date('Y-m-d H:i:s') : null,
            'total_amount'        => (float) $order->get_total(),
            'shipping_total'      => (float) $order->get_shipping_total(),
            'discount_total'      => (float) $order->get_discount_total(),
            'tax_total'           => (float) $order->get_total_tax(),
            'customer_id'         => $order->get_customer_id(),
            'payment_method'      => $order->get_payment_method(),
            'shipping_city'       => $shipping_city ?: null,
            'shipping_country'    => $shipping_country ?: null,
            'billing_email_hash'  => $billing_email_hash,
            'utm_source'          => $order->get_meta('_wc_order_attribution_utm_source') ?: null,
            'utm_medium'          => $order->get_meta('_wc_order_attribution_utm_medium') ?: null,
            'utm_campaign'        => $order->get_meta('_wc_order_attribution_utm_campaign') ?: null,
            'referrer'            => $order->get_meta('_wc_order_attribution_referrer') ?: null,
            'user_agent'          => $order->get_meta('_wc_order_attribution_user_agent') ?: null,
            // WooCommerce 8.5+ session/attribution meta. Absent on older WC and
            // for admin/API/POS orders — normalise missing/empty to null.
            'source_type'             => OrderAttribution::normalize('source_type', $order->get_meta('_wc_order_attribution_source_type')),
            'device_type_attribution' => OrderAttribution::normalize('device_type_attribution', $order->get_meta('_wc_order_attribution_device_type')),
            'session_entry'           => OrderAttribution::normalize('session_entry', $order->get_meta('_wc_order_attribution_session_entry')),
            'session_start_time'      => OrderAttribution::normalize('session_start_time', $order->get_meta('_wc_order_attribution_session_start_time')),
            'session_pages'           => OrderAttribution::normalize('session_pages', $order->get_meta('_wc_order_attribution_session_pages')),
            'session_duration'        => OrderAttribution::normalize('session_duration', $order->get_meta('_wc_order_attribution_session_duration')),
            'coupons'             => self::serialize_coupons($order),
            'line_items'          => self::serialize_line_items($order),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function serialize_line_items(\WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $qty          = $item->get_quantity();
            $total        = (float) $item->get_total();
            $product_name = $item->get_name();

            // For variation items, use the parent product's clean name
            if ($variation_id > 0) {
                $parent_product = wc_get_product($product_id);
                if ($parent_product) {
                    $product_name = $parent_product->get_name();
                }
            }

            // Extract variation details (attributes)
            $attributes = [];
            foreach ($item->get_meta_data() as $meta) {
                $key = $meta->key;
                $val = $meta->value;
                if (str_starts_with($key, '_') || in_array($key, ['_line_subtotal', '_line_subtotal_tax', '_line_tax', '_tax_class'])) {
                    continue;
                }
                $clean_key = str_replace('pa_', '', $key);
                $attributes[] = ucfirst($clean_key) . ': ' . $val;
            }
            $var_name = !empty($attributes) ? implode(', ', $attributes) : null;

            $items[] = [
                'product_id'     => $product_id,
                'variation_id'   => $variation_id,
                'product_name'   => $product_name,
                'variation_name' => $var_name,
                'quantity'       => $qty,
                'total'          => $total,
            ];
        }
        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private static function serialize_coupons(\WC_Order $order): array {
        $coupons = [];
        foreach ($order->get_items('coupon') as $item) {
            /** @var \WC_Order_Item_Coupon $item */
            $coupons[] = [
                'coupon_code'     => $item->get_code(),
                'discount_amount' => (float) $item->get_discount(),
            ];
        }
        return $coupons;
    }

    /** @return array<string, string> */
    private static function build_headers(string $auth_token, string $tenant_id): array {
        return [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $auth_token,
            'X-Tenant-ID'   => $tenant_id,
        ];
    }

    /**
     * Exponential back-off: 1 s, 4 s, 16 s for attempts 1, 2, 3.
     */
    private static function wait(int $attempt): void {
        sleep((int) (self::BASE_BACKOFF ** (2 * $attempt - 1)));
    }

    private static function log(string $message, string $level = 'info'): void {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'wc-analytics-mcp']);
        }
    }
}
