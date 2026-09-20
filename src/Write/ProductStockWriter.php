<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Write;

/**
 * Write handler for the `products.stock` action (scope: products.stock).
 *
 * Adjusts the stock quantity (and optionally the manage_stock flag) of a
 * product or a specific variation via the WooCommerce CRUD API (never raw SQL).
 *
 * Modes:
 *  - set   : stock_quantity = value
 *  - delta : stock_quantity = current + value  (value may be negative)
 *
 * Return envelope matches the SaaS site-relay contract:
 *   { ok, result, before, after, code, message }.
 */
final class ProductStockWriter {

    /**
     * @param array<string, mixed> $params { product_id:int, variant_id?:int, mode:'set'|'delta', value:int, manage_stock?:bool }
     * @return array<string, mixed>
     */
    public function write(array $params): array {
        $product_id = isset($params['product_id']) ? (int) $params['product_id'] : 0;
        $variant_id = isset($params['variant_id']) ? (int) $params['variant_id'] : 0;
        $mode       = isset($params['mode']) ? (string) $params['mode'] : '';
        $has_value  = array_key_exists('value', $params);
        $value      = $has_value ? (int) $params['value'] : 0;

        if ($product_id <= 0) {
            return self::fail('invalid_params', 'A positive product_id is required.');
        }

        if ($mode !== 'set' && $mode !== 'delta') {
            return self::fail('invalid_params', 'mode must be "set" or "delta".');
        }

        if (!$has_value) {
            return self::fail('invalid_params', 'An integer value is required.');
        }

        // Resolve the write target: variation when variant_id is provided.
        $target_id = $variant_id > 0 ? $variant_id : $product_id;
        $obj       = wc_get_product($target_id);
        if (!$obj) {
            return self::fail('product_not_found', sprintf('Product %d was not found.', $target_id));
        }

        // Optionally toggle stock management before computing quantity.
        if (array_key_exists('manage_stock', $params)) {
            $obj->set_manage_stock((bool) $params['manage_stock']);
        }

        $before = [
            'manage_stock'   => (bool) $obj->get_manage_stock(),
            'stock_quantity' => self::qty($obj->get_stock_quantity()),
            'stock_status'   => (string) $obj->get_stock_status(),
        ];

        $current = self::qty($obj->get_stock_quantity());
        $new_qty = $mode === 'set' ? $value : $current + $value;

        $obj->set_stock_quantity($new_qty);
        $obj->save();

        $after = [
            'manage_stock'   => (bool) $obj->get_manage_stock(),
            'stock_quantity' => self::qty($obj->get_stock_quantity()),
            'stock_status'   => (string) $obj->get_stock_status(),
        ];

        return [
            'ok'      => true,
            'result'  => ['product_id' => $target_id, 'stock_quantity' => $after['stock_quantity']],
            'before'  => $before,
            'after'   => $after,
            'code'    => null,
            'message' => null,
        ];
    }

    /** Normalize a nullable stock quantity to an int. */
    private static function qty(mixed $value): int {
        return $value === null || $value === '' ? 0 : (int) $value;
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
