<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Write;

/**
 * Write handler for the `coupons.manage` action (scope: coupons.manage).
 *
 * Creates or updates a WooCommerce coupon via the WC_Coupon CRUD API (never raw
 * SQL). The mode is explicit and enforced so a create never clobbers an existing
 * coupon and an update never silently creates one:
 *  - mode 'create' but the code already exists -> coupon_exists
 *  - mode 'update' but the code does not exist  -> coupon_not_found
 *
 * Return envelope matches the SaaS site-relay contract:
 *   { ok, result, before, after, code, message }.
 * For updates, `before` carries the pre-change normalized config; `after` is
 * always the resulting normalized config.
 */
final class CouponUpsertWriter {

    /**
     * @param array<string, mixed> $params { code:string, mode:'create'|'update', ... }
     * @return array<string, mixed>
     */
    public function write(array $params): array {
        $code = isset($params['code']) ? trim((string) $params['code']) : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : '';

        if ($code === '') {
            return self::fail('invalid_params', 'A non-empty coupon code is required.');
        }

        if ($mode !== 'create' && $mode !== 'update') {
            return self::fail('invalid_params', 'mode must be "create" or "update".');
        }

        $existing_id = (int) wc_get_coupon_id_by_code($code);

        if ($mode === 'create' && $existing_id > 0) {
            return self::fail('coupon_exists', sprintf('Coupon "%s" already exists.', $code));
        }

        if ($mode === 'update' && $existing_id <= 0) {
            return self::fail('coupon_not_found', sprintf('Coupon "%s" was not found.', $code));
        }

        // For create, WC_Coupon('') yields a new object; for update, load by id.
        $coupon = $mode === 'update' ? new \WC_Coupon($existing_id) : new \WC_Coupon('');

        $before = $mode === 'update' ? self::normalize($coupon) : null;

        $coupon->set_code($code);

        if (array_key_exists('discount_type', $params) && $params['discount_type'] !== null) {
            $coupon->set_discount_type((string) $params['discount_type']);
        }
        if (array_key_exists('amount', $params) && $params['amount'] !== null) {
            $coupon->set_amount((string) $params['amount']);
        }
        if (array_key_exists('individual_use', $params)) {
            $coupon->set_individual_use((bool) $params['individual_use']);
        }
        if (array_key_exists('exclude_sale_items', $params)) {
            $coupon->set_exclude_sale_items((bool) $params['exclude_sale_items']);
        }
        if (array_key_exists('minimum_amount', $params) && $params['minimum_amount'] !== null) {
            $coupon->set_minimum_amount((string) $params['minimum_amount']);
        }
        if (array_key_exists('maximum_amount', $params) && $params['maximum_amount'] !== null) {
            $coupon->set_maximum_amount((string) $params['maximum_amount']);
        }
        if (array_key_exists('usage_limit', $params) && $params['usage_limit'] !== null) {
            $coupon->set_usage_limit((int) $params['usage_limit']);
        }
        if (array_key_exists('expiry_date', $params) && $params['expiry_date'] !== null && $params['expiry_date'] !== '') {
            $coupon->set_date_expires((string) $params['expiry_date']);
        }
        if (array_key_exists('product_ids', $params) && is_array($params['product_ids'])) {
            $coupon->set_product_ids(array_values(array_map('intval', $params['product_ids'])));
        }
        if (array_key_exists('excluded_product_ids', $params) && is_array($params['excluded_product_ids'])) {
            $coupon->set_excluded_product_ids(array_values(array_map('intval', $params['excluded_product_ids'])));
        }

        $coupon->save();

        $after = self::normalize($coupon);

        return [
            'ok'      => true,
            'result'  => ['code' => $code, 'coupon_id' => (int) $coupon->get_id(), 'mode' => $mode],
            'before'  => $before,
            'after'   => $after,
            'code'    => null,
            'message' => null,
        ];
    }

    /**
     * Normalized, PII-free coupon configuration snapshot.
     *
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

    /**
     * @return array<string, mixed>
     */
    private static function fail(string $code, string $message): array {
        return [
            'ok'      => false,
            'result'  => null,
            'before'  => null,
            'after'   => null,
            'code'    => $code,
            'message' => $message,
        ];
    }
}
