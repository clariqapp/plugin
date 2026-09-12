<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Clariq\McpPlugin\Bridge\RestEndpoint;
use Clariq\McpPlugin\Security\TokenValidator;
use Brain\Monkey\Functions;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * NOTE: This class uses Mockery 'overload' mocks (overload:ToolRouter),
 * which permanently replace the target class in the PHP process. Run in a
 * separate process so the overload does not leak into ToolRouterTest and
 * break the real class with "mockery_getExpectations() on null" errors.
 */
#[\PHPUnit\Framework\Attributes\RunClassInSeparateProcess]
final class RestEndpointTest extends \Clariq\McpPlugin\Tests\TestCase {

    private RestEndpoint $endpoint;

    protected function setUp(): void {
        parent::setUp();
        $this->endpoint = new RestEndpoint();
    }

    public function test_check_permission_returns_true_when_bridge_mode_and_valid_token(): void {
        Functions\stubs(['get_option' => 'local_bridge']);
        \Patchwork\redefine([TokenValidator::class, 'validate'], fn () => true);

        $request = new WP_REST_Request();
        $result = $this->endpoint->check_permission($request);

        $this->assertTrue($result);
    }

    public function test_check_permission_rejects_cloud_sync_mode(): void {
        Functions\stubs(['get_option' => 'cloud_sync']);

        $request = new WP_REST_Request();
        $result = $this->endpoint->check_permission($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('mcp_bridge_disabled', $result->get_error_code());
    }

    public function test_check_permission_rejects_invalid_token(): void {
        Functions\stubs(['get_option' => 'local_bridge']);
        \Patchwork\redefine([TokenValidator::class, 'validate'], fn () => false);

        $request = new WP_REST_Request();
        $result = $this->endpoint->check_permission($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('mcp_invalid_token', $result->get_error_code());
    }

    public function test_handle_ping_returns_ok(): void {
        $options = [
            'wc_mcp_tenant_id'       => 'tenant-123',
            'wc_mcp_connection_mode' => 'local_bridge',
        ];
        Functions\stubs([
            'get_option' => function (string $key, mixed $default = null) use ($options) {
                return $options[$key] ?? $default;
            },
        ]);

        $request = new WP_REST_Request();
        /** @var WP_REST_Response $response */
        $response = $this->endpoint->handle_ping($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertEquals('ok', $data['status']);
        $this->assertEquals('tenant-123', $data['tenant_id']);
        $this->assertEquals('local_bridge', $data['mode']);
    }

    public function test_handle_request_returns_tool_result(): void {
        Functions\stubs(['update_option' => true]);

        $mock_router = \Mockery::mock('overload:Clariq\McpPlugin\Bridge\ToolRouter');
        $mock_router->shouldReceive('route')
            ->once()
            ->with('get_sales_performance', \Mockery::type('array'))
            ->andReturn(['revenue' => 1000]);

        $request = new WP_REST_Request();
        $request->set_param('tool', 'get_sales_performance');
        $request->set_param('args', []);

        /** @var WP_REST_Response $response */
        $response = $this->endpoint->handle_request($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertEquals('get_sales_performance', $data['tool']);
        $this->assertArrayHasKey('latency', $data);
        $this->assertEquals(['revenue' => 1000], $data['data']);
    }

    public function test_handle_request_returns_error_for_invalid_tool(): void {
        Functions\stubs(['update_option' => true]);

        $mock_router = \Mockery::mock('overload:Clariq\McpPlugin\Bridge\ToolRouter');
        $mock_router->shouldReceive('route')
            ->once()
            ->andReturn(new WP_Error('invalid_tool', 'Unknown tool'));

        $request = new WP_REST_Request();
        $request->set_param('tool', 'nonexistent_tool');
        $request->set_param('args', []);

        $response = $this->endpoint->handle_request($request);

        $this->assertInstanceOf(WP_Error::class, $response);
    }
}
