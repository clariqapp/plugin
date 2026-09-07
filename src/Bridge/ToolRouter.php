<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Bridge;

use Clariq\McpPlugin\Tools\GetSalesPerformance;
use Clariq\McpPlugin\Tools\GetMarketingAttribution;
use Clariq\McpPlugin\Tools\GetProductAnalytics;
use Clariq\McpPlugin\Tools\GetCustomerInsights;
use Clariq\McpPlugin\Tools\GetTechnicalAnalytics;
use Clariq\McpPlugin\Tools\GetInventoryRunway;
use Clariq\McpPlugin\Tools\GetCouponLeakage;
use Clariq\McpPlugin\Tools\GetRepurchaseClock;

/**
 * Routes a validated tool name to its PHP handler class.
 *
 * Security model: only pre-approved tool names are accepted. The router
 * never forwards raw SQL or arbitrary table names. Each handler receives
 * a sanitised args array and executes exclusively via $wpdb->prepare().
 */
final class ToolRouter {

    /**
     * @param string               $tool
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function route(string $tool, array $args): array|\WP_Error {
        return match ($tool) {
            'get_sales_performance'      => (new GetSalesPerformance())->execute($args),
            'get_marketing_attribution'  => (new GetMarketingAttribution())->execute($args),
            'get_product_analytics'      => (new GetProductAnalytics())->execute($args),
            'get_customer_insights'      => (new GetCustomerInsights())->execute($args),
            'get_technical_analytics'    => (new GetTechnicalAnalytics())->execute($args),
            'get_inventory_runway'       => (new GetInventoryRunway())->execute($args),
            'get_coupon_leakage'         => (new GetCouponLeakage())->execute($args),
            'get_repurchase_clock'       => (new GetRepurchaseClock())->execute($args),
            default                      => new \WP_Error(
                'mcp_unknown_tool',
                sprintf('Unknown tool: %s', esc_html($tool)),
                ['status' => 400]
            ),
        };
    }
}
