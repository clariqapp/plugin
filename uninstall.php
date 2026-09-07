<?php

/**
 * Uninstall handler for WooCommerce Analytics MCP.
 *
 * WordPress calls this file when the merchant clicks "Delete" in the plugins list.
 * It deregisters the tenant from the SaaS API and purges all plugin data from the DB.
 *
 * Note: deactivation (Plugin::on_deactivate) only cancels jobs — it does NOT remove data,
 * so merchants can safely re-activate without re-onboarding.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Bootstrap autoloader without booting the full plugin.
define('WC_MCP_DIR', plugin_dir_path(__FILE__));
define('WC_MCP_VERSION', '1.0.0');
define('WC_MCP_FILE', __FILE__);
define('WC_MCP_URL', '');
define('WC_MCP_BASENAME', '');

spl_autoload_register(function (string $class): void {
    $prefix   = 'Clariq\\McpPlugin\\';
    $base_dir = WC_MCP_DIR . 'src/';
    $len      = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $file = $base_dir . str_replace('\\', '/', substr($class, $len)) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Attempt graceful deregistration from the SaaS API.
\Clariq\McpPlugin\Onboarding\HandshakeClient::deregister_site();

// Purge all plugin options regardless of API result.
\Clariq\McpPlugin\Onboarding\HandshakeClient::purge_credentials();

// Cancel any remaining Action Scheduler jobs.
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('wc_mcp_backfill_batch');
    as_unschedule_all_actions('wc_mcp_force_sync');
}
