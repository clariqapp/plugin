<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Clariq\McpPlugin\Bridge\ReadEndpoint;
use Brain\Monkey\Functions;
use WP_REST_Response;
use WP_Error;

/**
 * Unit tests for the live-read relay endpoint (POST /wp-json/mcp-bridge/v1/read).
 *
 * Verifies it shares the write relay's trust boundary byte-for-byte:
 *   - cloud_sync mode required (wrong mode -> 403)
 *   - write-key HMAC over the raw body (bad sig -> 401)
 * plus a resource dispatch through ReadRouter.
 *
 * NOTE: uses a Mockery 'overload' mock (overload:ReadRouter) which permanently
 * replaces the class in-process; run in a separate process.
 */
#[\PHPUnit\Framework\Attributes\RunClassInSeparateProcess]
final class ReadEndpointTest extends \Clariq\McpPlugin\Tests\TestCase {

    private ReadEndpoint $endpoint;

    protected function setUp(): void {
        parent::setUp();
        $this->endpoint = new ReadEndpoint();
    }

    private function make_request(string $body, string $signature): \WP_REST_Request {
        return new class($body, $signature) extends \WP_REST_Request {
            public function __construct(private string $rawBody, private string $sig) {}
            public function get_header(string $key): string {
                return strtolower($key) === 'x-mcp-write-signature' ? $this->sig : '';
            }
            public function get_body(): string { return $this->rawBody; }
        };
    }

    public function test_permission_rejects_wrong_mode(): void {
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false) => match ($k) {
            'wc_mcp_connection_mode' => 'local_bridge',
            default                  => $d,
        });

        $result = $this->endpoint->check_permission($this->make_request('{}', 'sig'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('mcp_write_wrong_mode', $result->get_error_code());
        $this->assertEquals(403, $result->get_error_data()['status']);
    }

    public function test_permission_rejects_bad_signature(): void {
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false) => match ($k) {
            'wc_mcp_connection_mode' => 'cloud_sync',
            'wc_mcp_write_secret'    => 'the-write-secret',
            default                  => $d,
        });

        $result = $this->endpoint->check_permission(
            $this->make_request('{"resource":"product"}', 'not-the-signature')
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('mcp_invalid_write_signature', $result->get_error_code());
        $this->assertEquals(401, $result->get_error_data()['status']);
    }

    public function test_permission_accepts_valid_signature_in_cloud_sync(): void {
        $secret = 'the-write-secret';
        $body   = '{"resource":"product","params":{"product_id":10},"ts":1700000000}';
        $sig    = hash_hmac('sha256', $body, $secret);

        Functions\when('get_option')->alias(fn (string $k, mixed $d = false) => match ($k) {
            'wc_mcp_connection_mode' => 'cloud_sync',
            'wc_mcp_write_secret'    => $secret,
            default                  => $d,
        });

        $this->assertTrue($this->endpoint->check_permission($this->make_request($body, $sig)));
    }

    public function test_handle_request_dispatches_resource_to_router(): void {
        $body = '{"resource":"product","params":{"product_id":10},"ts":1700000000}';

        $envelope = ['ok' => true, 'data' => ['id' => 10, 'name' => 'Widget']];

        $mock_router = \Mockery::mock('overload:Clariq\McpPlugin\Read\ReadRouter');
        $mock_router->shouldReceive('route')
            ->once()
            ->with('product', ['product_id' => 10])
            ->andReturn($envelope);

        $response = $this->endpoint->handle_request($this->make_request($body, 'ignored-here'));

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
        $this->assertSame($envelope, $response->get_data());
    }
}
