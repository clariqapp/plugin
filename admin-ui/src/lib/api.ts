import apiFetch from '@wordpress/api-fetch';

export type ConnectionMode = 'cloud_sync' | 'local_bridge';

export interface StatusResponse {
  mode:          ConnectionMode;
  is_connected:  boolean;
  warehouse_sync: {
    connected:   boolean;
    last_synced: string;
  };
  bridge_latency:  number | null;
  pending_jobs:    number;
  plan?:           string;
  retention_days?: number | null;
  max_backfill_months?: number | null;
  backfill?: {
    status:       'idle' | 'running' | 'complete';
    processed:    number;
    total:        number;
    completed_at: string;
    window_label: string;
  };
  plugin_version:  string;
}

export interface SettingsPayload {
  connection_mode?: ConnectionMode;
  backfill_range?:  number;
  sync_hour?:       number;
}

export interface InitiateConnectResponse {
  redirect_url: string;
}

const base = window.wcMcpData.restUrl;

export async function fetchStatus(): Promise<StatusResponse> {
  return apiFetch({ url: `${base}status` });
}

export async function saveSettings(payload: SettingsPayload): Promise<void> {
  await apiFetch({
    url:    `${base}settings`,
    method: 'POST',
    data:   payload,
  });
}

export async function initiateConnect(mode: ConnectionMode): Promise<InitiateConnectResponse> {
  // `base` (rest_url) may already carry a query string on plain-permalink sites,
  // e.g. `…/index.php?rest_route=/wc-mcp/v1/`. Appending another `?…` would create
  // a second `?`, which @wordpress/api-fetch's locale middleware then folds into
  // the `rest_route` value (→ 404). Use `&` when the root already has a `?`.
  const sep = base.includes('?') ? '&' : '?';
  return apiFetch({ url: `${base}connect/initiate${sep}connection_mode=${encodeURIComponent(mode)}` });
}

export async function disconnect(): Promise<void> {
  await apiFetch({
    url:    `${base}connect/disconnect`,
    method: 'DELETE',
  });
}

export async function syncNow(): Promise<void> {
  await apiFetch({
    url:    `${base}sync-now`,
    method: 'POST',
  });
}

export interface RotateSecretResponse {
  success:       boolean;
  bridge_secret: string;
}

export async function rotateBridgeSecret(): Promise<RotateSecretResponse> {
  return apiFetch({
    url:    `${base}bridge/rotate-secret`,
    method: 'POST',
  });
}
