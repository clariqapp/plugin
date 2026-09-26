<?php
/**
 * Plugin Name:       Clariq Analytics MCP
 * Plugin URI:        https://clariqapp.com
 * Description:       Connects your WooCommerce store to LLMs via the Model Context Protocol. Supports real-time cloud warehouse sync (Option A) and privacy-first on-premise bridge (Option C).
 * Version:           1.3.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Clariq
 * Author URI:        https://clariqapp.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-analytics-mcp
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.0
 */

declare(strict_types=1);

// Prevent direct file access.
defined('ABSPATH') || exit;

// Plugin constants.
define('WC_MCP_VERSION',     '1.3.2');
define('WC_MCP_FILE',        __FILE__);
define('WC_MCP_DIR',         plugin_dir_path(__FILE__));
define('WC_MCP_URL',         plugin_dir_url(__FILE__));
define('WC_MCP_BASENAME',    plugin_basename(__FILE__));
// Allow wp-config.php (or a mu-plugin) to pre-define the Clariq URL for local dev/staging overrides.
if (!defined('WC_MCP_CLARIQ_URL')) {
    define('WC_MCP_CLARIQ_URL', apply_filters('wc_mcp_clariq_url', 'https://app.clariqapp.com'));
}
// Server-to-server API base (wp_remote_request): connect code exchange, order
// ingest, and the connection health-check all target this. It is the API host
// (api.clariqapp.com), NOT the dashboard (app.clariqapp.com) — they are separate
// services, so this must not fall back to WC_MCP_CLARIQ_URL.
//
// Production customers need no configuration: the default below is correct.
// Only override it (in wp-config.php, or via the wc_mcp_clariq_internal_url
// filter) for local dev — e.g. http://host.docker.internal:8000 when WordPress
// runs inside Docker and the API runs on the host.
if (!defined('WC_MCP_CLARIQ_INTERNAL_URL')) {
    define('WC_MCP_CLARIQ_INTERNAL_URL', apply_filters('wc_mcp_clariq_internal_url', 'https://api.clariqapp.com'));
}

// Declare HPOS compatibility.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

// PSR-4 autoloader (maps Clariq\McpPlugin\ → src/).
spl_autoload_register(function (string $class): void {
    $prefix    = 'Clariq\\McpPlugin\\';
    $base_dir  = WC_MCP_DIR . 'src/';
    $len       = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative  = substr($class, $len);
    $file      = $base_dir . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Boot the plugin after all plugins are loaded (ensures WooCommerce is available).
add_action('plugins_loaded', function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('WooCommerce Analytics MCP requires WooCommerce to be installed and active.', 'wc-analytics-mcp')
                . '</p></div>';
        });
        return;
    }

    // One-time migration: rename legacy internal mode values to meaningful names.
    $mode = get_option('wc_mcp_connection_mode', '');
    if ($mode === 'option_a') {
        update_option('wc_mcp_connection_mode', 'cloud_sync');
    } elseif ($mode === 'option_c') {
        update_option('wc_mcp_connection_mode', 'local_bridge');
    }

    \Clariq\McpPlugin\Plugin::instance()->boot();
});

// Activation / deactivation hooks (must be registered at file load time).
register_activation_hook(__FILE__,   ['Clariq\\McpPlugin\\Plugin', 'on_activate']);
register_deactivation_hook(__FILE__, ['Clariq\\McpPlugin\\Plugin', 'on_deactivate']);
