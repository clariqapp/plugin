import React, { useState, useEffect } from '@wordpress/element';
import { initiateConnect, disconnect, checkClariqCloud, saveClariqUrl, ConnectionMode } from '../lib/api';

interface Props {
  onDisconnect:  () => void;
  currentMode:   ConnectionMode;
  isConnected:   boolean;
}

/** Human-readable messages for the connect_error codes the callback redirects back with. */
const CONNECT_ERROR_MESSAGES: Record<string, string> = {
  invalid_state:      'Connection attempt expired or was replayed. Please click Connect again.',
  missing_params:     'The connection response was incomplete. Please try again.',
  signing_key_missing:'This site is missing its signing key (WC_MCP_CLARIQ_SIGNING_KEY). Contact your administrator.',
  invalid_signature:  'The connection response failed signature verification. Please try again — if it persists, verify the signing key matches your Clariq account.',
};

export function ConnectionPanel({ onDisconnect, currentMode, isConnected }: Props) {
  const {
    tenantId,
    siteUrl,
    clariqUrl,
    connectError: initialErrorCode,
    justConnected,
  } = window.wcMcpData;

  const [connecting,    setConnecting]    = useState(false);
  const [disconnecting, setDisconnecting] = useState(false);
  const [error,         setError]         = useState<string>(
    initialErrorCode ? (CONNECT_ERROR_MESSAGES[initialErrorCode] ?? `Connection failed (${initialErrorCode}).`) : ''
  );
  const [showSuccess,   setShowSuccess]   = useState(justConnected);

  // Advanced: cloud URL override + reachability check
  const [showAdvanced,  setShowAdvanced]  = useState(false);
  const [urlDraft,      setUrlDraft]      = useState(clariqUrl);
  const [savingUrl,     setSavingUrl]     = useState(false);
  const [checking,      setChecking]      = useState(false);
  const [checkResult,   setCheckResult]   = useState<string>('');

  const displayUrl = (() => {
    try {
      const url = new URL(clariqUrl || 'https://app.clariqapp.com');
      return url.host;
    } catch {
      return (clariqUrl || 'app.clariqapp.com').replace(/^https?:\/\//, '');
    }
  })();

  // Strip ?connected=1 from the URL so a page reload doesn't re-show the banner.
  useEffect(() => {
    if (justConnected) {
      const url = new URL(window.location.href);
      url.searchParams.delete('connected');
      window.history.replaceState(null, '', url.toString());
    }
  }, []);

  // When the parent detects a dashboard-side disconnect, clear the success banner.
  useEffect(() => {
    if (!isConnected) {
      setShowSuccess(false);
    }
  }, [isConnected]);

  async function handleConnect() {
    setConnecting(true);
    setError('');
    try {
      // Pass the currently selected mode so PHP persists it before the redirect,
      // even if the user never clicked "Save Changes".
      const { redirect_url } = await initiateConnect(currentMode);
      window.location.href = redirect_url;
    } catch {
      setError('Could not initiate connection. Please try again.');
      setConnecting(false);
    }
  }

  async function handleSaveUrl() {
    setSavingUrl(true);
    setCheckResult('');
    try {
      await saveClariqUrl(urlDraft.trim());
      setCheckResult('Saved. Reload to apply — the panel shows the resolved URL.');
      // Update the displayed URL without a full reload
      window.wcMcpData.clariqUrl = urlDraft.trim();
    } catch (e) {
      setCheckResult(e instanceof Error ? e.message : 'Failed to save URL.');
    } finally {
      setSavingUrl(false);
    }
  }

  async function handleCheckCloud() {
    setChecking(true);
    setCheckResult('');
    try {
      const res = await checkClariqCloud();
      if (res.reachable) {
        setCheckResult(`✓ Reachable (HTTP ${res.http_code}). Redirect target: ${res.target}`);
      } else {
        setCheckResult(`✗ Unreachable: ${res.error ?? 'unknown error'} (${res.target})`);
      }
    } catch (e) {
      setCheckResult(e instanceof Error ? e.message : 'Check failed.');
    } finally {
      setChecking(false);
    }
  }

  async function handleDisconnect() {
    if (!window.confirm(
      'Disconnect this store from Clariq? Your Clariq account and data are preserved — you can reconnect at any time.'
    )) {
      return;
    }
    setDisconnecting(true);
    setError('');
    try {
      await disconnect();
      setShowSuccess(false);
      onDisconnect();
    } catch {
      setError('Failed to disconnect. Please try again.');
    } finally {
      setDisconnecting(false);
    }
  }

  return (
    <div className="mcp-field-group">
      <label className="mcp-label">Clariq Connection</label>

      {/* Error banner */}
      {error && (
        <p className="mcp-inline-msg mcp-inline-msg--error" style={{ marginBottom: 12 }}>
          {error}
        </p>
      )}

      {/* Just-connected success banner */}
      {showSuccess && (
        <p className="mcp-inline-msg mcp-inline-msg--success" style={{ marginBottom: 12 }}>
          Store connected successfully! Your MCP server can now query analytics via Clariq.
        </p>
      )}

      {isConnected ? (
        /* ── Connected state ── */
        <>
          <div className="mcp-cred-row">
            <span className="mcp-cred-row__label">Status</span>
            <div className="mcp-cred-row__value-wrap">
              <span className="mcp-status-dot mcp-status-dot--green" />
              <span style={{ fontSize: 13, color: 'var(--mcp-green)', fontWeight: 500 }}>Connected</span>
            </div>
          </div>

          <div className="mcp-cred-row">
            <span className="mcp-cred-row__label">Store ID</span>
            <div className="mcp-cred-row__value-wrap">
              <code className="mcp-cred-value">{tenantId || '—'}</code>
              {tenantId && (
                <button
                  type="button"
                  className="mcp-icon-btn"
                  title="Copy Store ID"
                  onClick={() => navigator.clipboard.writeText(tenantId)}
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <rect x="9" y="9" width="13" height="13" rx="2" />
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                  </svg>
                </button>
              )}
            </div>
          </div>

          <div className="mcp-cred-row">
            <span className="mcp-cred-row__label">Connected Store</span>
            <div className="mcp-cred-row__value-wrap">
              <code className="mcp-cred-value" style={{ fontSize: 12 }}>{siteUrl || '—'}</code>
            </div>
          </div>

          <div style={{ marginTop: 16 }}>
            <button
              type="button"
              className="mcp-btn mcp-btn--danger"
              disabled={disconnecting}
              onClick={handleDisconnect}
            >
              {disconnecting ? <span className="mcp-spinner" aria-hidden="true" /> : null}
              {disconnecting ? 'Disconnecting…' : 'Disconnect Store'}
            </button>
          </div>
        </>
      ) : (
        /* ── Disconnected state ── */
        <div className="mcp-connect-prompt">
          <p style={{ fontSize: 13.5, color: 'var(--mcp-text-2)', marginBottom: 16, lineHeight: 1.6 }}>
            Connect your WooCommerce store to <strong style={{ color: 'var(--mcp-text)', fontWeight: 600 }}>Clariq Cloud</strong> for a managed analytics dashboard and team access. You'll be redirected to <span className="mcp-connect-prompt__domain">{displayUrl}</span> to securely authorize the integration.
          </p>

          <button
            type="button"
            className="mcp-btn mcp-btn--primary"
            disabled={connecting}
            onClick={handleConnect}
          >
            {connecting ? <span className="mcp-spinner" aria-hidden="true" /> : null}
            {connecting ? 'Redirecting…' : 'Securely Connect with Clariq'}
          </button>

          {/* Advanced: override the Clariq Cloud URL (staging / local testing) */}
          <div style={{ marginTop: 14 }}>
            <button
              type="button"
              onClick={() => setShowAdvanced(!showAdvanced)}
              style={{
                background: 'none', border: 'none', padding: 0, cursor: 'pointer',
                font: 'inherit', fontSize: 12, color: 'var(--mcp-text-3)',
              }}
            >
              {showAdvanced ? '▾' : '▸'} Advanced — Cloud URL override & diagnostics
            </button>
          </div>

          {showAdvanced && (
            <div style={{
              marginTop: 10, padding: 12, background: 'var(--mcp-bg)',
              border: '1px solid var(--mcp-border)', borderRadius: 10,
            }}>
              <label className="mcp-label" style={{ fontSize: 11 }}>Clariq Cloud URL</label>
              <div style={{ display: 'flex', gap: 8, marginTop: 4 }}>
                <input
                  type="text"
                  value={urlDraft}
                  onChange={(e) => setUrlDraft(e.target.value)}
                  placeholder="https://app.clariqapp.com"
                  style={{
                    flex: 1, fontSize: 13, padding: '7px 10px', borderRadius: 8,
                    border: '1px solid var(--mcp-border)', background: 'var(--mcp-surface)',
                    color: 'var(--mcp-text)', fontFamily: 'var(--mcp-font-mono)',
                  }}
                />
                <button type="button" className="mcp-btn mcp-btn--secondary" disabled={savingUrl} onClick={handleSaveUrl}>
                  {savingUrl ? 'Saving…' : 'Save'}
                </button>
                <button type="button" className="mcp-btn mcp-btn--secondary" disabled={checking} onClick={handleCheckCloud}>
                  {checking ? 'Checking…' : 'Test'}
                </button>
              </div>
              {checkResult && (
                <p style={{ marginTop: 8, fontSize: 12, color: 'var(--mcp-text-2)', fontFamily: 'var(--mcp-font-mono)', wordBreak: 'break-all' }}>
                  {checkResult}
                </p>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
