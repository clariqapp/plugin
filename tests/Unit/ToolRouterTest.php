<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Bridge\ToolRouter;
use Clariq\McpPlugin\Tests\TestCase;

/**
 * Unit tests for ToolRouter.
 *
 * Verifies that valid tool names route without error and that
 * unknown / malicious tool names are rejected as WP_Error.
 * All DB calls go through the wpdb stub (returns empty arrays).
 */
class ToolRouterTest extends TestCase {

    private ToolRouter $router;

    protected function setUp(): void {
        parent::setUp();
        $this->router = new ToolRouter();

        // Wire the wpdb stub into the global used by tool handlers.
        global $wpdb;
        $wpdb = new \wpdb();

        Functions\when('get_option')->justReturn(null);
    }

    public function test_unknown_tool_returns_wp_error(): void {
        $result = $this->router->route('drop_all_tables', []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('mcp_unknown_tool', $result->get_error_code());
    }

    public function test_empty_tool_name_returns_wp_error(): void {
        $result = $this->router->route('', []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('mcp_unknown_tool', $result->get_error_code());
    }

    public function test_sql_injection_in_tool_name_returns_wp_error(): void {
        $result = $this->router->route("get_sales'; DROP TABLE wp_wc_orders; --", []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('mcp_unknown_tool', $result->get_error_code());
    }

    /**
     * @dataProvider valid_tools_provider
     */
    public function test_valid_tool_routes_without_unknown_tool_error(string $tool, array $args): void {
        $result = $this->router->route($tool, $args);

        // The stub wpdb returns [] so tools return [].
        // A mcp_db_error is acceptable; mcp_unknown_tool is not.
        if ($result instanceof \WP_Error) {
            $this->assertNotSame('mcp_unknown_tool', $result->get_error_code(),
                "Tool '$tool' was not recognised by the router.");
        } else {
            $this->assertIsArray($result);
        }
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function valid_tools_provider(): array {
        return [
            'sales_summary'          => ['get_sales_performance',     ['interval'  => 'summary']],
            'sales_daily'            => ['get_sales_performance',     ['interval'  => 'daily', 'start_date' => '2024-01-01', 'end_date' => '2024-01-31']],
            'marketing_leaderboard'  => ['get_marketing_attribution', ['dimension' => 'campaign_leaderboard']],
            'marketing_source'       => ['get_marketing_attribution', ['dimension' => 'source_cleaned']],
            'marketing_coverage'     => ['get_marketing_attribution', ['dimension' => 'attribution_coverage']],
            'product_top_selling'    => ['get_product_analytics',     ['mode'      => 'top_selling_items']],
            'product_affinities'     => ['get_product_analytics',     ['mode'      => 'product_affinities']],
            'product_catalog'        => ['get_product_analytics',     ['mode'      => 'catalog_dictionary']],
            'customer_geography'     => ['get_customer_insights',     ['dimension' => 'geography']],
            'customer_retention'     => ['get_customer_insights',     ['dimension' => 'retention_cohorts']],
            'customer_repurchase'    => ['get_customer_insights',     ['dimension' => 'repurchase_intervals']],
            'technical_device_types' => ['get_technical_analytics',   ['breakdown' => 'device_types']],
            'technical_os_types'     => ['get_technical_analytics',   ['breakdown' => 'os_types']],
            'technical_os_x_payment' => ['get_technical_analytics',   ['breakdown' => 'os_x_payment']],
        ];
    }
}
