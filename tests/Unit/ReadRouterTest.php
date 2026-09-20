<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Tests\TestCase;
use Clariq\McpPlugin\Read\ReadRouter;

/**
 * Unit tests for ReadRouter (resource -> handler allowlist).
 */
class ReadRouterTest extends TestCase {

    public function test_product_resource_dispatches_to_product_reader(): void {
        $product = \Mockery::mock(\WC_Product::class);
        $product->shouldReceive('get_id')->andReturn(10);
        $product->shouldReceive('get_name')->andReturn('Widget');
        $product->shouldReceive('get_type')->andReturn('simple');
        $product->shouldReceive('get_status')->andReturn('publish');
        $product->shouldReceive('get_regular_price')->andReturn('100');
        $product->shouldReceive('get_sale_price')->andReturn('');
        $product->shouldReceive('get_stock_quantity')->andReturn(5);
        $product->shouldReceive('get_manage_stock')->andReturn(true);
        $product->shouldReceive('get_stock_status')->andReturn('instock');
        $product->shouldReceive('get_category_ids')->andReturn([3]);
        $product->shouldReceive('get_image_id')->andReturn(1);
        $product->shouldReceive('get_gallery_image_ids')->andReturn([2, 3]);

        Functions\when('wc_get_product')->justReturn($product);

        $result = (new ReadRouter())->route('product', ['product_id' => 10]);

        $this->assertTrue($result['ok']);
        $this->assertSame(10, $result['data']['id']);
        $this->assertSame('Widget', $result['data']['name']);
        $this->assertSame(5, $result['data']['stock_quantity']);
        $this->assertSame(3, $result['data']['image_count']); // 1 main + 2 gallery
    }

    public function test_unknown_resource_is_rejected(): void {
        $result = (new ReadRouter())->route('secrets', []);

        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_resource', $result['code']);
    }
}
