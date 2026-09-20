<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Sync;

/**
 * Shared, null-safe mapping of WooCommerce Order Attribution order meta keys
 * to the flat ingest payload field names used across all three cloud sync
 * paths (WebhookDispatcher real-time, BackfillWorker, DeltaSyncWorker).
 *
 * The first five entries are the long-standing UTM/referrer/user-agent fields.
 * The trailing six are the WooCommerce 8.5+ session/attribution fields. These
 * keys simply do not exist on older WooCommerce installs, nor for admin / REST
 * API / POS orders — so absence is always normalised to null and never fatal.
 */
final class OrderAttribution {

    /**
     * Map of WC order meta key => ingest payload field name.
     *
     * The payload field names are part of the shared ingest contract with the
     * SaaS API (OrderIn) — do NOT rename them.
     *
     * @var array<string, string>
     */
    public const META_MAP = [
        '_wc_order_attribution_utm_source'         => 'utm_source',
        '_wc_order_attribution_utm_medium'         => 'utm_medium',
        '_wc_order_attribution_utm_campaign'       => 'utm_campaign',
        '_wc_order_attribution_referrer'           => 'referrer',
        '_wc_order_attribution_user_agent'         => 'user_agent',
        '_wc_order_attribution_source_type'        => 'source_type',
        '_wc_order_attribution_device_type'        => 'device_type_attribution',
        '_wc_order_attribution_session_entry'      => 'session_entry',
        '_wc_order_attribution_session_start_time' => 'session_start_time',
        '_wc_order_attribution_session_pages'      => 'session_pages',
        '_wc_order_attribution_session_duration'   => 'session_duration',
    ];

    /**
     * Payload fields that are integer counters. When the raw meta value is
     * numeric it is cast to int; anything else (missing, empty, non-numeric)
     * becomes null. Legitimate zeros are preserved.
     *
     * @var array<int, string>
     */
    private const INT_FIELDS = [
        'session_pages',
        'session_duration',
    ];

    /**
     * Normalise a raw order-meta value for the given payload field.
     *
     * Missing/empty values always become null (never guessed or derived).
     * Integer counter fields are cast to int when numeric, else null.
     * All other fields pass through as a non-empty string, else null.
     *
     * @param string $field The ingest payload field name (a value in META_MAP).
     * @param mixed  $value The raw meta value (string from DB, or WC getter result).
     * @return int|string|null
     */
    public static function normalize(string $field, mixed $value): int|string|null {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, self::INT_FIELDS, true)) {
            return is_numeric($value) ? (int) $value : null;
        }

        $str = (string) $value;
        return $str === '' ? null : $str;
    }

    /**
     * The full set of attribution payload fields, all defaulting to null.
     *
     * @return array<string, null>
     */
    public static function null_defaults(): array {
        return array_fill_keys(array_values(self::META_MAP), null);
    }
}
