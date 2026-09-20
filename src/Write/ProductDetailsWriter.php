<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Write;

/**
 * Write handler for the `products.details` action (scope: products.details).
 *
 * PATCH-style update of a product's descriptive fields via the WooCommerce CRUD
 * API (never raw SQL). Only the fields present in the request are modified.
 *
 * SAFETY: this handler NEVER touches price or stock. Those are owned by the
 * dedicated products.pricing / products.stock actions so pricing/stock guards
 * cannot be bypassed through a details edit.
 *
 * Return envelope matches the SaaS site-relay contract:
 *   { ok, result, before, after, code, message }.
 * before/after only carry the fields that were provided (values may be large;
 * the SaaS side redacts free-text values before persisting to the audit log).
 */
final class ProductDetailsWriter {

    /**
     * @param array<string, mixed> $params { product_id:int, title?, description?, short_description?, category_ids?:int[], tags?:string[] }
     * @return array<string, mixed>
     */
    public function write(array $params): array {
        $product_id = isset($params['product_id']) ? (int) $params['product_id'] : 0;

        if ($product_id <= 0) {
            return self::fail('invalid_params', 'A positive product_id is required.');
        }

        $obj = wc_get_product($product_id);
        if (!$obj) {
            return self::fail('product_not_found', sprintf('Product %d was not found.', $product_id));
        }

        $before = [];
        $after  = [];

        if (array_key_exists('title', $params) && $params['title'] !== null) {
            $before['title'] = (string) $obj->get_name();
            $obj->set_name((string) $params['title']);
            $after['title'] = (string) $params['title'];
        }

        if (array_key_exists('description', $params) && $params['description'] !== null) {
            $before['description'] = (string) $obj->get_description();
            $obj->set_description((string) $params['description']);
            $after['description'] = (string) $params['description'];
        }

        if (array_key_exists('short_description', $params) && $params['short_description'] !== null) {
            $before['short_description'] = (string) $obj->get_short_description();
            $obj->set_short_description((string) $params['short_description']);
            $after['short_description'] = (string) $params['short_description'];
        }

        if (array_key_exists('category_ids', $params) && is_array($params['category_ids'])) {
            $ids = array_values(array_map('intval', $params['category_ids']));
            $before['category_ids'] = array_map('intval', (array) $obj->get_category_ids());
            $obj->set_category_ids($ids);
            $after['category_ids'] = $ids;
        }

        if (array_key_exists('tags', $params) && is_array($params['tags'])) {
            $tag_ids = array_values(array_map('intval', $params['tags']));
            $before['tags'] = array_map('intval', (array) $obj->get_tag_ids());
            $obj->set_tag_ids($tag_ids);
            $after['tags'] = $tag_ids;
        }

        if ($after === []) {
            return self::fail('invalid_params', 'No updatable product detail fields were provided.');
        }

        $obj->save();

        return [
            'ok'      => true,
            'result'  => ['product_id' => $product_id, 'updated_fields' => array_keys($after)],
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
