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
  return apiFetch({ url: `${base}connect/initiate?connection_mode=${encodeURIComponent(mode)}` });
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

export interface ConnectCheckResponse {
  reachable: boolean;
  target:    string;
  http_code?: number;
  error?:     string;
}

export async function checkClariqCloud(): Promise<ConnectCheckResponse> {
  return apiFetch({ url: `${base}connect/check` });
}

export async function saveClariqUrl(url: string): Promise<void> {
  await apiFetch({
    url:    `${base}settings`,
    method: 'POST',
    data:   { clariq_url: url },
  });
}
