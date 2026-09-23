import React, { useState } from '@wordpress/element';
import { rotateBridgeSecret } from '../lib/api';
import { CheckIcon, AlertIcon, EyeIcon, EyeOffIcon, CopyIcon } from './icons';

type TestState = 'idle' | 'testing' | 'ok' | 'fail';

/**
 * HMAC-SHA256 hex digest via WebCrypto — matches the bridge's X-MCP-Bridge-Token scheme.
 * @param secret
 * @param body
 */
async function hmacSha256Hex(
	secret: string,
	body: string
): Promise< string > {
	const enc = new TextEncoder();
	const key = await crypto.subtle.importKey(
		'raw',
		enc.encode( secret ),
		{ name: 'HMAC', hash: 'SHA-256' },
		false,
		[ 'sign' ]
	);
	const sig = await crypto.subtle.sign( 'HMAC', key, enc.encode( body ) );
	return Array.from( new Uint8Array( sig ) )
		.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

async function copyText(
	text: string,
	setCopied: ( id: string ) => void,
	id: string
) {
	try {
		await navigator.clipboard.writeText( text );
		setCopied( id );
		setTimeout( () => setCopied( '' ), 2000 );
	} catch {
		/* clipboard unavailable (non-secure context) — user can select manually */
	}
}

/**
 * Setup for Private (self-hosted) mode: presented as three plain-language steps —
 * install the connector, paste the config, then test. Monospace appears only in
 * the actual config block and for the opaque secret/endpoint values.
 */
export function BridgeSetupPanel() {
	const {
		bridgeUrl,
		bridgeSecret: initialSecret,
		siteUrl,
	} = window.wcMcpData;

	const [ secret, setSecret ] = useState( initialSecret );
	const [ revealed, setRevealed ] = useState( false );
	const [ rotating, setRotating ] = useState( false );
	const [ copiedId, setCopiedId ] = useState( '' );
	const [ testState, setTestState ] = useState< TestState >( 'idle' );
	const [ testMessage, setTestMessage ] = useState( '' );

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
		2
	);

	async function handleRotate() {
		if (
			// The destructive key rotation requires native synchronous confirmation.
			// eslint-disable-next-line no-alert
			! window.confirm(
				'Create a new secret key? Any AI assistant using the current key will stop working until you update its config.'
			)
		) {
			return;
		}
		setRotating( true );
		setTestState( 'idle' );
		try {
			const res = await rotateBridgeSecret();
			setSecret( res.bridge_secret );
			setRevealed( true );
		} catch {
			setTestState( 'fail' );
			setTestMessage( 'We couldn’t create a new key. Please try again.' );
		} finally {
			setRotating( false );
		}
	}

	async function handleTest() {
		setTestState( 'testing' );
		setTestMessage( '' );
		try {
			const token = await hmacSha256Hex( secret, '' );
			const res = await fetch( `${ bridgeUrl }ping`, {
				headers: { 'X-MCP-Bridge-Token': token },
			} );
			if ( res.ok ) {
				setTestState( 'ok' );
				setTestMessage(
					'Success — your connection is working and ready to use.'
				);
			} else if ( res.status === 401 ) {
				setTestState( 'fail' );
				setTestMessage(
					'The secret key was rejected. Create a new key and update your config.'
				);
			} else if ( res.status === 403 ) {
				setTestState( 'fail' );
				setTestMessage(
					'Private mode isn’t active. Switch to Private on the Connection tab.'
				);
			} else if ( res.status === 404 ) {
				setTestState( 'fail' );
				setTestMessage(
					'The connection address wasn’t found. In WordPress, go to Settings → Permalinks and click Save.'
				);
			} else {
				setTestState( 'fail' );
				setTestMessage(
					`Unexpected response (HTTP ${ res.status }). Please try again.`
				);
			}
		} catch {
			setTestState( 'fail' );
			setTestMessage(
				'We couldn’t reach your site. Check your address, HTTPS, and any firewall rules.'
			);
		}
	}

	const masked = '•'.repeat( 24 ) + secret.slice( -4 );

	return (
		<>
			<div className="clq-section__head">
				<div className="clq-section__title">
					Connect your AI assistant
				</div>
				<p className="clq-section__desc">
					Follow these three steps to let an assistant like Claude
					Desktop answer questions about your store. Everything stays
					on this server.
				</p>
			</div>

			<div className="clq-steps">
				{ /* Step 1 — install */ }
				<div className="clq-step">
					<span className="clq-step__num">1</span>
					<div className="clq-step__body">
						<div className="clq-step__title">
							Install the free connector
						</div>
						<p className="clq-step__text">
							On the computer running your AI assistant, install
							the Clariq connector by running{ ' ' }
							<code>uvx --from clariq-mcp-server clariq-mcp</code>{ ' ' }
							in a terminal.
						</p>
					</div>
				</div>

				{ /* Step 2 — paste config */ }
				<div className="clq-step">
					<span className="clq-step__num">2</span>
					<div className="clq-step__body">
						<div className="clq-step__title">
							Add this to your assistant’s config
						</div>
						<p className="clq-step__text">
							Copy the settings below into your assistant’s
							configuration file (for Claude Desktop, that’s{ ' ' }
							<code>claude_desktop_config.json</code>).
						</p>

						<div className="clq-code">
							<div className="clq-code__bar">
								<span>claude_desktop_config.json</span>
								<button
									type="button"
									className="clq-ghost"
									onClick={ () =>
										copyText(
											configSnippet,
											setCopiedId,
											'config'
										)
									}
								>
									{ copiedId === 'config'
										? 'Copied!'
										: 'Copy' }
								</button>
							</div>
							<pre className="clq-code__body">
								{ configSnippet }
							</pre>
						</div>

						{ /* Connection details — the two values used above */ }
						<div className="clq-rows" style={ { marginTop: 14 } }>
							<div className="clq-row">
								<span className="clq-row__label">
									Store address
								</span>
								<span className="clq-row__value">
									<code className="clq-mono">
										{ bridgeUrl }
									</code>
									<button
										type="button"
										className="clq-icon-btn"
										title={
											copiedId === 'endpoint'
												? 'Copied'
												: 'Copy'
										}
										onClick={ () =>
											copyText(
												bridgeUrl,
												setCopiedId,
												'endpoint'
											)
										}
									>
										{ copiedId === 'endpoint' ? (
											<CheckIcon />
										) : (
											<CopyIcon />
										) }
									</button>
								</span>
							</div>
							<div className="clq-row">
								<span className="clq-row__label">
									Secret key
								</span>
								<span className="clq-row__value">
									<code className="clq-mono">
										{ revealed ? secret : masked }
									</code>
									<button
										type="button"
										className="clq-icon-btn"
										title={ revealed ? 'Hide' : 'Reveal' }
										onClick={ () =>
											setRevealed( ! revealed )
										}
									>
										{ revealed ? (
											<EyeOffIcon />
										) : (
											<EyeIcon />
										) }
									</button>
									<button
										type="button"
										className="clq-icon-btn"
										title={
											copiedId === 'secret'
												? 'Copied'
												: 'Copy'
										}
										onClick={ () =>
											copyText(
												secret,
												setCopiedId,
												'secret'
											)
										}
									>
										{ copiedId === 'secret' ? (
											<CheckIcon />
										) : (
											<CopyIcon />
										) }
									</button>
								</span>
							</div>
						</div>

						<div style={ { marginTop: 12 } }>
							<button
								type="button"
								className="clq-ghost clq-ghost--danger"
								disabled={ rotating }
								onClick={ handleRotate }
							>
								{ rotating
									? 'Creating…'
									: 'Create a new secret key' }
							</button>
						</div>
					</div>
				</div>

				{ /* Step 3 — test */ }
				<div className="clq-step">
					<span className="clq-step__num">3</span>
					<div className="clq-step__body">
						<div className="clq-step__title">
							Test the connection
						</div>
						<p className="clq-step__text">
							Make sure everything is wired up correctly before
							you start asking questions.
						</p>

						<button
							type="button"
							className="clq-btn clq-btn--secondary"
							disabled={ testState === 'testing' }
							onClick={ handleTest }
						>
							{ testState === 'testing' ? (
								<span
									className="clq-spinner"
									aria-hidden="true"
								/>
							) : null }
							{ testState === 'testing'
								? 'Testing…'
								: 'Test connection' }
						</button>

						{ testMessage && (
							<div
								className={ `clq-notice ${
									testState === 'ok'
										? 'clq-notice--success'
										: 'clq-notice--error'
								}` }
								style={ { marginTop: 12 } }
							>
								{ testState === 'ok' ? (
									<CheckIcon />
								) : (
									<AlertIcon />
								) }
								<div>{ testMessage }</div>
							</div>
						) }
					</div>
				</div>
			</div>
		</>
	);
}
