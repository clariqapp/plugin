<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Read;

/**
 * Live-read handler for the `variants` resource.
 *
 * Returns the variations of a variable product via the WooCommerce CRUD API
 * (never raw SQL).
 *
 * Returns { ok:true, data } or { ok:false, code }.
 */
final class VariantsReader {

    /**
     * @param array<string, mixed> $params { product_id:int }
     * @return array<string, mixed>
     */
    public function read(array $params): array {
        $product_id = isset($params['product_id']) ? (int) $params['product_id'] : 0;

        if ($product_id <= 0) {
            return ['ok' => false, 'code' => 'invalid_params'];
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return ['ok' => false, 'code' => 'product_not_found'];
        }

        $variants = [];
        foreach ((array) $product->get_children() as $variation_id) {
            $variation = wc_get_product((int) $variation_id);
            if (!$variation) {
                continue;
            }
            $variants[] = [
                'variation_id'   => (int) $variation->get_id(),
                'attributes'     => (array) $variation->get_attributes(),
                'sku'            => (string) $variation->get_sku(),
                'regular_price'  => (string) $variation->get_regular_price(),
                'sale_price'     => (string) $variation->get_sale_price(),
                'stock_quantity' => $variation->get_stock_quantity() === null ? null : (int) $variation->get_stock_quantity(),
                'stock_status'   => (string) $variation->get_stock_status(),
            ];
        }

        return ['ok' => true, 'data' => $variants];
    }
}
