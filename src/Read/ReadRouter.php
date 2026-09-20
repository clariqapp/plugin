<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Read;

/**
 * Maps a cloud live-read `resource` name to its PHP handler.
 *
 * Security model: only pre-approved resources are accepted (mirrors the
 * write-side WriteRouter allowlist and the SaaS site-read allowlist). The
 * gateway validates entitlement upstream, but the plugin still fails safe on
 * any resource it does not implement.
 *
 * Resources (must match the SaaS site-read allowlist):
 *   order      -> OrderReader
 *   product    -> ProductReader
 *   variants   -> VariantsReader
 *   categories -> CategoriesReader
 *   coupons    -> CouponsReader
 *   stock      -> StockReader
 */
final class ReadRouter {

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> { ok, data? , code? }
     */
    public function route(string $resource, array $params): array {
        return match ($resource) {
            'order'      => (new OrderReader())->read($params),
            'product'    => (new ProductReader())->read($params),
            'variants'   => (new VariantsReader())->read($params),
            'categories' => (new CategoriesReader())->read($params),
            'coupons'    => (new CouponsReader())->read($params),
            'stock'      => (new StockReader())->read($params),
            default      => [
                'ok'   => false,
                'code' => 'unknown_resource',
            ],
        };
    }
}
