import React, { useState } from '@wordpress/element';
import { syncNow } from '../lib/api';

interface Props {
  mode:                'cloud_sync' | 'local_bridge';
  isConnected:         boolean;
  backfillRange:       number;
  onRangeChange:       (range: number) => void;
  backfillStatus:      'idle' | 'running' | 'complete';
  backfillCompletedAt: string;
  lastSyncAt:          string;
  syncHour:            number;
  onHourChange:        (hour: number) => void;
  disabled?:           boolean;
}

const RANGE_OPTIONS = [
  { value: 3,  label: 'Last 3 Months'  },
  { value: 6,  label: 'Last 6 Months'  },
  { value: 12, label: 'Last 12 Months' },
  { value: 24, label: 'Last 24 Months' },
  { value: 36, label: 'Last 36 Months' },
];

/** Build 24 hour options formatted as 12-hour AM/PM labels. */
const HOUR_OPTIONS = Array.from({ length: 24 }, (_, h) => {
  const suffix  = h < 12 ? 'AM' : 'PM';
  const display = h === 0 ? 12 : h > 12 ? h - 12 : h;
  return { value: h, label: `${display}:00 ${suffix}` };
});

export function SyncControls({
  mode,
  isConnected,
  backfillRange,
  onRangeChange,
  backfillStatus,
  backfillCompletedAt,
  lastSyncAt,
  syncHour,
  onHourChange,
  disabled,
}: Props) {
  const [syncing,  setSyncing]  = useState(false);
  const [syncMsg,  setSyncMsg]  = useState<string | null>(null);
  const [syncOk,   setSyncOk]   = useState(false);

  async function handleSyncNow() {
    setSyncing(true);
    setSyncMsg(null);
    setSyncOk(false);
    try {
      await syncNow();
      setSyncMsg('Delta sync queued. Orders will be pushed shortly.');
      setSyncOk(true);
    } catch {
      setSyncMsg('Failed to queue sync. Please try again.');
      setSyncOk(false);
    } finally {
      setSyncing(false);
      setTimeout(() => setSyncMsg(null), 6000);
    }
  }

  // ── Local Bridge: no sync needed ─────────────────────────────────────────
  if (mode === 'local_bridge') {
    return (
      <div className="mcp-field-group">
        <label className="mcp-label">Sync &amp; Performance Controls</label>
        <div className="mcp-local-bridge-notice">
          <svg className="mcp-local-bridge-notice__icon-svg" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" width="18" height="18">
            <path fillRule="evenodd" clipRule="evenodd" d="M18 10C18 14.4183 14.4183 18 10 18C5.58172 18 2 14.4183 2 10C2 5.58172 5.58172 2 10 2C14.4183 2 18 5.58172 18 10ZM11 9H9V14H11V9ZM11 6H9V8H11V6Z" fill="currentColor" />
          </svg>
          <div>
            <strong>Sync not available in Local Bridge mode.</strong>
            <p className="mcp-local-bridge-notice__body">
              Data is queried directly from your store on demand — nothing is
              pushed to the cloud, so there is nothing to sync.
            </p>
          </div>
        </div>
      </div>
    );
  }

  // ── Cloud Sync ────────────────────────────────────────────────────────────
  return (
    <div className="mcp-field-group">
      <label className="mcp-label">Sync &amp; Performance Controls</label>

      {/* ── Historical Backfill ── */}
      <div className="mcp-sync-section">
        <p className="mcp-sublabel mcp-sublabel--section">Historical Backfill</p>

        <div className="mcp-sync-row mcp-sync-row--align-end">
          <div className="mcp-select-wrap">
            <label className="mcp-sublabel" htmlFor="mcp-backfill-range">
              Range
            </label>
            <select
              id="mcp-backfill-range"
              className="mcp-select"
              value={backfillRange}
              disabled={disabled || backfillStatus === 'running' || backfillStatus === 'complete'}
              onChange={e => onRangeChange(Number(e.target.value))}
            >
              {RANGE_OPTIONS.map(opt => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </div>

          {/* Status badge */}
          {backfillStatus === 'running' && (
            <span className="mcp-status-badge mcp-status-badge--running">
              <span className="mcp-spinner mcp-spinner--inline" aria-hidden="true" />
              In progress…
            </span>
          )}
          {backfillStatus === 'running' && (
            <p className="mcp-inline-msg mcp-inline-msg--warning" style={{ marginTop: 8, fontSize: 12 }}>
              Waiting for WP-Cron to process the queue. In local/dev environments run:{' '}
              <code>wp action-scheduler run</code>
            </p>
          )}
          {backfillStatus === 'complete' && (
            <span className="mcp-status-badge mcp-status-badge--complete">
              ✓ Completed{backfillCompletedAt ? ` on ${backfillCompletedAt}` : ''}
            </span>
          )}
          {backfillStatus === 'idle' && (
            <span className="mcp-status-badge mcp-status-badge--idle">
              Not yet started
            </span>
          )}
        </div>
      </div>

      <div className="mcp-divider mcp-divider--inner" />

      {/* ── Sync Now ── */}
      <div className="mcp-sync-section">
        <p className="mcp-sublabel mcp-sublabel--section">Manual Sync</p>

        <div className="mcp-sync-row mcp-sync-row--align-end">
          <div className="mcp-select-wrap">
            <label className="mcp-sublabel" htmlFor="mcp-sync-hour">
              Daily automatic sync time (site timezone)
            </label>
            <select
              id="mcp-sync-hour"
              className="mcp-select"
              value={syncHour}
              disabled={disabled}
              onChange={e => onHourChange(Number(e.target.value))}
            >
              {HOUR_OPTIONS.map(opt => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </div>

          <div className="mcp-sync-now-wrap">
            {lastSyncAt && isConnected && (
              <span className="mcp-last-sync">Last synced: {lastSyncAt}</span>
            )}
            {isConnected ? (
              <button
                type="button"
                className="mcp-btn mcp-btn--secondary"
                disabled={disabled || syncing}
                onClick={handleSyncNow}
              >
                {syncing ? <span className="mcp-spinner" aria-hidden="true" /> : null}
                {syncing ? 'Queuing…' : 'Sync Now'}
              </button>
            ) : (
              <span className="mcp-inline-msg mcp-inline-msg--warning" style={{ fontSize: 12 }}>
                Connect to Clariq to enable sync.
              </span>
            )}
          </div>
        </div>

        {syncMsg && (
          <p className={`mcp-inline-msg ${syncOk ? 'mcp-inline-msg--success' : 'mcp-inline-msg--error'}`}>
            {syncMsg}
          </p>
        )}
      </div>
    </div>
  );
}
