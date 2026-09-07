<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the 5 MCP Tool handlers against a real WooCommerce DB.
 *
 * REQUIREMENTS:
 *   - Run via: npm run test:integration (inside apps/plugin/admin-ui)
 *   - Or: vendor/bin/phpunit --testsuite integration
 *   - Requires a running wp-env environment: npm run env:start
 *   - WP_PHPUNIT__TESTS_CONFIG must point to the wp-env WP install.
 *
 * These tests seed the database with known order fixtures,
 * call each tool handler, and assert the shape and values of the output.
 */
class ToolHandlersIntegrationTest extends TestCase {

    private static \wpdb $wpdb;

    /**
     * Set up test fixtures once for the whole class.
     * Seeds 3 orders into the HPOS tables via WC_Order factory.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        if (!function_exists('wc_create_order')) {
            self::markTestSkipped('WooCommerce is not loaded — run tests via wp-env.');
        }

        global $wpdb;
        self::$wpdb = $wpdb;

        self::seed_orders();
    }

    public static function tearDownAfterClass(): void {
        self::clean_orders();
        parent::tearDownAfterClass();
    }

    // -----------------------------------------------------------------------
    // Seed / teardown helpers
    // -----------------------------------------------------------------------

    private static array $order_ids = [];

    private static function seed_orders(): void {
        $orders_data = [
            [
                'total'          => 150.00,
                'payment_method' => 'stripe',
                'utm_source'     => 'google',
                'utm_campaign'   => 'black-friday',
                'device'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'city'           => 'London',
                'country'        => 'GB',
            ],
            [
                'total'          => 75.50,
                'payment_method' => 'paypal',
                'utm_source'     => 'facebook',
                'utm_campaign'   => 'summer-sale',
                'device'         => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)',
                'city'           => 'New York',
                'country'        => 'US',
            ],
            [
                'total'          => 200.00,
                'payment_method' => 'stripe',
                'utm_source'     => 'instagram',
                'utm_campaign'   => 'black-friday',
                'device'         => 'Mozilla/5.0 (Macintosh; Intel Mac OS X)',
                'city'           => 'Sydney',
                'country'        => 'AU',
            ],
        ];

        foreach ($orders_data as $data) {
            $order = wc_create_order();
            $order->set_status('wc-completed');
            $order->set_total($data['total']);
            $order->set_payment_method($data['payment_method']);
            $order->set_shipping_city($data['city']);
            $order->set_shipping_country($data['country']);
            $order->save();

            // Add attribution meta.
            $oid = $order->get_id();
            wc_update_order_item_meta($oid, '_wc_order_attribution_utm_source',   $data['utm_source']);
            wc_update_order_item_meta($oid, '_wc_order_attribution_utm_campaign', $data['utm_campaign']);
            wc_update_order_item_meta($oid, '_wc_order_attribution_user_agent',   $data['device']);

            self::$order_ids[] = $oid;
        }
    }

    private static function clean_orders(): void {
        foreach (self::$order_ids as $id) {
            $order = wc_get_order($id);
            if ($order) {
                $order->delete(true);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Tool 1: get_sales_performance
    // -----------------------------------------------------------------------

    public function test_sales_summary_returns_expected_shape(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetSalesPerformance();
        $result = $tool->execute(['interval' => 'summary']);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        $row = $result[0];
        $this->assertArrayHasKey('total_orders',     $row);
        $this->assertArrayHasKey('gross_revenue',    $row);
        $this->assertArrayHasKey('true_net_revenue', $row);
        $this->assertArrayHasKey('avg_order_value',  $row);
        $this->assertIsInt($row['total_orders']);
        $this->assertIsFloat($row['gross_revenue']);
    }

    public function test_sales_daily_returns_time_buckets(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetSalesPerformance();
        $result = $tool->execute([
            'interval'   => 'daily',
            'start_date' => date('Y-m-d', strtotime('-7 days')),
            'end_date'   => date('Y-m-d'),
        ]);

        $this->assertIsArray($result);

        if (!empty($result)) {
            $this->assertArrayHasKey('time_bucket',   $result[0]);
            $this->assertArrayHasKey('orders',        $result[0]);
            $this->assertArrayHasKey('gross_revenue', $result[0]);
        }
    }

    // -----------------------------------------------------------------------
    // Tool 2: get_marketing_attribution
    // -----------------------------------------------------------------------

    public function test_campaign_leaderboard_aggregates_correctly(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetMarketingAttribution();
        $result = $tool->execute(['dimension' => 'campaign_leaderboard']);

        $this->assertIsArray($result);

        // 'black-friday' campaign should appear (2 orders seeded).
        $campaigns = array_column($result, 'campaign_id');
        $this->assertContains('black-friday', $campaigns);

        $bf = $result[array_search('black-friday', $campaigns)];
        $this->assertSame(2, $bf['orders']);
        $this->assertEqualsWithDelta(350.00, $bf['revenue'], 0.01);
    }

    public function test_source_cleaned_maps_social_networks(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetMarketingAttribution();
        $result = $tool->execute(['dimension' => 'source_cleaned']);

        $sources = array_column($result, 'clean_source');
        $this->assertContains('Google',    $sources);
        $this->assertContains('Facebook',  $sources);
        $this->assertContains('Instagram', $sources);
    }

    public function test_attribution_coverage_returns_rate(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetMarketingAttribution();
        $result = $tool->execute(['dimension' => 'attribution_coverage']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('attribution_rate_pct', $result[0]);
        $this->assertIsFloat($result[0]['attribution_rate_pct']);
    }

    // -----------------------------------------------------------------------
    // Tool 3: get_product_analytics
    // -----------------------------------------------------------------------

    public function test_catalog_dictionary_returns_products(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetProductAnalytics();
        $result = $tool->execute(['mode' => 'catalog_dictionary', 'limit' => 10]);

        $this->assertIsArray($result);
        if (!empty($result)) {
            $this->assertArrayHasKey('product_id',   $result[0]);
            $this->assertArrayHasKey('product_name', $result[0]);
            $this->assertIsInt($result[0]['product_id']);
        }
    }

    public function test_limit_parameter_is_respected(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetProductAnalytics();
        $result = $tool->execute(['mode' => 'catalog_dictionary', 'limit' => 2]);

        $this->assertLessThanOrEqual(2, count($result));
    }

    // -----------------------------------------------------------------------
    // Tool 4: get_customer_insights
    // -----------------------------------------------------------------------

    public function test_geography_returns_cities(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetCustomerInsights();
        $result = $tool->execute(['dimension' => 'geography']);

        $this->assertIsArray($result);
        if (!empty($result)) {
            $cities = array_column($result, 'city');
            // At least one seeded city should appear.
            $this->assertNotEmpty(array_intersect(['London', 'New York', 'Sydney'], $cities));
        }
    }

    public function test_retention_cohorts_sums_correctly(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetCustomerInsights();
        $result = $tool->execute(['dimension' => 'retention_cohorts']);

        $this->assertIsArray($result);
        $row = $result[0];

        $this->assertArrayHasKey('first_time_buyers',        $row);
        $this->assertArrayHasKey('repeat_customers',         $row);
        $this->assertArrayHasKey('total_unique_customers',   $row);

        // first_time + repeat must equal total.
        $this->assertSame(
            $row['total_unique_customers'],
            $row['first_time_buyers'] + $row['repeat_customers']
        );
    }

    // -----------------------------------------------------------------------
    // Tool 5: get_technical_analytics
    // -----------------------------------------------------------------------

    public function test_device_breakdown_classifies_mobile_and_desktop(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetTechnicalAnalytics();
        $result = $tool->execute(['breakdown' => 'device_types']);

        $this->assertIsArray($result);
        if (!empty($result)) {
            $types = array_column($result, 'device_type');
            foreach ($types as $t) {
                $this->assertContains($t, ['Mobile', 'Tablet', 'Desktop']);
            }
        }
    }

    public function test_os_x_payment_crosses_two_dimensions(): void {
        $tool   = new \Clariq\McpPlugin\Tools\GetTechnicalAnalytics();
        $result = $tool->execute(['breakdown' => 'os_x_payment']);

        $this->assertIsArray($result);
        if (!empty($result)) {
            $this->assertArrayHasKey('os_type',        $result[0]);
            $this->assertArrayHasKey('payment_method', $result[0]);
            $this->assertArrayHasKey('orders',         $result[0]);
        }
    }
}
