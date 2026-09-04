/**
 * Page Load: server-side response time, DB query & peak-memory sampling, plus a
 * per-plugin load impact benchmark. Route: #/module?id=page-load
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Check, LoaderCircle } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'overview' | 'impact' | 'settings';

interface Summary {
	avg_ms: number;
	max_ms: number;
	min_ms: number;
	avg_queries: number;
	avg_memory_mb: number;
	max_memory_mb: number;
	sample_count: number;
}

interface HourlyRow {
	hour: string;
	avg_ms: number;
	avg_queries: number;
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
	query_count: number;
	memory_peak_kb: number;
}

interface ImpactRow {
	plugin_file: string;
	plugin_name: string;
	avg_impact_ms: number;
	max_impact_ms: number;
	event_count: number;
	last_event: string;
}

interface ImpactEvent {
	id: number;
	created_at: string;
	event_type: string;
	plugin_name: string;
	plugin_file: string;
	benchmark_before: number | null;
	benchmark_after: number | null;
	benchmark_diff: number | null;
	benchmark_count: number;
	status: string;
}

interface Impact {
	impacts: ImpactRow[];
	events: ImpactEvent[];
}

/** Signed millisecond delta, e.g. "+42 ms" / "-15 ms". */
function formatDiff( ms: number | null ): string {
	if ( ms === null ) {
		return '—';
	}
	const rounded = Math.round( ms );
	return `${ rounded >= 0 ? '+' : '' }${ rounded } ms`;
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
						{ __( 'Avg queries', 'ux-studio' ) }: { overview?.summary.avg_queries ?? 0 } &nbsp;·&nbsp;
						{ __( 'Avg memory', 'ux-studio' ) }: { overview?.summary.avg_memory_mb ?? 0 } MB &nbsp;·&nbsp;
						{ __( 'Peak memory', 'ux-studio' ) }: { overview?.summary.max_memory_mb ?? 0 } MB &nbsp;·&nbsp;
						{ __( 'Samples', 'ux-studio' ) }: { overview?.summary.sample_count ?? 0 }
					</p>
				</div>
			</div>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Hour', 'ux-studio' ) }</th>
						<th>{ __( 'Avg (ms)', 'ux-studio' ) }</th>
						<th>{ __( 'Avg queries', 'ux-studio' ) }</th>
						<th>{ __( 'Samples', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ ( overview?.hourly ?? [] ).map( ( row ) => (
						<tr key={ row.hour }>
							<td>{ row.hour }</td>
							<td>{ row.avg_ms }</td>
							<td>{ row.avg_queries }</td>
							<td>{ row.count }</td>
						</tr>
					) ) }
					{ ! overview?.hourly.length ? (
						<tr>
							<td colSpan={ 4 }>{ __( 'No data yet.', 'ux-studio' ) }</td>
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
						<th>{ __( 'Queries', 'ux-studio' ) }</th>
						<th>{ __( 'Memory (MB)', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ ( log ?? [] ).map( ( row ) => (
						<tr key={ row.id }>
							<td>{ row.created_at }</td>
							<td>{ row.url }</td>
							<td>{ row.load_time_ms }</td>
							<td>{ row.query_count }</td>
							<td>{ Math.round( ( row.memory_peak_kb / 1024 ) * 10 ) / 10 }</td>
						</tr>
					) ) }
					{ ! log?.length ? (
						<tr>
							<td colSpan={ 5 }>{ __( 'No requests logged yet.', 'ux-studio' ) }</td>
						</tr>
					) : null }
				</tbody>
			</table>
		</>
	);
}

function ImpactTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'page-load', 'impact' ],
		queryFn: () => api< Impact >( 'page-load/impact' ),
	} );

	if ( isLoading ) {
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
					<label>{ __( 'Estimated per-plugin impact', 'ux-studio' ) }</label>
					<p className="uxs-form__help">
						{ __(
							'Measured from front-page benchmarks run automatically when a plugin is activated or deactivated. Positive values mean the plugin slowed the page.',
							'ux-studio'
						) }
					</p>
				</div>
			</div>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Plugin', 'ux-studio' ) }</th>
						<th>{ __( 'Avg impact', 'ux-studio' ) }</th>
						<th>{ __( 'Max impact', 'ux-studio' ) }</th>
						<th>{ __( 'Measurements', 'ux-studio' ) }</th>
						<th>{ __( 'Last event', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ ( data?.impacts ?? [] ).map( ( row ) => (
						<tr key={ row.plugin_file }>
							<td>{ row.plugin_name }</td>
							<td>{ formatDiff( row.avg_impact_ms ) }</td>
							<td>{ formatDiff( row.max_impact_ms ) }</td>
							<td>{ row.event_count }</td>
							<td>{ row.last_event }</td>
						</tr>
					) ) }
					{ ! data?.impacts.length ? (
						<tr>
							<td colSpan={ 5 }>
								{ __(
									'No impact data yet. Activate or deactivate a plugin to trigger a benchmark.',
									'ux-studio'
								) }
							</td>
						</tr>
					) : null }
				</tbody>
			</table>
			<h2 style={ { fontSize: 'var(--uxs-fs-m)', margin: 'var(--uxs-sp-5) 0 var(--uxs-sp-3)' } }>
				{ __( 'Recent benchmark events', 'ux-studio' ) }
			</h2>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Time', 'ux-studio' ) }</th>
						<th>{ __( 'Plugin', 'ux-studio' ) }</th>
						<th>{ __( 'Event', 'ux-studio' ) }</th>
						<th>{ __( 'Before (ms)', 'ux-studio' ) }</th>
						<th>{ __( 'After (ms)', 'ux-studio' ) }</th>
						<th>{ __( 'Diff', 'ux-studio' ) }</th>
						<th>{ __( 'Status', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ ( data?.events ?? [] ).map( ( row ) => (
						<tr key={ row.id }>
							<td>{ row.created_at }</td>
							<td>{ row.plugin_name }</td>
							<td>{ row.event_type }</td>
							<td>{ row.benchmark_before ?? '—' }</td>
							<td>{ row.benchmark_after ?? '—' }</td>
							<td>{ formatDiff( row.benchmark_diff ) }</td>
							<td>{ row.status }</td>
						</tr>
					) ) }
					{ ! data?.events.length ? (
						<tr>
							<td colSpan={ 7 }>{ __( 'No benchmark events yet.', 'ux-studio' ) }</td>
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
					className={ tab === 'impact' ? 'is-active' : '' }
					onClick={ () => setTab( 'impact' ) }
				>
					{ __( 'Plugin impact', 'ux-studio' ) }
				</button>
				<button
					className={ tab === 'settings' ? 'is-active' : '' }
					onClick={ () => setTab( 'settings' ) }
				>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'overview' && <OverviewTab /> }
			{ tab === 'impact' && <ImpactTab /> }
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
