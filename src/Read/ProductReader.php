<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Read;

/**
 * Live-read handler for the `product` resource.
 *
 * Returns a single product's live config via the WooCommerce CRUD API
 * (never raw SQL). Serves live stock so a warehouse product-sync worker is
 * not required.
 *
 * Returns { ok:true, data } or { ok:false, code }.
 */
final class ProductReader {

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

        return [
            'ok'   => true,
            'data' => [
                'id'             => (int) $product->get_id(),
                'name'           => (string) $product->get_name(),
                'type'           => (string) $product->get_type(),
                'status'         => (string) $product->get_status(),
                'regular_price'  => (string) $product->get_regular_price(),
                'sale_price'     => (string) $product->get_sale_price(),
                'stock_quantity' => $product->get_stock_quantity() === null ? null : (int) $product->get_stock_quantity(),
                'manage_stock'   => (bool) $product->get_manage_stock(),
                'stock_status'   => (string) $product->get_stock_status(),
                'categories'     => array_map('intval', (array) $product->get_category_ids()),
                'image_count'    => ((int) $product->get_image_id() > 0 ? 1 : 0) + count((array) $product->get_gallery_image_ids()),
            ],
        ];
    }
}
