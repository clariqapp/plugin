import React, { useState } from '@wordpress/element';
import { rotateBridgeSecret } from '../lib/api';

type TestState = 'idle' | 'testing' | 'ok' | 'fail';

/** HMAC-SHA256 hex digest via WebCrypto — matches the bridge's X-MCP-Bridge-Token scheme. */
async function hmacSha256Hex(secret: string, body: string): Promise<string> {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw', enc.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'],
  );
  const sig = await crypto.subtle.sign('HMAC', key, enc.encode(body));
  return Array.from(new Uint8Array(sig)).map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function copyText(text: string, setCopied: (id: string) => void, id: string) {
  try {
    await navigator.clipboard.writeText(text);
    setCopied(id);
    setTimeout(() => setCopied(''), 2000);
  } catch {
    /* clipboard unavailable (non-secure context) — user can select manually */
  }
}

/**
 * Setup panel for the self-hosted Local Bridge mode: shows the bridge endpoint,
 * the shared secret, a ready-to-paste MCP client config, and a connectivity test.
 */
export function BridgeSetupPanel() {
  const { bridgeUrl, bridgeSecret: initialSecret, siteUrl } = window.wcMcpData;

  const [secret,      setSecret]      = useState(initialSecret);
  const [revealed,    setRevealed]    = useState(false);
  const [rotating,    setRotating]    = useState(false);
  const [copiedId,    setCopiedId]    = useState('');
  const [testState,   setTestState]   = useState<TestState>('idle');
  const [testMessage, setTestMessage] = useState('');

  const configSnippet = JSON.stringify(
    {
      mcpServers: {
        clariq: {
          command: 'clariq-mcp',
          env: {
            CLARIQ_MODE: 'local',
            CLARIQ_WP_URL: siteUrl,
            CLARIQ_BRIDGE_SECRET: secret,
          },
        },
      },
    },
    null,
    2,
  );

  async function handleRotate() {
    if (!window.confirm(
      'Generate a new bridge secret? Any MCP clients using the current secret will stop working until you update their config.',
    )) {
      return;
    }
    setRotating(true);
    setTestState('idle');
    try {
      const res = await rotateBridgeSecret();
      setSecret(res.bridge_secret);
      setRevealed(true);
    } catch {
      setTestState('fail');
      setTestMessage('Failed to rotate the secret. Please try again.');
    } finally {
      setRotating(false);
    }
  }

  async function handleTest() {
    setTestState('testing');
    setTestMessage('');
    try {
      const token = await hmacSha256Hex(secret, '');
      const res = await fetch(`${bridgeUrl}ping`, {
        headers: { 'X-MCP-Bridge-Token': token },
      });
      if (res.ok) {
        setTestState('ok');
        setTestMessage('Connected — the bridge is reachable and the secret is valid.');
      } else if (res.status === 401) {
        setTestState('fail');
        setTestMessage('Bridge rejected the secret (401). Try rotating it and updating your config.');
      } else if (res.status === 403) {
        setTestState('fail');
        setTestMessage('Bridge is disabled (403). Make sure Local Bridge is the active mode.');
      } else if (res.status === 404) {
        setTestState('fail');
        setTestMessage('Bridge endpoint not found (404). Check that permalinks are enabled.');
      } else {
        setTestState('fail');
        setTestMessage(`Unexpected response: HTTP ${res.status}.`);
      }
    } catch {
      setTestState('fail');
      setTestMessage('Could not reach your site. Check your URL, HTTPS, and any firewall/WAF rules.');
    }
  }

  const masked = '•'.repeat(12) + secret.slice(-4);

  return (
    <div className="mcp-field-group">
      <label className="mcp-label">Local Bridge Setup</label>
      <p className="mcp-sublabel">
        Self-hosted and private — analytics run on this server and no account is needed.
        Install the MCP server (<code>uvx --from clariq-mcp-server clariq-mcp</code>), then
        add the config below to your MCP client (e.g. Claude Desktop).
      </p>

      {/* Endpoint */}
      <div className="mcp-cred-row">
        <span className="mcp-cred-row__label">Bridge endpoint</span>
        <span className="mcp-cred-row__value-wrap">
          <code className="mcp-cred-value">{bridgeUrl}</code>
          <button
            type="button"
            className="mcp-copy-btn"
            onClick={() => copyText(bridgeUrl, setCopiedId, 'endpoint')}
          >
            {copiedId === 'endpoint' ? 'Copied!' : 'Copy'}
          </button>
        </span>
      </div>

      {/* Secret */}
      <div className="mcp-cred-row">
        <span className="mcp-cred-row__label">Bridge secret</span>
        <span className="mcp-cred-row__value-wrap">
          <code className="mcp-cred-value">{revealed ? secret : masked}</code>
          <button
            type="button"
            className="mcp-copy-btn"
            onClick={() => setRevealed(!revealed)}
          >
            {revealed ? 'Hide' : 'Reveal'}
          </button>
          <button
            type="button"
            className="mcp-copy-btn"
            onClick={() => copyText(secret, setCopiedId, 'secret')}
          >
            {copiedId === 'secret' ? 'Copied!' : 'Copy'}
          </button>
          <button
            type="button"
            className="mcp-copy-btn mcp-copy-btn--danger"
            disabled={rotating}
            onClick={handleRotate}
          >
            {rotating ? 'Rotating…' : 'Rotate'}
          </button>
        </span>
      </div>

      {/* MCP client config */}
      <div className="mcp-field-group" style={{ marginTop: '4px' }}>
        <div className="mcp-code-block__header">
          <span>claude_desktop_config.json</span>
          <button
            type="button"
            className="mcp-copy-btn"
            onClick={() => copyText(configSnippet, setCopiedId, 'config')}
          >
            {copiedId === 'config' ? 'Copied!' : 'Copy config'}
          </button>
        </div>
        <pre className="mcp-code-block">{configSnippet}</pre>
      </div>

      {/* Connectivity test */}
      <div className="mcp-bridge-test">
        <button
          type="button"
          className="mcp-btn mcp-btn--primary"
          disabled={testState === 'testing'}
          onClick={handleTest}
        >
          {testState === 'testing' ? 'Testing…' : 'Test Connection'}
        </button>
        {testMessage && (
          <span className={`mcp-inline-msg ${testState === 'ok' ? 'mcp-inline-msg--success' : 'mcp-inline-msg--error'}`}>
            {testMessage}
          </span>
        )}
      </div>
    </div>
  );
}
