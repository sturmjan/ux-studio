/**
 * Visitor stats overview: period switcher + 3 cards (Visitors, Views, Live) +
 * a dual bar chart (visitors + views), plus rankings and breakdowns.
 */
import { useEffect, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Eye, Users, Globe, LoaderCircle, RefreshCw, TrendingDown, TrendingUp } from 'lucide-react';
import { api } from '../../app/api';

type DailyPoint = { day: string; views: number; uniq: number };
type TopRow = { path?: string; referer_host?: string; views: number; visitors: number };
type DimRow = { val: string; views: number; visitors: number };
type Summary = {
	range: { from: string; to: string; period: string; days: number };
	totals: { views: number; visitors: number };
	previous: { views: number; visitors: number };
	realtime: number;
	daily: DailyPoint[];
	top_pages: TopRow[];
	referers: TopRow[];
	breakdowns: Record< string, DimRow[] >;
};

const flag = ( cc: string ): string =>
	/^[A-Za-z]{2}$/.test( cc )
		? String.fromCodePoint(
				...cc
					.toUpperCase()
					.split( '' )
					.map( ( c ) => 0x1f1e6 + c.charCodeAt( 0 ) - 65 )
		  )
		: '';

const PERIODS: { key: string; label: string }[] = [
	{ key: 'today', label: __( 'Today', 'ux-studio' ) },
	{ key: 'yesterday', label: __( 'Yesterday', 'ux-studio' ) },
	{ key: '7d', label: __( '7 days', 'ux-studio' ) },
	{ key: '14d', label: __( '14 days', 'ux-studio' ) },
	{ key: '30d', label: __( '30 days', 'ux-studio' ) },
	{ key: 'month', label: __( 'This month', 'ux-studio' ) },
];

/** Fixed categorical palette for donut/bar breakdowns (not tied to status colors). */
const CAT_COLORS = [ '#4f46e5', '#0ea5e9', '#16a34a', '#d97706', '#dc2626', '#7c3aed' ];
const CAT_OTHER = '#94a3b8';

function useCountUp( target: number, duration = 650 ): number {
	const [ val, setVal ] = useState( target );
	const fromRef = useRef( target );
	const rafRef = useRef< number >();

	useEffect( () => {
		const from = fromRef.current;
		const start = performance.now();
		const tick = ( now: number ) => {
			const t = Math.min( 1, ( now - start ) / duration );
			const eased = 1 - Math.pow( 1 - t, 3 );
			setVal( Math.round( from + ( target - from ) * eased ) );
			if ( t < 1 ) {
				rafRef.current = requestAnimationFrame( tick );
			} else {
				fromRef.current = target;
			}
		};
		rafRef.current = requestAnimationFrame( tick );
		return () => {
			if ( rafRef.current ) cancelAnimationFrame( rafRef.current );
			fromRef.current = target;
		};
	}, [ target, duration ] );

	return val;
}

const fmt = ( n: number ) => new Intl.NumberFormat().format( n );

const shortDate = ( iso: string ) => {
	const d = new Date( iso + 'T00:00:00' );
	return Number.isNaN( d.getTime() ) ? iso : d.toLocaleDateString( undefined, { day: 'numeric', month: 'numeric' } );
};

const fullDate = ( iso: string ) => {
	const d = new Date( iso + 'T00:00:00' );
	return Number.isNaN( d.getTime() )
		? iso
		: d.toLocaleDateString( undefined, { weekday: 'short', day: 'numeric', month: 'numeric' } );
};

function DeltaBadge( { cur, prev }: { cur: number; prev: number } ): JSX.Element | null {
	if ( prev <= 0 ) {
		return null;
	}
	const pct = Math.round( ( ( cur - prev ) / prev ) * 100 );
	const up = pct >= 0;
	return (
		<span
			style={ {
				display: 'inline-flex',
				alignItems: 'center',
				gap: 3,
				fontSize: 12,
				fontWeight: 600,
				padding: '2px 8px',
				borderRadius: 999,
				color: up ? 'var(--uxs-success)' : 'var(--uxs-danger)',
				background: up
					? 'color-mix(in srgb, var(--uxs-success) 12%, transparent)'
					: 'color-mix(in srgb, var(--uxs-danger) 12%, transparent)',
			} }
		>
			{ up ? <TrendingUp size={ 12 } /> : <TrendingDown size={ 12 } /> }
			{ up ? '+' : '' }
			{ pct }%
		</span>
	);
}

function prevSubtitle( cur: number, prev: number ): string {
	if ( prev <= 0 ) {
		return __( 'no data for the previous period', 'ux-studio' );
	}
	const diff = cur - prev;
	if ( diff === 0 ) {
		return __( 'same as the previous period', 'ux-studio' );
	}
	return diff > 0
		? sprintf( __( '%s more than the previous period', 'ux-studio' ), fmt( diff ) )
		: sprintf( __( '%s fewer than the previous period', 'ux-studio' ), fmt( -diff ) );
}

function sprintf( template: string, value: string ): string {
	return template.replace( '%s', value );
}

function StatCard( {
	icon,
	label,
	value,
	prev,
	live,
	subtitle,
}: {
	icon: JSX.Element | null;
	label: string;
	value: number;
	prev?: number;
	live?: boolean;
	subtitle: string;
} ): JSX.Element {
	const animated = useCountUp( value );
	return (
		<div className="uxs-card" style={ { margin: 0 } }>
			<div
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					color: 'var(--uxs-text-soft)',
					fontSize: 12,
					textTransform: 'uppercase',
					letterSpacing: '.03em',
				} }
			>
				<span style={ { display: 'inline-flex', alignItems: 'center', gap: 6 } }>
					{ icon } { label }
				</span>
				{ live ? (
					<span style={ { display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11 } }>
						<span
							style={ {
								width: 7,
								height: 7,
								borderRadius: '50%',
								background: 'var(--uxs-success)',
								display: 'inline-block',
							} }
						/>
						{ __( 'Live', 'ux-studio' ) }
					</span>
				) : (
					prev !== undefined && <DeltaBadge cur={ value } prev={ prev } />
				) }
			</div>
			<div style={ { fontSize: 34, fontWeight: 700, lineHeight: 1.15, marginTop: 6 } }>{ fmt( animated ) }</div>
			<div style={ { color: 'var(--uxs-text-soft)', fontSize: 12.5, marginTop: 2 } }>{ subtitle }</div>
		</div>
	);
}

function Chart( { data }: { data: DailyPoint[] } ): JSX.Element {
	const H = 170;
	const max = Math.max( 1, ...data.map( ( d ) => d.views ) );
	const [ hover, setHover ] = useState< number | null >( null );

	if ( data.length === 0 ) {
		return <p style={ { color: 'var(--uxs-text-soft)', margin: 0 } }>{ __( 'No data yet.', 'ux-studio' ) }</p>;
	}

	return (
		<div>
			<div
				style={ { display: 'flex', alignItems: 'flex-end', gap: data.length > 20 ? 3 : 6, height: H } }
				onMouseLeave={ () => setHover( null ) }
			>
				{ data.map( ( d, i ) => {
					const viewsH = ( d.views / max ) * H;
					const uniqH = ( d.uniq / max ) * H;
					const active = hover === i;
					return (
						<div
							key={ d.day }
							onMouseEnter={ () => setHover( i ) }
							style={ { flex: 1, position: 'relative', height: H, minWidth: 4, cursor: 'pointer' } }
						>
							<div
								style={ {
									position: 'absolute',
									bottom: 0,
									left: 0,
									right: 0,
									height: Math.max( 2, viewsH ),
									background: 'var(--uxs-brand)',
									opacity: active ? 0.4 : 0.28,
									borderRadius: '4px 4px 0 0',
									transition: 'height .3s var(--uxs-ease)',
								} }
							/>
							<div
								style={ {
									position: 'absolute',
									bottom: 0,
									left: 0,
									right: 0,
									height: Math.max( 2, uniqH ),
									background: 'var(--uxs-brand)',
									borderRadius: '4px 4px 0 0',
									transition: 'height .3s var(--uxs-ease)',
								} }
							/>
							{ active && (
								<div
									style={ {
										position: 'absolute',
										bottom: Math.max( viewsH, uniqH ) + 8,
										left: '50%',
										transform: 'translateX(-50%)',
										background: 'var(--uxs-surface)',
										border: '1px solid var(--uxs-border)',
										borderRadius: 'var(--uxs-radius-s)',
										boxShadow: 'var(--uxs-shadow-2)',
										padding: 'var(--uxs-sp-2) var(--uxs-sp-3)',
										fontSize: 12,
										whiteSpace: 'nowrap',
										zIndex: 10,
									} }
								>
									<div style={ { fontWeight: 600, marginBottom: 4 } }>{ fullDate( d.day ) }</div>
									<div>
										{ __( 'Visitors', 'ux-studio' ) }: <strong>{ fmt( d.uniq ) }</strong>
									</div>
									<div>
										{ __( 'Views', 'ux-studio' ) }: <strong>{ fmt( d.views ) }</strong>
									</div>
								</div>
							) }
						</div>
					);
				} ) }
			</div>
			<div
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					color: 'var(--uxs-text-soft)',
					fontSize: 11,
					marginTop: 8,
				} }
			>
				<span>{ shortDate( data[ 0 ]?.day ?? '' ) }</span>
				<span>{ shortDate( data[ data.length - 1 ]?.day ?? '' ) }</span>
			</div>
		</div>
	);
}

function TopTable( {
	title,
	rows,
	dimLabel,
	getKey,
}: {
	title: string;
	rows: TopRow[];
	dimLabel: string;
	getKey: ( r: TopRow ) => string;
} ): JSX.Element {
	return (
		<div className="uxs-card" style={ { margin: 0 } }>
			<h2 style={ { fontSize: 15, marginTop: 0, marginBottom: 12, color: 'var(--uxs-text)' } }>{ title }</h2>
			{ rows.length === 0 ? (
				<p style={ { color: 'var(--uxs-text-soft)', margin: 0, fontSize: 13 } }>{ __( 'No data.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th style={ { width: 28 } }>#</th>
							<th style={ { textAlign: 'left' } }>{ dimLabel }</th>
							<th style={ { textAlign: 'right', whiteSpace: 'nowrap' } }>{ __( 'Visitors', 'ux-studio' ) }</th>
							<th style={ { textAlign: 'right', whiteSpace: 'nowrap' } }>{ __( 'Views', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( r, i ) => (
							<tr key={ getKey( r ) }>
								<td style={ { color: 'var(--uxs-text-soft)' } }>{ i + 1 }</td>
								<td>
									<span
										style={ {
											display: 'inline-block',
											maxWidth: 340,
											overflow: 'hidden',
											textOverflow: 'ellipsis',
											whiteSpace: 'nowrap',
											verticalAlign: 'bottom',
										} }
									>
										{ getKey( r ) }
									</span>
								</td>
								<td style={ { textAlign: 'right', fontWeight: 600 } }>{ fmt( r.visitors ) }</td>
								<td style={ { textAlign: 'right' } }>{ fmt( r.views ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

type Seg = { label: string; value: number; color: string };

function Donut( { segments, size = 128, thickness = 20 }: { segments: Seg[]; size?: number; thickness?: number } ): JSX.Element {
	const total = Math.max( 1, segments.reduce( ( s, x ) => s + x.value, 0 ) );
	const cx = size / 2;
	const r = ( size - thickness ) / 2;
	const C = 2 * Math.PI * r;
	const gap = segments.length > 1 ? 2 : 0;

	let acc = 0;
	const segs = segments.map( ( s ) => {
		const frac = s.value / total;
		const start = acc;
		acc += frac;
		return { ...s, dash: Math.max( 0, frac * C - gap ), rot: start * 360 - 90 };
	} );

	return (
		<svg width={ size } height={ size } viewBox={ `0 0 ${ size } ${ size }` }>
			{ segs.map( ( s, i ) => (
				<circle
					key={ i }
					cx={ cx }
					cy={ cx }
					r={ r }
					fill="none"
					stroke={ s.color }
					strokeWidth={ thickness }
					strokeDasharray={ `${ s.dash } ${ C - s.dash }` }
					style={ { transform: `rotate(${ s.rot }deg)`, transformOrigin: 'center' } }
				/>
			) ) }
			<text x="50%" y="50%" textAnchor="middle" dominantBaseline="central" style={ { fontSize: 22, fontWeight: 700, fill: 'var(--uxs-text)' } }>
				{ fmt( total ) }
			</text>
		</svg>
	);
}

function BreakdownCard( {
	title,
	rows,
	variant = 'bars',
	labelFn,
}: {
	title: string;
	rows: DimRow[];
	variant?: 'bars' | 'donut';
	labelFn?: ( val: string ) => string;
} ): JSX.Element {
	const empty = rows.length === 0;

	let segments: Seg[] = [];
	if ( variant === 'donut' && ! empty ) {
		const top = rows.slice( 0, 6 );
		const rest = rows.slice( 6 ).reduce( ( s, r ) => s + r.visitors, 0 );
		segments = top.map( ( r, i ) => ( { label: r.val, value: r.visitors, color: CAT_COLORS[ i % CAT_COLORS.length ] ?? CAT_OTHER } ) );
		if ( rest > 0 ) segments.push( { label: __( 'Other', 'ux-studio' ), value: rest, color: CAT_OTHER } );
	}
	const totalVis = Math.max( 1, rows.reduce( ( s, r ) => s + r.visitors, 0 ) );
	const barMax = Math.max( 1, ...rows.map( ( r ) => r.visitors ) );

	return (
		<div className="uxs-card" style={ { margin: 0 } }>
			<h2 style={ { fontSize: 15, marginTop: 0, marginBottom: 12, color: 'var(--uxs-text)' } }>{ title }</h2>

			{ empty && <p style={ { color: 'var(--uxs-text-soft)', margin: 0, fontSize: 13 } }>{ __( 'No data.', 'ux-studio' ) }</p> }

			{ ! empty && variant === 'donut' && (
				<div style={ { display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' } }>
					<Donut segments={ segments } />
					<div style={ { flex: 1, minWidth: 130, display: 'flex', flexDirection: 'column', gap: 7 } }>
						{ segments.map( ( s ) => (
							<div key={ s.label } style={ { display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 } }>
								<span style={ { width: 10, height: 10, borderRadius: 3, background: s.color, flexShrink: 0 } } />
								<span style={ { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
									{ labelFn ? labelFn( s.label ) : s.label }
								</span>
								<strong style={ { marginLeft: 'auto' } }>{ fmt( s.value ) }</strong>
								<span style={ { color: 'var(--uxs-text-soft)', width: 38, textAlign: 'right' } }>
									{ Math.round( ( s.value / totalVis ) * 100 ) }%
								</span>
							</div>
						) ) }
					</div>
				</div>
			) }

			{ ! empty && variant === 'bars' && (
				<div style={ { display: 'flex', flexDirection: 'column', gap: 9 } }>
					{ rows.map( ( r ) => (
						<div key={ r.val }>
							<div style={ { display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 3 } }>
								<span style={ { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 180 } }>
									{ labelFn ? labelFn( r.val ) : r.val }
								</span>
								<strong>{ fmt( r.visitors ) }</strong>
							</div>
							<div style={ { height: 6, background: 'var(--uxs-surface-2)', borderRadius: 999 } }>
								<div
									style={ {
										width: `${ ( r.visitors / barMax ) * 100 }%`,
										height: '100%',
										background: 'var(--uxs-brand)',
										borderRadius: 999,
										transition: 'width .5s var(--uxs-ease)',
									} }
								/>
							</div>
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}

function DonutRow( { b }: { b: Record< string, DimRow[] > } ): JSX.Element {
	return (
		<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 } }>
			<BreakdownCard title={ __( 'Device', 'ux-studio' ) } variant="donut" rows={ b?.device ?? [] } />
			<BreakdownCard title={ __( 'Browser', 'ux-studio' ) } variant="donut" rows={ b?.browser ?? [] } />
			<BreakdownCard title={ __( 'Operating system', 'ux-studio' ) } variant="donut" rows={ b?.os ?? [] } />
		</div>
	);
}

type GeoStatus = { enabled: boolean; ready: boolean; rows: number; updated: string };

function GeoIpCard(): JSX.Element {
	const qc = useQueryClient();
	const q = useQuery< GeoStatus >( {
		queryKey: [ 'analytics-geoip' ],
		queryFn: () => api< GeoStatus >( 'analytics/geoip' ),
	} );

	const update = useMutation( {
		mutationFn: () => api( 'analytics/geoip', { method: 'POST', body: JSON.stringify( { action: 'update' } ) } ),
		onSuccess: () => void qc.invalidateQueries( { queryKey: [ 'analytics-geoip' ] } ),
	} );
	const disable = useMutation( {
		mutationFn: () => api( 'analytics/geoip', { method: 'POST', body: JSON.stringify( { action: 'disable' } ) } ),
		onSuccess: () => void qc.invalidateQueries( { queryKey: [ 'analytics-geoip' ] } ),
	} );

	const s = q.data;

	return (
		<div className="uxs-card" style={ { display: 'flex', alignItems: 'center', gap: 14, margin: 0 } }>
			<Globe size={ 22 } color="var(--uxs-brand)" />
			<div style={ { flex: 1 } }>
				<strong>{ __( 'Country stats (GeoIP)', 'ux-studio' ) }</strong>
				<div style={ { color: 'var(--uxs-text-soft)', fontSize: 13, marginTop: 2 } }>
					{ s?.ready
						? `${ __( 'Database active', 'ux-studio' ) } — ${ fmt( s.rows ) } ${ __( 'IP ranges', 'ux-studio' ) }${
								s.updated ? ', ' + __( 'updated', 'ux-studio' ) + ' ' + new Date( s.updated ).toLocaleDateString() : ''
						  }.`
						: __(
								'The public-domain country database is downloaded once. The raw IP address is never stored.',
								'ux-studio'
						  ) }
					{ update.isError && <span style={ { color: 'var(--uxs-danger)' } }> { __( 'Download failed, try again.', 'ux-studio' ) }</span> }
				</div>
			</div>
			<button type="button" className="button" onClick={ () => update.mutate() } disabled={ update.isPending }>
				{ update.isPending ? <LoaderCircle size={ 14 } /> : <RefreshCw size={ 14 } /> }{ ' ' }
				{ s?.ready ? __( 'Update', 'ux-studio' ) : __( 'Download database', 'ux-studio' ) }
			</button>
			{ s?.enabled && (
				<button type="button" className="button" onClick={ () => disable.mutate() } disabled={ disable.isPending }>
					{ __( 'Disable', 'ux-studio' ) }
				</button>
			) }
		</div>
	);
}

export default function AnalyticsOverview(): JSX.Element {
	const [ period, setPeriod ] = useState( '7d' );

	const q = useQuery< Summary >( {
		queryKey: [ 'analytics-summary', period ],
		queryFn: () => api< Summary >( `analytics/summary?period=${ period }` ),
		refetchInterval: 60000,
	} );

	const data = q.data;

	return (
		<div style={ { display: 'flex', flexDirection: 'column', gap: 20 } }>
			<div style={ { display: 'flex', justifyContent: 'flex-end' } }>
				<div className="uxs-seg" style={ { display: 'inline-flex', border: '1px solid var(--uxs-border)', borderRadius: 'var(--uxs-radius)', overflow: 'hidden' } }>
					{ PERIODS.map( ( p ) => (
						<button
							key={ p.key }
							type="button"
							onClick={ () => setPeriod( p.key ) }
							style={ {
								border: 'none',
								borderLeft: p.key === PERIODS[ 0 ]?.key ? 'none' : '1px solid var(--uxs-border)',
								padding: 'var(--uxs-sp-2) var(--uxs-sp-3)',
								background: period === p.key ? 'var(--uxs-brand)' : 'var(--uxs-surface)',
								color: period === p.key ? '#fff' : 'var(--uxs-text-soft)',
								cursor: 'pointer',
							} }
						>
							{ p.label }
						</button>
					) ) }
				</div>
			</div>

			{ q.isLoading && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }

			{ data && (
				<>
					<div style={ { display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 } }>
						<StatCard
							icon={ <Users size={ 14 } /> }
							label={ __( 'Visitors', 'ux-studio' ) }
							value={ data.totals.visitors }
							prev={ data.previous.visitors }
							subtitle={ prevSubtitle( data.totals.visitors, data.previous.visitors ) }
						/>
						<StatCard
							icon={ <Eye size={ 14 } /> }
							label={ __( 'Views', 'ux-studio' ) }
							value={ data.totals.views }
							prev={ data.previous.views }
							subtitle={ prevSubtitle( data.totals.views, data.previous.views ) }
						/>
						<StatCard
							icon={ null }
							label={ __( 'Right now', 'ux-studio' ) }
							value={ data.realtime }
							live
							subtitle={ __( 'views in the last hour', 'ux-studio' ) }
						/>
					</div>

					<div className="uxs-card">
						<h2 style={ { marginTop: 0, color: 'var(--uxs-text)' } }>{ __( 'Visitors and views', 'ux-studio' ) }</h2>
						<p style={ { color: 'var(--uxs-text-soft)', margin: '2px 0 16px', fontSize: 13 } }>
							{ __( 'Daily totals for the selected period', 'ux-studio' ) }
						</p>
						<Chart data={ data.daily } />
					</div>

					<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 } }>
						<TopTable
							title={ __( 'Top pages', 'ux-studio' ) }
							rows={ data.top_pages }
							dimLabel={ __( 'Page', 'ux-studio' ) }
							getKey={ ( r ) => r.path ?? '' }
						/>
						<TopTable
							title={ __( 'Traffic sources', 'ux-studio' ) }
							rows={ data.referers }
							dimLabel={ __( 'Source', 'ux-studio' ) }
							getKey={ ( r ) => r.referer_host ?? '' }
						/>
					</div>

					<DonutRow b={ data.breakdowns } />

					<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16 } }>
						<BreakdownCard title={ __( 'Language', 'ux-studio' ) } rows={ data.breakdowns?.lang ?? [] } />
						{ ( data.breakdowns?.country?.length ?? 0 ) > 0 && (
							<BreakdownCard
								title={ __( 'Country', 'ux-studio' ) }
								rows={ data.breakdowns.country ?? [] }
								labelFn={ ( cc ) => `${ flag( cc ) } ${ cc }`.trim() }
							/>
						) }
					</div>

					<GeoIpCard />
				</>
			) }
		</div>
	);
}
