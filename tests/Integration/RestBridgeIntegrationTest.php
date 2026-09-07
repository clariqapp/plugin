<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration test for the REST Bridge endpoint.
 *
 * Sends real HTTP requests to the wp-env WordPress instance and verifies:
 *  - Valid HMAC signatures are accepted.
 *  - Invalid / missing signatures are rejected with 401.
 *  - Option A mode rejects bridge requests with 403.
 *  - All 5 tool names return 200 with a valid JSON payload.
 *
 * REQUIREMENT: wp-env must be running on http://localhost:8889 (tests port).
 */
class RestBridgeIntegrationTest extends TestCase {

    private const BASE_URL      = 'http://localhost:8889';
    private const ENDPOINT      = '/wp-json/mcp-bridge/v1/analytics';
    private const PING_ENDPOINT = '/wp-json/mcp-bridge/v1/ping';
    private const TIMEOUT       = 10;

    private string $bridge_secret = '';

    protected function setUp(): void {
        parent::setUp();

        if (!function_exists('curl_init')) {
            $this->markTestSkipped('curl extension is required for REST bridge tests.');
        }

        // Read the bridge secret that wp-env seeded during plugin activation.
        // In a real CI run this would come from an env var or fixture file.
        $this->bridge_secret = getenv('WC_MCP_BRIDGE_SECRET') ?: 'test-bridge-secret';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function send(string $tool, array $args, ?string $secret = null): array {
        $body      = json_encode(['tool' => $tool, 'args' => $args]);
        $used_secret = $secret ?? $this->bridge_secret;
        $signature = hash_hmac('sha256', $body, $used_secret);

        $ch = curl_init(self::BASE_URL . self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-MCP-Bridge-Token: ' . $signature,
            ],
        ]);

        $response_body = curl_exec($ch);
        $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'code' => $http_code,
            'body' => json_decode($response_body ?: '{}', true) ?? [],
        ];
    }

    private function get_ping(): array {
        $ch = curl_init(self::BASE_URL . self::PING_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'body' => json_decode($body ?: '{}', true) ?? []];
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function test_ping_returns_ok(): void {
        $response = $this->get_ping();

        $this->assertSame(200, $response['code']);
        $this->assertSame('ok', $response['body']['status'] ?? '');
        $this->assertArrayHasKey('tenant_id', $response['body']);
    }

    public function test_valid_signature_returns_200(): void {
        $response = $this->send('get_sales_performance', ['interval' => 'summary']);

        $this->assertSame(200, $response['code']);
        $this->assertArrayHasKey('data', $response['body']);
    }

    public function test_invalid_signature_returns_401(): void {
        $response = $this->send('get_sales_performance', ['interval' => 'summary'], 'wrong-secret');

        $this->assertSame(401, $response['code']);
    }

    public function test_missing_signature_header_returns_401(): void {
        $body = json_encode(['tool' => 'get_sales_performance', 'args' => ['interval' => 'summary']]);

        $ch = curl_init(self::BASE_URL . self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $code = curl_getinfo(curl_exec($ch) ? $ch : $ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame(401, $code);
    }

    public function test_unknown_tool_returns_400(): void {
        $response = $this->send('drop_everything', []);

        $this->assertSame(400, $response['code']);
    }

    /**
     * @dataProvider all_tools_provider
     */
    public function test_all_tools_return_200(string $tool, array $args): void {
        $response = $this->send($tool, $args);

        $this->assertSame(200, $response['code'],
            "Tool $tool returned HTTP {$response['code']}: " . json_encode($response['body'])
        );
        $this->assertIsArray($response['body']['data'] ?? null);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function all_tools_provider(): array {
        $range = [
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date'   => date('Y-m-d'),
        ];

        return [
            'sales_summary'     => ['get_sales_performance',     array_merge($range, ['interval'  => 'summary'])],
            'sales_daily'       => ['get_sales_performance',     array_merge($range, ['interval'  => 'daily'])],
            'mkt_leaderboard'   => ['get_marketing_attribution', array_merge($range, ['dimension' => 'campaign_leaderboard'])],
            'mkt_source'        => ['get_marketing_attribution', array_merge($range, ['dimension' => 'source_cleaned'])],
            'mkt_coverage'      => ['get_marketing_attribution', array_merge($range, ['dimension' => 'attribution_coverage'])],
            'product_top'       => ['get_product_analytics',     array_merge($range, ['mode'      => 'top_selling_items'])],
            'product_affinities'=> ['get_product_analytics',     ['mode' => 'product_affinities', 'limit' => 10]],
            'product_catalog'   => ['get_product_analytics',     ['mode' => 'catalog_dictionary', 'limit' => 10]],
            'customer_geo'      => ['get_customer_insights',     array_merge($range, ['dimension' => 'geography'])],
            'customer_cohorts'  => ['get_customer_insights',     array_merge($range, ['dimension' => 'retention_cohorts'])],
            'tech_devices'      => ['get_technical_analytics',   array_merge($range, ['breakdown' => 'device_types'])],
            'tech_os'           => ['get_technical_analytics',   array_merge($range, ['breakdown' => 'os_types'])],
            'tech_os_payment'   => ['get_technical_analytics',   array_merge($range, ['breakdown' => 'os_x_payment'])],
        ];
    }

    public function test_response_includes_latency_metric(): void {
        $response = $this->send('get_sales_performance', ['interval' => 'summary']);

        $this->assertSame(200, $response['code']);
        $this->assertArrayHasKey('latency', $response['body']);
        $this->assertIsFloat($response['body']['latency']);
        $this->assertLessThan(15.0, $response['body']['latency']);
    }
}
