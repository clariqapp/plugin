<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Security;

/**
 * Manages the shared bridge secret used to authenticate the self-hosted
 * (local_bridge) MCP connection.
 *
 * The secret is generated locally — no Clariq account is required for
 * local bridge mode. It is stored twice:
 *  - plaintext in wc_mcp_bridge_secret (needed to compute request HMACs)
 *  - sha256 hash in wc_mcp_bridge_token_hash (what TokenValidator requires
 *    to be present before accepting any bridge request)
 */
final class BridgeKeyManager {

    public const SECRET_OPTION = 'wc_mcp_bridge_secret';
    public const HASH_OPTION   = 'wc_mcp_bridge_token_hash';

    /**
     * True when both the secret and its hash are stored.
     */
    public static function has_secret(): bool {
        return get_option(self::SECRET_OPTION, '') !== ''
            && get_option(self::HASH_OPTION, '') !== '';
    }

    /**
     * Returns the bridge secret, generating one on first use.
     * Called on plugin activation and lazily when the admin UI loads.
     */
    public static function ensure_secret(): string {
        $secret = (string) get_option(self::SECRET_OPTION, '');
        if ($secret === '') {
            $secret = self::rotate();
        }
        return $secret;
    }

    /**
     * Generates and stores a fresh secret, invalidating the previous one.
     *
     * @return string The new plaintext secret (only time it is returned).
     */
    public static function rotate(): string {
        $secret = wp_generate_password(48, false, false);
        update_option(self::SECRET_OPTION, $secret, false);
        update_option(self::HASH_OPTION, hash('sha256', $secret), false);
        return $secret;
    }
}
