<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Bridge;

use Clariq\McpPlugin\Security\WriteSignatureValidator;
use Clariq\McpPlugin\Write\WriteRouter;

/**
 * Registers the cloud -> WordPress WRITE relay endpoint.
 *
 * Route: POST /wp-json/mcp-bridge/v1/write
 *
 * This is the write-path counterpart to RestEndpoint (the read bridge), but
 * with the INVERSE mode requirement — an intentional trust-boundary change:
 *  - The read bridge (RestEndpoint) only works in `local_bridge` mode.
 *  - This write relay only works in `cloud_sync` mode, since hosted writes are
 *    driven by the Clariq gateway and must be off for self-hosted stores.
 *
 * Auth: HMAC-SHA256 over the raw request body using the dedicated write secret
 * (wc_mcp_write_secret), NEVER the local bridge secret. Signature is transported
 * in X-MCP-Write-Signature and matches the SaaS signer byte-for-byte.
 *
 * The endpoint delegates to WriteRouter and returns the site-relay envelope.
 * Downstream (handler-level) outcomes always return HTTP 200 with ok:false so
 * the gateway can distinguish gate failures (4xx here) from write outcomes.
 */
final class WriteEndpoint {

    public function register_routes(): void {
        register_rest_route('mcp-bridge/v1', '/write', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_request'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Permission callback: cloud_sync-only + write-key HMAC.
     */
    public function check_permission(\WP_REST_Request $request): bool|\WP_Error {
        // Intentional inverse of the read bridge: cloud writes are only accepted
        // when the store is in Cloud Sync mode.
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return new \WP_Error(
                'mcp_write_wrong_mode',
                'Cloud writes are only accepted when the store is in Cloud Sync mode.',
                ['status' => 403]
            );
        }

        // Reject when no write secret is configured — never fall through to a
        // signature check against an empty key.
        if ((string) get_option('wc_mcp_write_secret', '') === '') {
            return new \WP_Error(
                'mcp_invalid_write_signature',
                'No write secret is configured for this site.',
                ['status' => 401]
            );
        }

        if (!WriteSignatureValidator::validate($request)) {
            return new \WP_Error(
                'mcp_invalid_write_signature',
                'Invalid or missing write signature.',
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Dispatch the write action to its handler and return the relay envelope.
     */
    public function handle_request(\WP_REST_Request $request): \WP_REST_Response {
        $body = json_decode($request->get_body(), true);

        $action = is_array($body) && isset($body['action']) ? (string) $body['action'] : '';
        $params = is_array($body) && isset($body['params']) && is_array($body['params'])
            ? $body['params']
            : [];

        $result = (new WriteRouter())->route($action, $params);

        // Handler-level outcomes are HTTP 200 with ok:false; only permission
        // gate failures (above) use 4xx.
        return new \WP_REST_Response($result, 200);
    }
}
