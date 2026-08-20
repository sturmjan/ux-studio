/**
 * Page Load: server-side response time sampling. Route: #/module?id=page-load
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Check, LoaderCircle } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'overview' | 'settings';

interface Summary {
	avg_ms: number;
	max_ms: number;
	min_ms: number;
	sample_count: number;
}

interface HourlyRow {
	hour: string;
	avg_ms: number;
	count: number;
}

interface Overview {
	summary: Summary;
	hourly: HourlyRow[];
}

interface LogRow {
	id: number;
	created_at: string;
	url: string;
	load_time_ms: number;
}

function OverviewTab(): JSX.Element {
	const { data: overview, isLoading: loadingOverview } = useQuery( {
		queryKey: [ 'page-load', 'overview' ],
		queryFn: () => api< Overview >( 'page-load/overview' ),
	} );
	const { data: log, isLoading: loadingLog } = useQuery( {
		queryKey: [ 'page-load', 'log' ],
		queryFn: () => api< LogRow[] >( 'page-load/log?limit=50' ),
	} );

	if ( loadingOverview || loadingLog ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				<div className="uxs-form__row">
					<label>{ __( 'Last 24 hours', 'ux-studio' ) }</label>
					<p className="uxs-form__help">
						{ __( 'Average', 'ux-studio' ) }: { overview?.summary.avg_ms ?? 0 } ms &nbsp;·&nbsp;
						{ __( 'Min', 'ux-studio' ) }: { overview?.summary.min_ms ?? 0 } ms &nbsp;·&nbsp;
						{ __( 'Max', 'ux-studio' ) }: { overview?.summary.max_ms ?? 0 } ms &nbsp;·&nbsp;
						{ __( 'Samples', 'ux-studio' ) }: { overview?.summary.sample_count ?? 0 }
					</p>
				</div>
			</div>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Hour', 'ux-studio' ) }</th>
						<th>{ __( 'Avg (ms)', 'ux-studio' ) }</th>
						<th>{ __( 'Samples', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ ( overview?.hourly ?? [] ).map( ( row ) => (
						<tr key={ row.hour }>
							<td>{ row.hour }</td>
							<td>{ row.avg_ms }</td>
							<td>{ row.count }</td>
						</tr>
					) ) }
					{ ! overview?.hourly.length ? (
						<tr>
							<td colSpan={ 3 }>{ __( 'No data yet.', 'ux-studio' ) }</td>
						</tr>
					) : null }
				</tbody>
			</table>
			<h2 style={ { fontSize: 'var(--uxs-fs-m)', margin: 'var(--uxs-sp-5) 0 var(--uxs-sp-3)' } }>
				{ __( 'Recent requests', 'ux-studio' ) }
			</h2>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Time', 'ux-studio' ) }</th>
						<th>{ __( 'URL', 'ux-studio' ) }</th>
						<th>{ __( 'Load time (ms)', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ ( log ?? [] ).map( ( row ) => (
						<tr key={ row.id }>
							<td>{ row.created_at }</td>
							<td>{ row.url }</td>
							<td>{ row.load_time_ms }</td>
						</tr>
					) ) }
					{ ! log?.length ? (
						<tr>
							<td colSpan={ 3 }>{ __( 'No requests logged yet.', 'ux-studio' ) }</td>
						</tr>
					) : null }
				</tbody>
			</table>
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'overview' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'page-load' );

	return (
		<>
			<header className="uxs-pagehead">
				<h1>
					<button
						type="button"
						onClick={ () => navigate( '' ) }
						aria-label={ __( 'Back to modules', 'ux-studio' ) }
						style={ { background: 'none', border: 'none', cursor: 'pointer', verticalAlign: 'middle' } }
					>
						<ArrowLeft size={ 18 } />
					</button>{ ' ' }
					{ __( 'Page Load', 'ux-studio' ) }
				</h1>
				{ tab === 'settings' && (
					<button
						type="button"
						className="button button-primary"
						disabled={ save.isPending }
						onClick={ () => save.mutate() }
					>
						{ saved ? <Check size={ 14 } /> : null }
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				) }
			</header>
			<div className="uxs-tabs">
				<button
					className={ tab === 'overview' ? 'is-active' : '' }
					onClick={ () => setTab( 'overview' ) }
				>
					{ __( 'Overview', 'ux-studio' ) }
				</button>
				<button
					className={ tab === 'settings' ? 'is-active' : '' }
					onClick={ () => setTab( 'settings' ) }
				>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'overview' && <OverviewTab /> }
			{ tab === 'settings' &&
				( isLoading || ! data ? (
					<div className="uxs-loading">
						<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
					</div>
				) : (
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
				) ) }
		</>
	);
}
