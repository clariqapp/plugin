<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Sync;

/**
 * Handles historical order backfill via Action Scheduler.
 *
 * Strategy:
 *  - Processes HPOS orders in keyset-paginated pages of BATCH_SIZE (id > last_id).
 *  - Checks PHP memory headroom before each batch to prevent OOM crashes.
 *  - Stores a server-confirmed keyset cursor (wc_mcp_backfill_last_id) in
 *    wp_options so batches resume after failures without skipping or duplicating
 *    orders; progress is tracked separately (wc_mcp_backfill_processed).
 *  - Only active when connection_mode = 'cloud_sync' (warehouse sync).
 */
final class BackfillWorker {

    private const BATCH_SIZE       = 25;
    private const MAX_BATCH_ATTEMPTS = 5; // Retries for a single failing batch before pausing the run.
    private const RECONCILE_CHUNK  = 200; // WC ids per reconciliation probe (Phase 5).
    private const ACTION_HOOK      = 'wc_mcp_backfill_batch';
    private const RECONCILE_HOOK   = 'wc_mcp_backfill_reconcile';
    private const FORCE_SYNC_HOOK  = 'wc_mcp_force_sync';
    private const GROUP            = 'wc-mcp';
    private const MEMORY_HEADROOM  = 0.80; // Stop if memory usage exceeds 80%.

    public static function register_hooks(): void {
        add_action(self::ACTION_HOOK,     [self::class, 'process_batch']);
        add_action(self::RECONCILE_HOOK,  [self::class, 'run_reconcile']);
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

        // Reset cursor and clear any locked window + prior completion marker so
        // this run recomputes $since fresh and presents a clean "running" state
        // (the completion guard in process_batch keys off wc_mcp_backfill_complete,
        // so it must be cleared here for a legitimate new/re-import run to proceed).
        update_option('wc_mcp_backfill_last_id', 0);
        delete_option('wc_mcp_backfill_since');
        delete_option('wc_mcp_backfill_complete');
        delete_option('wc_mcp_backfill_processed');
        delete_option('wc_mcp_reconcile_done');
        delete_option('wc_mcp_reconcile_since');
        delete_option('wc_mcp_reconcile_last_id');
        // New run token — scopes batch idempotency keys so retries within this
        // run dedupe, but a later re-import (new token) is never masked by a
        // stale cached response for identical order content (Phase 6).
        update_option('wc_mcp_backfill_run_id', wp_generate_uuid4());

        as_enqueue_async_action(self::ACTION_HOOK, [], self::GROUP);
    }

    /**
     * Action Scheduler callback: processes one page of orders and schedules the next.
     */
    public static function process_batch(): void {
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return; // Local Bridge does not need warehouse sync.
        }

        // Completion is sticky. A finished run deletes wc_mcp_backfill_last_id and
        // sets wc_mcp_backfill_complete. If a stray/duplicate scheduled action
        // fires after that (e.g. a leftover queued batch, a double-enqueue, or a
        // retry that outlived the run), it must NOT restart the whole backfill:
        // with no live cursor the keyset would default to 0 and $since would be
        // recomputed, silently re-importing everything from scratch. Bail out
        // unless there is a live cursor (an in-flight run) to resume. A genuine
        // re-import goes through maybe_schedule_backfill(), which clears the
        // complete flag and seeds last_id=0 first, so it is unaffected by this.
        if (get_option('wc_mcp_backfill_last_id') === false && get_option('wc_mcp_backfill_complete')) {
            return;
        }

        if (!self::memory_ok()) {
            self::log('Memory headroom exhausted — requeueing batch for later.', 'warning');
            as_schedule_single_action(time() + 300, self::ACTION_HOOK, [], self::GROUP);
            return;
        }

        // Keyset cursor: the highest order id the SaaS API has CONFIRMED as
        // persisted (0 on the first page). Pages are fetched with `id > last_id`,
        // so this is a durable resume point, not a row count. Progress shown in
        // the UI is tracked independently via wc_mcp_backfill_processed.
        $last_id = (int) get_option('wc_mcp_backfill_last_id', 0);

        // The date floor ($since) MUST stay fixed for the entire run: it is
        // derived once (on the first batch, last_id === 0) from the range/
        // retention settings at that moment, and persisted to wc_mcp_backfill_since.
        // Recomputing it fresh from wc_mcp_backfill_range on every batch (the old
        // behaviour) let a merchant change the "how much history" dropdown while
        // a run was in flight; the next batch would then query a different
        // (often much narrower) window than the one count_orders() sized `total`
        // against, so fetch_orders() could return an empty page after only a
        // handful of rows — process_batch() would read that as "done" and mark
        // wc_mcp_backfill_complete, even though only a fraction of the original
        // total was actually sent. Locking $since fixes that: a mid-run range
        // change now takes effect on the *next* run (via handle_backfill_restart
        // or the natural next scheduled backfill), not by silently truncating
        // the one in progress.
        $since = get_option('wc_mcp_backfill_since', false);

        // Count total orders on first batch for progress tracking, and lock the
        // $since floor used for the rest of this run.
        $backfill_total = null;
        if ($last_id === 0 || $since === false) {
            // Phase 4 lifecycle: announce that a run has started and we're sizing
            // the window, BEFORE the (potentially slow) count + first data batch.
            // The server flips the store to `counting` so the dashboard shows
            // "Scanning your store…" during this gap instead of a stale idle
            // state. Best-effort and idempotent (deduped by the run token), so a
            // retried first batch never double-counts.
            (new WebhookDispatcher())->dispatch_batch([], 0, 'backfill_counting');

            $range = (int) get_option('wc_mcp_backfill_range', 12);
            $since = date('Y-m-d H:i:s', strtotime("-{$range} months"));

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

            update_option('wc_mcp_backfill_since', $since);

            $backfill_total = self::count_orders($since);
            update_option('wc_mcp_backfill_total', $backfill_total);
        }

        $orders = self::fetch_orders($last_id, $since);

        // Progress counter (orders sent so far this run), tracked independently of
        // the keyset cursor. This is the value the server treats as its progress
        // `cursor` (cursor==0 marks the start of a run and resets server-side
        // tracking) and the numerator the admin UI displays.
        $processed_before = (int) get_option('wc_mcp_backfill_processed', 0);

        if (empty($orders)) {
            // Forward paging is exhausted. Before declaring the run complete, run
            // a reconciliation sweep (Phase 5) that closes any exact gaps — orders
            // in the window the warehouse never received (e.g. a chunk that failed
            // and was dead-lettered). Only once that sweep has finished do we
            // finalize + re-verify, so "complete" reflects the repaired state.
            if (!get_option('wc_mcp_reconcile_done')) {
                // Start the sweep exactly once per run: seed it only if it isn't
                // already in flight (guards against a stray duplicate action
                // restarting reconcile from scratch).
                if (get_option('wc_mcp_reconcile_since') === false) {
                    update_option('wc_mcp_reconcile_since', $since);
                    update_option('wc_mcp_reconcile_last_id', 0);
                    if (function_exists('as_enqueue_async_action')) {
                        as_enqueue_async_action(self::RECONCILE_HOOK, [], self::GROUP);
                    }
                    self::log('Forward backfill pass done — starting reconciliation sweep.', 'info');
                }
                return;
            }

            // Reconciliation sweep finished — finalize. Send the completion event
            // (the server re-verifies imported vs target and settles the terminal
            // status to complete/failed), then clear all run state.
            $dispatcher = new WebhookDispatcher();
            $dispatcher->dispatch_batch([], $processed_before, 'backfill_complete');

            // wc_mcp_backfill_processed already holds the true number of orders
            // sent (incremented per confirmed batch below), which may be LESS than
            // wc_mcp_backfill_total if the run ended early — the UI then shows an
            // honest "N of M" rather than a forced 100%.

            delete_option('wc_mcp_backfill_last_id');
            delete_option('wc_mcp_backfill_since'); // Locked window is consumed; next run recomputes.
            delete_option('wc_mcp_backfill_batch_attempts');
            delete_option('wc_mcp_reconcile_done');
            delete_option('wc_mcp_reconcile_since');
            delete_option('wc_mcp_reconcile_last_id');
            update_option('wc_mcp_last_sync_timestamp', time());
            update_option('wc_mcp_backfill_complete', time()); // Timestamp for UI display.
            self::log('Historical backfill complete.', 'info');
            return;
        }

        self::attach_line_items($orders);
        self::attach_order_meta($orders);
        self::attach_coupons($orders);

        $dispatcher = new WebhookDispatcher();
        $result     = $dispatcher->dispatch_batch($orders, $processed_before, 'backfill_batch', $backfill_total, $since);

        // Server backpressure (Phase 6): the API shed this batch with 429 because
        // its DB pool is saturated. This is NOT a failure — do not advance, do not
        // touch the failure/attempt counter. Reschedule the SAME cursor after the
        // server's Retry-After so the backfill paces itself to what the DB can
        // absorb instead of piling up requests.
        if (is_array($result) && !empty($result['rate_limited'])) {
            $delay = max(1, (int) ($result['retry_after'] ?? 5));
            self::log(
                sprintf('Backfill throttled by server — retrying after id %d in %d s.', $last_id, $delay),
                'info'
            );
            as_schedule_single_action(time() + $delay, self::ACTION_HOOK, [], self::GROUP);
            return;
        }

        // Dispatch outcome handling.
        //
        // CRITICAL: only advance the cursor when the batch was actually accepted
        // by the SaaS API. dispatch_batch() returns null when it exhausts its
        // retries (network error, 5xx, timeout, etc.). The previous code advanced
        // past the batch anyway, which silently DROPPED those orders — they were
        // never persisted server-side yet the cursor moved on, so the run would
        // reach the empty page and mark "complete" with a hole in the data (this
        // is what left the most-recent orders missing from the warehouse while
        // the plugin reported 100%). Instead we now retry the SAME cursor with a
        // bounded attempt counter, and hard-stop (surfacing an error) rather than
        // skipping if it keeps failing.
        if (!is_array($result)) {
            $attempts = (int) get_option('wc_mcp_backfill_batch_attempts', 0) + 1;

            if ($attempts >= self::MAX_BATCH_ATTEMPTS) {
                // Give up on this batch to avoid an infinite loop, but do NOT
                // advance the cursor or mark complete — leave the run paused with
                // a visible error so the merchant/dev can act (check the API, then
                // re-import). The cursor stays put so a later retry resumes here.
                update_option('wc_mcp_sync_error', 'backfill_batch_failed');
                delete_option('wc_mcp_backfill_batch_attempts');

                // Phase 5: report the failed keyset range to the SaaS API so it
                // lands in dead_letter_events (visible + replayable) rather than
                // being silently stranded behind a paused run. Best-effort.
                $failed_from = (int) $orders[0]['id'];
                $failed_to   = (int) end($orders)['id'];
                reset($orders);
                (new WebhookDispatcher())->dispatch_dead_letter(
                    $failed_from,
                    $failed_to,
                    'backfill_batch_failed'
                );

                self::log(
                    sprintf('Backfill batch after id %d failed %d times — pausing run.', $last_id, $attempts),
                    'error'
                );
                return;
            }

            update_option('wc_mcp_backfill_batch_attempts', $attempts);
            self::log(
                sprintf('Backfill batch after id %d failed (attempt %d) — retrying shortly.', $last_id, $attempts),
                'warning'
            );
            // Re-run the SAME cursor after a short back-off (does not advance).
            as_schedule_single_action(time() + 30, self::ACTION_HOOK, [], self::GROUP);
            return;
        }

        // Success — clear any per-batch retry counter and any prior error.
        delete_option('wc_mcp_backfill_batch_attempts');
        delete_option('wc_mcp_sync_error');

        // Highest order id in this page (rows come back ORDER BY id ASC, so the
        // last one is the max). Used as the local fallback when talking to an
        // older API that doesn't return max_confirmed_id yet.
        $local_max = (int) end($orders)['id'];
        reset($orders);

        // Advance the keyset cursor to the id the API CONFIRMED it persisted.
        // Because a successful batch is all-or-nothing server-side, the confirmed
        // id equals $local_max; we fall back to $local_max only for older servers
        // that omit the field. Never regress below what we already fetched past.
        $max_confirmed = isset($result['max_confirmed_id'])
            ? (int) $result['max_confirmed_id']
            : $local_max;
        $new_last_id = $max_confirmed > $last_id ? $max_confirmed : $local_max;

        // Advance progress + keyset cursor.
        update_option('wc_mcp_backfill_processed', $processed_before + count($orders));
        update_option('wc_mcp_backfill_last_id', $new_last_id);
        update_option('wc_mcp_last_sync_timestamp', time());

        // Schedule the next page. Phase 6 server-owned pacing: if the server
        // asked us to slow down (next_batch_delay > 0, scaled to its current
        // pool pressure), honour it by scheduling the next batch after that
        // delay instead of firing immediately. This lets the server meter
        // aggregate load across all tenants short of having to shed with 429.
        $delay = isset($result['next_batch_delay']) ? max(0, (int) $result['next_batch_delay']) : 0;
        if ($delay > 0) {
            as_schedule_single_action(time() + $delay, self::ACTION_HOOK, [], self::GROUP);
        } else {
            as_enqueue_async_action(self::ACTION_HOOK, [], self::GROUP);
        }
    }

    /**
     * Force sync: resets the cursor and reschedules a full backfill.
     */
    public static function trigger_force_sync(): void {
        update_option('wc_mcp_backfill_last_id', 0);
        delete_option('wc_mcp_backfill_since');
        delete_option('wc_mcp_backfill_processed');
        delete_option('wc_mcp_reconcile_done');
        delete_option('wc_mcp_reconcile_since');
        delete_option('wc_mcp_reconcile_last_id');
        update_option('wc_mcp_backfill_run_id', wp_generate_uuid4());
        as_enqueue_async_action(self::ACTION_HOOK, [], self::GROUP);
        self::log('Force sync triggered.', 'info');
    }

    /**
     * Deterministically START a fresh backfill run right now.
     *
     * Unlike maybe_schedule_backfill() — which BAILS if Action Scheduler still
     * reports a queued/in-progress batch (correct for the idempotent activation
     * hook, wrong for an explicit re-import) — this ALWAYS leaves the store in a
     * live "running" state: a zeroed cursor plus a freshly enqueued batch. It is
     * called by handle_backfill_restart() after that endpoint has cleared prior
     * progress and unscheduled old jobs.
     *
     * The previous flow delegated to maybe_schedule_backfill() here; when its
     * as_has_scheduled_action() guard saw a lingering (just-canceled or
     * mid-flight) action it returned WITHOUT setting the offset or enqueuing,
     * so the re-import wiped the old "complete" state and then started nothing —
     * stranding the admin UI at "Not started · 0" while the warehouse still held
     * the fully-imported data. Setting the offset unconditionally also means
     * get_backfill_status() reports "running" immediately, so the merchant sees
     * "Importing…" rather than a dead idle screen even before the first batch runs.
     */
    public static function start_backfill_now(): void {
        update_option('wc_mcp_backfill_last_id', 0);
        delete_option('wc_mcp_backfill_since');
        delete_option('wc_mcp_backfill_complete');
        delete_option('wc_mcp_backfill_processed');
        delete_option('wc_mcp_backfill_batch_attempts');
        delete_option('wc_mcp_sync_error');
        delete_option('wc_mcp_reconcile_done');
        delete_option('wc_mcp_reconcile_since');
        delete_option('wc_mcp_reconcile_last_id');
        update_option('wc_mcp_backfill_run_id', wp_generate_uuid4());

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION_HOOK, [], self::GROUP);
        }

        self::log('Historical re-import started.', 'info');
    }

    /**
     * Action Scheduler callback: one page of the post-backfill reconciliation
     * sweep (Phase 5). Pages the store's own WooCommerce order ids across the
     * locked window, asks the SaaS API which are missing from the warehouse, and
     * re-dispatches exactly those. When the paging is exhausted it hands control
     * back to process_batch() to finalize + re-verify.
     *
     * This is what turns a recorded shortfall from "detected, merchant must
     * re-import" into self-healing: the exact missing orders are recomputed
     * (server diff of plugin-supplied ids) and re-sent automatically. Bounded —
     * each id is visited once; a chunk whose re-dispatch fails is left for the
     * final completeness check to dead-letter, so there is no retry loop.
     */
    public static function run_reconcile(): void {
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return;
        }

        $since = get_option('wc_mcp_reconcile_since', false);
        if ($since === false) {
            return; // No sweep in progress.
        }

        if (!self::memory_ok()) {
            self::log('Memory headroom exhausted — requeueing reconcile for later.', 'warning');
            as_schedule_single_action(time() + 300, self::RECONCILE_HOOK, [], self::GROUP);
            return;
        }

        $last_id = (int) get_option('wc_mcp_reconcile_last_id', 0);
        $ids     = self::fetch_ids($last_id, (string) $since, self::RECONCILE_CHUNK);

        if (empty($ids)) {
            // Sweep finished — mark done and let process_batch() finalize the run
            // (its forward cursor still points past the last order, so it will hit
            // the empty page again and, seeing reconcile_done, finalize+verify).
            update_option('wc_mcp_reconcile_done', 1);
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::ACTION_HOOK, [], self::GROUP);
            }
            self::log('Reconciliation sweep complete.', 'info');
            return;
        }

        $dispatcher = new WebhookDispatcher();
        $missing    = $dispatcher->reconcile_batch($ids);

        if (is_array($missing) && !empty($missing)) {
            $orders = self::fetch_orders_by_ids($missing);
            if (!empty($orders)) {
                self::attach_line_items($orders);
                self::attach_order_meta($orders);
                self::attach_coupons($orders);

                $processed = (int) get_option('wc_mcp_backfill_processed', 0);
                // Re-send as a normal (non-zero cursor) backfill batch so the
                // server upserts them without resetting run tracking.
                $result = $dispatcher->dispatch_batch($orders, $processed, 'backfill_batch');

                if (is_array($result) && !empty($result['rate_limited'])) {
                    // Server backpressure — retry the SAME reconcile cursor after
                    // Retry-After (do not advance past these unrepaired ids).
                    $delay = max(1, (int) ($result['retry_after'] ?? 5));
                    as_schedule_single_action(time() + $delay, self::RECONCILE_HOOK, [], self::GROUP);
                    return;
                }

                if (is_array($result)) {
                    update_option('wc_mcp_backfill_processed', $processed + count($orders));
                    self::log(sprintf('Reconcile re-sent %d missing order(s).', count($orders)), 'info');
                } else {
                    // Re-dispatch failed (e.g. API down). Leave the gap for the
                    // final completeness verify to dead-letter; don't loop here.
                    self::log('Reconcile re-dispatch failed for a chunk — leaving gap for final verify.', 'warning');
                }
            }
        }

        // Advance the reconcile keyset cursor and continue with the next chunk.
        update_option('wc_mcp_reconcile_last_id', (int) end($ids));
        reset($ids);
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::RECONCILE_HOOK, [], self::GROUP);
        }
    }

    /**
     * Fetch a page of orders from HPOS tables using $wpdb directly.
     * Avoids loading full WC_Order objects to keep memory footprint minimal.
     *
     * Phase 3: keyset pagination. Instead of `LIMIT n OFFSET m` — which grows
     * more expensive per page and, worse, silently shifts rows if any order in
     * the window changes between pages — we page by primary key:
     * `WHERE o.id > :last_id ORDER BY o.id ASC LIMIT n`. Combined with the
     * server-confirmed `$last_id` (only advanced to an id the API actually
     * persisted), this makes the scan stable, index-friendly, and resumable:
     * a crash/restart re-reads from exactly the last confirmed id with no
     * skipped or duplicated orders.
     *
     * @param int    $last_id Highest confirmed order id so far (0 on first page).
     * @return array<int, array<string, mixed>>
     */
    private static function fetch_orders(int $last_id, string $since): array {
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
                  AND o.id > %d
                ORDER BY o.id ASC
                LIMIT %d",
                $since,
                $last_id,
                self::BATCH_SIZE
            ),
            ARRAY_A
        );
        // phpcs:enable

        return $rows ?: [];
    }

    /**
     * Fetch just the order ids in the window past $last_id (Phase 5 reconcile
     * probe). Mirrors fetch_orders()'s WHERE clause exactly so the id set the
     * server is asked about is the same population the backfill targets.
     *
     * @return array<int, int> Order ids, ascending.
     */
    private static function fetch_ids(int $last_id, string $since, int $limit): array {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT o.id
                FROM {$wpdb->prefix}wc_orders o
                WHERE o.type = 'shop_order'
                  AND o.status IN ('wc-completed','wc-processing','wc-shipped','wc-refunded','wc-cancelled')
                  AND o.date_created_gmt >= %s
                  AND o.id > %d
                ORDER BY o.id ASC
                LIMIT %d",
                $since,
                $last_id,
                $limit
            )
        );
        // phpcs:enable

        return array_map('intval', $rows ?: []);
    }

    /**
     * Fetch full order rows for a specific set of ids (Phase 5 reconcile
     * re-dispatch). Same projection as fetch_orders() so the attach_* helpers
     * and the ingest payload shape are identical.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private static function fetch_orders_by_ids(array $ids): array {
        if (empty($ids)) {
            return [];
        }

        global $wpdb;

        $ids     = array_map('intval', $ids);
        $id_csv  = implode(',', $ids);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
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
              AND o.id IN ($id_csv)
            ORDER BY o.id ASC",
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

        // Closed allowlist of order-attribution meta keys (5 legacy UTM/referrer
        // fields + 6 WooCommerce 8.5+ session/attribution fields). Keys are
        // constants, never wire input, so the IN (...) list is safe to inline.
        $meta_keys = array_keys(OrderAttribution::META_MAP);
        $keys_sql  = "'" . implode("','", $meta_keys) . "'";

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT order_id, meta_key, meta_value
             FROM {$wpdb->prefix}wc_orders_meta
             WHERE order_id IN ($order_ids_csv)
               AND meta_key IN ($keys_sql)",
            ARRAY_A
        );
        // phpcs:enable

        $meta_by_order = [];
        foreach ($rows as $row) {
            $order_id = (int) $row['order_id'];
            if (!isset($meta_by_order[$order_id])) {
                $meta_by_order[$order_id] = OrderAttribution::null_defaults();
            }

            $key = $row['meta_key'];
            if (isset(OrderAttribution::META_MAP[$key])) {
                $field = OrderAttribution::META_MAP[$key];
                $meta_by_order[$order_id][$field] = OrderAttribution::normalize($field, $row['meta_value']);
            }
        }

        foreach ($orders as &$order) {
            $id   = (int) $order['id'];
            $meta = $meta_by_order[$id] ?? OrderAttribution::null_defaults();
            foreach ($meta as $field => $value) {
                $order[$field] = $value;
            }
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
