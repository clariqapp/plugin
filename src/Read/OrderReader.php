<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Read;

/**
 * Live-read handler for the `order` resource.
 *
 * Returns full order detail via the WooCommerce CRUD API (never raw SQL).
 *
 * PII policy: this exposes no more than analytics already does. City/country
 * are allowed; the customer email is NOT returned in the clear — it is omitted
 * (a stable hash is provided so the caller can correlate without seeing PII).
 *
 * Returns { ok:true, data } or { ok:false, code }.
 */
final class OrderReader {

    /**
     * @param array<string, mixed> $params { order_id:int }
     * @return array<string, mixed>
     */
    public function read(array $params): array {
        $order_id = isset($params['order_id']) ? (int) $params['order_id'] : 0;

        if ($order_id <= 0) {
            return ['ok' => false, 'code' => 'invalid_params'];
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return ['ok' => false, 'code' => 'order_not_found'];
        }

        $line_items = [];
        foreach ($order->get_items() as $item) {
            $line_items[] = [
                'name'       => (string) $item->get_name(),
                'product_id' => (int) $item->get_product_id(),
                'quantity'   => (int) $item->get_quantity(),
                'total'      => (string) $item->get_total(),
            ];
        }

        $email      = (string) $order->get_billing_email();
        $email_hash = $email === '' ? null : hash('sha256', strtolower($email));

        return [
            'ok'   => true,
            'data' => [
                'id'             => (int) $order->get_id(),
                'status'         => (string) $order->get_status(),
                'currency'       => (string) $order->get_currency(),
                'total'          => (string) $order->get_total(),
                'subtotal'       => (string) $order->get_subtotal(),
                'shipping_total' => (string) $order->get_shipping_total(),
                'discount_total' => (string) $order->get_discount_total(),
                'total_tax'      => (string) $order->get_total_tax(),
                'line_items'     => $line_items,
                'coupons'        => array_values((array) $order->get_coupon_codes()),
                'customer'       => [
                    'city'       => (string) $order->get_billing_city(),
                    'country'    => (string) $order->get_billing_country(),
                    'email_hash' => $email_hash,
                ],
                'date_created'  => self::date($order->get_date_created()),
                'date_modified' => self::date($order->get_date_modified()),
            ],
        ];
    }

    private static function date(mixed $date): ?string {
        if ($date === null) {
            return null;
        }
        if (is_object($date) && method_exists($date, 'date')) {
            return (string) $date->date('c');
        }
        return (string) $date;
    }
}
