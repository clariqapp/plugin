import React from '@wordpress/element';
import type { ReactNode } from 'react';
import type { StatusResponse } from '../lib/api';
import { CoinsIcon, TagIcon, BagIcon, UsersIcon } from './icons';

type Stats = NonNullable< StatusResponse[ 'store_stats' ] >;

interface Props {
	stats: Stats;
}

/**
 * Currency formatter with a graceful fallback for unknown ISO codes.
 * @param value
 * @param currency
 * @param fractionDigits
 */
function money(
	value: number,
	currency: string,
	fractionDigits: number
): string {
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency,
			minimumFractionDigits: fractionDigits,
			maximumFractionDigits: fractionDigits,
		} ).format( value );
	} catch {
		// Unknown/empty currency code — fall back to a grouped number + raw code.
		const num = new Intl.NumberFormat( undefined, {
			minimumFractionDigits: fractionDigits,
			maximumFractionDigits: fractionDigits,
		} ).format( value );
		return currency ? `${ currency } ${ num }` : num;
	}
}

/**
 * A compact, at-a-glance KPI strip for the last 30 days. The window is fixed at
 * 30 days on every plan (it's a headline snapshot, not the sync range), so the
 * caption is intentionally static. Rendered only when the store has orders.
 * @param root0
 * @param root0.stats
 */
export function StoreStats( { stats }: Props ) {
	const {
		currency,
		revenue,
		avg_order_value: avgOrderValue,
		orders,
		unique_buyers: uniqueBuyers,
	} = stats;

	const cards: {
		key: string;
		icon: ReactNode;
		label: string;
		value: string;
		accent?: boolean;
	}[] = [
		{
			key: 'revenue',
			icon: <CoinsIcon />,
			label: 'Revenue',
			value: money( revenue, currency, revenue >= 1000 ? 0 : 2 ),
			accent: true,
		},
		{
			key: 'aov',
			icon: <TagIcon />,
			label: 'Avg. order value',
			value: money( avgOrderValue, currency, 2 ),
		},
		{
			key: 'orders',
			icon: <BagIcon />,
			label: 'Orders',
			value: orders.toLocaleString(),
		},
		{
			key: 'buyers',
			icon: <UsersIcon />,
			label: 'Unique buyers',
			value: uniqueBuyers.toLocaleString(),
		},
	];

	return (
		<section
			className="clq-stats"
			aria-label="Store performance, last 30 days"
		>
			<div className="clq-stats__head">
				<span className="clq-stats__title">Last 30 days</span>
			</div>
			<div className="clq-stats__grid">
				{ cards.map( ( c ) => (
					<div
						key={ c.key }
						className={ `clq-stat-card${
							c.accent ? ' clq-stat-card--accent' : ''
						}` }
					>
						<span className="clq-stat-card__icon">{ c.icon }</span>
						<div className="clq-stat-card__body">
							<div className="clq-stat-card__label">
								{ c.label }
							</div>
							<div className="clq-stat-card__value">
								{ c.value }
							</div>
						</div>
					</div>
				) ) }
			</div>
		</section>
	);
}
