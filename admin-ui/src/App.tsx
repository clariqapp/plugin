import React, { useState, useEffect } from '@wordpress/element';
import { ConnectionModeToggle } from './components/ConnectionModeToggle';
import { ConnectionPanel }      from './components/ConnectionPanel';
import { BridgeSetupPanel }     from './components/BridgeSetupPanel';
import { SyncControls }         from './components/SyncControls';
import { HealthStatus }         from './components/HealthStatus';
import { saveSettings, ConnectionMode } from './lib/api';
import { useStatus }            from './hooks/useStatus';
import './style.css';

export function App() {
  const {
    mode: initialMode,
    backfillRange: initialRange,
    syncHour: initialSyncHour,
    isConnected: initialConnected,
    backfillStatus: initialBackfillStatus,
    backfillCompletedAt: initialBackfillCompletedAt,
    lastSyncAt: initialLastSyncAt,
    plan: initialPlan,
    maxBackfillMonths: initialMaxBackfillMonths,
    retentionDays: initialRetentionDays,
  } = window.wcMcpData;

  // Free (controlled-backfill) stores cap the sync window. Prefer the live
  // status value (refreshed from /v1/stores/me) and fall back to the bootstrap.
  const { status, loading, error, refresh } = useStatus();
  const maxBackfillMonths =
    status?.max_backfill_months ?? initialMaxBackfillMonths ?? null;
  const retentionDays = status?.retention_days ?? initialRetentionDays ?? null;
  const plan = status?.plan ?? initialPlan ?? 'free';

  const clampRange = (r: number) =>
    maxBackfillMonths != null ? Math.min(r, maxBackfillMonths) : r;

  const [mode,                setMode]                = useState<ConnectionMode>(initialMode);
  const [backfillRange,       setBackfillRange]       = useState(clampRange(Number(initialRange)));
  const [syncHour,            setSyncHour]            = useState(Number(initialSyncHour ?? 2));
  const [isConnected,         setIsConnected]         = useState(initialConnected);
  const [backfillStatus,      setBackfillStatus]      = useState(initialBackfillStatus);
  const [backfillCompletedAt, setBackfillCompletedAt] = useState(initialBackfillCompletedAt);
  const [lastSyncAt,          setLastSyncAt]          = useState(initialLastSyncAt);
  const [saving,              setSaving]              = useState(false);
  const [saveMsg,             setSaveMsg]             = useState<string | null>(null);

  // Keep the selected range within the plan cap if the cap tightens (e.g. the
  // status poll reports a newly-downgraded plan).
  useEffect(() => {
    if (maxBackfillMonths != null && backfillRange > maxBackfillMonths) {
      setBackfillRange(maxBackfillMonths);
    }
  }, [maxBackfillMonths]);

  // Detect dashboard-side disconnects: if the status poll sees is_connected=false
  // while we think we're connected, sync local state immediately.
  useEffect(() => {
    if (isConnected && status?.is_connected === false) {
      handleDisconnected();
    }
  }, [status?.is_connected]);

  /** Called by ConnectionPanel after a successful disconnect. */
  function handleDisconnected() {
    setIsConnected(false);
    // Reset all sync state so the UI reflects a clean slate immediately,
    // without needing a page reload.
    setBackfillStatus('idle');
    setBackfillCompletedAt('');
    setLastSyncAt('');
  }

  /**
   * Switch connection mode. Persist immediately (not deferred to "Save Changes")
   * so every mode-dependent surface — the bridge Test Connection endpoint, the
   * connect panel, sync controls, and health diagnostics — reads one consistent
   * value instead of a mix of saved/unsaved state. Optimistic with rollback.
   */
  async function handleModeChange(next: ConnectionMode) {
    if (next === mode || saving || isConnected) return;
    const prev = mode;
    setMode(next);
    setSaving(true);
    setSaveMsg(null);
    try {
      await saveSettings({ connection_mode: next });
      refresh(); // re-pull /status so all panels agree on the new mode
    } catch {
      setMode(prev); // revert on failure
      setSaveMsg('Failed to switch connection mode. Please try again.');
    } finally {
      setSaving(false);
    }
  }

  async function handleSave() {
    setSaving(true);
    setSaveMsg(null);
    try {
      // connection_mode is persisted immediately on toggle (handleModeChange),
      // so the batched save only carries the sync preferences.
      const payload: Parameters<typeof saveSettings>[0] = {
        backfill_range: backfillRange,
        sync_hour:      syncHour,
      };

      await saveSettings(payload);
      setSaveMsg('Settings saved.');
      refresh();
    } catch {
      setSaveMsg('Failed to save settings. Please try again.');
    } finally {
      setSaving(false);
      setTimeout(() => setSaveMsg(null), 4000);
    }
  }

  return (
    <div className="mcp-wrap">
      {/* Header */}
      <header className="mcp-header">
        <div className="mcp-header__logo">
          <span className="mcp-header__wordmark">Clariq<span className="mcp-header__dot">.</span></span>
        </div>
        <span className="mcp-header__version">v{status?.plugin_version ?? '—'}</span>
      </header>

      {/* Card */}
      <div className="mcp-card">

        {/* Section 1 */}
        <ConnectionModeToggle
          mode={mode}
          onChange={handleModeChange}
          disabled={saving}
          locked={isConnected}
        />

        {/* Local bridge self-hosted setup — no account needed */}
        {mode === 'local_bridge' && !isConnected && (
          <>
            <div className="mcp-divider" />
            <BridgeSetupPanel />
          </>
        )}

        {/* Cloud connect panel — only relevant in Cloud Sync mode */}
        {mode === 'cloud_sync' && (
          <>
            <div className="mcp-divider" />
            <ConnectionPanel onDisconnect={handleDisconnected} currentMode={mode} isConnected={isConnected} />
          </>
        )}

        <div className="mcp-divider" />

        {/* Section 3 */}
        <SyncControls
          mode={mode}
          isConnected={isConnected}
          backfillRange={backfillRange}
          onRangeChange={r => setBackfillRange(clampRange(r))}
          backfillStatus={isConnected ? backfillStatus : 'idle'}
          backfillCompletedAt={backfillCompletedAt}
          lastSyncAt={lastSyncAt}
          syncHour={syncHour}
          onHourChange={setSyncHour}
          disabled={saving}
          plan={plan}
          retentionDays={retentionDays}
          maxBackfillMonths={maxBackfillMonths}
        />

        <div className="mcp-divider" />

        {/* Section 4 */}
        <HealthStatus status={status} loading={loading} error={error} />

        {/* Save bar — only when there are unsaved changes or a save result */}
        {(backfillRange !== Number(initialRange) ||
          syncHour !== Number(initialSyncHour ?? 2) ||
          saveMsg) && (
          <div className="mcp-save-bar">
          {saveMsg && (
            <span className={`mcp-inline-msg ${saveMsg.includes('Failed') ? 'mcp-inline-msg--error' : 'mcp-inline-msg--success'}`}>
              {saveMsg}
            </span>
          )}
          <button
            type="button"
            className="mcp-btn mcp-btn--primary"
            disabled={saving}
            onClick={handleSave}
          >
            {saving ? <span className="mcp-spinner" aria-hidden="true" /> : null}
            {saving ? 'Saving…' : 'Save Changes'}
          </button>
          </div>
        )}
      </div>
    </div>
  );
}
