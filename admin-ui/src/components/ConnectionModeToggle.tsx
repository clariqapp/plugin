import React from '@wordpress/element';
import { CloudIcon, ShieldIcon, CheckIcon, InfoIcon } from './icons';

interface Props {
	mode: 'cloud_sync' | 'local_bridge';
	onChange: ( mode: 'cloud_sync' | 'local_bridge' ) => void;
	disabled?: boolean;
	locked?: boolean;
}

const MODES = [
	{
		id: 'cloud_sync' as const,
		glyph: <CloudIcon />,
		title: 'Clariq Cloud',
		tag: 'Best for most stores',
		desc: 'Your reports live in a shared dashboard your whole team can open from any browser.',
		feats: [
			'Ready-made analytics dashboard',
			'Invite teammates and share access',
			'Automatic daily updates',
		],
	},
	{
		id: 'local_bridge' as const,
		glyph: <ShieldIcon />,
		title: 'Private (self-hosted)',
		tag: 'Maximum privacy · Free',
		desc: 'Your store data never leaves this server. Questions are answered on demand, right here.',
		feats: [
			'No account or sign-up needed',
			'Nothing stored in the cloud',
			'Connect your own AI assistant',
		],
	},
];

export function ConnectionModeToggle( {
	mode,
	onChange,
	disabled,
	locked,
}: Props ) {
	return (
		<>
			<div className="clq-section__head">
				<div className="clq-section__title">
					How would you like to connect?
				</div>
				<p className="clq-section__desc">
					Choose where your store’s analytics are handled. You can
					change this anytime while disconnected.
				</p>
			</div>

			{ locked && (
				<div
					className="clq-notice clq-notice--warning"
					style={ { marginBottom: 16 } }
				>
					<InfoIcon />
					<div>
						Your store is connected, so the connection method is
						locked. Disconnect first to switch.
					</div>
				</div>
			) }

			<div className="clq-choice">
				{ MODES.map( ( m ) => {
					const active = mode === m.id;
					return (
						<button
							key={ m.id }
							type="button"
							disabled={ disabled || locked }
							onClick={ () => onChange( m.id ) }
							aria-pressed={ active }
							className={ `clq-choice__card ${
								active ? 'clq-choice__card--active' : ''
							} ${ locked ? 'clq-choice__card--locked' : '' }` }
						>
							<div className="clq-choice__top">
								<span className="clq-choice__glyph">
									{ m.glyph }
								</span>
								<div>
									<div className="clq-choice__title">
										{ m.title }
									</div>
									<div className="clq-choice__tag">
										{ m.tag }
									</div>
								</div>
								<span
									className="clq-choice__radio"
									aria-hidden="true"
								/>
							</div>

							<p className="clq-choice__desc">{ m.desc }</p>

							<div className="clq-choice__feats">
								{ m.feats.map( ( f ) => (
									<span
										key={ f }
										className="clq-choice__feat"
									>
										<CheckIcon />
										{ f }
									</span>
								) ) }
							</div>
						</button>
					);
				} ) }
			</div>
		</>
	);
}
