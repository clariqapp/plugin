<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Clariq\McpPlugin\Tests\TestCase;
use Clariq\McpPlugin\Write\CouponUpsertWriter;

/**
 * Unit tests for CouponUpsertWriter (action coupons.manage).
 *
 * Uses the functional WC_Coupon stub (property bag) from wordpress-stubs.php,
 * since the handler instantiates WC_Coupon directly.
 */
class CouponUpsertWriterTest extends TestCase {

    public function test_create_new_coupon_returns_after_config(): void {
        // No existing coupon with this code.
        Functions\when('wc_get_coupon_id_by_code')->justReturn(0);

        $result = (new CouponUpsertWriter())->write([
            'code'          => 'SAVE10',
            'mode'          => 'create',
            'discount_type' => 'percent',
            'amount'        => '10',
            'usage_limit'   => 100,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['before']); // create has no before
        $this->assertSame('SAVE10', $result['after']['code']);
        $this->assertSame('percent', $result['after']['discount_type']);
        $this->assertSame('10', $result['after']['amount']);
        $this->assertSame(100, $result['after']['usage_limit']);
        $this->assertSame('create', $result['result']['mode']);
    }

    public function test_create_when_code_exists_is_rejected(): void {
        Functions\when('wc_get_coupon_id_by_code')->justReturn(55);

        $result = (new CouponUpsertWriter())->write([
            'code' => 'SAVE10',
            'mode' => 'create',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('coupon_exists', $result['code']);
    }

    public function test_update_missing_coupon_is_rejected(): void {
        Functions\when('wc_get_coupon_id_by_code')->justReturn(0);

        $result = (new CouponUpsertWriter())->write([
            'code' => 'NOPE',
            'mode' => 'update',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('coupon_not_found', $result['code']);
    }

    public function test_update_existing_coupon_returns_before_and_after(): void {
        Functions\when('wc_get_coupon_id_by_code')->justReturn(77);

        $result = (new CouponUpsertWriter())->write([
            'code'   => 'SAVE10',
            'mode'   => 'update',
            'amount' => '15',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertIsArray($result['before']); // update carries before snapshot
        $this->assertSame('15', $result['after']['amount']);
        $this->assertSame(77, $result['result']['coupon_id']);
    }
}
