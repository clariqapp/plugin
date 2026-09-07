import React from '@wordpress/element';

interface Props {
  mode:     'cloud_sync' | 'local_bridge';
  onChange: (mode: 'cloud_sync' | 'local_bridge') => void;
  disabled?: boolean;
  locked?:   boolean;
}

export function ConnectionModeToggle({ mode, onChange, disabled, locked }: Props) {
  return (
    <div className="mcp-field-group">
      <label className="mcp-label">Connection Mode</label>

      {locked && (
        <p className="mcp-inline-msg mcp-inline-msg--warning" style={{ marginBottom: '12px' }}>
          Connection mode is locked while your store is connected. Disconnect first to change it.
        </p>
      )}

      <div className="mcp-mode-cards">
        {/* Cloud Sync */}
        <button
          type="button"
          disabled={disabled || locked}
          onClick={() => onChange('cloud_sync')}
          className={`mcp-mode-card ${mode === 'cloud_sync' ? 'mcp-mode-card--active' : ''} ${locked ? 'mcp-mode-card--locked' : ''}`}
        >
          <div className="mcp-mode-card__header">
            <div className={`mcp-mode-card__radio ${mode === 'cloud_sync' ? 'mcp-mode-card__radio--active' : ''}`}>
              {mode === 'cloud_sync' && <span className="mcp-mode-card__radio-inner" />}
            </div>
            <span className="mcp-mode-card__badge">Recommended</span>
          </div>
          <strong className="mcp-mode-card__title">Cloud Sync — High-Performance Cloud Cluster</strong>
          <p className="mcp-mode-card__desc">
            Real-time, secure data streaming to Clariq's high-speed reporting cluster.
            Optimized for sub-millisecond response rates.
          </p>
        </button>

        {/* Local Bridge */}
        <button
          type="button"
          disabled={disabled || locked}
          onClick={() => onChange('local_bridge')}
          className={`mcp-mode-card ${mode === 'local_bridge' ? 'mcp-mode-card--active' : ''} ${locked ? 'mcp-mode-card--locked' : ''}`}
        >
          <div className="mcp-mode-card__header">
            <div className={`mcp-mode-card__radio ${mode === 'local_bridge' ? 'mcp-mode-card__radio--active' : ''}`}>
              {mode === 'local_bridge' && <span className="mcp-mode-card__radio-inner" />}
            </div>
          </div>
          <strong className="mcp-mode-card__title">Local Bridge — On-Premises Privacy</strong>
          <p className="mcp-mode-card__desc">
            Zero cloud storage. Queries execute entirely inside your local WordPress container
            and are securely fetched on demand.
          </p>
        </button>
      </div>
    </div>
  );
}
