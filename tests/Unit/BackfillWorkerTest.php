<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Sync\BackfillWorker;
use Clariq\McpPlugin\Tests\TestCase;

/**
 * Unit tests for BackfillWorker scheduling logic.
 * Database queries are not exercised here — those live in integration tests.
 */
class BackfillWorkerTest extends TestCase {

    // -----------------------------------------------------------------------
    // maybe_schedule_backfill()
    // -----------------------------------------------------------------------

    public function test_schedules_backfill_when_no_action_pending(): void {
        $enqueued = false;

        Functions\when('as_has_scheduled_action')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('as_enqueue_async_action')->alias(function () use (&$enqueued): int {
            $enqueued = true;
            return 1;
        });

        BackfillWorker::maybe_schedule_backfill();

        $this->assertTrue($enqueued, 'Expected as_enqueue_async_action to be called.');
    }

    public function test_does_not_double_schedule_when_job_already_pending(): void {
        $enqueued = false;

        Functions\when('as_has_scheduled_action')->justReturn(true);
        Functions\when('as_enqueue_async_action')->alias(function () use (&$enqueued): int {
            $enqueued = true;
            return 1;
        });

        BackfillWorker::maybe_schedule_backfill();

        $this->assertFalse($enqueued, 'as_enqueue_async_action must not be called when a job is already pending.');
    }

    // -----------------------------------------------------------------------
    // process_batch() — Option C bypass
    // -----------------------------------------------------------------------

    public function test_process_batch_skips_entirely_when_option_c_active(): void {
        $enqueued = false;

        Functions\when('get_option')->alias(fn (string $key) => match ($key) {
            'wc_mcp_connection_mode' => 'option_c',
            default                  => null,
        });
        Functions\when('as_enqueue_async_action')->alias(function () use (&$enqueued): int {
            $enqueued = true;
            return 1;
        });

        BackfillWorker::process_batch();

        $this->assertFalse($enqueued, 'No scheduler action should fire when Option C is active.');
    }

    // -----------------------------------------------------------------------
    // trigger_force_sync()
    // -----------------------------------------------------------------------

    public function test_force_sync_resets_cursor_and_enqueues_batch(): void {
        $stored   = [];
        $enqueued = false;

        Functions\when('update_option')->alias(function (string $key, mixed $val) use (&$stored): bool {
            $stored[$key] = $val;
            return true;
        });
        Functions\when('delete_option')->justReturn(true);
        Functions\when('as_enqueue_async_action')->alias(function () use (&$enqueued): int {
            $enqueued = true;
            return 1;
        });
        Functions\when('wc_get_logger')->justReturn(
            new class { public function log(): void {} }
        );

        BackfillWorker::trigger_force_sync();

        $this->assertSame(0, $stored['wc_mcp_backfill_last_id'] ?? -1,
            'Backfill keyset cursor must be reset to 0 on force sync.');
        $this->assertTrue($enqueued,
            'A new batch action must be enqueued after force sync.');
    }
}
