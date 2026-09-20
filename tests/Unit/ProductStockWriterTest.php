<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Tests\TestCase;
use Clariq\McpPlugin\Write\ProductStockWriter;

/**
 * Unit tests for ProductStockWriter (action products.stock).
 */
class ProductStockWriterTest extends TestCase {

    public function test_set_mode_updates_quantity_and_returns_before_after(): void {
        $product = \Mockery::mock(\WC_Product::class);
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        // before: 5, after: 20
        $product->shouldReceive('get_stock_quantity')->andReturn(5, 5, 20);
        $product->shouldReceive('set_stock_quantity')->once()->with(20);
        $product->shouldReceive('save')->once();

        Functions\when('wc_get_product')->justReturn($product);

        $result = (new ProductStockWriter())->write([
            'product_id' => 10,
            'mode'       => 'set',
            'value'      => 20,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(5, $result['before']['stock_quantity']);
        $this->assertSame(20, $result['after']['stock_quantity']);
    }

    public function test_delta_mode_adds_to_current_quantity(): void {
        $product = \Mockery::mock(\WC_Product::class);
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_stock_quantity')->andReturn(5, 5, 2);
        $product->shouldReceive('set_stock_quantity')->once()->with(2); // 5 + (-3)
        $product->shouldReceive('save')->once();

        Functions\when('wc_get_product')->justReturn($product);

        $result = (new ProductStockWriter())->write([
            'product_id' => 10,
            'mode'       => 'delta',
            'value'      => -3,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['after']['stock_quantity']);
    }

    public function test_manage_stock_flag_is_applied(): void {
        $product = \Mockery::mock(\WC_Product::class);
        $product->shouldReceive('set_manage_stock')->once()->with(true);
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_stock_quantity')->andReturn(0, 0, 8);
        $product->shouldReceive('set_stock_quantity')->once()->with(8);
        $product->shouldReceive('save')->once();

        Functions\when('wc_get_product')->justReturn($product);

        $result = (new ProductStockWriter())->write([
            'product_id'   => 10,
            'mode'         => 'set',
            'value'        => 8,
            'manage_stock' => true,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['after']['manage_stock']);
    }

    public function test_missing_product_returns_product_not_found(): void {
        Functions\when('wc_get_product')->justReturn(false);

        $result = (new ProductStockWriter())->write([
            'product_id' => 999,
            'mode'       => 'set',
            'value'      => 1,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('product_not_found', $result['code']);
    }
}
