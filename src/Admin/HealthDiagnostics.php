<?php

declare(strict_types=1);

namespace Clariq\McpPlugin\Admin;

/**
 * Integrates with the WP Site Health screen to surface
 * MCP pipeline diagnostics alongside core WordPress checks.
 */
final class HealthDiagnostics {

    public function register(): void {
        add_filter('site_status_tests', [$this, 'add_tests']);
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function add_tests(array $tests): array {
        $tests['direct']['wc_mcp_connection'] = [
            'label' => __('WooCommerce Analytics MCP Connection', 'wc-analytics-mcp'),
            'test'  => [$this, 'test_connection'],
        ];

        $tests['direct']['wc_mcp_action_scheduler'] = [
            'label' => __('WooCommerce Analytics MCP — Action Scheduler', 'wc-analytics-mcp'),
            'test'  => [$this, 'test_action_scheduler'],
        ];

        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    public function test_connection(): array {
        $tenant_id = get_option('wc_mcp_tenant_id');

        if (!$tenant_id) {
            return $this->make_result(
                __('MCP plugin is not connected to a tenant.', 'wc-analytics-mcp'),
                'critical',
                __('Visit WooCommerce > Analytics MCP to complete onboarding.', 'wc-analytics-mcp')
            );
        }

        return $this->make_result(
            /* translators: %s: tenant ID */
            sprintf(__('Connected as tenant %s.', 'wc-analytics-mcp'), esc_html($tenant_id)),
            'good'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function test_action_scheduler(): array {
        if (!function_exists('as_get_scheduled_actions')) {
            return $this->make_result(
                __('Action Scheduler is not available.', 'wc-analytics-mcp'),
                'critical',
                __('Ensure WooCommerce is active and fully installed.', 'wc-analytics-mcp')
            );
        }

        $stuck = as_get_scheduled_actions([
            'hook'         => 'wc_mcp_backfill_batch',
            'status'       => \ActionScheduler_Store::STATUS_RUNNING,
            'date'         => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'date_compare' => '<',
        ]);

        if (!empty($stuck)) {
            return $this->make_result(
                __('One or more backfill jobs appear to be stuck.', 'wc-analytics-mcp'),
                'recommended',
                __('Navigate to WooCommerce > Status > Scheduled Actions to investigate.', 'wc-analytics-mcp')
            );
        }

        return $this->make_result(
            __('Action Scheduler queue is healthy.', 'wc-analytics-mcp'),
            'good'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function make_result(string $description, string $status, string $actions = ''): array {
        return [
            'label'       => __('WooCommerce Analytics MCP', 'wc-analytics-mcp'),
            'status'      => $status,
            'badge'       => [
                'label' => __('Analytics MCP', 'wc-analytics-mcp'),
                'color' => match ($status) {
                    'good'        => 'green',
                    'recommended' => 'orange',
                    default       => 'red',
                },
            ],
            'description' => '<p>' . esc_html($description) . '</p>',
            'actions'     => $actions ? '<p>' . esc_html($actions) . '</p>' : '',
            'test'        => 'wc_mcp_connection',
        ];
    }
}
