import React, { useState, useEffect } from '@wordpress/element';
import type { ReactNode } from 'react';
import { ConnectionModeToggle } from './components/ConnectionModeToggle';
import { ConnectionPanel } from './components/ConnectionPanel';
import { BridgeSetupPanel } from './components/BridgeSetupPanel';
import { SyncControls } from './components/SyncControls';
import { HealthStatus } from './components/HealthStatus';
import { StoreStats } from './components/StoreStats';
import {
	CloudIcon,
	ShieldIcon,
	PlugIcon,
	SyncIcon,
	PulseIcon,
	CheckIcon,
	ArrowRightIcon,
} from './components/icons';
import { saveSettings, restartBackfill, ConnectionMode } from './lib/api';
import { useStatus } from './hooks/useStatus';
import './style.css';

/** The Clariq brand mark — the official favicon (arc "C" on an orange tile). */
function BrandMark() {
	return (
		<svg
			xmlns="http://www.w3.org/2000/svg"
			viewBox="0 0 64 64"
			aria-hidden="true"
		>
			<rect width="64" height="64" rx="14" fill="#F38020" />
			<path
				d="M42 20.5a16 16 0 1 0 0 23"
				fill="none"
				stroke="#ffffff"
				strokeWidth="7"
				strokeLinecap="round"
			/>
			<circle cx="47" cy="32" r="3.5" fill="#ffffff" />
		</svg>
	);
}

type TabId = 'connection' | 'workspace' | 'status';

export function App() {
	const {
		mode: initialMode,
		backfillRange: initialRange,
		syncHour: initialSyncHour,
		isConnected: initialConnected,
		backfillStatus: initialBackfillStatus,
		backfillCompletedAt: initialBackfillCompletedAt,
		lastSyncAt: initialLastSyncAt,
		plan: initialPlan,
		maxBackfillMonths: initialMaxBackfillMonths,
		retentionDays: initialRetentionDays,
		siteUrl,
		clariqUrl,
	} = window.wcMcpData;

	const { status, loading, error, refresh } = useStatus();
	const maxBackfillMonths =
		status?.max_backfill_months ?? initialMaxBackfillMonths ?? null;
	const retentionDays =
		status?.retention_days ?? initialRetentionDays ?? null;
	const plan = status?.plan ?? initialPlan ?? 'free';

	const clampRange = ( r: number ) =>
		maxBackfillMonths !== null ? Math.min( r, maxBackfillMonths ) : r;

	const [ mode, setMode ] = useState< ConnectionMode >( initialMode );
	const [ backfillRange, setBackfillRange ] = useState(
		clampRange( Number( initialRange ) )
	);
	const [ syncHour, setSyncHour ] = useState(
		Number( initialSyncHour ?? 2 )
	);
	// Saved baseline the dirty-check compares against. Initialised to the *clamped*
	// range so a plan cap (e.g. free → 30 days) doesn't read as an unsaved change,
	// and advanced on every successful save so the bar clears after saving.
	const [ savedRange, setSavedRange ] = useState(
		clampRange( Number( initialRange ) )
	);
	const [ savedHour, setSavedHour ] = useState(
		Number( initialSyncHour ?? 2 )
	);
	const [ isConnected, setIsConnected ] = useState( initialConnected );
	const [ backfillStatus, setBackfillStatus ] = useState(
		initialBackfillStatus
	);
	const [ backfillCompletedAt, setBackfillCompletedAt ] = useState(
		initialBackfillCompletedAt
	);
	const [ backfillProcessed, setBackfillProcessed ] = useState( 0 );
	const [ backfillTotal, setBackfillTotal ] = useState( 0 );
	const [ backfillWindow, setBackfillWindow ] = useState( '' );
	const [ lastSyncAt, setLastSyncAt ] = useState( initialLastSyncAt );
	const [ saving, setSaving ] = useState( false );
	const [ saveMsg, setSaveMsg ] = useState< string | null >( null );
	const [ activeTab, setActiveTab ] = useState< TabId >( 'connection' );

	useEffect( () => {
		if ( maxBackfillMonths !== null ) {
			setBackfillRange( ( r ) => Math.min( r, maxBackfillMonths ) );
			setSavedRange( ( r ) => Math.min( r, maxBackfillMonths ) );
		}
	}, [ maxBackfillMonths ] );

	useEffect( () => {
		if ( isConnected && status?.is_connected === false ) {
			setIsConnected( false );
			setBackfillStatus( 'idle' );
			setBackfillCompletedAt( '' );
			setLastSyncAt( '' );
		}
	}, [ isConnected, status?.is_connected ] );

	// Keep the historical-import indicator in sync with the live status payload
	// (30s poll + manual refresh). Without this the pill would be frozen at its
	// initial server-rendered value and never reflect progress or completion.
	useEffect( () => {
		const bf = status?.backfill;
		if ( ! bf ) {
			return;
		}
		setBackfillStatus( bf.status );
		setBackfillCompletedAt( bf.completed_at );
		setBackfillProcessed( bf.processed );
		setBackfillTotal( bf.total );
		setBackfillWindow( bf.window_label );
	}, [ status?.backfill ] );

	function handleDisconnected() {
		setIsConnected( false );
		setBackfillStatus( 'idle' );
		setBackfillCompletedAt( '' );
		setLastSyncAt( '' );
	}

	async function handleModeChange( next: ConnectionMode ) {
		if ( next === mode || saving || isConnected ) {
			return;
		}
		const prev = mode;
		setMode( next );
		setSaving( true );
		setSaveMsg( null );
		try {
			await saveSettings( { connection_mode: next } );
			refresh();
		} catch {
			setMode( prev );
			setSaveMsg( 'Failed to switch connection mode. Please try again.' );
		} finally {
			setSaving( false );
		}
	}

	async function handleSave() {
		setSaving( true );
		setSaveMsg( null );
		try {
			await saveSettings( {
				backfill_range: backfillRange,
				sync_hour: syncHour,
			} );
			setSavedRange( backfillRange );
			setSavedHour( syncHour );
			setSaveMsg( 'Your changes have been saved.' );
			refresh();
		} catch {
			setSaveMsg( 'Failed to save settings. Please try again.' );
		} finally {
			setSaving( false );
			setTimeout( () => setSaveMsg( null ), 4000 );
		}
	}

	const isDirty = backfillRange !== savedRange || syncHour !== savedHour;

	// Re-run the historical import for the currently selected range. Persists the
	// range first (so a just-changed selection is honoured and the save bar clears),
	// then asks the server to restart the backfill from scratch.
	async function handleReimport() {
		await saveSettings( {
			backfill_range: backfillRange,
			sync_hour: syncHour,
		} );
		setSavedRange( backfillRange );
		setSavedHour( syncHour );
		await restartBackfill();
		refresh();
	}

	const displaySite = ( siteUrl || '' )
		.replace( /^https?:\/\//, '' )
		.replace( /\/$/, '' );

	// ── Status banner (at-a-glance) ─────────────────────────────────────────
	let banner: {
		variant: string;
		icon: ReactNode;
		title: string;
		desc: ReactNode;
		pill: ReactNode;
	};
	if ( mode === 'cloud_sync' && isConnected ) {
		const dashboardUrl = ( clariqUrl || '' ).replace( /\/$/, '' );
		banner = {
			variant: 'connected',
			icon: <CheckIcon />,
			title: 'Your store is connected',
			desc: (
				<>
					Analytics are syncing to your Clariq workspace for{ ' ' }
					<code>{ displaySite || 'your store' }</code>.
				</>
			),
			pill: (
				<div className="clq-banner__aside-stack">
					<span className="clq-pill clq-pill--ok">
						<span className="clq-dot clq-dot--green" />
						Live
					</span>
					{ dashboardUrl && (
						<a
							className="clq-banner__link"
							href={ dashboardUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							Open dashboard <ArrowRightIcon />
						</a>
					) }
				</div>
			),
		};
	} else if ( mode === 'cloud_sync' ) {
		banner = {
			variant: 'attention',
			icon: <CloudIcon />,
			title: 'Finish connecting your store',
			desc: 'Link this store to Clariq to unlock your analytics dashboard and AI assistant.',
			pill: (
				<span className="clq-pill clq-pill--idle">Not connected</span>
			),
		};
	} else {
		banner = {
			variant: 'local',
			icon: <ShieldIcon />,
			title: 'Running in Private mode',
			desc: 'Your data stays on this server. No account needed — connect an AI assistant from the Setup tab.',
			pill: (
				<span className="clq-pill clq-pill--running">
					<span className="clq-dot clq-dot--green" />
					Private
				</span>
			),
		};
	}

	const workspaceTab = {
		id: 'workspace' as const,
		label: mode === 'cloud_sync' ? 'Data & Sync' : 'AI Setup',
		icon: mode === 'cloud_sync' ? <SyncIcon /> : <PlugIcon />,
	};

	const tabs = [
		{ id: 'connection' as const, label: 'Connection', icon: <CloudIcon /> },
		workspaceTab,
		{ id: 'status' as const, label: 'Status', icon: <PulseIcon /> },
	];

	return (
		<div className="clq">
			{ /* ── Top bar ── */ }
			<div className="clq-topbar">
				<div className="clq-brand">
					<span className="clq-brand__mark">
						<BrandMark />
					</span>
					<div>
						<div className="clq-brand__name">
							Clariq<span className="clq-dot">.</span>
						</div>
						<div className="clq-brand__sub">
							Analytics for WooCommerce
						</div>
					</div>
				</div>
				<div className="clq-topbar__spacer" />
				<span className="clq-version">
					Version { status?.plugin_version ?? '—' }
				</span>
			</div>

			{ /* ── Status banner ── */ }
			<div className={ `clq-banner clq-banner--${ banner.variant }` }>
				<span className="clq-banner__icon">{ banner.icon }</span>
				<div className="clq-banner__body">
					<div className="clq-banner__title">{ banner.title }</div>
					<div className="clq-banner__desc">{ banner.desc }</div>
				</div>
				<div className="clq-banner__aside">{ banner.pill }</div>
			</div>

			{ /* ── Store KPIs (last 30 days) ── */ }
			{ status?.store_stats && (
				<StoreStats stats={ status.store_stats } />
			) }

			{ /* ── Tabs ── */ }
			<div className="clq-tabs" role="tablist">
				{ tabs.map( ( tab ) => (
					<button
						key={ tab.id }
						type="button"
						role="tab"
						aria-selected={ activeTab === tab.id }
						className={ `clq-tab ${
							activeTab === tab.id ? 'clq-tab--active' : ''
						}` }
						onClick={ () => setActiveTab( tab.id ) }
					>
						{ tab.icon }
						{ tab.label }
						{ tab.id === 'connection' &&
							mode === 'cloud_sync' &&
							isConnected && (
								<span
									className="clq-tab__dot"
									aria-hidden="true"
								/>
							) }
					</button>
				) ) }
			</div>

			{ /* ── Panels ── */ }
			{ activeTab === 'connection' && (
				<div className="clq-panel" role="tabpanel">
					<div className="clq-section">
						<ConnectionModeToggle
							mode={ mode }
							onChange={ handleModeChange }
							disabled={ saving }
							locked={ isConnected }
						/>
					</div>

					{ mode === 'cloud_sync' && (
						<div className="clq-section">
							<ConnectionPanel
								onDisconnect={ handleDisconnected }
								currentMode={ mode }
								isConnected={ isConnected }
							/>
						</div>
					) }

					{ mode === 'local_bridge' && (
						<div className="clq-section">
							<div className="clq-notice clq-notice--info">
								<PlugIcon />
								<div>
									You&apos;re all set for private mode. To
									start asking questions about your store,
									open the{ ' ' }
									<button
										type="button"
										className="clq-linklike"
										onClick={ () =>
											setActiveTab( 'workspace' )
										}
									>
										AI&nbsp;Setup
									</button>{ ' ' }
									tab and connect your AI assistant.
								</div>
							</div>
						</div>
					) }
				</div>
			) }

			{ activeTab === 'workspace' && (
				<div className="clq-panel" role="tabpanel">
					{ mode === 'cloud_sync' ? (
						<SyncControls
							mode={ mode }
							isConnected={ isConnected }
							backfillRange={ backfillRange }
							onRangeChange={ ( r ) =>
								setBackfillRange( clampRange( r ) )
							}
							backfillStatus={
								isConnected ? backfillStatus : 'idle'
							}
							backfillCompletedAt={ backfillCompletedAt }
							backfillProcessed={ backfillProcessed }
							backfillTotal={ backfillTotal }
							backfillWindow={ backfillWindow }
							onRefreshStatus={ refresh }
							refreshingStatus={ loading }
							lastSyncAt={ lastSyncAt }
							syncHour={ syncHour }
							onHourChange={ setSyncHour }
							disabled={ saving }
							plan={ plan }
							retentionDays={ retentionDays }
							maxBackfillMonths={ maxBackfillMonths }
							upgradeUrl={
								clariqUrl
									? `${ clariqUrl.replace(
											/\/$/,
											''
									  ) }/settings`
									: undefined
							}
							onReimport={ handleReimport }
						/>
					) : (
						<BridgeSetupPanel />
					) }
				</div>
			) }

			{ activeTab === 'status' && (
				<div className="clq-panel" role="tabpanel">
					<HealthStatus
						status={ status }
						loading={ loading }
						error={ error }
					/>
				</div>
			) }

			{ /* ── Save bar (only when there are unsaved sync preferences) ── */ }
			{ ( isDirty || saveMsg ) && (
				<div className="clq-savebar">
					<span className="clq-savebar__note">
						{ saveMsg ? (
							<span
								className={
									saveMsg.includes( 'Failed' )
										? 'clq-inline-err'
										: 'clq-inline-ok'
								}
							>
								{ ! saveMsg.includes( 'Failed' ) && (
									<CheckIcon className="clq-savebar__check" />
								) }
								{ saveMsg }
							</span>
						) : (
							'You have unsaved changes.'
						) }
					</span>
					<button
						type="button"
						className="clq-btn clq-btn--primary"
						disabled={ saving || ! isDirty }
						onClick={ handleSave }
					>
						{ saving ? (
							<span className="clq-spinner" aria-hidden="true" />
						) : null }
						{ saving ? 'Saving…' : 'Save changes' }
						{ ! saving && <ArrowRightIcon /> }
					</button>
				</div>
			) }
		</div>
	);
}
