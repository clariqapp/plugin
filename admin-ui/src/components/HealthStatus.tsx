import React from '@wordpress/element';
import type { ReactNode } from 'react';
import { StatusResponse } from '../lib/api';
import { AlertIcon } from './icons';

interface Props {
  status:  StatusResponse | null;
  loading: boolean;
  error:   string | null;
}

function Dot({ ok }: { ok: boolean }) {
  return <span className={`clq-dot ${ok ? 'clq-dot--green' : 'clq-dot--red'}`} aria-hidden="true" />;
}

function Row({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="clq-diag__row">
      <span className="clq-diag__label">{label}</span>
      <span className="clq-diag__value">{value}</span>
    </div>
  );
}

function Shell({ children }: { children: ReactNode }) {
  return (
    <>
      <div className="clq-section__head">
        <div className="clq-section__title">Status</div>
        <p className="clq-section__desc">A quick health check of your Clariq connection.</p>
      </div>
      {children}
    </>
  );
}

/** Plain-language latency label — no raw seconds shown to non-technical users. */
function latencyLabel(latency: number | null): string {
  if (latency === null || latency <= 0) return 'Not measured yet';
  if (latency < 0.5) return 'Fast';
  if (latency < 1.5) return 'Good';
  return 'Slow';
}

export function HealthStatus({ status, loading, error }: Props) {
  if (loading) {
    return <Shell><div className="clq-skeleton" aria-label="Loading status…" /></Shell>;
  }

  if (error) {
    return (
      <Shell>
        <div className="clq-notice clq-notice--error"><AlertIcon /><div>{error}</div></div>
      </Shell>
    );
  }

  if (!status) return null;

  const { mode, warehouse_sync, bridge_latency, pending_jobs } = status;

  if (mode === 'local_bridge') {
    return (
      <Shell>
        <div className="clq-diag">
          <Row
            label="Mode"
            value={<><span className="clq-dot clq-dot--green" />Private — self-hosted</>}
          />
          <Row label="Response speed" value={latencyLabel(bridge_latency)} />
          <Row label="Plugin version" value={`v${status.plugin_version}`} />
        </div>
      </Shell>
    );
  }

  return (
    <Shell>
      <div className="clq-diag">
        <Row
          label="Connection"
          value={
            <>
              <Dot ok={warehouse_sync.connected} />
              {warehouse_sync.connected
                ? `Connected · updated ${warehouse_sync.last_synced}`
                : 'Not connected'}
            </>
          }
        />
        <Row label="Response speed" value={latencyLabel(bridge_latency)} />
        <Row
          label="Pending updates"
          value={pending_jobs === 0 ? 'All caught up' : `${pending_jobs} in progress`}
        />
        <Row label="Plugin version" value={`v${status.plugin_version}`} />
      </div>
    </Shell>
  );
}
