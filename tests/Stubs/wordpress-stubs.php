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
