import React, { useState, useEffect } from '@wordpress/element';
import { ConnectionModeToggle } from './components/ConnectionModeToggle';
import { ConnectionPanel }      from './components/ConnectionPanel';
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
  } = window.wcMcpData;

  const [mode,                setMode]                = useState<ConnectionMode>(initialMode);
  const [backfillRange,       setBackfillRange]       = useState(Number(initialRange));
  const [syncHour,            setSyncHour]            = useState(Number(initialSyncHour ?? 2));
  const [isConnected,         setIsConnected]         = useState(initialConnected);
  const [backfillStatus,      setBackfillStatus]      = useState(initialBackfillStatus);
  const [backfillCompletedAt, setBackfillCompletedAt] = useState(initialBackfillCompletedAt);
  const [lastSyncAt,          setLastSyncAt]          = useState(initialLastSyncAt);
  const [saving,              setSaving]              = useState(false);
  const [saveMsg,             setSaveMsg]             = useState<string | null>(null);

  const { status, loading, error, refresh } = useStatus();

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

  async function handleSave() {
    setSaving(true);
    setSaveMsg(null);
    try {
      const payload: Parameters<typeof saveSettings>[0] = {
        backfill_range: backfillRange,
        sync_hour:      syncHour,
      };

      // Do not send connection_mode when connected — backend rejects it (403).
      if (!isConnected) {
        payload.connection_mode = mode;
      }

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
          <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true">
            <rect width="28" height="28" rx="8" fill="#7C3AED" />
            <path d="M9 19V15M14 19V9M19 19V12" stroke="#fff" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
          <span className="mcp-header__title">Clariq Analytics</span>
        </div>
        <span className="mcp-header__version">v{status?.plugin_version ?? '—'}</span>
      </header>

      {/* Card */}
      <div className="mcp-card">

        {/* Section 1 */}
        <ConnectionModeToggle
          mode={mode}
          onChange={setMode}
          disabled={saving}
          locked={isConnected}
        />

        <div className="mcp-divider" />

        {/* Section 2 */}
        <ConnectionPanel onDisconnect={handleDisconnected} currentMode={mode} isConnected={isConnected} />

        <div className="mcp-divider" />

        {/* Section 3 */}
        <SyncControls
          mode={mode}
          isConnected={isConnected}
          backfillRange={backfillRange}
          onRangeChange={setBackfillRange}
          backfillStatus={backfillStatus}
          backfillCompletedAt={backfillCompletedAt}
          lastSyncAt={lastSyncAt}
          syncHour={syncHour}
          onHourChange={setSyncHour}
          disabled={saving}
        />

        <div className="mcp-divider" />

        {/* Section 4 */}
        <HealthStatus status={status} loading={loading} error={error} />

        {/* Save bar */}
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
      </div>
    </div>
  );
}
