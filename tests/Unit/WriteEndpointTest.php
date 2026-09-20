<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Clariq\McpPlugin\Bridge\WriteEndpoint;
use Clariq\McpPlugin\Security\WriteSignatureValidator;
use Brain\Monkey\Functions;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * NOTE: This class uses a Mockery 'overload' mock (overload:WriteRouter),
 * which permanently replaces the target class in the PHP process. Run in a
 * separate process so the overload does not leak into other tests.
 */
#[\PHPUnit\Framework\Attributes\RunClassInSeparateProcess]
final class WriteEndpointTest extends \Clariq\McpPlugin\Tests\TestCase {

    private WriteEndpoint $endpoint;

    protected function setUp(): void {
        parent::setUp();
        $this->endpoint = new WriteEndpoint();
    }

    /** Build a request stub with a controllable raw body + signature header. */
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
        // local_bridge is the intentional inverse — cloud writes must be off.
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false) => match ($k) {
            'wc_mcp_connection_mode' => 'local_bridge',
            default                  => $d,
        });

        $result = $this->endpoint->check_permission($this->make_request('{}', 'sig'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('mcp_write_wrong_mode', $result->get_error_code());
        $this->assertEquals(403, $result->get_error_data()['status']);
    }

    public function test_permission_rejects_missing_write_secret(): void {
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false) => match ($k) {
            'wc_mcp_connection_mode' => 'cloud_sync',
            'wc_mcp_write_secret'    => '',
            default                  => $d,
        });

        $result = $this->endpoint->check_permission($this->make_request('{}', 'sig'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('mcp_invalid_write_signature', $result->get_error_code());
        $this->assertEquals(401, $result->get_error_data()['status']);
    }

    public function test_permission_rejects_bad_signature(): void {
        Functions\when('get_option')->alias(fn (string $k, mixed $d = false) => match ($k) {
            'wc_mcp_connection_mode' => 'cloud_sync',
            'wc_mcp_write_secret'    => 'the-write-secret',
            default                  => $d,
        });

        $result = $this->endpoint->check_permission($this->make_request('{"action":"orders.note"}', 'not-the-signature'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('mcp_invalid_write_signature', $result->get_error_code());
        $this->assertEquals(401, $result->get_error_data()['status']);
    }

    public function test_permission_accepts_valid_signature_in_cloud_sync(): void {
        $secret = 'the-write-secret';
        $body   = '{"action":"orders.note","params":{"order_id":1,"note":"hi"},"request_id":"r1","ts":1700000000}';
        $sig    = hash_hmac('sha256', $body, $secret);

        Functions\when('get_option')->alias(fn (string $k, mixed $d = false) => match ($k) {
            'wc_mcp_connection_mode' => 'cloud_sync',
            'wc_mcp_write_secret'    => $secret,
            default                  => $d,
        });

        $this->assertTrue($this->endpoint->check_permission($this->make_request($body, $sig)));
    }

    public function test_handle_request_dispatches_action_to_router(): void {
        $body = '{"action":"orders.note","params":{"order_id":42,"note":"packed"},"request_id":"r1","ts":1700000000}';

        $envelope = [
            'ok'      => true,
            'result'  => ['note_id' => 99],
            'before'  => ['note_count' => 0],
            'after'   => ['note_id' => 99, 'note_count' => 1],
            'code'    => null,
            'message' => null,
        ];

        $mock_router = \Mockery::mock('overload:Clariq\McpPlugin\Write\WriteRouter');
        $mock_router->shouldReceive('route')
            ->once()
            ->with('orders.note', ['order_id' => 42, 'note' => 'packed'])
            ->andReturn($envelope);

        $request = $this->make_request($body, 'ignored-here');

        /** @var WP_REST_Response $response */
        $response = $this->endpoint->handle_request($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
        $this->assertSame($envelope, $response->get_data());
    }

    public function test_write_signature_scheme_matches_saas_signer(): void {
        // Byte-for-byte: HMAC-SHA256 over the raw body with the write secret, hex.
        $secret = 'the-write-secret';
        $body   = '{"action":"orders.note","params":{"order_id":1},"request_id":"r1","ts":1}';
        $sig    = hash_hmac('sha256', $body, $secret);

        Functions\when('get_option')->alias(fn (string $k, mixed $d = false) => match ($k) {
            'wc_mcp_write_secret' => $secret,
            default               => $d,
        });

        $this->assertTrue(WriteSignatureValidator::validate($this->make_request($body, $sig)));
        $this->assertFalse(WriteSignatureValidator::validate($this->make_request($body . 'x', $sig)));
    }
}
