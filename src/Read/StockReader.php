<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Read;

/**
 * Live-read handler for the `stock` resource.
 *
 * Serves live stock for a product or variation via the WooCommerce CRUD API
 * (never raw SQL). This is the deferred warehouse product-sync worker's
 * stand-in: live stock is read on demand instead of mirrored into the SaaS.
 *
 * Returns { ok:true, data } or { ok:false, code }.
 */
final class StockReader {

    /**
     * @param array<string, mixed> $params { product_id:int, variant_id?:int }
     * @return array<string, mixed>
     */
    public function read(array $params): array {
        $product_id = isset($params['product_id']) ? (int) $params['product_id'] : 0;
        $variant_id = isset($params['variant_id']) ? (int) $params['variant_id'] : 0;

        if ($product_id <= 0) {
            return ['ok' => false, 'code' => 'invalid_params'];
        }

        $target_id = $variant_id > 0 ? $variant_id : $product_id;
        $obj       = wc_get_product($target_id);
        if (!$obj) {
            return ['ok' => false, 'code' => 'product_not_found'];
        }

        return [
            'ok'   => true,
            'data' => [
                'product_id'     => $target_id,
                'manage_stock'   => (bool) $obj->get_manage_stock(),
                'stock_quantity' => $obj->get_stock_quantity() === null ? null : (int) $obj->get_stock_quantity(),
                'stock_status'   => (string) $obj->get_stock_status(),
            ],
        ];
    }
}
