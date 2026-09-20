<?php

declare(strict_types=1);

namespace Clariq\McpPlugin;

use Clariq\McpPlugin\Admin\SettingsPage;
use Clariq\McpPlugin\Bridge\RestEndpoint;
use Clariq\McpPlugin\Bridge\WriteEndpoint;
use Clariq\McpPlugin\Bridge\ReadEndpoint;
use Clariq\McpPlugin\Security\BridgeKeyManager;
use Clariq\McpPlugin\Sync\BackfillWorker;
use Clariq\McpPlugin\Sync\DeltaSyncWorker;
use Clariq\McpPlugin\Sync\WebhookDispatcher;

/**
 * Central plugin controller — singleton.
 */
final class Plugin {

    private static ?Plugin $instance = null;

    private function __construct() {}

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Wire up all action/filter hooks.
     */
    public function boot(): void {
        // Admin UI + status REST routes.
        // Note: no is_admin() guard — REST API requests run with is_admin()=false,
        // so the status/settings routes must be registered unconditionally.
        // The admin_menu / enqueue hooks inside register() only fire in admin context anyway.
        (new SettingsPage())->register();

        // REST Bridge (Component 2) — always registered; only functional when Option C active.
        add_action('rest_api_init', [new RestEndpoint(), 'register_routes']);

        // Cloud -> WP write relay — always registered; only functional in
        // cloud_sync mode and only with a valid write-key HMAC signature.
        add_action('rest_api_init', [new WriteEndpoint(), 'register_routes']);

        // Cloud -> WP live-read relay — same trust boundary as the write relay
        // (cloud_sync mode + write-key HMAC); non-mutating, no audit.
        add_action('rest_api_init', [new ReadEndpoint(), 'register_routes']);

        // Action Scheduler jobs.
        BackfillWorker::register_hooks();
        DeltaSyncWorker::register_hooks();

        // Order mutation hooks for real-time sync (Cloud Sync mode).
        WebhookDispatcher::register_hooks();
    }

    /**
     * Fired once on plugin activation.
     */
    public static function on_activate(): void {
        // Ensure Action Scheduler tables exist.
        if (class_exists('ActionScheduler')) {
            \ActionScheduler::store();
        }

        // Local bridge is the default mode for fresh installs — make sure a
        // bridge secret exists so the self-hosted path works out of the box,
        // with no Clariq account required.
        BridgeKeyManager::ensure_secret();

        // Note: site registration now happens via browser-based connect flow.
        // See ConnectFlow::initiate() triggered from the admin UI.

        // Schedule the first backfill batch if not already queued.
        BackfillWorker::maybe_schedule_backfill();

        // Schedule the first delta sync at the configured hour (default 2 AM).
        DeltaSyncWorker::maybe_schedule();

        flush_rewrite_rules();
    }

    /**
     * Fired on plugin deactivation (data is preserved; uninstall.php handles removal).
     */
    public static function on_deactivate(): void {
        // Cancel any pending Action Scheduler jobs for this plugin.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wc_mcp_backfill_batch');
            as_unschedule_all_actions('wc_mcp_force_sync');
            as_unschedule_all_actions('wc_mcp_delta_sync');
        }

        flush_rewrite_rules();
    }
}
