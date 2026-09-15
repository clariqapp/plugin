<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Onboarding;

/**
 * Registers the WordPress site with the Clariq SaaS Management API
 * during plugin activation and retrieves tenant credentials.
 *
 * Credentials stored in wp_options:
 *   wc_mcp_tenant_id            — public tenant identifier
 *   wc_mcp_auth_token           — plaintext token (used for HMAC; stored encrypted)
 *   wc_mcp_bridge_token_hash    — SHA-256 hash of the bridge HMAC key
 *   wc_mcp_connection_mode      — 'option_a' | 'option_c'
 */
final class HandshakeClient {

    private const REQUEST_TIMEOUT        = 15; // seconds

    /**
     * SaaS API base URL — the internal (API) URL, not the dashboard URL.
     * Falls back to WC_MCP_CLARIQ_URL (app.clariqapp.com) when the internal
     * URL is not defined; production should define WC_MCP_CLARIQ_INTERNAL_URL.
     */
    private static function saas_base_url(): string {
        return (string) apply_filters(
            'wc_mcp_clariq_internal_url',
            defined('WC_MCP_CLARIQ_INTERNAL_URL') ? WC_MCP_CLARIQ_INTERNAL_URL : WC_MCP_CLARIQ_URL
        );
    }

    /**
     * Called once on plugin activation.
     * Skips gracefully if already registered.
     */
    public static function register_site(): void {
        // Registration is now handled via the browser-based connect flow.
        // See ConnectFlow::initiate() and ConnectFlow::handle_callback().
        // This method is kept for backwards compatibility only.
    }

    /**
     * Deregisters the site from the SaaS API and scrubs local credentials.
     * Called from uninstall.php (not deactivation — data is preserved on deactivate).
     */
    public static function deregister_site(): void {
        $tenant_id  = get_option('wc_mcp_tenant_id');
        $auth_token = get_option('wc_mcp_auth_token');

        if (!$tenant_id || !$auth_token) {
            return;
        }

        wp_remote_request(
            self::saas_base_url() . '/v1/tenants/' . rawurlencode($tenant_id),
            [
                'method'  => 'DELETE',
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $auth_token,
                    'Accept'        => 'application/json',
                ],
            ]
        );

        // Scrub all stored credentials regardless of API response.
        self::purge_credentials();
    }

    /**
     * Removes all plugin-specific options from wp_options.
     */
    public static function purge_credentials(): void {
        $keys = [
            'wc_mcp_tenant_id',
            'wc_mcp_auth_token',
            'wc_mcp_bridge_token_hash',
            'wc_mcp_bridge_secret',
            'wc_mcp_connection_mode',
            'wc_mcp_backfill_range',
            'wc_mcp_last_sync_timestamp',
            'wc_mcp_backfill_offset',
            'wc_mcp_bridge_latency',
            'wc_mcp_auth_fail_count',
            'wc_mcp_sync_error',
        ];

        foreach ($keys as $key) {
            delete_option($key);
        }
    }

    private static function log(string $message, string $level = 'info'): void {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'wc-analytics-mcp']);
        }
    }
}
