<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for WooCommerce Analytics MCP unit tests.
 *
 * Load order matters:
 *  1. Patchwork  — must be first so it can intercept every function defined after it.
 *  2. Composer autoloader — registers PSR-4 maps (lazy, loads no classes yet).
 *  3. WordPress stubs — defines WP classes/functions; Patchwork can now intercept them.
 *  4. Plugin constants — so src/ classes resolve correctly.
 */

// 1. Patchwork MUST come before any function definitions.
require_once dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';

// 2. Composer autoloader (lazy — no classes loaded yet).
require_once dirname(__DIR__) . '/vendor/autoload.php';

// 3. Minimal WordPress / WooCommerce stubs (defined after Patchwork — patchable).
require_once __DIR__ . '/Stubs/wordpress-stubs.php';

// 4. Plugin constants.
if (!defined('WC_MCP_VERSION')) {
    define('WC_MCP_VERSION',  '1.0.0');
    define('WC_MCP_FILE',     dirname(__DIR__) . '/wc-analytics-mcp.php');
    define('WC_MCP_DIR',      dirname(__DIR__) . '/');
    define('WC_MCP_URL',      'http://localhost:8888/wp-content/plugins/wc-analytics-mcp/');
    define('WC_MCP_BASENAME', 'wc-analytics-mcp/wc-analytics-mcp.php');
}
if (!defined('WC_MCP_CLARIQ_URL')) {
    define('WC_MCP_CLARIQ_URL', 'http://localhost:8000');
}
if (!defined('WC_MCP_CLARIQ_INTERNAL_URL')) {
    define('WC_MCP_CLARIQ_INTERNAL_URL', 'http://localhost:8000');
}
