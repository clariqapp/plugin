<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Tests\TestCase;
use Clariq\McpPlugin\Write\ProductPricingWriter;

/**
 * Unit tests for ProductPricingWriter (action products.pricing).
 */
class ProductPricingWriterTest extends TestCase {

    public function test_within_guard_updates_prices(): void {
        Functions\when('apply_filters')->returnArg(2); // default guard 0.25

        $product = \Mockery::mock(\WC_Product::class);
        // before regular 100 / sale ''; after regular 110 / sale 90
        $product->shouldReceive('get_regular_price')->andReturn('100', '100', '110');
        $product->shouldReceive('get_sale_price')->andReturn('', '90');
        $product->shouldReceive('set_regular_price')->once()->with('110');
        $product->shouldReceive('set_sale_price')->once()->with('90');
        $product->shouldReceive('save')->once();

        Functions\when('wc_get_product')->justReturn($product);

        $result = (new ProductPricingWriter())->write([
            'product_id'    => 10,
            'regular_price' => '110',
            'sale_price'    => '90',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('100', $result['before']['regular_price']);
        $this->assertSame('110', $result['after']['regular_price']);
    }

    public function test_price_change_exceeding_guard_is_rejected_without_applying(): void {
        Functions\when('apply_filters')->returnArg(2); // 0.25 guard

        $product = \Mockery::mock(\WC_Product::class);
        $product->shouldReceive('get_regular_price')->andReturn('100');
        $product->shouldReceive('get_sale_price')->andReturn('');
        // No setters/save should be called — guard rejects first.
        $product->shouldNotReceive('set_regular_price');
        $product->shouldNotReceive('save');

        Functions\when('wc_get_product')->justReturn($product);

        $result = (new ProductPricingWriter())->write([
            'product_id'    => 10,
            'regular_price' => '200', // +100% > 25%
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('price_change_exceeds_guard', $result['code']);
        $this->assertStringContainsString('100', $result['message']);
        $this->assertStringContainsString('200', $result['message']);
    }

    public function test_missing_product_returns_product_not_found(): void {
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('wc_get_product')->justReturn(false);

        $result = (new ProductPricingWriter())->write([
            'product_id'    => 999,
            'regular_price' => '10',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('product_not_found', $result['code']);
    }
}
