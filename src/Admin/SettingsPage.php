<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Admin;

/**
 * Registers the "WooCommerce > Analytics MCP" admin settings page
 * and enqueues the React admin UI build artefact.
 */
final class SettingsPage {

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
                } elseif ($code === 401) {
                    // Only a definitive 401 Unauthorized means the token is genuinely
                    // invalid. Any other non-200 (5xx, timeout, etc.) is treated
                    // optimistically to avoid wiping credentials on transient API errors.
                    delete_option('wc_mcp_tenant_id');
                    delete_option('wc_mcp_auth_token');
                    delete_option('wc_mcp_bridge_secret');
                    delete_option('wc_mcp_bridge_token_hash');
                } else {
                    // 5xx or unexpected — keep credentials, show as connected.
                    $is_connected = true;
                }
            }
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
            'plugin_version'     => WC_MCP_VERSION,
            'signing_key_defined' => defined('WC_MCP_CLARIQ_SIGNING_KEY'),
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
            update_option('wc_mcp_backfill_range', (int) $request->get_param('backfill_range'));
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
            'permission_callback' => '__return_true', // Public — verified by state+sig
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

        // ── Sync state ───────────────────────────────────────────────────────
        // Clear all sync state so a fresh reconnect starts from a clean slate.
        // Merchant preferences (connection_mode, backfill_range) are intentionally kept.
        delete_option('wc_mcp_backfill_complete');
        delete_option('wc_mcp_backfill_offset');
        delete_option('wc_mcp_last_sync_timestamp');

        // ── Action Scheduler jobs ────────────────────────────────────────────
        // Cancel any in-flight or pending jobs so they don't fire with stale auth.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wc_mcp_backfill_batch', [], 'wc-mcp');
            as_unschedule_all_actions('wc_mcp_delta_sync',     [], 'wc-mcp');
        }

        return new \WP_REST_Response(['success' => true, 'message' => 'Disconnected successfully.']);
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

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Determines the current state of the historical backfill job.
     *
     * @return 'idle'|'running'|'complete'
     */
    private static function get_backfill_status(): string {
        // A backfill_offset in wp_options means a batch run is in progress (cursor is live).
        $offset_exists = get_option('wc_mcp_backfill_offset') !== false;

        // Action Scheduler has a pending batch job.
        $action_pending = function_exists('as_has_scheduled_action')
            && as_has_scheduled_action('wc_mcp_backfill_batch', [], 'wc-mcp');

        if ($offset_exists || $action_pending) {
            return 'running';
        }

        // wc_mcp_backfill_complete is set once process_batch() exhausts all rows.
        if (get_option('wc_mcp_backfill_complete')) {
            return 'complete';
        }

        return 'idle';
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
