import React from '@wordpress/element';
import type { ReactNode } from 'react';
import { StatusResponse } from '../lib/api';

interface Props {
  status:  StatusResponse | null;
  loading: boolean;
  error:   string | null;
}

function Dot({ ok }: { ok: boolean }) {
  return (
    <span
      className={`mcp-status-dot ${ok ? 'mcp-status-dot--green' : 'mcp-status-dot--red'}`}
      aria-hidden="true"
    />
  );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="mcp-health-row">
      <span className="mcp-health-row__label">{label}</span>
      <span className="mcp-health-row__value">{value}</span>
    </div>
  );
}

export function HealthStatus({ status, loading, error }: Props) {
  if (loading) {
    return (
      <div className="mcp-field-group">
        <label className="mcp-label">System Status &amp; Health Diagnostics</label>
        <div className="mcp-skeleton-block" aria-label="Loading status…" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="mcp-field-group">
        <label className="mcp-label">System Status &amp; Health Diagnostics</label>
        <p className="mcp-inline-msg mcp-inline-msg--error">{error}</p>
      </div>
    );
  }

  if (!status) return null;

  const { warehouse_sync, bridge_latency, pending_jobs } = status;

  const latencyLabel = bridge_latency !== null
    ? `${bridge_latency.toFixed(2)}s (${bridge_latency < 0.5 ? 'Excellent' : bridge_latency < 1.5 ? 'Good' : 'Degraded'})`
    : 'N/A (Option A active)';

  return (
    <div className="mcp-field-group">
      <label className="mcp-label">System Status &amp; Health Diagnostics</label>

      <div className="mcp-health-panel">
        <Row
          label="Clariq Sync Status"
          value={
            <>
              <Dot ok={warehouse_sync.connected} />
              {warehouse_sync.connected
                ? `Connected · Last synced: ${warehouse_sync.last_synced}`
                : 'Disconnected — complete onboarding to connect'}
            </>
          }
        />
        <Row label="Bridge Latency Index"   value={latencyLabel} />
        <Row label="Action Scheduler Queue" value={`${pending_jobs} Pending Job${pending_jobs !== 1 ? 's' : ''}`} />
        <Row label="Plugin Version"         value={`v${status.plugin_version}`} />
      </div>
    </div>
  );
}
