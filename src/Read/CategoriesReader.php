<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Read;

/**
 * Live-read handler for the `categories` resource.
 *
 * Returns the store's product categories via the WordPress taxonomy API
 * (never raw SQL).
 *
 * Returns { ok:true, data } or { ok:false, code }.
 */
final class CategoriesReader {

    /**
     * @param array<string, mixed> $params {}
     * @return array<string, mixed>
     */
    public function read(array $params): array {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || !is_array($terms)) {
            return ['ok' => false, 'code' => 'categories_unavailable'];
        }

        $categories = [];
        foreach ($terms as $term) {
            $categories[] = [
                'id'     => (int) ($term->term_id ?? 0),
                'name'   => (string) ($term->name ?? ''),
                'parent' => (int) ($term->parent ?? 0),
                'count'  => (int) ($term->count ?? 0),
            ];
        }

        return ['ok' => true, 'data' => $categories];
    }
}
