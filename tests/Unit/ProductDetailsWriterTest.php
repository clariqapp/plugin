<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Tests\TestCase;
use Clariq\McpPlugin\Write\ProductDetailsWriter;

/**
 * Unit tests for ProductDetailsWriter (action products.details).
 */
class ProductDetailsWriterTest extends TestCase {

    public function test_patches_only_provided_fields(): void {
        $product = \Mockery::mock(\WC_Product::class);
        $product->shouldReceive('get_name')->andReturn('Old title');
        $product->shouldReceive('set_name')->once()->with('New title');
        // description/short_description/categories/tags NOT touched.
        $product->shouldNotReceive('set_description');
        $product->shouldNotReceive('set_category_ids');
        // NEVER touches price/stock.
        $product->shouldNotReceive('set_regular_price');
        $product->shouldNotReceive('set_stock_quantity');
        $product->shouldReceive('save')->once();

        Functions\when('wc_get_product')->justReturn($product);

        $result = (new ProductDetailsWriter())->write([
            'product_id' => 10,
            'title'      => 'New title',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('Old title', $result['before']['title']);
        $this->assertSame('New title', $result['after']['title']);
        $this->assertSame(['title'], $result['result']['updated_fields']);
    }

    public function test_missing_product_returns_product_not_found(): void {
        Functions\when('wc_get_product')->justReturn(false);

        $result = (new ProductDetailsWriter())->write([
            'product_id' => 999,
            'title'      => 'x',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('product_not_found', $result['code']);
    }

    public function test_no_fields_provided_is_rejected(): void {
        $product = \Mockery::mock(\WC_Product::class);
        $product->shouldNotReceive('save');
        Functions\when('wc_get_product')->justReturn($product);

        $result = (new ProductDetailsWriter())->write([
            'product_id' => 10,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_params', $result['code']);
    }
}
