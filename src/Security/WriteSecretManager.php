<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Security;

/**
 * Manages the dedicated per-store WRITE secret used to authenticate
 * cloud -> WordPress write relay requests (hosted MCP writes).
 *
 * This secret is DISTINCT from the local bridge secret (wc_mcp_bridge_secret):
 *  - The bridge secret authenticates the self-hosted (local_bridge) READ path
 *    and is generated locally.
 *  - The write secret is generated + encrypted at rest by the Clariq SaaS,
 *    dashboard-rotatable, and PULLED by the plugin over its existing
 *    authenticated (bearer auth-token) sync channel — the SAME channel the
 *    order ingest uses. It is never reused for reads and never generated locally.
 *
 * Storage (both with autoload disabled — only needed on the write REST path,
 * never on every page load):
 *  - wc_mcp_write_secret    — the raw write key, used to verify inbound HMACs
 *  - wc_mcp_write_secret_fp — the SaaS-provided fingerprint, for diagnostics only
 */
final class WriteSecretManager {

    public const SECRET_OPTION = 'wc_mcp_write_secret';
    public const FP_OPTION      = 'wc_mcp_write_secret_fp';

    /** Best-effort network timeout for the pull (seconds). */
    private const REQUEST_TIMEOUT = 10;

    /**
     * True when a write secret is stored.
     */
    public static function has_secret(): bool {
        return (string) get_option(self::SECRET_OPTION, '') !== '';
    }

    /**
     * Persist the write secret (+ optional fingerprint) with autoload disabled.
     */
    public static function store(string $secret, string $fingerprint = ''): void {
        update_option(self::SECRET_OPTION, $secret, false);
        if ($fingerprint !== '') {
            update_option(self::FP_OPTION, $fingerprint, false);
        }
    }

    /**
     * Remove the write secret + fingerprint. Called on disconnect and uninstall.
     */
    public static function clear(): void {
        delete_option(self::SECRET_OPTION);
        delete_option(self::FP_OPTION);
    }

    /**
     * Pull the dedicated write secret from the SaaS over the existing bearer
     * auth-token channel (GET /v1/connect/write-secret) and store it.
     *
     * Best-effort and NON-BLOCKING for callers: if the store is not connected,
     * the endpoint 404s (older SaaS), the network fails, or the payload is
     * empty, the stored secret is left UNCHANGED and the failure is logged.
     * This must never throw or block order ingest.
     *
     * Response shape: {write_secret, fingerprint, rotated_at}.
     *
     * @return bool True when a secret was fetched and stored.
     */
    public static function pull(): bool {
        $auth_token = (string) get_option('wc_mcp_auth_token', '');
        if ($auth_token === '') {
            return false;
        }

        $url = rtrim(self::saas_internal_url(), '/') . '/v1/connect/write-secret';

        $response = wp_remote_get($url, [
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $auth_token,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            self::log('Write-secret pull failed (network): ' . $response->get_error_message(), 'warning');
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        // 404 = endpoint not present (older SaaS). Leave any existing secret intact.
        if ($code === 404) {
            return false;
        }

        if ($code < 200 || $code >= 300) {
            self::log(sprintf('Write-secret pull returned HTTP %d.', $code), 'warning');
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['write_secret'])) {
            // Nothing usable — do not clobber the current secret.
            return false;
        }

        $secret      = sanitize_text_field((string) $body['write_secret']);
        $fingerprint = isset($body['fingerprint']) ? sanitize_text_field((string) $body['fingerprint']) : '';

        self::store($secret, $fingerprint);

        return true;
    }

    /**
     * SaaS API base URL for server-to-server calls (the internal/API URL used by
     * the order ingest). Filterable; falls back to the dashboard URL constant.
     * No cloud hostname is hardcoded here — the value comes from the already
     * configured constant/filter the plugin uses for ingest.
     */
    private static function saas_internal_url(): string {
        return (string) apply_filters(
            'wc_mcp_clariq_internal_url',
            defined('WC_MCP_CLARIQ_INTERNAL_URL') ? WC_MCP_CLARIQ_INTERNAL_URL : WC_MCP_CLARIQ_URL
        );
    }

    private static function log(string $message, string $level = 'info'): void {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'wc-analytics-mcp']);
        }
    }
}
