<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Tests\TestCase;
use Clariq\McpPlugin\Write\OrderNoteWriter;

/**
 * Unit tests for OrderNoteWriter (action orders.note, scope orders.notes).
 *
 * Verifies:
 *  - Valid order: note is added and before/after counts are reported.
 *  - Missing order: order_not_found (never fatal).
 *  - Empty note: rejected with invalid_params.
 */
class OrderNoteWriterTest extends TestCase {

    public function test_valid_order_adds_note_and_returns_before_after(): void {
        $order = \Mockery::mock(\WC_Order::class);
        $order->shouldReceive('add_order_note')
            ->once()
            ->with('Order packed', 0)
            ->andReturn(777);

        Functions\when('wc_get_order')->justReturn($order);
        // Two existing notes before the write.
        Functions\when('wc_get_order_notes')->justReturn([['id' => 1], ['id' => 2]]);

        $result = (new OrderNoteWriter())->write([
            'order_id' => 42,
            'note'     => 'Order packed',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['note_id' => 777], $result['result']);
        $this->assertSame(['note_count' => 2], $result['before']);
        $this->assertSame(['note_id' => 777, 'note_count' => 3], $result['after']);
        $this->assertNull($result['code']);
    }

    public function test_customer_visible_flag_is_passed_through(): void {
        $order = \Mockery::mock(\WC_Order::class);
        $order->shouldReceive('add_order_note')
            ->once()
            ->with('Shipped today', 1) // customer_visible true -> 1
            ->andReturn(5);

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('wc_get_order_notes')->justReturn([]);

        $result = (new OrderNoteWriter())->write([
            'order_id'         => 7,
            'note'             => 'Shipped today',
            'customer_visible' => true,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['before']['note_count']);
        $this->assertSame(1, $result['after']['note_count']);
    }

    public function test_missing_order_returns_order_not_found(): void {
        Functions\when('wc_get_order')->justReturn(false);

        $result = (new OrderNoteWriter())->write([
            'order_id' => 999,
            'note'     => 'anything',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('order_not_found', $result['code']);
        $this->assertNull($result['result']);
    }

    public function test_empty_note_is_rejected(): void {
        // wc_get_order should never be reached for an empty note.
        Functions\when('wc_get_order')->justReturn(\Mockery::mock(\WC_Order::class));
        Functions\when('wc_get_order_notes')->justReturn([]);

        $result = (new OrderNoteWriter())->write([
            'order_id' => 42,
            'note'     => '   ',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_params', $result['code']);
    }

    public function test_missing_order_id_is_rejected(): void {
        $result = (new OrderNoteWriter())->write([
            'note' => 'hello',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_params', $result['code']);
    }
}
