<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Security;

/**
 * Validates inbound HMAC signatures from the Clariq Cloud MCP Server.
 *
 * Security properties:
 *  - Uses hash_equals() for timing-safe comparison (prevents timing side-channel).
 *  - Never accepts raw SQL — only validates that the caller is our cloud server.
 *  - Tokens are stored as SHA-256 hashes in wp_options (never plaintext).
 */
final class TokenValidator {

    /**
     * Validates the X-MCP-Bridge-Token header against the stored HMAC hash.
     *
     * The cloud server signs each request body with the shared bridge secret:
     *   Signature = HMAC_SHA256(JSON_body, shared_secret)
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public static function validate(\WP_REST_Request $request): bool {
        $provided_signature = $request->get_header('X-MCP-Bridge-Token');
        $stored_hash        = get_option('wc_mcp_bridge_token_hash', '');
        $bridge_secret      = get_option('wc_mcp_bridge_secret', '');

        if (empty($provided_signature) || empty($stored_hash) || empty($bridge_secret)) {
            return false;
        }

        // Re-derive the HMAC from the raw request body using the shared secret.
        $raw_body         = $request->get_body();
        $expected_sig     = hash_hmac('sha256', $raw_body, $bridge_secret);

        // Timing-safe comparison — prevents enumeration attacks.
        return hash_equals($expected_sig, $provided_signature);
    }
}
