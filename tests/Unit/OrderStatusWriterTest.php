<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Tests\TestCase;
use Clariq\McpPlugin\Write\OrderStatusWriter;

/**
 * Unit tests for OrderStatusWriter (action orders.status).
 */
class OrderStatusWriterTest extends TestCase {

    public function test_valid_transition_updates_status_and_returns_before_after(): void {
        $order = \Mockery::mock(\WC_Order::class);
        $order->shouldReceive('get_status')->andReturn('pending', 'processing');
        $order->shouldReceive('update_status')->once()->with('processing', 'Clariq MCP');

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('wc_get_order_statuses')->justReturn([
            'wc-pending'    => 'Pending payment',
            'wc-processing' => 'Processing',
            'wc-completed'  => 'Completed',
        ]);

        $result = (new OrderStatusWriter())->write([
            'order_id'  => 42,
            'to_status' => 'processing',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['status' => 'pending'], $result['before']);
        $this->assertSame(['status' => 'processing'], $result['after']);
    }

    public function test_wc_prefixed_status_is_normalized(): void {
        $order = \Mockery::mock(\WC_Order::class);
        $order->shouldReceive('get_status')->andReturn('pending', 'completed');
        $order->shouldReceive('update_status')->once()->with('completed', 'Clariq MCP');

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('wc_get_order_statuses')->justReturn([
            'wc-pending'   => 'Pending payment',
            'wc-completed' => 'Completed',
        ]);

        $result = (new OrderStatusWriter())->write([
            'order_id'  => 42,
            'to_status' => 'wc-completed',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('completed', $result['after']['status']);
    }

    public function test_missing_order_returns_order_not_found(): void {
        Functions\when('wc_get_order')->justReturn(false);
        Functions\when('wc_get_order_statuses')->justReturn(['wc-pending' => 'Pending payment']);

        $result = (new OrderStatusWriter())->write([
            'order_id'  => 999,
            'to_status' => 'processing',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('order_not_found', $result['code']);
    }

    public function test_invalid_status_is_rejected_with_allowed_list(): void {
        $order = \Mockery::mock(\WC_Order::class);
        $order->shouldReceive('get_status')->andReturn('pending');

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('wc_get_order_statuses')->justReturn([
            'wc-pending'    => 'Pending payment',
            'wc-processing' => 'Processing',
        ]);

        $result = (new OrderStatusWriter())->write([
            'order_id'  => 42,
            'to_status' => 'shipped', // not a registered status
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_status', $result['code']);
        $this->assertStringContainsString('pending', $result['message']);
        $this->assertStringContainsString('processing', $result['message']);
    }
}
