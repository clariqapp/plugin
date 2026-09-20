<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Write;

/**
 * Write handler for the `orders.note` action (scope: orders.notes).
 *
 * This is the first — and, for R2a, the ONLY — cloud write tool: a low-risk,
 * non-destructive operation that appends a note to an existing WooCommerce
 * order. It never mutates order status, line items, or money, and never fatals:
 * every failure path returns a structured envelope.
 *
 * Return envelope matches the SaaS site-relay contract:
 *   { ok, result, before, after, code, message }
 * where code/message are populated only when ok === false.
 */
final class OrderNoteWriter {

    /**
     * @param array<string, mixed> $params { order_id:int, note:string, customer_visible?:bool }
     * @return array<string, mixed>
     */
    public function write(array $params): array {
        $order_id         = isset($params['order_id']) ? (int) $params['order_id'] : 0;
        $note             = isset($params['note']) ? trim((string) $params['note']) : '';
        $customer_visible = !empty($params['customer_visible']);

        if ($order_id <= 0) {
            return self::fail('invalid_params', 'A positive order_id is required.');
        }

        if ($note === '') {
            return self::fail('invalid_params', 'A non-empty note is required.');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return self::fail('order_not_found', sprintf('Order %d was not found.', $order_id));
        }

        $before_count = count((array) wc_get_order_notes(['order_id' => $order_id]));
        $before        = ['note_count' => $before_count];

        $note_id = (int) $order->add_order_note($note, $customer_visible ? 1 : 0);

        $after = [
            'note_id'    => $note_id,
            'note_count' => $before_count + 1,
        ];

        return [
            'ok'      => true,
            'result'  => ['note_id' => $note_id],
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
