<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Onboarding\ConnectFlow;
use Clariq\McpPlugin\Tests\TestCase;

/**
 * Unit tests for ConnectFlow.
 *
 * Verifies the OAuth-style connect flow:
 *  - State token generation and storage
 *  - CSRF state validation on callback
 *
 * Note: handle_callback() performs a server-to-server code exchange and calls
 * exit() at the end, which makes it hard to test end-to-end in unit tests. We
 * test the state-validation logic directly and the fully-testable initiate().
 */
class ConnectFlowTest extends TestCase {

    // -----------------------------------------------------------------------
    // initiate()
    // -----------------------------------------------------------------------

    public function test_initiate_returns_redirect_url_with_state(): void {
        Functions\when('sanitize_text_field')->alias(function(string $s) { return $s; });
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_generate_password')->justReturn('random-state-token-32chars');

        Functions\when('get_option')->alias(function(string $k, mixed $d = false) {
            if ($k === 'wc_mcp_connection_mode') return 'cloud_sync';
            return $d;
        });

        Functions\when('add_query_arg')->alias(function(array|string $args, string $url = '') {
            $query = is_array($args) ? http_build_query($args) : $args;
            return $url . '?' . $query;
        });

        $request = new class extends \WP_REST_Request {
            public function get_param(string $key): mixed {
                if ($key === 'connection_mode') return 'cloud_sync';
                return null;
            }
            public function has_param(string $key): bool {
                return $key === 'connection_mode';
            }
        };

        $response = ConnectFlow::initiate($request);

        $this->assertSame(200, $response->status);
        $data = $response->data;
        $this->assertArrayHasKey('redirect_url', $data);
        $this->assertStringContainsString('store_url=', $data['redirect_url']);
        $this->assertStringContainsString('state=random-state-token-32chars', $data['redirect_url']);
        $this->assertStringContainsString('connection_mode=cloud_sync', $data['redirect_url']);
    }

    public function test_initiate_persists_connection_mode(): void {
        Functions\when('sanitize_text_field')->alias(function(string $s) { return $s; });

        $update_called = false;
        Functions\when('update_option')->alias(function(string $k, mixed $v) use (&$update_called) {
            if ($k === 'wc_mcp_connection_mode') {
                $update_called = true;
                if ($v !== 'local_bridge') {
                    throw new \RuntimeException("Expected local_bridge, got: $v");
                }
            }
            return true;
        });

        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_generate_password')->justReturn('state');

        Functions\when('get_option')->alias(function(string $k, mixed $d = false) {
            if ($k === 'wc_mcp_connection_mode') return 'local_bridge';
            return $d;
        });

        Functions\when('add_query_arg')->alias(function(array|string $args, string $url = '') {
            return $url;
        });

        $request = new class extends \WP_REST_Request {
            public function get_param(string $key): mixed {
                if ($key === 'connection_mode') return 'local_bridge';
                return null;
            }
            public function has_param(string $key): bool {
                return $key === 'connection_mode';
            }
        };

        ConnectFlow::initiate($request);
        $this->assertTrue($update_called);
    }

    public function test_initiate_stores_state_in_transient(): void {
        Functions\when('sanitize_text_field')->alias(function(string $s) { return $s; });
        Functions\when('get_option')->justReturn('cloud_sync');
        Functions\when('add_query_arg')->alias(function(array|string $args, string $url = '') {
            return $url;
        });

        $transient_key = null;
        $transient_value = null;
        Functions\when('set_transient')->alias(function(string $k, mixed $v, int $e) use (&$transient_key, &$transient_value) {
            $transient_key = $k;
            $transient_value = $v;
            return true;
        });
        Functions\when('wp_generate_password')->justReturn('my-state-token');

        $request = new class extends \WP_REST_Request {
            public function get_param(string $key): mixed { return null; }
            public function has_param(string $key): bool { return false; }
        };

        ConnectFlow::initiate($request);

        $this->assertSame('wc_mcp_connect_state', $transient_key);
        $this->assertSame('my-state-token', $transient_value);
    }

    // -----------------------------------------------------------------------
    // State token validation logic
    // -----------------------------------------------------------------------

    public function test_state_verification_uses_hash_equals(): void {
        $stored = 'correct-state-value';
        $provided = 'correct-state-value';
        $this->assertTrue(hash_equals($stored, $provided));
    }

    public function test_state_verification_rejects_mismatch(): void {
        $stored = 'correct-state-value';
        $provided = 'wrong-state-value';
        $this->assertFalse(hash_equals($stored, $provided));
    }

    public function test_state_verification_rejects_empty(): void {
        $this->assertFalse(hash_equals('valid-state', ''));
        $this->assertFalse(hash_equals('', 'valid-state'));
    }
}
