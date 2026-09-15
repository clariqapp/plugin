import React, { useState, useEffect } from '@wordpress/element';
import { initiateConnect, disconnect, ConnectionMode } from '../lib/api';
import { CheckIcon, AlertIcon, CopyIcon, LinkIcon, ArrowRightIcon } from './icons';

interface Props {
  onDisconnect:  () => void;
  currentMode:   ConnectionMode;
  isConnected:   boolean;
}

const CONNECT_ERROR_MESSAGES: Record<string, string> = {
  invalid_state:        'The connection attempt expired. Please click Connect again.',
  missing_params:       'The connection response was incomplete. Please try again.',
  exchange_unreachable: 'We couldn’t reach Clariq to finish connecting. Check this site’s internet access and try again.',
  exchange_failed:      'Finishing the connection failed. Please click Connect and try again.',
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
  const [copied,        setCopied]        = useState(false);
  const [error,         setError]         = useState<string>(
    initialErrorCode ? (CONNECT_ERROR_MESSAGES[initialErrorCode] ?? `Connection failed (${initialErrorCode}).`) : ''
  );
  const [showSuccess,   setShowSuccess]   = useState(justConnected);

  const displayUrl = (() => {
    try {
      return new URL(clariqUrl || 'https://app.clariqapp.com').host;
    } catch {
      return (clariqUrl || 'app.clariqapp.com').replace(/^https?:\/\//, '');
    }
  })();

  useEffect(() => {
    if (justConnected) {
      const url = new URL(window.location.href);
      url.searchParams.delete('connected');
      window.history.replaceState(null, '', url.toString());
    }
  }, []);

  useEffect(() => {
    if (!isConnected) setShowSuccess(false);
  }, [isConnected]);

  async function handleConnect() {
    setConnecting(true);
    setError('');
    try {
      const { redirect_url } = await initiateConnect(currentMode);
      window.location.href = redirect_url;
    } catch {
      setError('We couldn’t start the connection. Please try again.');
      setConnecting(false);
    }
  }

  async function handleDisconnect() {
    if (!window.confirm(
      'Disconnect this store from Clariq? Your account and past data are kept — you can reconnect anytime.'
    )) return;
    setDisconnecting(true);
    setError('');
    try {
      await disconnect();
      setShowSuccess(false);
      onDisconnect();
    } catch {
      setError('We couldn’t disconnect. Please try again.');
    } finally {
      setDisconnecting(false);
    }
  }

  function copyId() {
    if (!tenantId) return;
    navigator.clipboard.writeText(tenantId).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }).catch(() => {});
  }

  return (
    <>
      <div className="clq-section__head">
        <div className="clq-section__title">
          {isConnected ? 'Your Clariq workspace' : 'Connect to Clariq'}
        </div>
        <p className="clq-section__desc">
          {isConnected
            ? 'This store is linked to your Clariq account. Manage everything from your dashboard.'
            : 'A quick, secure sign-in links this store to your Clariq account.'}
        </p>
      </div>

      {error && (
        <div className="clq-notice clq-notice--error" style={{ marginBottom: 16 }}>
          <AlertIcon />
          <div>{error}</div>
        </div>
      )}

      {showSuccess && (
        <div className="clq-notice clq-notice--success" style={{ marginBottom: 16 }}>
          <CheckIcon />
          <div>Your store is connected. Your AI assistant can now answer questions about your data.</div>
        </div>
      )}

      {isConnected ? (
        <>
          <div className="clq-rows">
            <div className="clq-row">
              <span className="clq-row__label">Status</span>
              <span className="clq-row__value">
                <span className="clq-pill clq-pill--ok"><span className="clq-dot clq-dot--green" />Connected</span>
              </span>
            </div>
            <div className="clq-row">
              <span className="clq-row__label">Connected store</span>
              <span className="clq-row__value">
                <span style={{ color: 'var(--clq-ink)', fontWeight: 500 }}>{siteUrl || '—'}</span>
              </span>
            </div>
            <div className="clq-row">
              <span className="clq-row__label">Store ID</span>
              <span className="clq-row__value">
                <code className="clq-mono">{tenantId || '—'}</code>
                {tenantId && (
                  <button type="button" className="clq-icon-btn" title={copied ? 'Copied' : 'Copy Store ID'} onClick={copyId}>
                    {copied ? <CheckIcon /> : <CopyIcon />}
                  </button>
                )}
              </span>
            </div>
          </div>

          <div style={{ marginTop: 16 }}>
            <button
              type="button"
              className="clq-btn clq-btn--danger"
              disabled={disconnecting}
              onClick={handleDisconnect}
            >
              {disconnecting ? <span className="clq-spinner" aria-hidden="true" /> : null}
              {disconnecting ? 'Disconnecting…' : 'Disconnect store'}
            </button>
          </div>
        </>
      ) : (
        <div className="clq-card clq-card__pad">
          <div style={{ display: 'flex', alignItems: 'flex-start', gap: 13, marginBottom: 18 }}>
            <span className="clq-choice__glyph"><LinkIcon /></span>
            <p style={{ fontSize: 13.5, color: 'var(--clq-text)', lineHeight: 1.6 }}>
              You’ll be taken to <strong>{displayUrl}</strong> to sign in and approve access.
              It only takes a minute, and no passwords are ever shared with this plugin.
            </p>
          </div>
          <button
            type="button"
            className="clq-btn clq-btn--primary"
            disabled={connecting}
            onClick={handleConnect}
          >
            {connecting ? <span className="clq-spinner" aria-hidden="true" /> : null}
            {connecting ? 'Redirecting…' : 'Connect with Clariq'}
            {!connecting && <ArrowRightIcon />}
          </button>
        </div>
      )}
    </>
  );
}
