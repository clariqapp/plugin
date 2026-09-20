<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Onboarding;

/**
 * Handles the browser-based OAuth-style connect flow between the
 * WordPress plugin and the Clariq SaaS platform.
 */
final class ConnectFlow {

    private const STATE_OPTION = 'wc_mcp_connect_state';
    private const STATE_TTL    = 900; // 15 minutes in seconds

    /**
     * REST callback: GET /wp-json/wc-mcp/v1/connect/initiate
     * Generates a state token, stores it, and returns the Clariq connect URL as JSON.
     * The React UI performs the actual browser redirect via window.location.href.
     *
     * Accepts an optional `connection_mode` query param from the React UI so the
     * user's current toggle selection is persisted to wp_options before the
     * OAuth redirect — even if they never clicked "Save Changes".
     *
     * @return \WP_REST_Response
     */
    public static function initiate(\WP_REST_Request $request): \WP_REST_Response {
        // Persist the mode the user has selected in React (if supplied).
        $mode = sanitize_text_field($request->get_param('connection_mode') ?? '');
        if (in_array($mode, ['cloud_sync', 'local_bridge'], true)) {
            update_option('wc_mcp_connection_mode', $mode);
        }

        // Generate state token
        $state = wp_generate_password(32, false, false);
        set_transient(self::STATE_OPTION, $state, self::STATE_TTL);

        // Build callback URL (points back to this plugin's REST endpoint)
        $callback = rest_url('wc-mcp/v1/connect/callback');

        // Build Clariq connect URL — mode is now guaranteed to be the user's choice.
        $connect_url = add_query_arg([
            'store_url'       => home_url(),
            'state'           => $state,
            'callback'        => $callback,
            'connection_mode' => get_option('wc_mcp_connection_mode', 'local_bridge'),
        ], \Clariq\McpPlugin\Admin\SettingsPage::resolved_clariq_url() . '/connect');

        return new \WP_REST_Response(['redirect_url' => $connect_url], 200);
    }

    /**
     * REST callback: GET /wp-json/wc-mcp/v1/connect/callback
     * Receives a single-use ``code`` from Clariq, verifies the CSRF ``state``,
     * then exchanges the code server-to-server for the store credentials. No
     * shared signing key is involved — the response is trusted because this
     * request goes directly to the known Clariq API host over TLS.
     */
    public static function handle_callback(\WP_REST_Request $request): void {
        $state = sanitize_text_field($request->get_param('state') ?? '');
        $code  = sanitize_text_field($request->get_param('code') ?? '');

        // Verify state (CSRF). Keep the transient until the exchange succeeds so a
        // transient network blip on exchange doesn't burn the attempt permanently.
        $stored_state = get_transient(self::STATE_OPTION);
        if (!$stored_state || !hash_equals($stored_state, $state)) {
            self::redirect_with_error('invalid_state');
            return;
        }

        if (empty($code)) {
            self::redirect_with_error('missing_params');
            return;
        }

        // ── Exchange the code for credentials (server-to-server) ─────────────
        $exchange_url = rtrim(self::saas_internal_url(), '/') . '/v1/connect/exchange';
        $response = wp_remote_post($exchange_url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode([
                'code'      => $code,
                'store_url' => home_url(),
            ]),
        ]);

        if (is_wp_error($response)) {
            self::log(
                'Connect exchange unreachable at ' . $exchange_url . ': ' . $response->get_error_message(),
                'error'
            );
            self::redirect_with_error('exchange_unreachable');
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200 || !is_array($body) || empty($body['tenant_id']) || empty($body['auth_token']) || empty($body['bridge_secret'])) {
            self::log(
                sprintf(
                    'Connect exchange failed: POST %s returned HTTP %s. Detail: %s',
                    $exchange_url,
                    $status,
                    is_array($body) && isset($body['detail']) ? (string) $body['detail'] : substr((string) wp_remote_retrieve_body($response), 0, 200)
                ),
                'error'
            );
            self::redirect_with_error('exchange_failed');
            return;
        }

        // Exchange succeeded — now the state can be safely consumed.
        delete_transient(self::STATE_OPTION);

        $tenant_id     = sanitize_text_field((string) $body['tenant_id']);
        $auth_token    = sanitize_text_field((string) $body['auth_token']);
        $bridge_secret = sanitize_text_field((string) $body['bridge_secret']);

        // Store credentials
        update_option('wc_mcp_tenant_id',        $tenant_id,                     false);
        update_option('wc_mcp_auth_token',        $auth_token,                    false);
        update_option('wc_mcp_bridge_secret',     $bridge_secret,                 false);
        update_option('wc_mcp_bridge_token_hash', hash('sha256', $bridge_secret), false);
        // Clear any stale sync-error / auth-failure flags from a previous connection.
        delete_option('wc_mcp_sync_error');
        delete_option('wc_mcp_auth_fail_count');

        // Kick off a fresh historical backfill and schedule the daily delta sync.
        // Only applicable for Cloud Sync mode — Local Bridge queries data live
        // and never pushes anything to the cloud.
        if (get_option('wc_mcp_connection_mode', 'local_bridge') === 'cloud_sync') {
            \Clariq\McpPlugin\Sync\BackfillWorker::maybe_schedule_backfill();
            \Clariq\McpPlugin\Sync\DeltaSyncWorker::maybe_schedule();

            // Pull the dedicated cloud-write secret over the authed sync channel.
            // Best-effort — never blocks the connect flow.
            \Clariq\McpPlugin\Security\WriteSecretManager::pull();
        }

        // Redirect to admin page with success flag
        wp_redirect(admin_url('admin.php?page=wc-analytics-mcp&connected=1'));
        exit;
    }

    /**
     * SaaS API base URL for server-to-server calls (the internal/API URL, not
     * the dashboard URL). Filterable; falls back to the dashboard URL constant.
     */
    private static function saas_internal_url(): string {
        return (string) apply_filters(
            'wc_mcp_clariq_internal_url',
            defined('WC_MCP_CLARIQ_INTERNAL_URL') ? WC_MCP_CLARIQ_INTERNAL_URL : WC_MCP_CLARIQ_URL
        );
    }

    private static function redirect_with_error(string $code): void {
        wp_redirect(admin_url('admin.php?page=wc-analytics-mcp&connect_error=' . $code));
        exit;
    }

    private static function log(string $message, string $level = 'info'): void {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'wc-analytics-mcp']);
        }
    }
}
