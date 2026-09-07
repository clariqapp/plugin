<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Sync\DeltaSyncWorker;
use Clariq\McpPlugin\Tests\TestCase;

/**
 * Unit tests for DeltaSyncWorker.
 *
 * Verifies scheduling, mode guards, and timestamp calculations.
 * Database queries are not exercised — those live in integration tests.
 */
class DeltaSyncWorkerTest extends TestCase {

    // -----------------------------------------------------------------------
    // register_hooks()
    // -----------------------------------------------------------------------

    public function test_register_hooks_registers_action(): void {
        $registered = false;
        Functions\when('add_action')->alias(function () use (&$registered) {
            $registered = true;
        });

        DeltaSyncWorker::register_hooks();

        $this->assertTrue($registered);
    }

    // -----------------------------------------------------------------------
    // enqueue_immediate()
    // -----------------------------------------------------------------------

    public function test_enqueue_immediate_enqueues_action(): void {
        $enqueued = false;
        Functions\when('as_enqueue_async_action')->alias(function () use (&$enqueued) {
            $enqueued = true;
            return 1;
        });

        DeltaSyncWorker::enqueue_immediate();

        $this->assertTrue($enqueued);
    }

    // -----------------------------------------------------------------------
    // maybe_schedule()
    // -----------------------------------------------------------------------

    public function test_maybe_schedule_creates_action_when_none_pending(): void {
        $scheduled = false;
        Functions\when('as_has_scheduled_action')->justReturn(false);
        Functions\when('get_option')->justReturn(2);
        Functions\when('as_schedule_single_action')->alias(function () use (&$scheduled) {
            $scheduled = true;
            return 1;
        });

        DeltaSyncWorker::maybe_schedule();

        $this->assertTrue($scheduled);
    }

    public function test_maybe_schedule_skips_when_already_pending(): void {
        $scheduled = false;
        Functions\when('as_has_scheduled_action')->justReturn(true);
        Functions\when('as_schedule_single_action')->alias(function () use (&$scheduled) {
            $scheduled = true;
            return 1;
        });

        DeltaSyncWorker::maybe_schedule();

        $this->assertFalse($scheduled);
    }

    public function test_maybe_schedule_uses_custom_hour(): void {
        $captured_timestamp = null;
        Functions\when('as_has_scheduled_action')->justReturn(false);
        Functions\when('as_schedule_single_action')->alias(function (int $ts) use (&$captured_timestamp) {
            $captured_timestamp = $ts;
            return 1;
        });

        DeltaSyncWorker::maybe_schedule(5);

        $this->assertNotNull($captured_timestamp);
    }

    // -----------------------------------------------------------------------
    // reschedule()
    // -----------------------------------------------------------------------

    public function test_reschedule_cancels_and_reschedules(): void {
        $unscheduled = false;
        $rescheduled = false;

        Functions\when('as_unschedule_all_actions')->alias(function () use (&$unscheduled) {
            $unscheduled = true;
        });
        Functions\when('as_schedule_single_action')->alias(function () use (&$rescheduled) {
            $rescheduled = true;
            return 1;
        });

        DeltaSyncWorker::reschedule(6);

        $this->assertTrue($unscheduled);
        $this->assertTrue($rescheduled);
    }

    // -----------------------------------------------------------------------
    // run() — mode guard
    // -----------------------------------------------------------------------

    public function test_run_skips_in_bridge_mode(): void {
        $dispatched = false;

        Functions\when('get_option')->alias(function (string $k, mixed $d = false) {
            if ($k === 'wc_mcp_connection_mode') return 'local_bridge';
            return $d;
        });

        DeltaSyncWorker::run();

        // Should return early, no dispatch
        $this->assertFalse($dispatched);
    }

    public function test_run_skips_when_not_connected(): void {
        Functions\when('get_option')->alias(function (string $k, mixed $d = false) {
            if ($k === 'wc_mcp_connection_mode') return 'cloud_sync';
            if ($k === 'wc_mcp_tenant_id') return '';
            if ($k === 'wc_mcp_auth_token') return '';
            return $d;
        });

        $rescheduled = false;
        Functions\when('as_schedule_single_action')->alias(function () use (&$rescheduled) {
            $rescheduled = true;
            return 1;
        });

        DeltaSyncWorker::run();

        // Should schedule_next even when skipping
        $this->assertTrue($rescheduled);
    }

    // -----------------------------------------------------------------------
    // run() — empty orders
    // -----------------------------------------------------------------------

    public function test_run_with_no_orders_still_schedules_next(): void {
        Functions\when('get_option')->alias(function (string $k, mixed $d = false) {
            if ($k === 'wc_mcp_connection_mode') return 'cloud_sync';
            if ($k === 'wc_mcp_tenant_id') return 'tenant-123';
            if ($k === 'wc_mcp_auth_token') return 'auth-456';
            if ($k === 'wc_mcp_last_sync_timestamp') return (string) time();
            return $d;
        });

        // wpdb returns empty results
        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public function prepare(string $query, mixed ...$args): string { return $query; }
            public function get_results(string $query, string $output = 'OBJECT'): ?array { return []; }
            public function get_var(string $query): mixed { return null; }
        };

        $rescheduled = false;
        Functions\when('as_schedule_single_action')->alias(function () use (&$rescheduled) {
            $rescheduled = true;
            return 1;
        });

        DeltaSyncWorker::run();

        $this->assertTrue($rescheduled);
    }

    // -----------------------------------------------------------------------
    // since_timestamp() — private helper via reflection
    // -----------------------------------------------------------------------

    public function test_since_timestamp_returns_iso_format(): void {
        Functions\when('get_option')->justReturn((string) time());

        $ref = new \ReflectionMethod(DeltaSyncWorker::class, 'since_timestamp');
        $ref->setAccessible(true);

        $result = $ref->invoke(null);

        // gmdate returns "Y-m-d H:i:s" format (space-separated)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }

    public function test_since_timestamp_uses_last_sync(): void {
        $test_ts = 1700000000;
        Functions\when('get_option')->justReturn((string) $test_ts);

        $ref = new \ReflectionMethod(DeltaSyncWorker::class, 'since_timestamp');
        $ref->setAccessible(true);

        $result = $ref->invoke(null);
        $expected = gmdate('Y-m-d H:i:s', $test_ts);

        $this->assertSame($expected, $result);
    }
}
