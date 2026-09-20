<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Write;

/**
 * Write handler for the `products.pricing` action (scope: products.pricing).
 *
 * Updates the regular/sale price (and optional sale schedule) of a product or
 * variation via the WooCommerce CRUD API (never raw SQL).
 *
 * PRICING GUARD: a plugin-enforced safety rail rejects large regular-price
 * swings. The maximum allowed change fraction defaults to 0.25 (25%) and is
 * filterable via `apply_filters('wc_mcp_max_price_change', 0.25)`. When a
 * regular_price is supplied, the current price is > 0, and
 * abs(new - current) / current exceeds the guard, the write is rejected with
 * `price_change_exceeds_guard` and NOTHING is applied.
 *
 * Return envelope matches the SaaS site-relay contract:
 *   { ok, result, before, after, code, message }.
 */
final class ProductPricingWriter {

    /**
     * @param array<string, mixed> $params { product_id:int, variant_id?:int, regular_price?, sale_price?, sale_from?, sale_to? }
     * @return array<string, mixed>
     */
    public function write(array $params): array {
        $product_id = isset($params['product_id']) ? (int) $params['product_id'] : 0;
        $variant_id = isset($params['variant_id']) ? (int) $params['variant_id'] : 0;

        if ($product_id <= 0) {
            return self::fail('invalid_params', 'A positive product_id is required.');
        }

        $target_id = $variant_id > 0 ? $variant_id : $product_id;
        $obj       = wc_get_product($target_id);
        if (!$obj) {
            return self::fail('product_not_found', sprintf('Product %d was not found.', $target_id));
        }

        $before = [
            'regular_price' => (string) $obj->get_regular_price(),
            'sale_price'    => (string) $obj->get_sale_price(),
        ];

        // Pricing guard — evaluated BEFORE any mutation so a rejected write
        // leaves the product untouched.
        if (array_key_exists('regular_price', $params) && $params['regular_price'] !== null && $params['regular_price'] !== '') {
            $proposed = (float) $params['regular_price'];
            $current  = (float) $obj->get_regular_price();
            $limit    = (float) apply_filters('wc_mcp_max_price_change', 0.25);

            if ($current > 0 && abs($proposed - $current) / $current > $limit) {
                return self::fail(
                    'price_change_exceeds_guard',
                    sprintf(
                        'Regular price change from %s to %s exceeds the %s%% guard.',
                        $current,
                        $proposed,
                        round($limit * 100, 2)
                    )
                );
            }

            $obj->set_regular_price((string) $params['regular_price']);
        }

        if (array_key_exists('sale_price', $params) && $params['sale_price'] !== null) {
            $obj->set_sale_price((string) $params['sale_price']);
        }

        if (array_key_exists('sale_from', $params) && $params['sale_from'] !== null && $params['sale_from'] !== '') {
            $obj->set_date_on_sale_from((string) $params['sale_from']);
        }

        if (array_key_exists('sale_to', $params) && $params['sale_to'] !== null && $params['sale_to'] !== '') {
            $obj->set_date_on_sale_to((string) $params['sale_to']);
        }

        $obj->save();

        $after = [
            'regular_price' => (string) $obj->get_regular_price(),
            'sale_price'    => (string) $obj->get_sale_price(),
        ];

        return [
            'ok'      => true,
            'result'  => ['product_id' => $target_id],
            'before'  => $before,
            'after'   => $after,
            'code'    => null,
            'message' => null,
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
