<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Write;

/**
 * Write handler for the `orders.status` action (scope: orders.status).
 *
 * Transitions an existing WooCommerce order to a new registered status via the
 * WooCommerce CRUD API (never raw SQL). A valid-transition check rejects any
 * target status that is not registered on this store.
 *
 * SIDE-EFFECT NOTE: Some order-status transitions trigger WooCommerce customer
 * emails (e.g. "processing", "completed", "refunded" map to transactional
 * emails via WC_Emails). Callers/tool descriptions must surface that changing
 * status here can send an email to the customer. This handler does not suppress
 * those emails — it uses the standard WC_Order::update_status() path so store
 * behaviour and any email hooks remain intact.
 *
 * Return envelope matches the SaaS site-relay contract:
 *   { ok, result, before, after, code, message }.
 */
final class OrderStatusWriter {

    /**
     * @param array<string, mixed> $params { order_id:int, to_status:string }
     * @return array<string, mixed>
     */
    public function write(array $params): array {
        $order_id  = isset($params['order_id']) ? (int) $params['order_id'] : 0;
        $to_status = isset($params['to_status']) ? trim((string) $params['to_status']) : '';

        if ($order_id <= 0) {
            return self::fail('invalid_params', 'A positive order_id is required.');
        }

        if ($to_status === '') {
            return self::fail('invalid_params', 'A non-empty to_status is required.');
        }

        // Normalize: WooCommerce statuses are stored/keyed without the "wc-"
        // prefix when passed to update_status(), but callers may send either.
        $normalized = self::normalize_status($to_status);

        $order = wc_get_order($order_id);
        if (!$order) {
            return self::fail('order_not_found', sprintf('Order %d was not found.', $order_id));
        }

        // Valid-transition check against this store's registered statuses.
        $statuses = wc_get_order_statuses(); // e.g. ['wc-pending' => 'Pending payment', ...]
        $allowed  = [];
        foreach (array_keys($statuses) as $key) {
            $allowed[] = self::normalize_status((string) $key);
        }

        if (!in_array($normalized, $allowed, true)) {
            return self::fail(
                'invalid_status',
                sprintf(
                    'Status "%s" is not a registered order status. Allowed: %s.',
                    $normalized,
                    implode(', ', $allowed)
                )
            );
        }

        $before = ['status' => (string) $order->get_status()];

        $order->update_status($normalized, 'Clariq MCP');

        $after = ['status' => (string) $order->get_status()];

        return [
            'ok'      => true,
            'result'  => ['order_id' => $order_id, 'status' => $after['status']],
            'before'  => $before,
            'after'   => $after,
            'code'    => null,
            'message' => null,
        ];
    }

    private static function normalize_status(string $status): string {
        return str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
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
