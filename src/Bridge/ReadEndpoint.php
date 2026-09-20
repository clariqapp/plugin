<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Bridge;

use Clariq\McpPlugin\Security\WriteSignatureValidator;
use Clariq\McpPlugin\Read\ReadRouter;

/**
 * Registers the cloud -> WordPress live-READ relay endpoint.
 *
 * Route: POST /wp-json/mcp-bridge/v1/read
 *
 * This is the read-path counterpart to WriteEndpoint and shares its EXACT
 * trust boundary and auth scheme so the plugin verifier is a single code path:
 *  - Only functional in `cloud_sync` mode (self-hosted stores use the local
 *    bridge for reads). Wrong mode -> 403 mcp_write_wrong_mode.
 *  - HMAC-SHA256 over the raw request body using the dedicated write secret
 *    (wc_mcp_write_secret), transported in X-MCP-Write-Signature and matching
 *    the SaaS signer byte-for-byte. Missing/invalid -> 401.
 *
 * Unlike /write, reads are non-mutating: no audit, no idempotency key. The body
 * is { resource, params, ts } and is dispatched via ReadRouter. Handler-level
 * outcomes return HTTP 200 with ok:false; only gate failures use 4xx.
 */
final class ReadEndpoint {

    public function register_routes(): void {
        register_rest_route('mcp-bridge/v1', '/read', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_request'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Permission callback: identical to the write relay — cloud_sync-only +
     * write-key HMAC over the raw body.
     */
    public function check_permission(\WP_REST_Request $request): bool|\WP_Error {
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'cloud_sync') {
            return new \WP_Error(
                'mcp_write_wrong_mode',
                'Cloud reads are only accepted when the store is in Cloud Sync mode.',
                ['status' => 403]
            );
        }

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
     * Dispatch the read resource to its handler and return the envelope.
     */
    public function handle_request(\WP_REST_Request $request): \WP_REST_Response {
        $body = json_decode($request->get_body(), true);

        $resource = is_array($body) && isset($body['resource']) ? (string) $body['resource'] : '';
        $params   = is_array($body) && isset($body['params']) && is_array($body['params'])
            ? $body['params']
            : [];

        $result = (new ReadRouter())->route($resource, $params);

        return new \WP_REST_Response($result, 200);
    }
}
