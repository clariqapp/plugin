import React, { useState } from '@wordpress/element';
import { syncNow } from '../lib/api';
import { CheckIcon, AlertIcon, InfoIcon, ShieldIcon, SparkIcon, SyncIcon } from './icons';

interface Props {
  mode:                'cloud_sync' | 'local_bridge';
  isConnected:         boolean;
  backfillRange:       number;
  onRangeChange:       (range: number) => void;
  backfillStatus:      'idle' | 'running' | 'complete';
  backfillCompletedAt: string;
  backfillProcessed:   number;
  backfillTotal:       number;
  backfillWindow:      string;
  onRefreshStatus:     () => void;
  refreshingStatus:    boolean;
  lastSyncAt:          string;
  syncHour:            number;
  onHourChange:        (hour: number) => void;
  disabled?:           boolean;
  plan?:               string;
  retentionDays?:      number | null;
  maxBackfillMonths?:  number | null;
}

const RANGE_OPTIONS = [
  { value: 3,  label: 'Last 3 months'  },
  { value: 6,  label: 'Last 6 months'  },
  { value: 12, label: 'Last 12 months' },
  { value: 24, label: 'Last 2 years'   },
  { value: 36, label: 'Last 3 years'   },
];

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
  backfillProcessed,
  backfillTotal,
  backfillWindow,
  onRefreshStatus,
  refreshingStatus,
  lastSyncAt,
  syncHour,
  onHourChange,
  disabled,
  plan,
  retentionDays,
  maxBackfillMonths,
}: Props) {
  const [syncing, setSyncing] = useState(false);
  const [syncMsg, setSyncMsg] = useState<string | null>(null);
  const [syncOk,  setSyncOk]  = useState(false);

  const isCapped = maxBackfillMonths != null;
  let rangeOptions = RANGE_OPTIONS;
  if (isCapped) {
    rangeOptions = RANGE_OPTIONS.filter(o => o.value <= maxBackfillMonths!);
    if (rangeOptions.length === 0 || (retentionDays != null && retentionDays <= 30)) {
      rangeOptions = [{ value: maxBackfillMonths!, label: `Last ${retentionDays ?? 30} days` }];
    }
  }

  async function handleSyncNow() {
    setSyncing(true);
    setSyncMsg(null);
    setSyncOk(false);
    try {
      await syncNow();
      setSyncMsg('Update started — your latest orders will appear shortly.');
      setSyncOk(true);
    } catch {
      setSyncMsg('We couldn’t start the update. Please try again.');
      setSyncOk(false);
    } finally {
      setSyncing(false);
      setTimeout(() => setSyncMsg(null), 6000);
    }
  }

  // ── Private mode: nothing to sync ─────────────────────────────────────────
  if (mode === 'local_bridge') {
    return (
      <>
        <div className="clq-section__head">
          <div className="clq-section__title">Data &amp; Sync</div>
        </div>
        <div className="clq-notice clq-notice--accent">
          <ShieldIcon />
          <div>
            <strong>Nothing to sync in Private mode.</strong>
            <p style={{ marginTop: 4 }}>
              Your store is read directly, on demand — no data is copied to the cloud,
              so there’s nothing to schedule or update here.
            </p>
          </div>
        </div>
      </>
    );
  }

  const backfillPill = (() => {
    if (backfillStatus === 'running') {
      return <span className="clq-pill clq-pill--running"><span className="clq-spinner clq-spinner--accent" aria-hidden="true" />Importing…</span>;
    }
    if (backfillStatus === 'complete') {
      return <span className="clq-pill clq-pill--ok"><CheckIcon />{backfillCompletedAt ? `Done · ${backfillCompletedAt}` : 'Done'}</span>;
    }
    return <span className="clq-pill clq-pill--idle">Not started</span>;
  })();

  const importPct = backfillTotal > 0
    ? Math.min(100, Math.round((backfillProcessed / backfillTotal) * 100))
    : (backfillStatus === 'complete' ? 100 : 0);
  const showImportProgress = isConnected && (backfillStatus === 'running' || backfillStatus === 'complete');

  // ── Cloud Sync ────────────────────────────────────────────────────────────
  return (
    <>
      {/* Historical import */}
      <div className="clq-section">
        <div className="clq-section__head">
          <div className="clq-section__title">Import your history</div>
          <p className="clq-section__desc">
            Choose how far back to bring in past orders when you first connect. This runs once in the background.
          </p>
        </div>

        <div className="clq-card clq-card__pad">
          <div style={{ display: 'flex', alignItems: 'flex-end', gap: 16, flexWrap: 'wrap' }}>
            <div className="clq-field" style={{ flex: 1, minWidth: 220 }}>
              <label className="clq-field__label" htmlFor="clq-backfill-range">How much history to import</label>
              <select
                id="clq-backfill-range"
                className="clq-select"
                value={backfillRange}
                disabled={disabled || backfillStatus === 'running' || backfillStatus === 'complete'}
                onChange={e => onRangeChange(Number(e.target.value))}
              >
                {rangeOptions.map(opt => (
                  <option key={opt.value} value={opt.value}>{opt.label}</option>
                ))}
              </select>
            </div>
            <div className="clq-import-status">
              {backfillPill}
              <button
                type="button"
                className="clq-iconbtn"
                onClick={onRefreshStatus}
                disabled={refreshingStatus}
                aria-label="Refresh import status"
                title="Refresh import status"
              >
                <SyncIcon className={refreshingStatus ? 'clq-spin' : undefined} />
              </button>
            </div>
          </div>

          {showImportProgress && (
            <div className="clq-import-progress" style={{ marginTop: 14 }}>
              <div className="clq-import-progress__head">
                <span className="clq-import-progress__count">
                  {backfillTotal > 0
                    ? `${backfillProcessed.toLocaleString()} / ${backfillTotal.toLocaleString()} orders imported`
                    : `${backfillProcessed.toLocaleString()} orders imported`}
                </span>
                <span className="clq-import-progress__pct">{importPct}%</span>
              </div>
              <div
                className="clq-progress"
                role="progressbar"
                aria-valuenow={importPct}
                aria-valuemin={0}
                aria-valuemax={100}
              >
                <div
                  className={`clq-progress__bar${backfillStatus === 'complete' ? ' clq-progress__bar--done' : ''}`}
                  style={{ width: `${importPct}%` }}
                />
              </div>
              <div className="clq-import-progress__meta">
                {backfillWindow && <span>Covers {backfillWindow}</span>}
                {backfillStatus === 'complete' && backfillCompletedAt && (
                  <span> · Finished {backfillCompletedAt}</span>
                )}
                {backfillStatus === 'running' && backfillTotal === 0 && (
                  <span> · Counting orders…</span>
                )}
                <span> · Use Refresh to update</span>
              </div>
            </div>
          )}

          {isCapped && (
            <div className="clq-notice clq-notice--info" style={{ marginTop: 14 }}>
              <SparkIcon />
              <div>
                Your {plan ?? 'free'} plan imports the last {retentionDays ?? 30} days of orders.
                Upgrade to import and analyze your full order history.
              </div>
            </div>
          )}

          {backfillStatus === 'running' && (
            <div className="clq-notice clq-notice--info" style={{ marginTop: 14 }}>
              <InfoIcon />
              <div>
                Your history is importing in the background — you can safely leave this page.
                <span style={{ display: 'block', marginTop: 4, color: 'var(--clq-text-muted)' }}>
                  On local/dev sites, run <code>wp action-scheduler run</code> to process the queue.
                </span>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Ongoing updates */}
      <div className="clq-section">
        <div className="clq-section__head">
          <div className="clq-section__title">Keep data up to date</div>
          <p className="clq-section__desc">
            New orders sync automatically every day. Pick a time, or update now whenever you like.
          </p>
        </div>

        <div className="clq-card clq-card__pad">
          <div className="clq-field" style={{ maxWidth: 320 }}>
            <label className="clq-field__label" htmlFor="clq-sync-hour">Daily update time (your store’s timezone)</label>
            <select
              id="clq-sync-hour"
              className="clq-select"
              value={syncHour}
              disabled={disabled}
              onChange={e => onHourChange(Number(e.target.value))}
            >
              {HOUR_OPTIONS.map(opt => (
                <option key={opt.value} value={opt.value}>{opt.label}</option>
              ))}
            </select>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap', marginTop: 18 }}>
            {isConnected ? (
              <button
                type="button"
                className="clq-btn clq-btn--secondary"
                disabled={disabled || syncing}
                onClick={handleSyncNow}
              >
                {syncing ? <span className="clq-spinner" aria-hidden="true" /> : null}
                {syncing ? 'Updating…' : 'Update now'}
              </button>
            ) : (
              <span className="clq-field__hint">Connect your store to enable updates.</span>
            )}
            {lastSyncAt && isConnected && (
              <span className="clq-field__hint">Last updated {lastSyncAt}</span>
            )}
          </div>

          {syncMsg && (
            <div className={`clq-notice ${syncOk ? 'clq-notice--success' : 'clq-notice--error'}`} style={{ marginTop: 14 }}>
              {syncOk ? <CheckIcon /> : <AlertIcon />}
              <div>{syncMsg}</div>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
