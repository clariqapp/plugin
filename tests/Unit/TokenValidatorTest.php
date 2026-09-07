<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Security\TokenValidator;
use Clariq\McpPlugin\Tests\TestCase;

/**
 * Unit tests for TokenValidator.
 *
 * Verifies HMAC-SHA256 request authentication:
 *  - Valid body + correct secret  → true
 *  - Tampered body                → false
 *  - Wrong secret                 → false
 *  - Missing signature header     → false
 *  - Missing stored secret        → false
 */
class TokenValidatorTest extends TestCase {

    private const SECRET   = 'super-secret-bridge-key';
    private const TOKEN_HASH = 'stored-sha256-hash';
    private const BODY     = '{"tool":"get_sales_performance","args":{"interval":"summary"}}';

    // -----------------------------------------------------------------------
    // Helper: build a WP_REST_Request stub with controlled body + header
    // -----------------------------------------------------------------------

    private function make_request(string $body, string $signature): \WP_REST_Request {
        $req = new class($body, $signature) extends \WP_REST_Request {
            public function __construct(
                private string $rawBody,
                private string $sig,
            ) {}

            public function get_header(string $key): string {
                return strtolower($key) === 'x-mcp-bridge-token' ? $this->sig : '';
            }

            public function get_body(): string { return $this->rawBody; }
        };

        return $req;
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function test_valid_signature_returns_true(): void {
        $sig = hash_hmac('sha256', self::BODY, self::SECRET);

        Functions\when('get_option')->alias(fn (string $k) => match ($k) {
            'wc_mcp_bridge_token_hash' => self::TOKEN_HASH,
            'wc_mcp_bridge_secret'     => self::SECRET,
            default                    => null,
        });

        $this->assertTrue(TokenValidator::validate($this->make_request(self::BODY, $sig)));
    }

    public function test_tampered_body_returns_false(): void {
        // Signature was computed over original body but body changed.
        $sig     = hash_hmac('sha256', self::BODY, self::SECRET);
        $tampered = '{"tool":"get_sales_performance","args":{"interval":"monthly"}}';

        Functions\when('get_option')->alias(fn (string $k) => match ($k) {
            'wc_mcp_bridge_token_hash' => self::TOKEN_HASH,
            'wc_mcp_bridge_secret'     => self::SECRET,
            default                    => null,
        });

        $this->assertFalse(TokenValidator::validate($this->make_request($tampered, $sig)));
    }

    public function test_wrong_signature_returns_false(): void {
        Functions\when('get_option')->alias(fn (string $k) => match ($k) {
            'wc_mcp_bridge_token_hash' => self::TOKEN_HASH,
            'wc_mcp_bridge_secret'     => self::SECRET,
            default                    => null,
        });

        $this->assertFalse(TokenValidator::validate($this->make_request(self::BODY, 'completely-wrong')));
    }

    public function test_empty_signature_header_returns_false(): void {
        Functions\when('get_option')->alias(fn (string $k) => match ($k) {
            'wc_mcp_bridge_token_hash' => self::TOKEN_HASH,
            'wc_mcp_bridge_secret'     => self::SECRET,
            default                    => null,
        });

        $this->assertFalse(TokenValidator::validate($this->make_request(self::BODY, '')));
    }

    public function test_missing_stored_secret_returns_false(): void {
        Functions\when('get_option')->justReturn('');

        $sig = hash_hmac('sha256', self::BODY, self::SECRET);
        $this->assertFalse(TokenValidator::validate($this->make_request(self::BODY, $sig)));
    }

    public function test_different_secrets_produce_different_signatures(): void {
        $this->assertNotSame(
            hash_hmac('sha256', self::BODY, 'secret-a'),
            hash_hmac('sha256', self::BODY, 'secret-b')
        );
    }

    public function test_same_secret_same_body_always_matches(): void {
        $sig = hash_hmac('sha256', self::BODY, self::SECRET);
        $this->assertTrue(hash_equals($sig, hash_hmac('sha256', self::BODY, self::SECRET)));
    }
}
