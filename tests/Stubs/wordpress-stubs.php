<?php

declare(strict_types=1);

/**
 * Minimal WordPress/WooCommerce stub definitions for unit tests.
 *
 * Brain\Monkey can only intercept functions that PHP already knows about.
 * These stubs declare the bare minimum so tests can run without loading WordPress.
 */

// -----------------------------------------------------------------------
// Core constants
// -----------------------------------------------------------------------

if (!defined('ARRAY_A'))  { define('ARRAY_A',  'ARRAY_A'); }
if (!defined('ARRAY_N'))  { define('ARRAY_N',  'ARRAY_N'); }
if (!defined('OBJECT'))   { define('OBJECT',   'OBJECT'); }
if (!defined('ABSPATH'))  { define('ABSPATH',  '/tmp/wordpress/'); }

// -----------------------------------------------------------------------
// Core classes
// -----------------------------------------------------------------------

if (!class_exists('WP_Error')) {
    class WP_Error {
        private string $code;
        private string $message;
        /** @var array<string, mixed> */
        private array $data;

        public function __construct(string $code = '', string $message = '', mixed $data = '') {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = is_array($data) ? $data : ['status' => $data];
        }

        public function get_error_code(): string    { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        /** @return array<string, mixed> */
        public function get_error_data(): array     { return $this->data; }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        /** @var array<string, mixed> */
        private array $params  = [];
        /** @var array<string, string> */
        private array $headers = [];
        private string $body   = '';

        public function get_header(string $key): string {
            return $this->headers[strtolower($key)] ?? '';
        }
        public function get_body(): string { return $this->body; }
        /** @return mixed */
        public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
        public function set_param(string $key, mixed $value): void { $this->params[$key] = $value; }
        public function has_param(string $key): bool  { return isset($this->params[$key]); }
    }
}

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix     = 'wp_';
        public string $last_error = '';

        public function prepare(string $query, mixed ...$args): string { return $query; }
        /** @return array<int, array<string, mixed>>|null */
        public function get_results(string $query, string $output = 'OBJECT'): ?array { return []; }
        public function get_var(string $query): mixed { return null; }
        public function db_version(): ?string { return '8.0.0'; }
        public function db_server_info(): string { return '8.0.0'; }
    }
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server {
        const READABLE  = 'GET';
        const CREATABLE = 'POST';
        const EDITABLE  = 'POST, PUT, PATCH';
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        /** @var mixed */
        public mixed $data;
        public int $status;
        /** @param mixed $data */
        public function __construct(mixed $data = null, int $status = 200) {
            $this->data   = $data;
            $this->status = $status;
        }
        /** @return mixed */
        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}

// -----------------------------------------------------------------------
// Core functions — declared so Brain\Monkey / patchwork can intercept them
// -----------------------------------------------------------------------

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool {
        return $thing instanceof WP_Error;
    }
}
if (!function_exists('get_option'))     { function get_option(string $k, mixed $d = false): mixed { return $d; } }
if (!function_exists('update_option'))  { function update_option(string $k, mixed $v): bool { return true; } }
if (!function_exists('delete_option'))  { function delete_option(string $k): bool { return true; } }
if (!function_exists('home_url'))       { function home_url(string $path = ''): string { return 'https://example.com' . $path; } }
if (!function_exists('esc_html'))       { function esc_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES); } }
if (!function_exists('esc_html__'))     { function esc_html__(string $s): string { return $s; } }
if (!function_exists('__'))             { function __(string $s): string { return $s; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode(mixed $d): string|false { return json_encode($d); } }
if (!function_exists('wp_remote_post')) { function wp_remote_post(string $url, array $args = []): array { return ['response' => ['code' => 200], 'body' => '']; } }
if (!function_exists('wp_remote_get'))  { function wp_remote_get(string $url, array $args = []): array { return ['response' => ['code' => 200], 'body' => '{}']; } }
if (!function_exists('wp_remote_request')) { function wp_remote_request(string $url, array $args = []): array { return ['response' => ['code' => 200], 'body' => '']; } }
if (!function_exists('is_wp_error'))    {} // already declared above
if (!function_exists('wc_get_logger'))  {
    function wc_get_logger(): object {
        return new class { public function log(string $level, string $msg, array $ctx = []): void {} };
    }
}
if (!function_exists('current_user_can'))  { function current_user_can(string $cap): bool { return true; } }
if (!function_exists('wp_create_nonce'))   { function wp_create_nonce(string $action): string { return 'test-nonce'; } }
if (!function_exists('rest_url'))          { function rest_url(string $path = ''): string { return 'http://localhost/' . $path; } }
if (!function_exists('admin_url'))         { function admin_url(string $path = ''): string { return 'http://localhost/wp-admin/' . $path; } }
if (!function_exists('register_rest_route')) { function register_rest_route(): void {} }
if (!function_exists('add_action'))        { function add_action(): void {} }
if (!function_exists('add_filter'))        { function add_filter(): void {} }
if (!function_exists('apply_filters'))     { function apply_filters(string $tag, mixed $value): mixed { return $value; } }
if (!function_exists('add_option'))        { function add_option(string $k, mixed $v = '', string $deprecated = '', mixed $autoload = 'yes'): bool { return true; } }
if (!function_exists('wc_get_order'))      { function wc_get_order(mixed $id): mixed { return false; } }
if (!function_exists('wc_get_order_notes')) { function wc_get_order_notes(array $args = []): array { return []; } }
if (!function_exists('wc_get_order_statuses')) { function wc_get_order_statuses(): array { return []; } }
if (!function_exists('wc_get_product'))    { function wc_get_product(mixed $id = 0): mixed { return false; } }
if (!function_exists('wc_get_coupon_id_by_code')) { function wc_get_coupon_id_by_code(string $code, int $exclude = 0): int { return 0; } }
if (!function_exists('get_terms'))         { function get_terms(array $args = []): mixed { return []; } }
if (!function_exists('get_posts'))         { function get_posts(array $args = []): array { return []; } }
if (!function_exists('flush_rewrite_rules')) { function flush_rewrite_rules(): void {} }
if (!function_exists('add_query_arg'))      { function add_query_arg(array|string $args, string $url = ''): string { return $url; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field(string $s): string { return $s; } }
if (!function_exists('wp_generate_password')) { function wp_generate_password(int $length = 12, bool $special = true, bool $extra = true): string { return 'test-password-' . $length; } }
if (!function_exists('wp_timezone'))       { function wp_timezone(): \DateTimeZone { return new \DateTimeZone('UTC'); } }
if (!function_exists('DAY_IN_SECONDS'))    {} // constant, not function
if (!function_exists('set_transient'))       { function set_transient(string $k, mixed $v, int $e = 0): bool { return true; } }
if (!function_exists('get_transient'))       { function get_transient(string $k): mixed { return false; } }
if (!function_exists('delete_transient'))    { function delete_transient(string $k): bool { return true; } }
if (!function_exists('wp_redirect'))         { function wp_redirect(string $url, int $status = 302): void {} }
if (!function_exists('wp_generate_uuid4'))   { function wp_generate_uuid4(): string { return '00000000-0000-0000-0000-000000000000'; } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code(array $response): int { return $response['response']['code'] ?? 200; } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body(array $response): string { return $response['body'] ?? ''; } }
if (!function_exists('wp_remote_retrieve_header')) { function wp_remote_retrieve_header(array $response, string $key): string { return $response['headers'][$key] ?? ''; } }

// WC_Order stub for type hints in WebhookDispatcher
if (!class_exists('WC_Order')) {
    class WC_Order {
        public function get_id(): int { return 0; }
        public function get_status(): string { return 'pending'; }
        public function get_date_created(): mixed { return null; }
        public function get_date_modified(): mixed { return null; }
        public function get_total(): string { return '0'; }
        public function get_shipping_total(): string { return '0'; }
        public function get_discount_total(): string { return '0'; }
        public function get_total_tax(): string { return '0'; }
        public function get_customer_id(): int { return 0; }
        public function get_payment_method(): string { return ''; }
        public function get_billing_email(): string { return ''; }
        public function get_shipping_city(): string { return ''; }
        public function get_shipping_country(): string { return ''; }
        public function get_billing_city(): string { return ''; }
        public function get_billing_country(): string { return ''; }
        public function get_meta(string $key): mixed { return null; }
        public function get_items(?string $type = null): array { return []; }
        public function add_order_note(string $note, int $is_customer_note = 0, bool $added_by_user = false): int { return 0; }
    }
}

// WC_Product stub — methods declared so Mockery can mock the class in tests.
if (!class_exists('WC_Product')) {
    class WC_Product {
        public function get_id(): int { return 0; }
        public function get_name(): string { return ''; }
        public function get_type(): string { return 'simple'; }
        public function get_status(): string { return 'publish'; }
        public function get_regular_price(mixed $context = 'view'): string { return ''; }
        public function get_sale_price(mixed $context = 'view'): string { return ''; }
        public function get_stock_quantity(mixed $context = 'view'): mixed { return null; }
        public function get_manage_stock(mixed $context = 'view'): bool { return false; }
        public function get_stock_status(mixed $context = 'view'): string { return 'instock'; }
        public function get_category_ids(mixed $context = 'view'): array { return []; }
        public function get_tag_ids(mixed $context = 'view'): array { return []; }
        public function get_description(mixed $context = 'view'): string { return ''; }
        public function get_short_description(mixed $context = 'view'): string { return ''; }
        public function get_image_id(mixed $context = 'view'): mixed { return 0; }
        public function get_gallery_image_ids(mixed $context = 'view'): array { return []; }
        public function get_children(): array { return []; }
        public function get_attributes(mixed $context = 'view'): array { return []; }
        public function get_sku(mixed $context = 'view'): string { return ''; }
        public function set_manage_stock(bool $manage): void {}
        public function set_stock_quantity(mixed $qty): void {}
        public function set_regular_price(string $price): void {}
        public function set_sale_price(string $price): void {}
        public function set_date_on_sale_from(mixed $date): void {}
        public function set_date_on_sale_to(mixed $date): void {}
        public function set_name(string $name): void {}
        public function set_description(string $desc): void {}
        public function set_short_description(string $desc): void {}
        public function set_category_ids(array $ids): void {}
        public function set_tag_ids(array $ids): void {}
        public function save(): int { return 0; }
    }
}

if (!class_exists('WC_Product_Variation')) {
    class WC_Product_Variation extends WC_Product {
        public function get_type(): string { return 'variation'; }
    }
}

// WC_Coupon functional stub — a property bag, since handlers instantiate it
// directly (new WC_Coupon(...)) and cannot receive an injected mock.
if (!class_exists('WC_Coupon')) {
    class WC_Coupon {
        /** @var array<string, mixed> */
        private array $data = [
            'id'                   => 0,
            'code'                 => '',
            'discount_type'        => '',
            'amount'               => '',
            'individual_use'       => false,
            'exclude_sale_items'   => false,
            'minimum_amount'       => '',
            'maximum_amount'       => '',
            'usage_limit'          => null,
            'date_expires'         => null,
            'product_ids'          => [],
            'excluded_product_ids' => [],
        ];

        public function __construct(mixed $code_or_id = '') {
            if (is_int($code_or_id) && $code_or_id > 0) {
                $this->data['id'] = $code_or_id;
            }
        }

        public function get_id(): int { return (int) $this->data['id']; }
        public function get_code(): string { return (string) $this->data['code']; }
        public function get_discount_type(): string { return (string) $this->data['discount_type']; }
        public function get_amount(): string { return (string) $this->data['amount']; }
        public function get_individual_use(): bool { return (bool) $this->data['individual_use']; }
        public function get_exclude_sale_items(): bool { return (bool) $this->data['exclude_sale_items']; }
        public function get_minimum_amount(): string { return (string) $this->data['minimum_amount']; }
        public function get_maximum_amount(): string { return (string) $this->data['maximum_amount']; }
        public function get_usage_limit(): mixed { return $this->data['usage_limit']; }
        public function get_product_ids(): array { return (array) $this->data['product_ids']; }
        public function get_excluded_product_ids(): array { return (array) $this->data['excluded_product_ids']; }

        public function set_code(string $v): void { $this->data['code'] = $v; }
        public function set_discount_type(string $v): void { $this->data['discount_type'] = $v; }
        public function set_amount(string $v): void { $this->data['amount'] = $v; }
        public function set_individual_use(bool $v): void { $this->data['individual_use'] = $v; }
        public function set_exclude_sale_items(bool $v): void { $this->data['exclude_sale_items'] = $v; }
        public function set_minimum_amount(string $v): void { $this->data['minimum_amount'] = $v; }
        public function set_maximum_amount(string $v): void { $this->data['maximum_amount'] = $v; }
        public function set_usage_limit(int $v): void { $this->data['usage_limit'] = $v; }
        public function set_date_expires(mixed $v): void { $this->data['date_expires'] = $v; }
        public function set_product_ids(array $v): void { $this->data['product_ids'] = $v; }
        public function set_excluded_product_ids(array $v): void { $this->data['excluded_product_ids'] = $v; }

        public function save(): int {
            if ((int) $this->data['id'] === 0) {
                $this->data['id'] = 1234; // simulate insert
            }
            return (int) $this->data['id'];
        }
    }
}

// Action Scheduler stubs — must exist so function_exists() returns true
// and Brain\Monkey can intercept the calls in tests.
if (!class_exists('ActionScheduler_Store')) {
    class ActionScheduler_Store {
        const STATUS_PENDING = 'pending';
    }
}
if (!function_exists('as_has_scheduled_action'))    { function as_has_scheduled_action(string $hook, array $args = [], string $group = ''): bool { return false; } }
if (!function_exists('as_enqueue_async_action'))    { function as_enqueue_async_action(string $hook, array $args = [], string $group = ''): int { return 0; } }
if (!function_exists('as_schedule_single_action')) { function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = ''): int { return 0; } }
if (!function_exists('as_unschedule_all_actions')) { function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): void {} }
if (!function_exists('as_get_scheduled_actions'))  { function as_get_scheduled_actions(array $args = []): array { return []; } }
