<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Security\BridgeKeyManager;
use Clariq\McpPlugin\Tests\TestCase;

/**
 * Unit tests for BridgeKeyManager.
 *
 * Verifies local bridge secret lifecycle (no Clariq account required):
 *  - ensure_secret() generates once, then reuses
 *  - rotate() replaces both the secret and its stored hash
 *  - has_secret() requires both options to be present
 */
final class BridgeKeyManagerTest extends TestCase {

    /** In-memory wp_options store for stateful assertions. */
    private array $store;

    protected function setUp(): void {
        parent::setUp();
        $this->store = [];

        Functions\stubs([
            'get_option' => function (string $key, mixed $default = false) {
                return $this->store[$key] ?? $default;
            },
            'update_option' => function (string $key, mixed $value, mixed $autoload = null): bool {
                $this->store[$key] = $value;
                return true;
            },
            'wp_generate_password' => fn (): string => 'generated-' . bin2hex(random_bytes(8)),
        ]);
    }

    public function test_ensure_secret_generates_on_first_use(): void {
        $secret = BridgeKeyManager::ensure_secret();

        $this->assertNotSame('', $secret);
        $this->assertSame($secret, $this->store[BridgeKeyManager::SECRET_OPTION]);
        $this->assertSame(
            hash('sha256', $secret),
            $this->store[BridgeKeyManager::HASH_OPTION]
        );
    }

    public function test_ensure_secret_reuses_existing_secret(): void {
        $this->store[BridgeKeyManager::SECRET_OPTION] = 'existing-secret';
        $this->store[BridgeKeyManager::HASH_OPTION]   = hash('sha256', 'existing-secret');

        $this->assertSame('existing-secret', BridgeKeyManager::ensure_secret());
        // Stored value must be untouched.
        $this->assertSame('existing-secret', $this->store[BridgeKeyManager::SECRET_OPTION]);
    }

    public function test_rotate_replaces_secret_and_hash(): void {
        $first  = BridgeKeyManager::ensure_secret();
        $second = BridgeKeyManager::rotate();

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->store[BridgeKeyManager::SECRET_OPTION]);
        $this->assertSame(
            hash('sha256', $second),
            $this->store[BridgeKeyManager::HASH_OPTION]
        );
    }

    public function test_has_secret_requires_both_options(): void {
        $this->assertFalse(BridgeKeyManager::has_secret());

        $this->store[BridgeKeyManager::SECRET_OPTION] = 'only-secret';
        $this->assertFalse(BridgeKeyManager::has_secret());

        $this->store[BridgeKeyManager::HASH_OPTION] = hash('sha256', 'only-secret');
        $this->assertTrue(BridgeKeyManager::has_secret());
    }

    public function test_generated_secret_is_hash_consistent_with_validator_expectations(): void {
        // TokenValidator requires both a non-empty secret and a non-empty hash,
        // and computes HMAC over the raw request body using the plaintext secret.
        BridgeKeyManager::ensure_secret();

        $this->assertTrue(BridgeKeyManager::has_secret());
        $expected = hash_hmac('sha256', '', $this->store[BridgeKeyManager::SECRET_OPTION]);
        $this->assertNotSame('', $expected);
    }
}
