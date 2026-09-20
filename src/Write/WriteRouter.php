<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Write;

/**
 * Maps a cloud write action name to its PHP handler.
 *
 * Security model: only pre-approved action names are accepted (mirrors the
 * read-side ToolRouter allowlist). The gateway already validates entitlement
 * and rejects unknown actions upstream, but the plugin still fails safe on any
 * action it does not implement.
 *
 * Actions:
 *   orders.note      -> OrderNoteWriter      (R2a)
 *   orders.status    -> OrderStatusWriter    (R2b)
 *   products.stock   -> ProductStockWriter   (R2b)
 *   products.pricing -> ProductPricingWriter (R2b)
 *   products.details -> ProductDetailsWriter (R2b)
 *   coupons.manage   -> CouponUpsertWriter   (R2b)
 */
final class WriteRouter {

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> The site-relay envelope { ok, result, before, after, code, message }.
     */
    public function route(string $action, array $params): array {
        return match ($action) {
            'orders.note'      => (new OrderNoteWriter())->write($params),
            'orders.status'    => (new OrderStatusWriter())->write($params),
            'products.stock'   => (new ProductStockWriter())->write($params),
            'products.pricing' => (new ProductPricingWriter())->write($params),
            'products.details' => (new ProductDetailsWriter())->write($params),
            'coupons.manage'   => (new CouponUpsertWriter())->write($params),
            default            => [
                'ok'      => false,
                'result'  => null,
                'before'  => null,
                'after'   => null,
                'code'    => 'unknown_action',
                'message' => sprintf('Unknown write action: %s', $action),
            ],
        };
    }
}

