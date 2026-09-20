<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Admin;

/**
 * Registers the "WooCommerce > Analytics MCP" admin settings page
 * and enqueues the React admin UI build artefact.
 */
final class SettingsPage {

    /**
     * Number of consecutive 401 responses from the SaaS health-check before the
     * plugin tears down local credentials. Prevents a single transient 401
     * (API redeploy, post-connect race, proxy blip) from wiping a good
     * connection, while still detecting a genuine dashboard-side disconnect
     * within a poll cycle or two (~30s each).
     */
    private const AUTH_FAIL_THRESHOLD = 2;

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handler for Force Sync button.
        add_action('wp_ajax_wc_mcp_force_sync', [$this, 'handle_force_sync']);

        // REST route to expose live status data to the React app.
        add_action('rest_api_init', [$this, 'register_status_route']);
        add_action('rest_api_init', [$this, 'register_connect_routes']);
    }

    public function add_menu_page(): void {
        add_submenu_page(
            'woocommerce',
            __('Clariq Analytics', 'wc-analytics-mcp'),
            __('Clariq Analytics', 'wc-analytics-mcp'),
            'manage_woocommerce',
            'wc-analytics-mcp',
            [$this, 'render_page']
        );
    }

    /**
     * Render the bare React mount point. All UI is handled by the JS bundle.
     */
    public function render_page(): void {
        echo '<div id="wc-mcp-admin-root"></div>';
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'woocommerce_page_wc-analytics-mcp') {
            return;
        }

        $asset_file = WC_MCP_DIR . 'admin-ui/build/index.asset.php';

        // Graceful fallback during development (build not yet run).
        $asset = file_exists($asset_file)
            ? require $asset_file
            : ['dependencies' => ['wp-element', 'wp-api-fetch', 'wp-i18n'], 'version' => WC_MCP_VERSION];

        wp_enqueue_script(
            'wc-mcp-admin',
            WC_MCP_URL . 'admin-ui/build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'wc-mcp-admin-style',
            WC_MCP_URL . 'admin-ui/build/style-index.css',
            [],
            $asset['version']
        );

        // Ensure a bridge secret exists for upgraded installs that predate
        // the local-bridge-first default (fresh installs get one on activation).
        $bridge_secret = \Clariq\McpPlugin\Security\BridgeKeyManager::ensure_secret();

        // Pass runtime data to JS.
        wp_localize_script('wc-mcp-admin', 'wcMcpData', [
            'bridgeSecret'        => $bridge_secret,
            'bridgeUrl'           => rest_url('mcp-bridge/v1/'),
            'ajaxUrl'             => admin_url('admin-ajax.php'),
            'restUrl'             => rest_url('wc-mcp/v1/'),
            'nonce'               => wp_create_nonce('wp_rest'),
            'forceSyncNonce'      => wp_create_nonce('wc_mcp_force_sync_nonce'),
            'tenantId'            => get_option('wc_mcp_tenant_id', ''),
            'isConnected'         => (bool) get_option('wc_mcp_tenant_id', ''),
            'siteUrl'             => home_url(),
            'clariqUrl'           => self::resolved_clariq_url(),
            'mode'                => get_option('wc_mcp_connection_mode', 'local_bridge'),
            'backfillRange'       => get_option('wc_mcp_backfill_range', '12'),
            'plan'                => get_option('wc_mcp_plan', 'free'),
            'retentionDays'       => self::retention_days(),
            'maxBackfillMonths'   => self::max_backfill_months(),
            'syncHour'            => (int) get_option('wc_mcp_sync_hour', 2),
            'backfillStatus'      => self::get_backfill_status(),
            'backfillCompletedAt' => self::format_option_timestamp('wc_mcp_backfill_complete'),
            'lastSyncAt'          => self::format_option_timestamp('wc_mcp_last_sync_timestamp'),
            'connectError'        => sanitize_text_field($_GET['connect_error'] ?? ''),
            'justConnected'       => isset($_GET['connected']) && $_GET['connected'] === '1',
        ]);
    }

    /**
     * REST route: GET /wp-json/wc-mcp/v1/status
     * Returns live health diagnostics consumed by the React admin UI.
     */
    public function register_status_route(): void {
        register_rest_route('wc-mcp/v1', '/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_status'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);

        register_rest_route('wc-mcp/v1', '/settings', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'update_settings'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
            'args' => [
                'connection_mode' => [
                    'type' => 'string',
                    'enum' => ['cloud_sync', 'local_bridge'],
                ],
                'backfill_range' => [
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => 60,
                ],
                'sync_hour' => [
                    'type'    => 'integer',
                    'minimum' => 0,
                    'maximum' => 23,
                ],
            ],
        ]);

        // Manual delta sync ("Sync Now") — only pushes orders since last sync.
        register_rest_route('wc-mcp/v1', '/sync-now', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_sync_now'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);

        // Re-run the historical import (e.g. after a plan upgrade widens the
        // allowed window). Resets the backfill cursor and reschedules a full
        // import for the currently saved range.
        register_rest_route('wc-mcp/v1', '/backfill/restart', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_backfill_restart'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);

        // Rotate the local bridge secret (invalidates any configured MCP clients).
        register_rest_route('wc-mcp/v1', '/bridge/rotate-secret', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_rotate_secret'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_status(\WP_REST_Request $request): \WP_REST_Response {
        $mode       = get_option('wc_mcp_connection_mode', 'local_bridge');
        $tenant_id  = get_option('wc_mcp_tenant_id');
        $auth_token = get_option('wc_mcp_auth_token');

        // Validate connection against Clariq — detect dashboard-side disconnects.
        // Only relevant in cloud_sync mode; local bridge needs no cloud account.
        $is_connected = false;
        if ('cloud_sync' === $mode && $tenant_id && $auth_token) {
            $response = wp_remote_get(
                rtrim(WC_MCP_CLARIQ_INTERNAL_URL, '/') . '/v1/stores/me',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $auth_token],
                    'timeout' => 5,
                ]
            );

            if (is_wp_error($response)) {
                // Network issue — optimistic: keep connected to avoid false disconnects.
                $is_connected = true;
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);

                if ($code === 200 && !empty($body['is_active'])) {
                    $is_connected = true;
                    // A good check clears any accumulated auth-failure strikes.
                    delete_option('wc_mcp_auth_fail_count');
                    // Persist the owning org's plan + effective data-retention
                    // window so sync + admin UI can self-enforce the free-tier
                    // "controlled backfill" cap (30 days). null/absent = unlimited.
                    if (isset($body['plan'])) {
                        update_option('wc_mcp_plan', sanitize_text_field((string) $body['plan']));
                    }
                    $retention = $body['retention_days'] ?? null;
                    if (is_int($retention) && $retention > 0) {
                        update_option('wc_mcp_retention_days', $retention);
                    } else {
                        delete_option('wc_mcp_retention_days'); // paid → unlimited
                    }
                } elseif ($code === 401) {
                    // A 401 means the token was rejected — but a SINGLE 401 is not
                    // enough to wipe a working connection. Transient causes (an API
                    // redeploy, a brief race right after (re)connecting, a proxy
                    // hiccup) can all produce a one-off 401. Only tear down local
                    // credentials after the failure persists across consecutive
                    // polls (~30s apart), so a genuine dashboard-side disconnect is
                    // still detected within a minute, without nuking on a blip.
                    $strikes = (int) get_option('wc_mcp_auth_fail_count', 0) + 1;
                    if ($strikes >= self::AUTH_FAIL_THRESHOLD) {
                        delete_option('wc_mcp_auth_fail_count');
                        delete_option('wc_mcp_tenant_id');
                        delete_option('wc_mcp_auth_token');
                        delete_option('wc_mcp_bridge_secret');
                        delete_option('wc_mcp_bridge_token_hash');
                        \Clariq\McpPlugin\Security\WriteSecretManager::clear();
                        // Tear down any in-flight backfill/delta jobs + cursors so
                        // the UI doesn't show a phantom "in progress" backfill or a
                        // stuck pending job after the connection is gone.
                        self::reset_sync_state();
                        // $is_connected stays false → UI shows the reconnect prompt.
                    } else {
                        // Not yet confident it's a real revocation — stay optimistic
                        // and preserve credentials so the next poll can confirm.
                        update_option('wc_mcp_auth_fail_count', $strikes);
                        $is_connected = true;
                    }
                } else {
                    // 5xx or unexpected — keep credentials, show as connected.
                    $is_connected = true;
                }
            }
        }

        // Self-heal: when in cloud mode with no live connection (auto-disconnected,
        // or an abandoned connect that never completed), make sure no stale backfill
        // cursor or scheduled jobs linger — otherwise the UI reports a phantom
        // "In progress…" backfill and a pending job while showing "Disconnected".
        if ('cloud_sync' === $mode && !$is_connected && self::has_stale_sync_state()) {
            self::reset_sync_state();
        }

        $pending_jobs = 0;
        if (function_exists('as_get_scheduled_actions')) {
            $pending_jobs = count(as_get_scheduled_actions([
                'hook'   => 'wc_mcp_backfill_batch',
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ]));
        }

        $last_sync_ts = get_option('wc_mcp_last_sync_timestamp', 0);
        $last_sync    = $last_sync_ts
            ? human_time_diff((int) $last_sync_ts, time()) . ' ago'
            : 'Never';

        return new \WP_REST_Response([
            'mode'               => $mode,
            'is_connected'       => $is_connected,
            'tenant_id'          => $is_connected ? $tenant_id : '',
            'warehouse_sync'     => [
                'connected'   => $is_connected,
                'last_synced' => $last_sync,
            ],
            'bridge_latency'     => ($v = get_option('wc_mcp_bridge_latency')) !== false ? (float) $v : null,
            'pending_jobs'       => $pending_jobs,
            'plan'                => get_option('wc_mcp_plan', 'free'),
            'retention_days'      => self::retention_days(),        // null = unlimited history
            'max_backfill_months' => self::max_backfill_months(),   // null = unlimited
            'backfill'           => self::backfill_progress(),      // live import progress for the UI
            'store_stats'        => self::store_stats(),            // 30-day KPI snapshot (null = hide)
            'plugin_version'     => WC_MCP_VERSION,
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function update_settings(\WP_REST_Request $request): \WP_REST_Response {
        $is_connected = (bool) get_option('wc_mcp_tenant_id', '');

        if ($request->has_param('connection_mode')) {
            if ($is_connected) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Disconnect from Clariq before changing connection mode.',
                ], 403);
            }
            update_option('wc_mcp_connection_mode', $request->get_param('connection_mode'));
        }

        if ($request->has_param('backfill_range')) {
            $range = (int) $request->get_param('backfill_range');
            // Free (controlled-backfill) stores cap the sync window; clamp the
            // requested months down to the plan's max so the UI can't exceed it.
            $max = self::max_backfill_months();
            if ($max !== null && $range > $max) {
                $range = $max;
            }
            update_option('wc_mcp_backfill_range', $range);
        }

        if ($request->has_param('sync_hour')) {
            $hour = (int) $request->get_param('sync_hour');
            update_option('wc_mcp_sync_hour', $hour);
            // Reschedule the delta sync at the new hour.
            \Clariq\McpPlugin\Sync\DeltaSyncWorker::reschedule($hour);
        }

        // Advanced: Clariq Cloud URL override (staging/local testing without wp-config).
        if ($request->has_param('clariq_url')) {
            $raw = trim((string) $request->get_param('clariq_url'));
            if ($raw === '') {
                delete_option('wc_mcp_clariq_url_override');
            } else {
                $parts = wp_parse_url($raw);
                if (!$parts || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
                    return new \WP_REST_Response([
                        'success' => false,
                        'message' => 'Invalid URL — must start with http:// or https://.',
                    ], 400);
                }
                update_option('wc_mcp_clariq_url_override', untrailingslashit(esc_url_raw($raw)));
            }
        }

        return new \WP_REST_Response(['success' => true]);
    }

    /**
     * Resolved Clariq Cloud URL: admin override option wins, else the constant.
     */
    public static function resolved_clariq_url(): string {
        $override = get_option('wc_mcp_clariq_url_override', '');
        return $override ? untrailingslashit((string) $override) : WC_MCP_CLARIQ_URL;
    }

    /**
     * Effective data-retention window (in days) for this store's plan, as last
     * reported by the SaaS API via /v1/stores/me. Returns null for unlimited
     * history (paid plans). Free stores on cloud sync get a 30-day window (the
     * "controlled backfill" tier); this drives both the sync cap and the UI.
     */
    public static function retention_days(): ?int {
        $days = (int) get_option('wc_mcp_retention_days', 0);
        return $days > 0 ? $days : null;
    }

    /**
     * The retention window expressed as whole backfill months (rounded up), or
     * null when history is unlimited. Used to cap the backfill range selector.
     */
    public static function max_backfill_months(): ?int {
        $days = self::retention_days();
        if ($days === null) {
            return null; // unlimited
        }
        return max(1, (int) ceil($days / 30));
    }

    /**
     * At-a-glance store KPIs for the last 30 days, read from the pre-aggregated
     * HPOS analytics table (wp_wc_order_stats). The window is fixed at 30 days
     * for every plan — it's a headline snapshot, not the (plan-capped) sync
     * range. Cached briefly so the 30s status poll doesn't re-run the aggregate.
     *
     * Returns null when WooCommerce/stats aren't available or there are no
     * qualifying orders, so the UI can cleanly hide the section.
     *
     * @return array<string, mixed>|null
     */
    public static function store_stats(): ?array {
        $cached = get_transient('wc_mcp_store_stats');
        if (is_array($cached)) {
            return $cached['orders'] > 0 ? $cached : null;
        }

        if (!function_exists('WC') || !function_exists('get_woocommerce_currency')) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_order_stats';

        // Guard: the analytics table may not exist yet (fresh install / analytics
        // disabled). Avoid a fatal SQL error — just skip the section.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return null;
        }

        // Same status set + local-date convention as the MCP sales tool, so the
        // dashboard snapshot and the AI answers agree.
        $statuses   = "'wc-completed','wc-processing','wc-shipped'";
        $start_date = date('Y-m-d', strtotime('-30 days')) . ' 00:00:00';
        $end_date   = date('Y-m-d') . ' 23:59:59';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(order_id)                               AS orders,
                    COALESCE(SUM(total_sales), 0)                 AS revenue,
                    ROUND(COALESCE(SUM(total_sales) / NULLIF(COUNT(order_id), 0), 0), 2) AS avg_order_value,
                    COUNT(DISTINCT customer_id)                   AS unique_buyers
                 FROM {$table}
                 WHERE status IN ({$statuses})
                   AND date_created BETWEEN %s AND %s",
                $start_date,
                $end_date
            ),
            ARRAY_A
        );

        $stats = [
            'currency'        => get_woocommerce_currency(),
            'revenue'         => (float) ($row['revenue'] ?? 0),
            'avg_order_value' => (float) ($row['avg_order_value'] ?? 0),
            'orders'          => (int)   ($row['orders'] ?? 0),
            'unique_buyers'   => (int)   ($row['unique_buyers'] ?? 0),
            'window_days'     => 30,
        ];

        set_transient('wc_mcp_store_stats', $stats, 5 * MINUTE_IN_SECONDS);

        return $stats['orders'] > 0 ? $stats : null;
    }

    /**
     * REST: GET /wc-mcp/v1/connect/check
     * Probes the resolved Clariq Cloud URL reachability so the admin gets a
     * clear DNS/HTTP diagnosis instead of a silent failed redirect.
     */
    public function handle_connect_check(\WP_REST_Request $request): \WP_REST_Response {
        $target = self::resolved_clariq_url() . '/login';
        $response = wp_remote_get($target, ['timeout' => 6, 'redirection' => 3]);
        if (is_wp_error($response)) {
            return new \WP_REST_Response([
                'reachable' => false,
                'target'    => $target,
                'error'     => $response->get_error_message(),
            ]);
        }
        return new \WP_REST_Response([
            'reachable' => true,
            'target'    => $target,
            'http_code' => (int) wp_remote_retrieve_response_code($response),
        ]);
    }

    /**
     * REST: POST /wc-mcp/v1/bridge/rotate-secret
     * Generates a new local bridge secret and returns it so the admin UI can
     * display it once. Any previously configured MCP clients must be updated.
     */
    public function handle_rotate_secret(\WP_REST_Request $request): \WP_REST_Response {
        $secret = \Clariq\McpPlugin\Security\BridgeKeyManager::rotate();

        return new \WP_REST_Response([
            'success'       => true,
            'bridge_secret' => $secret,
        ]);
    }

    public function handle_force_sync(): void {
        check_ajax_referer('wc_mcp_force_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('wc_mcp_force_sync', [], 'wc-mcp');
        }

        wp_send_json_success(['message' => 'Force sync queued.']);
    }

    public function register_connect_routes(): void {
        // Initiates the connect flow — redirects browser to Clariq.
        register_rest_route('wc-mcp/v1', '/connect/initiate', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => ['\Clariq\McpPlugin\Onboarding\ConnectFlow', 'initiate'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);

        // Receives credentials back from Clariq after successful auth.
        register_rest_route('wc-mcp/v1', '/connect/callback', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => ['\Clariq\McpPlugin\Onboarding\ConnectFlow', 'handle_callback'],
            'permission_callback' => '__return_true', // Public — verified by state + single-use code exchange
        ]);

        // Disconnect: clears local credentials only (soft disconnect).
        register_rest_route('wc-mcp/v1', '/connect/disconnect', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'handle_disconnect'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);

        // Reachability probe for the resolved Clariq Cloud URL.
        register_rest_route('wc-mcp/v1', '/connect/check', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_connect_check'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);
    }

    public function handle_disconnect(\WP_REST_Request $request): \WP_REST_Response {
        // ── Notify Clariq SaaS ───────────────────────────────────────────────
        // Mark the tenant inactive on the SaaS side so the Clariq dashboard
        // reflects the disconnected state. Must happen before we clear the
        // local credentials (we need the auth token for the request).
        // Errors are silently ignored — local disconnect always proceeds.
        $tenant_id  = get_option('wc_mcp_tenant_id');
        $auth_token = get_option('wc_mcp_auth_token');

        if ($tenant_id && $auth_token) {
            wp_remote_request(
                rtrim(WC_MCP_CLARIQ_INTERNAL_URL, '/') . '/v1/stores/me',
                [
                    'method'  => 'DELETE',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $auth_token,
                        'Content-Type'  => 'application/json',
                    ],
                    'timeout' => 5,
                ]
            );
        }

        // ── Credentials ──────────────────────────────────────────────────────
        // Clear WP-side credentials. Tenant data on Clariq is preserved;
        // reconnecting will reactivate the tenant automatically.
        delete_option('wc_mcp_tenant_id');
        delete_option('wc_mcp_auth_token');
        delete_option('wc_mcp_bridge_secret');
        delete_option('wc_mcp_bridge_token_hash');
        delete_option('wc_mcp_auth_fail_count');

        // Drop the dedicated cloud-write secret alongside the other credentials.
        \Clariq\McpPlugin\Security\WriteSecretManager::clear();

        // ── Sync state ───────────────────────────────────────────────────────
        // Clear all sync state so a fresh reconnect starts from a clean slate.
        // Merchant preferences (connection_mode, backfill_range) are intentionally kept.
        self::reset_sync_state();

        return new \WP_REST_Response(['success' => true, 'message' => 'Disconnected successfully.']);
    }

    /**
     * Clears backfill/delta sync cursors and cancels any in-flight or pending
     * Action Scheduler jobs. Merchant preferences (connection_mode, backfill_range,
     * sync_hour) are intentionally preserved. Shared by explicit disconnect, the
     * auto-disconnect (repeated-401) path, and the disconnected-state self-heal.
     */
    private static function reset_sync_state(): void {
        delete_option('wc_mcp_backfill_complete');
        delete_option('wc_mcp_backfill_last_id');
        delete_option('wc_mcp_backfill_since');
        delete_option('wc_mcp_backfill_processed');
        delete_option('wc_mcp_backfill_run_id');
        delete_option('wc_mcp_backfill_batch_attempts');
        delete_option('wc_mcp_reconcile_done');
        delete_option('wc_mcp_reconcile_since');
        delete_option('wc_mcp_reconcile_last_id');
        delete_option('wc_mcp_last_sync_timestamp');
        delete_option('wc_mcp_sync_error');

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wc_mcp_backfill_batch', [], 'wc-mcp');
            as_unschedule_all_actions('wc_mcp_backfill_reconcile', [], 'wc-mcp');
            as_unschedule_all_actions('wc_mcp_delta_sync',     [], 'wc-mcp');
        }
    }

    /**
     * True when backfill/sync artifacts exist (a live cursor, a completion flag,
     * or scheduled jobs) — used to decide whether a disconnected store needs its
     * stale sync state cleaned up.
     */
    private static function has_stale_sync_state(): bool {
        if (get_option('wc_mcp_backfill_last_id') !== false
            || get_option('wc_mcp_backfill_complete')
            || get_option('wc_mcp_last_sync_timestamp')) {
            return true;
        }
        if (function_exists('as_has_scheduled_action')) {
            return as_has_scheduled_action('wc_mcp_backfill_batch', [], 'wc-mcp')
                || as_has_scheduled_action('wc_mcp_delta_sync', [], 'wc-mcp');
        }
        return false;
    }

    /**
     * REST: POST /wc-mcp/v1/sync-now
     * Enqueues an immediate delta sync (orders since last sync only).
     */
    public function handle_sync_now(\WP_REST_Request $request): \WP_REST_Response {
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Sync Now is only available in Cloud Sync mode.',
            ], 400);
        }

        if (!get_option('wc_mcp_tenant_id')) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Store is not connected to Clariq.',
            ], 400);
        }

        \Clariq\McpPlugin\Sync\DeltaSyncWorker::enqueue_immediate();

        return new \WP_REST_Response(['success' => true, 'message' => 'Delta sync queued.']);
    }

    /**
     * REST: POST /wc-mcp/v1/backfill/restart
     * Re-runs the full historical import for the currently saved backfill range.
     * Used after a plan upgrade widens the allowed window (or to redo an import).
     * Clears the previous cursor/total/completion marker and any in-flight batches,
     * then reschedules from offset 0. The retention clamp in BackfillWorker still
     * applies, so a free store can't pull beyond its 30-day window this way.
     */
    public function handle_backfill_restart(\WP_REST_Request $request): \WP_REST_Response {
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Re-import is only available in Cloud Sync mode.',
            ], 400);
        }

        if (!get_option('wc_mcp_tenant_id')) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Store is not connected to Clariq.',
            ], 400);
        }

        // Clear the previous import's state so progress + status reset cleanly.
        delete_option('wc_mcp_backfill_complete');
        delete_option('wc_mcp_backfill_last_id');
        delete_option('wc_mcp_backfill_since');
        delete_option('wc_mcp_backfill_processed');
        delete_option('wc_mcp_backfill_batch_attempts');
        delete_option('wc_mcp_backfill_total');
        delete_option('wc_mcp_reconcile_done');
        delete_option('wc_mcp_reconcile_since');
        delete_option('wc_mcp_reconcile_last_id');

        // Drop any pending batches so the fresh run below isn't racing a stale one.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wc_mcp_backfill_batch', [], 'wc-mcp');
        }

        // Deterministically start the run. NOT maybe_schedule_backfill(): its
        // "already queued?" guard can see a just-canceled/in-flight action and
        // bail, which would leave the store at idle/0 after we've already wiped
        // the previous completion state (the re-import-does-nothing bug).
        \Clariq\McpPlugin\Sync\BackfillWorker::start_backfill_now();

        return new \WP_REST_Response(['success' => true, 'message' => 'Historical re-import queued.']);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Determines the current state of the historical backfill job.
     *
     * @return 'idle'|'running'|'complete'
     */
    private static function get_backfill_status(): string {
        // A live keyset cursor (wc_mcp_backfill_last_id) means a batch run is in
        // progress. It exists for the duration of a run and is deleted on completion.
        $cursor_exists = get_option('wc_mcp_backfill_last_id') !== false;

        // A live cursor is the only unambiguous signal of an in-flight run.
        if ($cursor_exists) {
            return 'running';
        }

        // Completion is AUTHORITATIVE once the cursor is gone. process_batch()
        // deletes wc_mcp_backfill_last_id and sets wc_mcp_backfill_complete when a
        // run finishes. A stray/duplicate wc_mcp_backfill_batch action can linger
        // in the Action Scheduler queue after that (it no-ops via process_batch()'s
        // completion guard when it fires) — but if we let a merely-queued action
        // report "running" here, the admin UI shows a perpetual "importing" screen
        // for an import that already finished (offset gone, completed_at set, all
        // orders in the warehouse). So check completion BEFORE the pending action.
        if (get_option('wc_mcp_backfill_complete')) {
            return 'complete';
        }

        // No live cursor and not marked complete: a queued batch means a fresh run
        // is about to start (e.g. just scheduled, first batch not yet executed).
        $action_pending = function_exists('as_has_scheduled_action')
            && as_has_scheduled_action('wc_mcp_backfill_batch', [], 'wc-mcp');
        if ($action_pending) {
            return 'running';
        }

        return 'idle';
    }

    /**
     * Live progress of the historical import, for the admin UI.
     *
     * `processed` is the running count of orders sent so far (Phase 3:
     * wc_mcp_backfill_processed, incremented per confirmed batch) while the
     * import is in flight, and the final count once complete. It is NOT derived
     * from the keyset cursor (wc_mcp_backfill_last_id) — that holds an order id,
     * not a count, and would read as a meaningless huge number. `total` is
     * counted on the first batch. `window_label` is the date range the import
     * targets. Together these let the UI show a progress bar and "X / Y orders"
     * without any realtime channel — the merchant refreshes.
     *
     * @return array{status:string, processed:int, total:int, completed_at:string, window_label:string}
     */
    private static function backfill_progress(): array {
        $status = self::get_backfill_status();
        $total  = (int) get_option('wc_mcp_backfill_total', 0);

        if ($status === 'complete') {
            // Report the TRUE number of orders sent (persisted at completion),
            // not a forced `= $total`. These can differ when the run ended with
            // fewer orders than the initial count implied (e.g. orders deleted
            // mid-run, or a status-set mismatch between the count and fetch
            // queries). Falling back to $total only when the real figure is
            // missing (older installs that completed before this was tracked).
            $processed = (int) get_option('wc_mcp_backfill_processed', $total);
            // Never display more than we counted, and if we somehow sent the full
            // set, snap to total so the bar reads a clean 100%.
            if ($processed > $total && $total > 0) {
                $processed = $total;
            }
        } else {
            // In flight (or idle): the live running count of confirmed orders.
            $processed = (int) get_option('wc_mcp_backfill_processed', 0);
        }

        return [
            'status'       => $status,
            'processed'    => $processed,
            'total'        => $total,
            'completed_at' => self::format_option_timestamp('wc_mcp_backfill_complete'),
            'window_label' => self::backfill_window_label(),
        ];
    }

    /**
     * Human-readable date range the historical import targets: from the
     * (retention-clamped) range floor to today. Mirrors the $since computation
     * in BackfillWorker::process_batch() so the UI shows exactly what was pulled.
     */
    private static function backfill_window_label(): string {
        $range    = (int) get_option('wc_mcp_backfill_range', 12);
        $since_ts = strtotime("-{$range} months");

        // Free (controlled-backfill) plans clamp the window to the retention floor.
        $retention_days = (int) get_option('wc_mcp_retention_days', 0);
        if ($retention_days > 0) {
            $floor = strtotime("-{$retention_days} days");
            if ($floor !== false && $floor > $since_ts) {
                $since_ts = $floor;
            }
        }

        if ($since_ts === false) {
            return '';
        }

        // Match format_option_timestamp(): wp_timezone() + DateTime (no date_i18n,
        // which keeps this callable under the unit-test harness too).
        $tz    = wp_timezone();
        $start = (new \DateTime('@' . $since_ts))->setTimezone($tz);
        $end   = (new \DateTime('@' . time()))->setTimezone($tz);

        return $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
    }

    /**
     * Formats a Unix timestamp stored in a wp_option as a human-readable string.
     * Returns an empty string if the option is not set or zero.
     */
    private static function format_option_timestamp(string $option_name): string {
        $ts = (int) get_option($option_name, 0);
        if ($ts <= 0) {
            return '';
        }

        $tz = wp_timezone();
        $dt = new \DateTime('@' . $ts);
        $dt->setTimezone($tz);

        // e.g. "Jun 2, 2026 at 9:42 AM"
        return $dt->format('M j, Y \a\t g:i A');
    }
}
