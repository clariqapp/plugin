import React, { useState } from '@wordpress/element';

interface Props {
  tenantId: string;
}

export function CredentialsPanel({ tenantId }: Props) {
  const [tokenVisible, setTokenVisible] = useState(false);

  // The security token value is never passed to the React app — only the hash
  // is stored server-side. The UI surfaces the tenant ID only.
  return (
    <div className="mcp-field-group">
      <label className="mcp-label">Pipeline Credentials</label>

      <div className="mcp-cred-row">
        <span className="mcp-cred-row__label">Your Tenant ID</span>
        <div className="mcp-cred-row__value-wrap">
          <code className="mcp-cred-value">
            {tenantId || <span className="mcp-cred-placeholder">Not registered yet</span>}
          </code>
          {tenantId && (
            <button
              type="button"
              className="mcp-icon-btn"
              title="Copy Tenant ID"
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
        <span className="mcp-cred-row__label">Security Token</span>
        <div className="mcp-cred-row__value-wrap">
          <code className="mcp-cred-value">
            {tokenVisible
              ? '(Token shown once at registration — stored securely in wp_options)'
              : '••••••••••••••••••••••••••••••••'}
          </code>
          <button
            type="button"
            className="mcp-icon-btn"
            title={tokenVisible ? 'Hide token' : 'Show info'}
            onClick={() => setTokenVisible(v => !v)}
          >
            {tokenVisible ? (
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
                <line x1="1" y1="1" x2="23" y2="23" />
              </svg>
            ) : (
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
              </svg>
            )}
          </button>
        </div>
      </div>
    </div>
  );
}
