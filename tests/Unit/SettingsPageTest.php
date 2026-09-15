<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Clariq\McpPlugin\Admin\SettingsPage;
use Brain\Monkey\Functions;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsPageTest extends \Clariq\McpPlugin\Tests\TestCase {

    private SettingsPage $page;

    protected function setUp(): void {
        parent::setUp();
        $this->page = new SettingsPage();
    }

    private static function option_stub(array $overrides = []): \Closure {
        $defaults = [
            'wc_mcp_tenant_id'            => '',
            'wc_mcp_auth_token'           => '',
            'wc_mcp_connection_mode'      => 'cloud_sync',
            'wc_mcp_last_sync_timestamp'  => 0,
            'wc_mcp_bridge_latency'       => false,
            'wc_mcp_backfill_complete'    => false,
            'wc_mcp_backfill_offset'      => false,
            'wc_mcp_backfill_range'       => '12',
            'wc_mcp_sync_hour'            => 2,
        ];
        $opts = array_merge($defaults, $overrides);
        return function (string $key, mixed $default = null) use ($opts) {
            return $opts[$key] ?? $default;
        };
    }

    // ── GET /status ─────────────────────────────────────────────────────────

    public function test_get_status_not_connected(): void {
        Functions\stubs(['get_option' => self::option_stub()]);

        $request = new WP_REST_Request();
        $response = $this->page->get_status($request);
        $data = $response->get_data();

        $this->assertFalse($data['is_connected']);
        $this->assertEquals('', $data['tenant_id']);
    }

    public function test_get_status_connected_when_valid_token(): void {
        Functions\stubs(['get_option' => self::option_stub([
            'wc_mcp_tenant_id' => 't-abc',
            'wc_mcp_auth_token' => 'tok_valid',
        ])]);

        \Patchwork\redefine('wp_remote_get', function (string $url, array $args = []): array {
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['is_active' => true]),
            ];
        });

        $request = new WP_REST_Request();
        $response = $this->page->get_status($request);
        $data = $response->get_data();

        $this->assertTrue($data['is_connected']);
        $this->assertEquals('t-abc', $data['tenant_id']);
    }

    public function test_get_status_optimistic_on_first_401(): void {
        // A single 401 must NOT tear down a working connection — it takes
        // AUTH_FAIL_THRESHOLD (2) consecutive failures. First strike stays
        // optimistically connected and records the strike.
        Functions\stubs([
            'get_option' => self::option_stub([
                'wc_mcp_tenant_id'       => 't-abc',
                'wc_mcp_auth_token'      => 'tok_expired',
                'wc_mcp_auth_fail_count' => 0,
            ]),
            'update_option' => true,
            'delete_option' => true,
        ]);

        \Patchwork\redefine('wp_remote_get', function (string $url, array $args = []): array {
            return [
                'response' => ['code' => 401],
                'body'     => json_encode(['detail' => 'Unauthorized']),
            ];
        });

        $request = new WP_REST_Request();
        $response = $this->page->get_status($request);
        $data = $response->get_data();

        $this->assertTrue($data['is_connected']);
    }

    public function test_get_status_cleared_on_repeated_401(): void {
        // Second consecutive 401 (strike count already at threshold-1) reaches
        // AUTH_FAIL_THRESHOLD and clears credentials.
        $deleted = [];
        Functions\stubs([
            'get_option' => self::option_stub([
                'wc_mcp_tenant_id'       => 't-abc',
                'wc_mcp_auth_token'      => 'tok_expired',
                'wc_mcp_auth_fail_count' => 1,
            ]),
            'update_option' => true,
            'delete_option' => function (string $key) use (&$deleted) {
                $deleted[] = $key;
                return true;
            },
        ]);

        \Patchwork\redefine('wp_remote_get', function (string $url, array $args = []): array {
            return [
                'response' => ['code' => 401],
                'body'     => json_encode(['detail' => 'Unauthorized']),
            ];
        });

        $request = new WP_REST_Request();
        $response = $this->page->get_status($request);
        $data = $response->get_data();

        $this->assertFalse($data['is_connected']);
        $this->assertContains('wc_mcp_tenant_id', $deleted);
        $this->assertContains('wc_mcp_auth_token', $deleted);
    }

    public function test_get_status_self_heals_stale_sync_when_disconnected(): void {
        // Cloud mode, no live connection, but a stale backfill cursor lingers
        // (e.g. after an abandoned connect / auto-disconnect). get_status must
        // clear it so the UI doesn't show a phantom "in progress" backfill.
        $deleted = [];
        Functions\stubs([
            'get_option' => self::option_stub([
                'wc_mcp_connection_mode'  => 'cloud_sync',
                'wc_mcp_tenant_id'        => '',
                'wc_mcp_backfill_offset'  => '250',
            ]),
            'update_option' => true,
            'delete_option' => function (string $key) use (&$deleted) {
                $deleted[] = $key;
                return true;
            },
        ]);

        $request = new WP_REST_Request();
        $response = $this->page->get_status($request);
        $data = $response->get_data();

        $this->assertFalse($data['is_connected']);
        $this->assertContains('wc_mcp_backfill_offset', $deleted);
        $this->assertContains('wc_mcp_last_sync_timestamp', $deleted);
    }

    public function test_get_status_optimistic_on_500(): void {
        Functions\stubs(['get_option' => self::option_stub([
            'wc_mcp_tenant_id' => 't-abc',
            'wc_mcp_auth_token' => 'tok_valid',
        ])]);

        \Patchwork\redefine('wp_remote_get', function (string $url, array $args = []): array {
            return [
                'response' => ['code' => 500],
                'body'     => '',
            ];
        });

        $request = new WP_REST_Request();
        $response = $this->page->get_status($request);
        $data = $response->get_data();

        // 5xx is treated optimistically — still shown as connected
        $this->assertTrue($data['is_connected']);
    }

    // ── PUT /settings ──────────────────────────────────────────────────────

    public function test_update_settings_connection_mode_when_disconnected(): void {
        Functions\stubs([
            'get_option'    => self::option_stub(),
            'update_option' => true,
        ]);
        \Patchwork\redefine(
            ['\Clariq\McpPlugin\Sync\DeltaSyncWorker', 'reschedule'],
            fn () => null
        );

        $request = new WP_REST_Request();
        $request->set_param('connection_mode', 'local_bridge');

        $response = $this->page->update_settings($request);

        $this->assertEquals(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
    }

    public function test_update_settings_connection_mode_blocked_when_connected(): void {
        Functions\stubs([
            'get_option' => self::option_stub(['wc_mcp_tenant_id' => 't-abc']),
        ]);

        $request = new WP_REST_Request();
        $request->set_param('connection_mode', 'local_bridge');

        $response = $this->page->update_settings($request);

        $this->assertEquals(403, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
    }

    public function test_update_settings_backfill_range(): void {
        Functions\stubs([
            'get_option'    => self::option_stub(),
            'update_option' => true,
        ]);

        $request = new WP_REST_Request();
        $request->set_param('backfill_range', 24);

        $response = $this->page->update_settings($request);

        $this->assertTrue($response->get_data()['success']);
    }

    public function test_update_settings_sync_hour(): void {
        Functions\stubs([
            'get_option'    => self::option_stub(),
            'update_option' => true,
        ]);
        \Patchwork\redefine(
            ['\Clariq\McpPlugin\Sync\DeltaSyncWorker', 'reschedule'],
            fn () => null
        );

        $request = new WP_REST_Request();
        $request->set_param('sync_hour', 6);

        $response = $this->page->update_settings($request);

        $this->assertTrue($response->get_data()['success']);
    }

    // ── POST /sync-now ─────────────────────────────────────────────────────

    public function test_sync_now_success(): void {
        Functions\stubs([
            'get_option' => self::option_stub([
                'wc_mcp_connection_mode' => 'cloud_sync',
                'wc_mcp_tenant_id'       => 't-abc',
            ]),
            'as_enqueue_async_action' => 1,
        ]);
        \Patchwork\redefine(
            ['\Clariq\McpPlugin\Sync\DeltaSyncWorker', 'enqueue_immediate'],
            fn () => null
        );

        $request = new WP_REST_Request();
        $response = $this->page->handle_sync_now($request);

        $this->assertTrue($response->get_data()['success']);
    }

    public function test_sync_now_rejects_bridge_mode(): void {
        Functions\stubs([
            'get_option' => self::option_stub(['wc_mcp_connection_mode' => 'local_bridge']),
        ]);

        $request = new WP_REST_Request();
        $response = $this->page->handle_sync_now($request);

        $this->assertEquals(400, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
    }

    public function test_sync_now_rejects_not_connected(): void {
        Functions\stubs([
            'get_option' => self::option_stub([
                'wc_mcp_connection_mode' => 'cloud_sync',
                'wc_mcp_tenant_id'       => '',
            ]),
        ]);

        $request = new WP_REST_Request();
        $response = $this->page->handle_sync_now($request);

        $this->assertEquals(400, $response->get_status());
    }

    // ── DELETE /connect/disconnect ──────────────────────────────────────────

    public function test_disconnect_clears_credentials(): void {
        $deleted = [];
        Functions\stubs([
            'get_option' => self::option_stub([
                'wc_mcp_tenant_id'  => 't-abc',
                'wc_mcp_auth_token' => 'tok_xyz',
            ]),
            'delete_option' => function (string $key) use (&$deleted) {
                $deleted[] = $key;
                return true;
            },
        ]);

        \Patchwork\redefine('wp_remote_request', function (string $url, array $args = []): array {
            return ['response' => ['code' => 200], 'body' => ''];
        });

        $request = new WP_REST_Request();
        $response = $this->page->handle_disconnect($request);

        $this->assertTrue($response->get_data()['success']);
        $this->assertContains('wc_mcp_tenant_id', $deleted);
        $this->assertContains('wc_mcp_auth_token', $deleted);
        $this->assertContains('wc_mcp_backfill_complete', $deleted);
        $this->assertContains('wc_mcp_last_sync_timestamp', $deleted);
    }

    public function test_disconnect_works_without_existing_credentials(): void {
        Functions\stubs([
            'get_option'    => self::option_stub(),
            'delete_option' => true,
        ]);

        $request = new WP_REST_Request();
        $response = $this->page->handle_disconnect($request);

        $this->assertTrue($response->get_data()['success']);
    }
}
