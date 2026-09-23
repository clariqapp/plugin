/**
 * Global runtime data injected by PHP via wp_localize_script().
 */
export interface WcMcpData {
	ajaxUrl: string;
	restUrl: string;
	nonce: string;
	forceSyncNonce: string;
	tenantId: string;
	bridgeSecret: string;
	bridgeUrl: string;
	mode: 'cloud_sync' | 'local_bridge';
	backfillRange: string;
	plan: string; // owning org's plan (free|starter|business|enterprise)
	retentionDays: number | null; // trailing-day data window; null = unlimited
	maxBackfillMonths: number | null; // backfill range cap (months); null = unlimited
	syncHour: number;
	isConnected: boolean;
	siteUrl: string;
	clariqUrl: string;
	connectError: string;
	justConnected: boolean;
	backfillStatus: 'idle' | 'running' | 'complete';
	backfillCompletedAt: string; // e.g. "Jun 2, 2026 at 9:42 AM" or ""
	lastSyncAt: string; // most recent sync of any kind, or ""
}

declare global {
	interface Window {
		wcMcpData: WcMcpData;
	}
}
