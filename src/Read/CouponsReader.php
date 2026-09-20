<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Read;

/**
 * Live-read handler for the `coupons` resource.
 *
 * Returns the live config of a single coupon (when `code` is given) or all
 * coupons, via the WC_Coupon CRUD API (never raw SQL).
 *
 * Returns { ok:true, data } or { ok:false, code }.
 */
final class CouponsReader {

    /**
     * @param array<string, mixed> $params { code?:string }
     * @return array<string, mixed>
     */
    public function read(array $params): array {
        $code = isset($params['code']) ? trim((string) $params['code']) : '';

        if ($code !== '') {
            $coupon_id = (int) wc_get_coupon_id_by_code($code);
            if ($coupon_id <= 0) {
                return ['ok' => false, 'code' => 'coupon_not_found'];
            }
            return ['ok' => true, 'data' => [self::normalize(new \WC_Coupon($coupon_id))]];
        }

        // List all published coupons.
        $ids = get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $coupons = [];
        foreach ((array) $ids as $id) {
            $coupons[] = self::normalize(new \WC_Coupon((int) $id));
        }

        return ['ok' => true, 'data' => $coupons];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalize(\WC_Coupon $coupon): array {
        return [
            'code'                 => (string) $coupon->get_code(),
            'discount_type'        => (string) $coupon->get_discount_type(),
            'amount'               => (string) $coupon->get_amount(),
            'individual_use'       => (bool) $coupon->get_individual_use(),
            'exclude_sale_items'   => (bool) $coupon->get_exclude_sale_items(),
            'minimum_amount'       => (string) $coupon->get_minimum_amount(),
            'maximum_amount'       => (string) $coupon->get_maximum_amount(),
            'usage_limit'          => $coupon->get_usage_limit() === null ? null : (int) $coupon->get_usage_limit(),
            'product_ids'          => array_map('intval', (array) $coupon->get_product_ids()),
            'excluded_product_ids' => array_map('intval', (array) $coupon->get_excluded_product_ids()),
        ];
    }
}
