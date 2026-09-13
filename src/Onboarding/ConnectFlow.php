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
        // Block connect flow if signing key is not configured.
        if (!defined('WC_MCP_CLARIQ_SIGNING_KEY')) {
            return new \WP_REST_Response([
                'error'   => 'signing_key_missing',
                'message' => 'WC_MCP_CLARIQ_SIGNING_KEY is not defined. Add define(\'WC_MCP_CLARIQ_SIGNING_KEY\', \'your-key\'); to wp-config.php before connecting.',
            ], 503);
        }

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
     * Receives credentials from Clariq, verifies them, stores in wp_options.
     */
    public static function handle_callback(\WP_REST_Request $request): void {
        $tenant_id     = sanitize_text_field($request->get_param('tenant_id') ?? '');
        $auth_token    = sanitize_text_field($request->get_param('auth_token') ?? '');
        $bridge_secret = sanitize_text_field($request->get_param('bridge_secret') ?? '');
        $state         = sanitize_text_field($request->get_param('state') ?? '');
        $sig           = sanitize_text_field($request->get_param('sig') ?? '');

        // Verify state
        $stored_state = get_transient(self::STATE_OPTION);
        if (!$stored_state || !hash_equals($stored_state, $state)) {
            self::redirect_with_error('invalid_state');
            return;
        }
        delete_transient(self::STATE_OPTION);

        // All params must be present
        if (empty($tenant_id) || empty($auth_token) || empty($bridge_secret)) {
            self::redirect_with_error('missing_params');
            return;
        }

        // Verify Clariq's signature
        // Note: CLARIQ_SIGNING_KEY must match the SaaS API's CLARIQ_SIGNING_KEY
        if (!defined('WC_MCP_CLARIQ_SIGNING_KEY')) {
            self::redirect_with_error('signing_key_missing');
            return;
        }
        $signing_key = WC_MCP_CLARIQ_SIGNING_KEY;
        $expected_sig = hash_hmac(
            'sha256',
            "{$state}.{$tenant_id}.{$auth_token}.{$bridge_secret}",
            $signing_key
        );
        if (!hash_equals($expected_sig, $sig)) {
            self::redirect_with_error('invalid_signature');
            return;
        }

        // Store credentials
        update_option('wc_mcp_tenant_id',        $tenant_id,                     false);
        update_option('wc_mcp_auth_token',        $auth_token,                    false);
        update_option('wc_mcp_bridge_secret',     $bridge_secret,                 false);
        update_option('wc_mcp_bridge_token_hash', hash('sha256', $bridge_secret), false);

        // Kick off a fresh historical backfill and schedule the daily delta sync.
        // Only applicable for Cloud Sync mode — Local Bridge queries data live
        // and never pushes anything to the cloud.
        if (get_option('wc_mcp_connection_mode', 'local_bridge') === 'cloud_sync') {
            \Clariq\McpPlugin\Sync\BackfillWorker::maybe_schedule_backfill();
            \Clariq\McpPlugin\Sync\DeltaSyncWorker::maybe_schedule();
        }

        // Redirect to admin page with success flag
        wp_redirect(admin_url('admin.php?page=wc-analytics-mcp&connected=1'));
        exit;
    }

    private static function redirect_with_error(string $code): void {
        wp_redirect(admin_url('admin.php?page=wc-analytics-mcp&connect_error=' . $code));
        exit;
    }
}
