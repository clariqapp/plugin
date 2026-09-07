import React, { useState, useEffect } from '@wordpress/element';
import { initiateConnect, disconnect, ConnectionMode } from '../lib/api';

interface Props {
  onDisconnect:  () => void;
  currentMode:   ConnectionMode;
  isConnected:   boolean;
}

export function ConnectionPanel({ onDisconnect, currentMode, isConnected }: Props) {
  const {
    tenantId,
    siteUrl,
    clariqUrl,
    connectError: initialError,
    justConnected,
  } = window.wcMcpData;

  const [connecting,    setConnecting]    = useState(false);
  const [disconnecting, setDisconnecting] = useState(false);
  const [error,         setError]         = useState<string>(initialError ?? '');
  const [showSuccess,   setShowSuccess]   = useState(justConnected);

  const displayUrl = (() => {
    try {
      const url = new URL(clariqUrl || 'https://app.clariq.com');
      return url.host;
    } catch {
      return (clariqUrl || 'app.clariq.com').replace(/^https?:\/\//, '');
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
              className="mcp-btn mcp-btn--secondary"
              style={{ color: 'var(--mcp-red)', borderColor: 'var(--mcp-red)' }}
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
          <p style={{ fontSize: 13.5, color: 'var(--mcp-text-2)', marginBottom: 16 }}>
            Connect your WooCommerce store with <strong style={{ color: 'var(--mcp-text)' }}>Clariq</strong> to unlock modern, deep-insights analytics. You will be redirected to <span style={{ fontFamily: 'monospace', background: 'var(--mcp-primary-muted)', padding: '2px 6px', borderRadius: '4px' }}>{displayUrl}</span> to securely authorize the integration.
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
        </div>
      )}
    </div>
  );
}
