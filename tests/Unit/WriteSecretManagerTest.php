<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Security\WriteSecretManager;
use Clariq\McpPlugin\Tests\TestCase;

/**
 * Unit tests for WriteSecretManager.
 *
 * Verifies:
 *  - store() persists the secret + fingerprint with autoload DISABLED.
 *  - clear() removes both options (disconnect / uninstall cleanup).
 *  - has_secret() reflects presence.
 */
class WriteSecretManagerTest extends TestCase {

    public function test_store_persists_secret_with_autoload_disabled(): void {
        $captured = [];
        Functions\when('update_option')->alias(function (string $k, mixed $v, mixed $autoload = null) use (&$captured) {
            $captured[$k] = ['value' => $v, 'autoload' => $autoload];
            return true;
        });

        WriteSecretManager::store('raw-write-secret', 'abc123def456');

        $this->assertArrayHasKey('wc_mcp_write_secret', $captured);
        $this->assertSame('raw-write-secret', $captured['wc_mcp_write_secret']['value']);
        // autoload must be disabled (false === 'no').
        $this->assertFalse($captured['wc_mcp_write_secret']['autoload']);

        $this->assertArrayHasKey('wc_mcp_write_secret_fp', $captured);
        $this->assertSame('abc123def456', $captured['wc_mcp_write_secret_fp']['value']);
        $this->assertFalse($captured['wc_mcp_write_secret_fp']['autoload']);
    }

    public function test_store_without_fingerprint_only_writes_secret(): void {
        $keys = [];
        Functions\when('update_option')->alias(function (string $k) use (&$keys) {
            $keys[] = $k;
            return true;
        });

        WriteSecretManager::store('raw-write-secret');

        $this->assertContains('wc_mcp_write_secret', $keys);
        $this->assertNotContains('wc_mcp_write_secret_fp', $keys);
    }

    public function test_clear_deletes_both_options(): void {
        $deleted = [];
        Functions\when('delete_option')->alias(function (string $k) use (&$deleted) {
            $deleted[] = $k;
            return true;
        });

        WriteSecretManager::clear();

        $this->assertContains('wc_mcp_write_secret', $deleted);
        $this->assertContains('wc_mcp_write_secret_fp', $deleted);
    }

    public function test_has_secret_reflects_option_presence(): void {
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false) => match ($k) {
            'wc_mcp_write_secret' => 'present',
            default               => $d,
        });
        $this->assertTrue(WriteSecretManager::has_secret());

        Functions\when('get_option')->justReturn('');
        $this->assertFalse(WriteSecretManager::has_secret());
    }

    public function test_option_names_are_stable(): void {
        // These names are part of the storage contract (diagnostics + cleanup).
        $this->assertSame('wc_mcp_write_secret', WriteSecretManager::SECRET_OPTION);
        $this->assertSame('wc_mcp_write_secret_fp', WriteSecretManager::FP_OPTION);
    }
}
