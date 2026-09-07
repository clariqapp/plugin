<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Sync\WebhookDispatcher;
use Clariq\McpPlugin\Tests\TestCase;

/**
 * Unit tests for WebhookDispatcher.
 *
 * Verifies PII protection, retry logic, and auth header construction.
 * Does NOT make real HTTP requests — patches wp_remote_post.
 */

/** Stub WC_Order that extends the base stub for method overrides */
class StubWCOrder extends \WC_Order {
    private array $data;

    public function __construct(array $data = []) {
        $defaults = [
            'id' => 123, 'status' => 'completed', 'date_created' => null,
            'date_modified' => null, 'total' => '99.99', 'shipping_total' => '10.00',
            'discount_total' => '5.00', 'total_tax' => '15.00', 'customer_id' => 1,
            'payment_method' => 'stripe', 'billing_email' => 'customer@example.com',
            'shipping_city' => 'London', 'shipping_country' => 'GB',
            'billing_city' => '', 'billing_country' => '',
        ];
        $this->data = array_merge($defaults, $data);
    }

    public function get_id(): int { return $this->data['id']; }
    public function get_status(): string { return $this->data['status']; }
    public function get_date_created(): mixed { return $this->data['date_created']; }
    public function get_date_modified(): mixed { return $this->data['date_modified']; }
    public function get_total(): string { return $this->data['total']; }
    public function get_shipping_total(): string { return $this->data['shipping_total']; }
    public function get_discount_total(): string { return $this->data['discount_total']; }
    public function get_total_tax(): string { return $this->data['total_tax']; }
    public function get_customer_id(): int { return $this->data['customer_id']; }
    public function get_payment_method(): string { return $this->data['payment_method']; }
    public function get_billing_email(): string { return $this->data['billing_email']; }
    public function get_shipping_city(): string { return $this->data['shipping_city']; }
    public function get_shipping_country(): string { return $this->data['shipping_country']; }
    public function get_billing_city(): string { return $this->data['billing_city']; }
    public function get_billing_country(): string { return $this->data['billing_country']; }
    public function get_meta(string $key): mixed { return null; }
    public function get_items(?string $type = null): array { return []; }
}

class WebhookDispatcherTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        Functions\when('get_option')->alias(function(string $k, mixed $d = false) {
            if ($k === 'wc_mcp_connection_mode') return 'cloud_sync';
            if ($k === 'wc_mcp_tenant_id') return 'test-tenant-123';
            if ($k === 'wc_mcp_auth_token') return 'test-auth-token-456';
            return $d;
        });
    }

    // -----------------------------------------------------------------------
    // build_headers()
    // -----------------------------------------------------------------------

    public function test_build_headers_includes_bearer_and_tenant(): void {
        $ref = new \ReflectionMethod(WebhookDispatcher::class, 'build_headers');
        $ref->setAccessible(true);

        $headers = $ref->invoke(null, 'my-token', 'my-tenant');

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertArrayHasKey('X-Tenant-ID', $headers);
        $this->assertSame('Bearer my-token', $headers['Authorization']);
        $this->assertSame('my-tenant', $headers['X-Tenant-ID']);
        $this->assertSame('application/json', $headers['Content-Type']);
    }

    // -----------------------------------------------------------------------
    // serialize_order() — PII protection
    // -----------------------------------------------------------------------

    public function test_serialize_order_hashes_email(): void {
        $order = new StubWCOrder(['billing_email' => 'john@example.com']);

        $ref = new \ReflectionMethod(WebhookDispatcher::class, 'serialize_order');
        $ref->setAccessible(true);

        $data = $ref->invoke(null, $order);

        $this->assertArrayHasKey('billing_email_hash', $data);
        $this->assertNotSame('john@example.com', $data['billing_email_hash']);
        // HMAC-SHA256 salted with the store auth token (from get_option mock)
        $this->assertSame(
            hash_hmac('sha256', 'john@example.com', 'test-auth-token-456'),
            $data['billing_email_hash']
        );
    }

    public function test_serialize_order_excludes_address_lines(): void {
        $order = new StubWCOrder([
            'billing_email' => 'test@test.com',
            'shipping_city' => 'London',
            'shipping_country' => 'GB',
        ]);

        $ref = new \ReflectionMethod(WebhookDispatcher::class, 'serialize_order');
        $ref->setAccessible(true);

        $data = $ref->invoke(null, $order);

        $this->assertArrayNotHasKey('billing_email', $data);
        $this->assertArrayNotHasKey('billing_first_name', $data);
        $this->assertArrayNotHasKey('billing_last_name', $data);
        $this->assertArrayNotHasKey('billing_address_1', $data);
        $this->assertSame('London', $data['shipping_city']);
        $this->assertSame('GB', $data['shipping_country']);
    }

    public function test_serialize_order_falls_back_to_billing_address(): void {
        $order = new StubWCOrder([
            'billing_email' => 'test@test.com',
            'billing_city' => 'Manchester',
            'billing_country' => 'GB',
            'shipping_city' => '',
            'shipping_country' => '',
        ]);

        $ref = new \ReflectionMethod(WebhookDispatcher::class, 'serialize_order');
        $ref->setAccessible(true);

        $data = $ref->invoke(null, $order);

        $this->assertSame('Manchester', $data['shipping_city']);
        $this->assertSame('GB', $data['shipping_country']);
    }

    public function test_serialize_order_no_email_returns_null_hash(): void {
        $order = new StubWCOrder(['billing_email' => '']);

        $ref = new \ReflectionMethod(WebhookDispatcher::class, 'serialize_order');
        $ref->setAccessible(true);

        $data = $ref->invoke(null, $order);

        $this->assertNull($data['billing_email_hash']);
    }

    public function test_serialize_order_includes_line_items_and_coupons(): void {
        $order = new StubWCOrder(['billing_email' => 'test@test.com']);

        $ref = new \ReflectionMethod(WebhookDispatcher::class, 'serialize_order');
        $ref->setAccessible(true);

        $data = $ref->invoke(null, $order);

        $this->assertArrayHasKey('line_items', $data);
        $this->assertArrayHasKey('coupons', $data);
        $this->assertIsArray($data['line_items']);
        $this->assertIsArray($data['coupons']);
    }

    public function test_serialize_order_status_prefixed_with_wc(): void {
        $order = new StubWCOrder(['status' => 'completed']);

        $ref = new \ReflectionMethod(WebhookDispatcher::class, 'serialize_order');
        $ref->setAccessible(true);

        $data = $ref->invoke(null, $order);

        $this->assertSame('wc-completed', $data['status']);
    }

    // -----------------------------------------------------------------------
    // dispatch_batch() — retry logic
    // -----------------------------------------------------------------------

    public function test_dispatch_batch_stops_on_401(): void {
        Functions\when('wp_generate_uuid4')->justReturn('idempotency-key');
        Functions\when('wp_json_encode')->alias(function(mixed $d) { return json_encode($d); });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(401);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);

        $call_count = 0;
        Functions\when('wp_remote_post')->alias(function() use (&$call_count) {
            $call_count++;
            return ['response' => ['code' => 401], 'body' => '{"error":"unauthorized"}'];
        });

        $dispatcher = new WebhookDispatcher();
        $result = $dispatcher->dispatch_batch([['id' => 1]]);

        $this->assertNull($result);
        $this->assertSame(1, $call_count);
    }

    public function test_dispatch_batch_returns_null_on_empty_credentials(): void {
        Functions\when('get_option')->justReturn('');

        $dispatcher = new WebhookDispatcher();
        $result = $dispatcher->dispatch_batch([['id' => 1]]);

        $this->assertNull($result);
    }

    public function test_dispatch_batch_returns_null_in_bridge_mode(): void {
        Functions\when('get_option')->alias(function(string $k, mixed $d = false) {
            if ($k === 'wc_mcp_connection_mode') return 'local_bridge';
            return $d;
        });

        $dispatcher = new WebhookDispatcher();
        $result = $dispatcher->dispatch_batch([['id' => 1]]);

        $this->assertNull($result);
    }

    public function test_dispatch_batch_includes_idempotency_key(): void {
        Functions\when('wp_generate_uuid4')->justReturn('unique-key-123');
        Functions\when('wp_json_encode')->alias(function(mixed $d) { return json_encode($d); });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"status":"accepted"}');
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);

        $captured_headers = null;
        Functions\when('wp_remote_post')->alias(function(string $url, array $args) use (&$captured_headers) {
            $captured_headers = $args['headers'] ?? null;
            return ['response' => ['code' => 200], 'body' => '{"status":"accepted"}'];
        });

        $dispatcher = new WebhookDispatcher();
        $dispatcher->dispatch_batch([['id' => 1]]);

        $this->assertNotNull($captured_headers);
        $this->assertArrayHasKey('X-Idempotency-Key', $captured_headers);
        $this->assertSame('unique-key-123', $captured_headers['X-Idempotency-Key']);
    }

    // -----------------------------------------------------------------------
    // push_order() — mode guard
    // -----------------------------------------------------------------------

    public function test_push_order_skips_in_bridge_mode(): void {
        Functions\when('get_option')->alias(function(string $k, mixed $d = false) {
            if ($k === 'wc_mcp_connection_mode') return 'local_bridge';
            return $d;
        });

        $post_called = false;
        Functions\when('wp_remote_post')->alias(function() use (&$post_called) {
            $post_called = true;
            return ['response' => ['code' => 200], 'body' => ''];
        });

        $ref = new \ReflectionMethod(WebhookDispatcher::class, 'push_order');
        $ref->setAccessible(true);
        $ref->invoke(null, 123, 'created');

        $this->assertFalse($post_called);
    }

    public function test_push_order_fires_non_blocking(): void {
        Functions\when('get_option')->alias(function(string $k, mixed $d = false) {
            if ($k === 'wc_mcp_tenant_id') return 't1';
            if ($k === 'wc_mcp_auth_token') return 'a1';
            return $d;
        });
        Functions\when('wp_json_encode')->alias(function(mixed $d) { return json_encode($d); });
        Functions\when('update_option')->justReturn(true);

        $blocking_value = null;
        Functions\when('wp_remote_post')->alias(function(string $url, array $args) use (&$blocking_value) {
            $blocking_value = $args['blocking'] ?? null;
            return ['response' => ['code' => 200], 'body' => ''];
        });

        $ref = new \ReflectionMethod(WebhookDispatcher::class, 'push_order');
        $ref->setAccessible(true);
        $ref->invoke(null, 123, 'created');

        $this->assertFalse($blocking_value);
    }
}
