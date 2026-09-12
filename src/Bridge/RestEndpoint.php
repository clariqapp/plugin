<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Bridge;

use Clariq\McpPlugin\Security\TokenValidator;
use Clariq\McpPlugin\Bridge\ToolRouter;

/**
 * Registers the REST endpoint consumed by the Clariq Cloud MCP Server (Option C).
 *
 * Route: POST /wp-json/mcp-bridge/v1/analytics
 *
 * The endpoint:
 *  1. Validates the HMAC signature on every request.
 *  2. Confirms Option C is the active mode (rejects if Option A is selected).
 *  3. Delegates execution to ToolRouter, which maps tool names to PHP handlers.
 *  4. Returns a normalised JSON array — never raw SQL or schema metadata.
 */
final class RestEndpoint {

    public function register_routes(): void {
        register_rest_route('mcp-bridge/v1', '/analytics', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_request'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $this->get_args_schema(),
        ]);

        // Lightweight ping endpoint so the MCP server can verify connectivity.
        register_rest_route('mcp-bridge/v1', '/ping', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_ping'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Permission callback: validates HMAC token and connection mode.
     */
    public function check_permission(\WP_REST_Request $request): bool|\WP_Error {
        // Bridge is disabled when cloud_sync is the active mode.
        if (get_option('wc_mcp_connection_mode', 'local_bridge') !== 'local_bridge') {
            return new \WP_Error(
                'mcp_bridge_disabled',
                'The on-premise bridge is not enabled for this site.',
                ['status' => 403]
            );
        }

        if (!TokenValidator::validate($request)) {
            return new \WP_Error(
                'mcp_invalid_token',
                'Invalid or missing bridge authentication token.',
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Primary analytics handler.
     */
    public function handle_request(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $tool = $request->get_param('tool');
        $args = $request->get_param('args') ?? [];

        // Instrument latency for the health dashboard.
        $start = microtime(true);

        $result = (new ToolRouter())->route($tool, (array) $args);

        $latency = round(microtime(true) - $start, 4);
        update_option('wc_mcp_bridge_latency', $latency);

        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response([
            'tool'    => $tool,
            'latency' => $latency,
            'data'    => $result,
        ]);
    }

    public function handle_ping(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'status'    => 'ok',
            'tenant_id' => get_option('wc_mcp_tenant_id', ''),
            'mode'      => get_option('wc_mcp_connection_mode', 'local_bridge'),
            'version'   => WC_MCP_VERSION,
        ]);
    }

    /**
     * JSON Schema for the REST request body args.
     *
     * @return array<string, mixed>
     */
    private function get_args_schema(): array {
        return [
            'tool' => [
                'required' => true,
                'type'     => 'string',
                'enum'     => [
                    'get_sales_performance',
                    'get_marketing_attribution',
                    'get_product_analytics',
                    'get_customer_insights',
                    'get_technical_analytics',
                    'get_inventory_runway',
                    'get_coupon_leakage',
                    'get_repurchase_clock',
                ],
            ],
            'args' => [
                'required' => false,
                'type'     => 'object',
            ],
        ];
    }
}
