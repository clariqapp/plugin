<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Security;

/**
 * Validates inbound HMAC signatures on cloud -> WordPress WRITE relay requests.
 *
 * This is the write-path counterpart to TokenValidator (which guards the READ
 * bridge). It uses a SEPARATE secret (wc_mcp_write_secret) — the local bridge
 * secret is NEVER reused for writes.
 *
 * Scheme (must match the SaaS signer byte-for-byte):
 *   signature = HMAC_SHA256(raw_request_body, wc_mcp_write_secret)  // hex
 *   transported in the header  X-MCP-Write-Signature
 *
 * The bytes signed are exactly the bytes received (the raw request body), so
 * the plugin re-derives the HMAC over $request->get_body() and never re-encodes.
 */
final class WriteSignatureValidator {

    public const SIGNATURE_HEADER = 'X-MCP-Write-Signature';

    /**
     * @return bool True when the provided signature matches the expected HMAC.
     */
    public static function validate(\WP_REST_Request $request): bool {
        $provided_signature = $request->get_header(self::SIGNATURE_HEADER);
        $write_secret       = (string) get_option('wc_mcp_write_secret', '');

        if (empty($provided_signature) || $write_secret === '') {
            return false;
        }

        // Sign the raw body exactly as received — no re-encoding.
        $expected_sig = hash_hmac('sha256', $request->get_body(), $write_secret);

        // Timing-safe comparison.
        return hash_equals($expected_sig, $provided_signature);
    }
}
